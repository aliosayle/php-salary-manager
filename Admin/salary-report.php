<?php
// Set error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
error_log("salary-report.php - Page loaded");

// Required files
require_once "layouts/config.php";
require_once "layouts/session.php";
require_once "layouts/helpers.php";
require_once "layouts/translations.php";

// Ensure the user is logged in and has appropriate permissions
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: auth-login.php");
    exit;
}

// Check for permission to view reports
if (!hasPermission('view_reports')) {
    $_SESSION['error_message'] = $_SESSION['lang'] == 'fr' ? 'Vous n\'avez pas la permission de voir ce rapport.' : 'You do not have permission to view this report.';
    header("location: index.php");
    exit;
}

// Initialize variables
$managers = [];
$errors = [];
$shops = [];

// Get current month and year for default selection
$currentMonth = date('m');
$currentYear = date('Y');

// Get the selected month/year from GET parameters
$selectedMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)$currentMonth;
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)$currentYear;

// Month names
$month_names = [
    1 => $_SESSION['lang'] == 'fr' ? 'Janvier' : 'January',
    2 => $_SESSION['lang'] == 'fr' ? 'Février' : 'February',
    3 => $_SESSION['lang'] == 'fr' ? 'Mars' : 'March',
    4 => $_SESSION['lang'] == 'fr' ? 'Avril' : 'April',
    5 => $_SESSION['lang'] == 'fr' ? 'Mai' : 'May',
    6 => $_SESSION['lang'] == 'fr' ? 'Juin' : 'June',
    7 => $_SESSION['lang'] == 'fr' ? 'Juillet' : 'July',
    8 => $_SESSION['lang'] == 'fr' ? 'Août' : 'August',
    9 => $_SESSION['lang'] == 'fr' ? 'Septembre' : 'September',
    10 => $_SESSION['lang'] == 'fr' ? 'Octobre' : 'October',
    11 => $_SESSION['lang'] == 'fr' ? 'Novembre' : 'November',
    12 => $_SESSION['lang'] == 'fr' ? 'Décembre' : 'December'
];

// Try to get all shops
try {
    $stmt = $pdo->query("SELECT id, name, location FROM shops ORDER BY name ASC");
    $shops = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("salary-report.php - Error fetching shops: " . $e->getMessage());
    $errors[] = "Error fetching shops: " . $e->getMessage();
}

// Get salary data for managers
try {
    // Query to get manager data
    $query = "
        SELECT 
            s.id AS shop_id, 
            s.name AS shop_name, 
            e.id AS manager_id,
            e.full_name AS manager_name,
            e.base_salary AS base_salary,
            
            -- Get monthly sales data for the selected month/year if available
            (SELECT SUM(ms.sales_amount) 
             FROM monthly_sales ms 
             WHERE ms.shop_id = s.id 
             AND MONTH(ms.sales_month) = ? 
             AND YEAR(ms.sales_month) = ?) AS monthly_sales,
            
            -- Get evaluation score for the manager
            (SELECT total_score 
             FROM employee_evaluations ev 
             WHERE ev.employee_id = e.id
             AND MONTH(ev.evaluation_month) = ?
             AND YEAR(ev.evaluation_month) = ?
             LIMIT 1) AS evaluation_score,
             
            -- Get manager debt information (advances on salary)
            (SELECT SUM(md.salary_advance) 
             FROM manager_debts md 
             WHERE md.employee_id = e.id 
             AND MONTH(md.evaluation_month) = ? 
             AND YEAR(md.evaluation_month) = ?) AS salary_advance,
             
            -- Get fines/sanctions
            (SELECT SUM(md.sanction) 
             FROM manager_debts md 
             WHERE md.employee_id = e.id 
             AND MONTH(md.evaluation_month) = ? 
             AND YEAR(md.evaluation_month) = ?) AS sanctions,
             
            -- Get inventory shortages
            (SELECT SUM(md.inventory_month) 
             FROM manager_debts md 
             WHERE md.employee_id = e.id 
             AND MONTH(md.evaluation_month) = ? 
             AND YEAR(md.evaluation_month) = ?) AS inventory_shortage,
             
            -- Get register differences
            (SELECT SUM(md.cash_discrepancy) 
             FROM manager_debts md 
             WHERE md.employee_id = e.id 
             AND MONTH(md.evaluation_month) = ? 
             AND YEAR(md.evaluation_month) = ?) AS register_difference,
             
            -- Check if manager is entitled to paid leave
            (SELECT CASE WHEN TIMESTAMPDIFF(MONTH, e.recruitment_date, CURDATE()) >= 12 THEN true ELSE false END) AS has_paid_leave,
            
            -- Check if manager is eligible for yearly/5-year/10-year bonus
            (SELECT
                CASE
                    -- Check if this is the employee's 10-year anniversary (exact month and year)
                    WHEN TIMESTAMPDIFF(YEAR, e.recruitment_date, CONCAT(?,'/',?,'/01')) = 10 
                         AND MONTH(e.recruitment_date) = ? THEN 'ten_year'
                    
                    -- Check if this is the employee's 5-year anniversary (exact month and year)
                    WHEN TIMESTAMPDIFF(YEAR, e.recruitment_date, CONCAT(?,'/',?,'/01')) = 5 
                         AND MONTH(e.recruitment_date) = ? THEN 'five_year'
                    
                    -- Check if this is any other yearly anniversary (exact month)
                    WHEN TIMESTAMPDIFF(YEAR, e.recruitment_date, CONCAT(?,'/',?,'/01')) >= 1 
                         AND MONTH(e.recruitment_date) = ? THEN 'yearly'
                    
                    -- Not an anniversary month
                    ELSE NULL
                END
            ) AS years_bonus,
            
            -- Get the employment years for the manager
            (SELECT TIMESTAMPDIFF(YEAR, e.recruitment_date, CONCAT(?,'/',?,'/01'))) AS employment_years,
            
            -- Get the recruitment month of the manager
            (SELECT MONTH(e.recruitment_date)) AS recruitment_month
            
        FROM 
            shops s
            LEFT JOIN employee_shops es ON s.id = es.shop_id
            LEFT JOIN employees e ON es.employee_id = e.id AND e.post_id = '04b5ce3e-1aaf-11f0-99a1-cc28aa53b74d' -- Manager post ID
        WHERE
            e.id IS NOT NULL
        ORDER BY 
            s.name ASC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([
        $selectedMonth, $selectedYear,   // Monthly sales
        $selectedMonth, $selectedYear,   // Evaluation score
        $selectedMonth, $selectedYear,   // Salary advances
        $selectedMonth, $selectedYear,   // Sanctions
        $selectedMonth, $selectedYear,   // Inventory shortage
        $selectedMonth, $selectedYear,   // Register difference
        $selectedYear, $selectedMonth, $selectedMonth,   // 10-year anniversary check with month
        $selectedYear, $selectedMonth, $selectedMonth,   // 5-year anniversary check with month
        $selectedYear, $selectedMonth, $selectedMonth,   // Regular yearly anniversary check with month
        $selectedYear, $selectedMonth    // Employment years calculation
    ]);
    
    $managers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get bonus configuration from database
    $bonusConfig = [];
    $bonusStmt = $pdo->query("SELECT * FROM bonus_tiers ORDER BY min_sales ASC");
    $bonusConfig = $bonusStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process each manager to calculate salary components
    foreach ($managers as &$manager) {
        // 1. Base Salary
        $baseSalary = $manager['base_salary'] ?? 0;
        
        // 2. Sales Bonus calculation
        $salesBonus = 0;
        $monthlySales = $manager['monthly_sales'] ?? 0;
        
        if ($monthlySales > 0 && !empty($bonusConfig)) {
            // Find the applicable bonus tier
            $appliedBonus = 0;
            foreach ($bonusConfig as $tier) {
                if ($monthlySales >= $tier['min_sales']) {
                    $appliedBonus = ($monthlySales * $tier['bonus_percent']) / 100;
                } else {
                    break;
                }
            }
            $salesBonus = $appliedBonus;
        }
        
        // 3. Evaluation Bonus
        $evaluationBonus = 0;
        $evalScore = $manager['evaluation_score'] ?? 0;
        
        // Check if there's a stored bonus_amount first (new way)
        $evalBonusStmt = $pdo->prepare("
            SELECT bonus_amount FROM employee_evaluations 
            WHERE employee_id = ? AND MONTH(evaluation_month) = ? AND YEAR(evaluation_month) = ?
            LIMIT 1
        ");
        $evalBonusStmt->execute([$manager['manager_id'], $selectedMonth, $selectedYear]);
        $evalBonusResult = $evalBonusStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($evalBonusResult && !empty($evalBonusResult['bonus_amount'])) {
            // Use the stored bonus amount
            $evaluationBonus = $evalBonusResult['bonus_amount'];
        } 
        else if ($evalScore > 0) {
            // Fallback to calculating from score (old way)
            $evalBonusStmt = $pdo->prepare("
                SELECT amount FROM total_ranges 
                WHERE ? BETWEEN min_value AND max_value
                LIMIT 1
            ");
            $evalBonusStmt->execute([$evalScore]);
            $evalBonusResult = $evalBonusStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($evalBonusResult) {
                $evaluationBonus = $evalBonusResult['amount'];
            }
        }
        
        // 4. Paid Leave
        $paidLeaveAmount = 0;
        if ($manager['has_paid_leave'] && $manager['recruitment_month'] == $selectedMonth) {
            // Give one extra base salary as an annual paid leave bonus
            $paidLeaveAmount = $baseSalary;
        }
        
        // 5. Years of Service Bonus (yearly/5-year/10-year)
        $yearsBonus = 0;
        $employmentYears = $manager['employment_years'] ?? 0;
        
        if ($employmentYears >= 1) {
            if ($manager['years_bonus'] === 'ten_year') {
                // 10th year: They get two additional base salaries
                $yearsBonus = $baseSalary * 2;
            } else if ($manager['years_bonus'] === 'five_year') {
                // 5th year: They get one additional base salary
                $yearsBonus = $baseSalary;
            } 
            // Removed the yearly bonus since it's now handled by the paid leave
        }
        
        // 6. Deductions
        $inventoryShortage = $manager['inventory_shortage'] ?? 0;
        $salaryAdvance = $manager['salary_advance'] ?? 0;
        $sanctions = $manager['sanctions'] ?? 0;
        $registerDifference = $manager['register_difference'] ?? 0;
        
        // 7. Calculate Net Salary
        $netSalary = $baseSalary + $salesBonus + $evaluationBonus + $paidLeaveAmount + $yearsBonus - 
                    $inventoryShortage - $salaryAdvance - $sanctions - $registerDifference;
        
        // Store all calculated values
        $manager['base_salary'] = $baseSalary;
        $manager['sales_bonus'] = $salesBonus;
        $manager['evaluation_bonus'] = $evaluationBonus;
        $manager['paid_leave'] = $paidLeaveAmount;
        $manager['years_bonus'] = $yearsBonus;
        $manager['inventory_shortage'] = $inventoryShortage;
        $manager['salary_advance'] = $salaryAdvance;
        $manager['sanctions'] = $sanctions;
        $manager['register_difference'] = $registerDifference;
        $manager['net_salary'] = $netSalary;
    }
    
    // Calculate totals for all numeric columns
    $totals = [
        'base_salary' => 0,
        'sales_bonus' => 0,
        'evaluation_bonus' => 0,
        'paid_leave' => 0,
        'years_bonus' => 0,
        'inventory_shortage' => 0,
        'salary_advance' => 0,
        'sanctions' => 0,
        'register_difference' => 0,
        'net_salary' => 0
    ];
    
    foreach ($managers as $manager) {
        $totals['base_salary'] += $manager['base_salary'];
        $totals['sales_bonus'] += $manager['sales_bonus'];
        $totals['evaluation_bonus'] += $manager['evaluation_bonus'];
        $totals['paid_leave'] += $manager['paid_leave'];
        $totals['years_bonus'] += $manager['years_bonus'];
        $totals['inventory_shortage'] += $manager['inventory_shortage'];
        $totals['salary_advance'] += $manager['salary_advance'];
        $totals['sanctions'] += $manager['sanctions'];
        $totals['register_difference'] += $manager['register_difference'];
        $totals['net_salary'] += $manager['net_salary'];
    }
    
} catch (PDOException $e) {
    error_log("salary-report.php - Database error: " . $e->getMessage());
    $errors[] = "Database error: " . $e->getMessage();
}

// Page title
$page_title = $_SESSION['lang'] == 'fr' ? 'Rapport de Salaires' : 'Salary Report';
$page_description = $_SESSION['lang'] == 'fr' ? 'Calcul des salaires pour les gérants' : 'Salary calculation for managers';
?>

<?php include 'layouts/head-main.php'; ?>

<head>
    <title><?php echo $page_title; ?> | <?php echo $_SESSION['lang'] == 'fr' ? 'Système de Gestion des Employés' : 'Employee Management System'; ?></title>
    <?php include 'layouts/head.php'; ?>
    
    <!-- DataTables CSS -->
    <link href="assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/libs/datatables.net-buttons-bs4/css/buttons.bootstrap4.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css" rel="stylesheet" type="text/css" />
    
    <!-- Add custom CSS for the report -->
    <style>
        .salary-table {
            font-size: 14px;
            width: 100%;
        }
        
        .salary-table th {
            font-size: 13px;
            white-space: nowrap;
            vertical-align: middle !important;
            text-align: center;
            font-weight: bold;
            background-color: #ffc107;
            color: #000;
        }
        
        .signature-cell {
            height: 60px;
            min-width: 120px;
        }
        
        .filter-form {
            margin-bottom: 1.5rem;
            padding: 1rem;
            background-color: #f8f9fa;
            border-radius: 0.25rem;
            border: 1px solid #e9ecef;
        }
        
        .table .currency {
            text-align: right;
            font-family: monospace;
        }
        
        .print-header {
            display: none;
        }
        
        /* Custom styles for export button */
        #exportExcelBtn {
            cursor: pointer;
        }
        
        @media print {
            @page {
                size: landscape;
                margin: 10mm;
            }
            
            body {
                background-color: #fff !important;
            }
            
            .no-print {
                display: none !important;
            }
            
            .print-header {
                display: block;
                text-align: center;
                margin-bottom: 20px;
                font-weight: bold;
            }
            
            .print-header h2 {
                font-size: 18px;
                text-transform: uppercase;
                margin-bottom: 5px;
            }
            
            .print-header h3 {
                font-size: 16px;
            }
            
            .page-break {
                page-break-after: always;
            }
            
            .card {
                border: none !important;
                box-shadow: none !important;
            }
            
            .card-body {
                padding: 0 !important;
            }
            
            .salary-table {
                font-size: 11px !important;
                width: 100% !important;
                border-collapse: collapse !important;
            }
            
            .salary-table th, 
            .salary-table td {
                border: 1px solid #000 !important;
                padding: 4px !important;
            }
            
            .salary-table th {
                background-color: #ffc107 !important;
                color: #000 !important;
                font-weight: bold !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            
            .signature-cell {
                height: 40px !important;
            }
        }
    </style>
    
    <?php include 'layouts/head-style.php'; ?>
</head>

<?php include 'layouts/body.php'; ?>

<!-- Begin page -->
<div id="layout-wrapper">

    <?php include 'layouts/menu.php'; ?>
    
    <?php include 'layouts/header.php'; ?>

    <!-- ============================================================== -->
    <!-- Start right Content here -->
    <!-- ============================================================== -->
    <div class="main-content">

        <div class="page-content">
            <div class="container-fluid">

                <!-- start page title -->
                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                            <h4 class="mb-sm-0 font-size-18">
                                <?php echo $page_title; ?>
                                <span class="badge bg-info"><?php echo $month_names[$selectedMonth] . ' ' . $selectedYear; ?></span>
                            </h4>

                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php"><?php echo $_SESSION['lang'] == 'fr' ? 'Tableau de Bord' : 'Dashboard'; ?></a></li>
                                    <li class="breadcrumb-item active"><?php echo $page_title; ?></li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <?php include 'layouts/notification.php'; ?>
                
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo $error; ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Month selection form -->
                <div class="row no-print">
                    <div class="col-12">
                        <div class="filter-form">
                            <form method="GET" action="salary-report.php" class="row g-3 align-items-center">
                                <div class="col-md-4">
                                    <label for="month" class="form-label">
                                        <?php echo $_SESSION['lang'] == 'fr' ? 'Mois' : 'Month'; ?>
                                    </label>
                                    <select class="form-select" id="month" name="month">
                                        <?php foreach ($month_names as $num => $name): ?>
                                            <option value="<?php echo $num; ?>" <?php echo $selectedMonth == $num ? 'selected' : ''; ?>>
                                                <?php echo $name; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="year" class="form-label">
                                        <?php echo $_SESSION['lang'] == 'fr' ? 'Année' : 'Year'; ?>
                                    </label>
                                    <select class="form-select" id="year" name="year">
                                        <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                                            <option value="<?php echo $y; ?>" <?php echo $selectedYear == $y ? 'selected' : ''; ?>>
                                                <?php echo $y; ?>
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="col-md-4 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <?php echo $_SESSION['lang'] == 'fr' ? 'Générer le Rapport' : 'Generate Report'; ?>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Printable header (only visible when printing) -->
                <div class="print-header">
                    <h2><?php echo $_SESSION['lang'] == 'fr' 
                        ? 'SALAIRES GERANTS ET AIDES GERANTS IBA FER ' . strtoupper($month_names[$selectedMonth]) . ' ' . $selectedYear
                        : 'MANAGER AND ASSISTANT MANAGER SALARIES REPORT ' . strtoupper($month_names[$selectedMonth]) . ' ' . $selectedYear; ?></h2>
                </div>

                <!-- Results table -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-sm-flex align-items-center justify-content-between">
                                    <div>
                                        <h4 class="card-title">
                                            <?php 
                                            $report_title = $_SESSION['lang'] == 'fr' 
                                                ? 'SALAIRES GERANTS ET AIDES GERANTS IBA FER ' . strtoupper($month_names[$selectedMonth]) . ' ' . $selectedYear
                                                : 'MANAGER AND ASSISTANT MANAGER SALARIES REPORT ' . strtoupper($month_names[$selectedMonth]) . ' ' . $selectedYear;
                                            echo $report_title; 
                                            ?>
                                        </h4>
                                        <p class="card-title-desc">
                                            <?php echo $_SESSION['lang'] == 'fr' ? 'Période: ' : 'Period: '; ?> 
                                            <strong><?php echo $month_names[$selectedMonth] . ' ' . $selectedYear; ?></strong>
                                        </p>
                                    </div>
                                    <div class="no-print">
                                        <button type="button" class="btn btn-info" id="printReportBtn">
                                            <i class="bx bx-printer me-1"></i> <?php echo $_SESSION['lang'] == 'fr' ? 'Imprimer' : 'Print'; ?>
                                        </button>
                                        <button type="button" class="btn btn-danger ms-2" id="exportPdfBtn">
                                            <i class="bx bx-file-pdf me-1"></i> <?php echo $_SESSION['lang'] == 'fr' ? 'Exporter PDF' : 'Export PDF'; ?>
                                        </button>
                                        <button type="button" class="btn btn-success ms-2" id="exportExcelBtn">
                                            <i class="bx bx-file me-1"></i> <?php echo $_SESSION['lang'] == 'fr' ? 'Exporter Excel' : 'Export Excel'; ?>
                                        </button>
                                        <button type="button" class="btn btn-secondary ms-2" id="helpBtn" data-bs-toggle="modal" data-bs-target="#helpModal">
                                            <i class="bx bx-question-mark me-1"></i> <?php echo $_SESSION['lang'] == 'fr' ? 'Aide' : 'Help'; ?>
                                        </button>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table id="salary-table" class="table table-bordered table-striped salary-table">
                                        <thead class="table-warning">
                                            <tr>
                                                <th><?php echo $_SESSION['lang'] == 'fr' ? 'MAGASIN' : 'STORE'; ?></th>
                                                <th><?php echo $_SESSION['lang'] == 'fr' ? 'GERANTS' : 'MANAGERS'; ?></th>
                                                <th><?php echo $_SESSION['lang'] == 'fr' ? 'SAL BASES' : 'BASE SALARY'; ?></th>
                                                <th><?php echo $_SESSION['lang'] == 'fr' ? 'PRIME/ VENTE' : 'SALES BONUS'; ?></th>
                                                <th><?php echo $_SESSION['lang'] == 'fr' ? 'PRIME/NOTE' : 'EVAL BONUS'; ?></th>
                                                <th><?php echo $_SESSION['lang'] == 'fr' ? 'CONGE PAYER' : 'PAID LEAVE'; ?></th>
                                                <th><?php echo $_SESSION['lang'] == 'fr' ? 'PRIME 05/10 ANS' : '5/10 YR BONUS'; ?></th>
                                                <th><?php echo $_SESSION['lang'] == 'fr' ? 'MANQUANT INVENTAIRE' : 'INV SHORTAGE'; ?></th>
                                                <th><?php echo $_SESSION['lang'] == 'fr' ? 'AVANCE SUR SALAIRE' : 'SALARY ADVANCE'; ?></th>
                                                <th><?php echo $_SESSION['lang'] == 'fr' ? 'SANCTION' : 'SANCTION'; ?></th>
                                                <th><?php echo $_SESSION['lang'] == 'fr' ? 'ECART CAISSE' : 'REGISTER DIFF'; ?></th>
                                                <th><?php echo $_SESSION['lang'] == 'fr' ? 'NET A PAYER' : 'NET SALARY'; ?></th>
                                                <th><?php echo $_SESSION['lang'] == 'fr' ? 'SIGNATURE' : 'SIGNATURE'; ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($managers)): ?>
                                                <tr>
                                                    <td colspan="13" class="text-center">
                                                        <?php echo $_SESSION['lang'] == 'fr' ? 'Aucun gérant trouvé pour cette période.' : 'No managers found for this period.'; ?>
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($managers as $manager): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($manager['shop_name']); ?></td>
                                                        <td><?php echo htmlspecialchars($manager['manager_name']); ?></td>
                                                        <td class="currency"><?php echo number_format($manager['base_salary'], 2); ?></td>
                                                        <td class="currency"><?php echo number_format($manager['sales_bonus'], 2); ?></td>
                                                        <td class="currency"><?php echo number_format($manager['evaluation_bonus'], 2); ?></td>
                                                        <td class="currency"><?php echo number_format($manager['paid_leave'], 2); ?></td>
                                                        <td class="currency"><?php echo number_format($manager['years_bonus'], 2); ?></td>
                                                        <td class="currency"><?php echo number_format($manager['inventory_shortage'], 2); ?></td>
                                                        <td class="currency"><?php echo number_format($manager['salary_advance'], 2); ?></td>
                                                        <td class="currency"><?php echo number_format($manager['sanctions'], 2); ?></td>
                                                        <td class="currency"><?php echo number_format($manager['register_difference'], 2); ?></td>
                                                        <td class="currency"><strong><?php echo number_format($manager['net_salary'], 2); ?></strong></td>
                                                        <td class="signature-cell"></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <!-- Removed totals row from HTML display -->
                                            <?php endif; ?>
                                        </tbody>
                                        <!-- Store totals in hidden element for DataTables export -->
                                        <tfoot class="d-none">
                                            <tr>
                                                <td colspan="2" class="text-end"><strong><?php echo $_SESSION['lang'] == 'fr' ? 'Totaux' : 'Totals'; ?></strong></td>
                                                <td class="currency"><strong><?php echo number_format($totals['base_salary'], 2); ?></strong></td>
                                                <td class="currency"><strong><?php echo number_format($totals['sales_bonus'], 2); ?></strong></td>
                                                <td class="currency"><strong><?php echo number_format($totals['evaluation_bonus'], 2); ?></strong></td>
                                                <td class="currency"><strong><?php echo number_format($totals['paid_leave'], 2); ?></strong></td>
                                                <td class="currency"><strong><?php echo number_format($totals['years_bonus'], 2); ?></strong></td>
                                                <td class="currency"><strong><?php echo number_format($totals['inventory_shortage'], 2); ?></strong></td>
                                                <td class="currency"><strong><?php echo number_format($totals['salary_advance'], 2); ?></strong></td>
                                                <td class="currency"><strong><?php echo number_format($totals['sanctions'], 2); ?></strong></td>
                                                <td class="currency"><strong><?php echo number_format($totals['register_difference'], 2); ?></strong></td>
                                                <td class="currency"><strong><?php echo number_format($totals['net_salary'], 2); ?></strong></td>
                                                <td></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php include 'layouts/footer.php'; ?>
    </div>
    <!-- end main content-->

</div>
<!-- END layout-wrapper -->

<?php include 'layouts/vendor-scripts.php'; ?>

<!-- Required datatable js -->
<script src="assets/libs/datatables.net/js/jquery.dataTables.min.js"></script>
<script src="assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js"></script>

<!-- Buttons examples -->
<script src="assets/libs/datatables.net-buttons/js/dataTables.buttons.min.js"></script>
<script src="assets/libs/datatables.net-buttons-bs4/js/buttons.bootstrap4.min.js"></script>
<script src="assets/libs/jszip/jszip.min.js"></script>
<script src="assets/libs/pdfmake/build/pdfmake.min.js"></script>
<script src="assets/libs/pdfmake/build/vfs_fonts.js"></script>
<script src="assets/libs/datatables.net-buttons/js/buttons.html5.min.js"></script>
<script src="assets/libs/datatables.net-buttons/js/buttons.print.min.js"></script>
<script src="assets/libs/datatables.net-buttons/js/buttons.colVis.min.js"></script>

<!-- Responsive examples -->
<script src="assets/libs/datatables.net-responsive/js/dataTables.responsive.min.js"></script>
<script src="assets/libs/datatables.net-responsive-bs4/js/responsive.bootstrap4.min.js"></script>

<!-- Datatable init js -->
<script>
    $(document).ready(function() {
        // Basic DataTable initialization - simplified to avoid columns mismatch errors
        var dataTable = $('#salary-table').DataTable({
            dom: 'Bfrtip',
            buttons: [
                {
                    extend: 'excel',
                    title: '<?php echo $_SESSION['lang'] == 'fr' 
                        ? 'SALAIRES GERANTS ET AIDES GERANTS IBA FER ' . strtoupper($month_names[$selectedMonth]) . ' ' . $selectedYear
                        : 'MANAGER AND ASSISTANT MANAGER SALARIES REPORT ' . strtoupper($month_names[$selectedMonth]) . ' ' . $selectedYear; ?>',
                    className: 'btn btn-sm btn-success d-none excel-export-btn',
                    footer: true,
                    exportOptions: {
                        columns: ':not(:last-child)'
                    }
                },
                {
                    extend: 'pdf',
                    title: '<?php echo $_SESSION['lang'] == 'fr' 
                        ? 'SALAIRES GERANTS ET AIDES GERANTS IBA FER ' . strtoupper($month_names[$selectedMonth]) . ' ' . $selectedYear
                        : 'MANAGER AND ASSISTANT MANAGER SALARIES REPORT ' . strtoupper($month_names[$selectedMonth]) . ' ' . $selectedYear; ?>',
                    orientation: 'landscape',
                    pageSize: 'LEGAL',
                    className: 'btn btn-sm btn-danger d-none pdf-export-btn',
                    footer: true,
                    exportOptions: {
                        columns: ':not(:last-child)'
                    },
                    customize: function(doc) {
                        // Add footer with total amount
                        var now = new Date();
                        var jsDate = now.getDate() + '/' + (now.getMonth() + 1) + '/' + now.getFullYear();
                        doc.footer = function(page, pages) {
                            return {
                                columns: [
                                    {
                                        text: '<?php echo $_SESSION["lang"] == "fr" ? "Date d\'exportation" : "Export Date"; ?>: ' + jsDate,
                                        alignment: 'left',
                                        margin: [40, 0]
                                    },
                                    { 
                                        text: '<?php echo $_SESSION["lang"] == "fr" ? "Total des salaires nets" : "Total Net Salary"; ?>: <?php echo number_format($totals["net_salary"], 2); ?>',
                                        alignment: 'right',
                                        margin: [0, 0, 40, 0]
                                    }
                                ],
                                margin: [40, 0]
                            };
                        };
                    }
                },
                {
                    extend: 'print',
                    title: '<?php echo $_SESSION['lang'] == 'fr' 
                        ? 'SALAIRES GERANTS ET AIDES GERANTS IBA FER ' . strtoupper($month_names[$selectedMonth]) . ' ' . $selectedYear
                        : 'MANAGER AND ASSISTANT MANAGER SALARIES REPORT ' . strtoupper($month_names[$selectedMonth]) . ' ' . $selectedYear; ?>',
                    className: 'btn btn-sm btn-info d-none print-export-btn',
                    footer: true,
                    exportOptions: {
                        columns: ':not(:last-child)'
                    },
                    customize: function(win) {
                        $(win.document.body).find('table').addClass('display').css('font-size', '12px');
                        $(win.document.body).find('table thead th').css({
                            'text-align': 'center',
                            'background-color': '#f8f9fa',
                            'font-weight': 'bold'
                        });
                        
                        // Style footer (totals row)
                        $(win.document.body).find('table tfoot tr').css({
                            'background-color': '#f2f2f2',
                            'font-weight': 'bold'
                        });
                        
                        // Add a summary of totals at the bottom
                        $(win.document.body).append(
                            '<div style="text-align: right; margin-top: 20px; padding-right: 20px; font-weight: bold;">' +
                            '<?php echo $_SESSION['lang'] == 'fr' ? 'Total des salaires nets' : 'Total Net Salary'; ?>: ' +
                            '<?php echo number_format($totals["net_salary"], 2); ?>' +
                            '</div>'
                        );
                    }
                }
            ],
            "lengthMenu": [[10, 25, 50, 100, -1], [10, 25, 50, 100, "<?php echo $_SESSION['lang'] == 'fr' ? 'Tous' : 'All'; ?>"]],
            "pageLength": 10,
            searching: true,
            info: true,
            ordering: true,
            autoWidth: false,
            scrollX: true,
            language: {
                <?php if ($_SESSION['lang'] == 'fr'): ?>
                search: 'Rechercher:',
                paginate: {
                    first: 'Premier',
                    previous: 'Précédent',
                    next: 'Suivant',
                    last: 'Dernier'
                },
                info: 'Affichage de _START_ à _END_ sur _TOTAL_ entrées',
                emptyTable: 'Aucune donnée disponible dans le tableau',
                infoEmpty: 'Affichage de 0 à 0 sur 0 entrées',
                infoFiltered: '(filtré à partir de _MAX_ entrées au total)',
                lengthMenu: 'Afficher _MENU_ entrées',
                loadingRecords: 'Chargement...',
                processing: 'Traitement...',
                zeroRecords: 'Aucun enregistrement correspondant trouvé'
                <?php endif; ?>
            }
        });
        
        // Handle Excel export button click
        $('#exportExcelBtn').on('click', function() {
            $('.excel-export-btn').trigger('click');
        });
        
        // Handle PDF export button click
        $('#exportPdfBtn').on('click', function() {
            $('.pdf-export-btn').trigger('click');
        });
        
        // Custom print button to print all data
        $('#printReportBtn').on('click', function() {
            // First, set DataTable to show all records
            dataTable.page.len(-1).draw();
            
            // Wait for the table to redraw with all records, then print
            setTimeout(function() {
                window.print();
                
                // After printing, restore original page length
                setTimeout(function() {
                    dataTable.page.len(10).draw();
                }, 1000);
            }, 500);
        });
    });
</script>

<!-- Help Modal -->
<div class="modal fade" id="helpModal" tabindex="-1" aria-labelledby="helpModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="helpModalLabel">
                    <?php echo $_SESSION['lang'] == 'fr' ? 'Explications des calculs de salaire' : 'Salary Calculation Explanations'; ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="accordion" id="salaryColumnsAccordion">
                    <!-- Base Salary -->
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="headingBaseSalary">
                            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseBaseSalary" aria-expanded="true" aria-controls="collapseBaseSalary">
                                <?php echo $_SESSION['lang'] == 'fr' ? 'SALAIRE DE BASE' : 'BASE SALARY'; ?>
                            </button>
                        </h2>
                        <div id="collapseBaseSalary" class="accordion-collapse collapse show" aria-labelledby="headingBaseSalary" data-bs-parent="#salaryColumnsAccordion">
                            <div class="accordion-body">
                                <?php if ($_SESSION['lang'] == 'fr'): ?>
                                    <p>Le salaire de base est défini pour chaque gérant dans leur profil employé. Ce montant est fixe et sert de base pour calculer d'autres composantes du salaire.</p>
                                    <p><strong>Source :</strong> Champ <code>base_salary</code> dans la table <code>employees</code>.</p>
                                <?php else: ?>
                                    <p>The base salary is defined for each manager in their employee profile. This amount is fixed and serves as the foundation for calculating other salary components.</p>
                                    <p><strong>Source:</strong> Field <code>base_salary</code> in the <code>employees</code> table.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Sales Bonus -->
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="headingSalesBonus">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseSalesBonus" aria-expanded="false" aria-controls="collapseSalesBonus">
                                <?php echo $_SESSION['lang'] == 'fr' ? 'PRIME/VENTE' : 'SALES BONUS'; ?>
                            </button>
                        </h2>
                        <div id="collapseSalesBonus" class="accordion-collapse collapse" aria-labelledby="headingSalesBonus" data-bs-parent="#salaryColumnsAccordion">
                            <div class="accordion-body">
                                <?php if ($_SESSION['lang'] == 'fr'): ?>
                                    <p>La prime de vente est calculée en fonction du montant des ventes mensuelles réalisées par le magasin du gérant. Le pourcentage appliqué dépend des paliers de vente définis dans la configuration des bonus.</p>
                                    <p><strong>Formule :</strong> Montant des ventes mensuelles × Pourcentage de bonus applicable</p>
                                    <p>Le pourcentage de bonus varie selon les paliers de vente, par exemple:</p>
                                    <ul>
                                        <?php 
                                        if (!empty($bonusConfig)):
                                            foreach ($bonusConfig as $tier):
                                        ?>
                                            <li>Pour les ventes ≥ <?php echo number_format($tier['min_sales'], 2); ?> $ : <?php echo $tier['bonus_percent']; ?>%</li>
                                        <?php 
                                            endforeach;
                                        endif;
                                        ?>
                                    </ul>
                                    <p><strong>Sources :</strong> 
                                        <ul>
                                            <li>Ventes mensuelles : Table <code>monthly_sales</code></li>
                                            <li>Configuration des bonus : Table <code>bonus_tiers</code></li>
                                        </ul>
                                    </p>
                                <?php else: ?>
                                    <p>The sales bonus is calculated based on the monthly sales amount achieved by the manager's store. The percentage applied depends on the sales tiers defined in the bonus configuration.</p>
                                    <p><strong>Formula:</strong> Monthly sales amount × Applicable bonus percentage</p>
                                    <p>The bonus percentage varies according to sales tiers, for example:</p>
                                    <ul>
                                        <?php 
                                        if (!empty($bonusConfig)):
                                            foreach ($bonusConfig as $tier):
                                        ?>
                                            <li>For sales ≥ $<?php echo number_format($tier['min_sales'], 2); ?>: <?php echo $tier['bonus_percent']; ?>%</li>
                                        <?php 
                                            endforeach;
                                        endif;
                                        ?>
                                    </ul>
                                    <p><strong>Sources:</strong> 
                                        <ul>
                                            <li>Monthly sales: <code>monthly_sales</code> table</li>
                                            <li>Bonus configuration: <code>bonus_tiers</code> table</li>
                                        </ul>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Evaluation Bonus -->
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="headingEvalBonus">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseEvalBonus" aria-expanded="false" aria-controls="collapseEvalBonus">
                                <?php echo $_SESSION['lang'] == 'fr' ? 'PRIME/NOTE' : 'EVALUATION BONUS'; ?>
                            </button>
                        </h2>
                        <div id="collapseEvalBonus" class="accordion-collapse collapse" aria-labelledby="headingEvalBonus" data-bs-parent="#salaryColumnsAccordion">
                            <div class="accordion-body">
                                <?php if ($_SESSION['lang'] == 'fr'): ?>
                                    <p>La prime d'évaluation est basée sur le score total obtenu par le gérant lors de son évaluation mensuelle. Les scores sont classés par plages, chaque plage correspondant à un montant de prime différent.</p>
                                    <p><strong>Processus :</strong></p>
                                    <ol>
                                        <li>Le gérant est évalué sur plusieurs critères qui donnent un score total</li>
                                        <li>Ce score total est comparé aux plages définies dans la table <code>total_ranges</code></li>
                                        <li>Le montant correspondant à la plage est attribué comme prime d'évaluation</li>
                                    </ol>
                                    <p><strong>Sources :</strong>
                                        <ul>
                                            <li>Score d'évaluation : Table <code>employee_evaluations</code></li>
                                            <li>Plages et montants : Table <code>total_ranges</code></li>
                                        </ul>
                                    </p>
                                <?php else: ?>
                                    <p>The evaluation bonus is based on the total score obtained by the manager during their monthly evaluation. Scores are classified by ranges, with each range corresponding to a different bonus amount.</p>
                                    <p><strong>Process:</strong></p>
                                    <ol>
                                        <li>The manager is evaluated on multiple criteria that result in a total score</li>
                                        <li>This total score is compared to the ranges defined in the <code>total_ranges</code> table</li>
                                        <li>The amount corresponding to the matching range is awarded as the evaluation bonus</li>
                                    </ol>
                                    <p><strong>Sources:</strong>
                                        <ul>
                                            <li>Evaluation score: <code>employee_evaluations</code> table</li>
                                            <li>Ranges and amounts: <code>total_ranges</code> table</li>
                                        </ul>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Paid Leave -->
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="headingPaidLeave">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapsePaidLeave" aria-expanded="false" aria-controls="collapsePaidLeave">
                                <?php echo $_SESSION['lang'] == 'fr' ? 'CONGÉ PAYÉ' : 'PAID LEAVE'; ?>
                            </button>
                        </h2>
                        <div id="collapsePaidLeave" class="accordion-collapse collapse" aria-labelledby="headingPaidLeave" data-bs-parent="#salaryColumnsAccordion">
                            <div class="accordion-body">
                                <?php if ($_SESSION['lang'] == 'fr'): ?>
                                    <p>Le congé payé correspond à un salaire de base supplémentaire accordé chaque année au mois d'embauche du gérant, si celui-ci a au moins 12 mois d'ancienneté dans l'entreprise.</p>
                                    <p><strong>Formule :</strong> Salaire de base (si le gérant a au moins 12 mois d'ancienneté et le mois sélectionné correspond au mois de recrutement)</p>
                                    <p><strong>Source :</strong> Calculé en fonction de la <code>recruitment_date</code> dans la table <code>employees</code>.</p>
                                <?php else: ?>
                                    <p>Paid leave corresponds to one additional base salary granted each year in the manager's hiring month, if they have at least 12 months of seniority in the company.</p>
                                    <p><strong>Formula:</strong> Base salary (if the manager has at least 12 months of seniority and the selected month matches their recruitment month)</p>
                                    <p><strong>Source:</strong> Calculated based on the <code>recruitment_date</code> in the <code>employees</code> table.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- 5/10 Years Bonus -->
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="headingYearBonus">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseYearBonus" aria-expanded="false" aria-controls="collapseYearBonus">
                                <?php echo $_SESSION['lang'] == 'fr' ? 'PRIME 05/10 ANS' : '5/10 YEAR BONUS'; ?>
                            </button>
                        </h2>
                        <div id="collapseYearBonus" class="accordion-collapse collapse" aria-labelledby="headingYearBonus" data-bs-parent="#salaryColumnsAccordion">
                            <div class="accordion-body">
                                <?php if ($_SESSION['lang'] == 'fr'): ?>
                                    <p>Cette prime est attribuée lors des anniversaires importants d'embauche du gérant :</p>
                                    <ul>
                                        <li><strong>5 ans de service :</strong> 1 salaire de base supplémentaire</li>
                                        <li><strong>10 ans de service :</strong> 2 salaires de base supplémentaires</li>
                                    </ul>
                                    <p>Cette prime n'est attribuée que si le mois et l'année actuels correspondent exactement à l'anniversaire des 5 ou 10 ans d'embauche du gérant.</p>
                                    <p><strong>Source :</strong> Calculé en fonction de la <code>recruitment_date</code> dans la table <code>employees</code>.</p>
                                <?php else: ?>
                                    <p>This bonus is awarded on the manager's significant hiring anniversaries:</p>
                                    <ul>
                                        <li><strong>5 years of service:</strong> 1 additional base salary</li>
                                        <li><strong>10 years of service:</strong> 2 additional base salaries</li>
                                    </ul>
                                    <p>This bonus is only awarded if the current month and year exactly match the manager's 5th or 10th hiring anniversary.</p>
                                    <p><strong>Source:</strong> Calculated based on the <code>recruitment_date</code> in the <code>employees</code> table.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Inventory Shortage -->
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="headingInventoryShortage">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseInventoryShortage" aria-expanded="false" aria-controls="collapseInventoryShortage">
                                <?php echo $_SESSION['lang'] == 'fr' ? 'MANQUANT INVENTAIRE' : 'INVENTORY SHORTAGE'; ?>
                            </button>
                        </h2>
                        <div id="collapseInventoryShortage" class="accordion-collapse collapse" aria-labelledby="headingInventoryShortage" data-bs-parent="#salaryColumnsAccordion">
                            <div class="accordion-body">
                                <?php if ($_SESSION['lang'] == 'fr'): ?>
                                    <p>Les manquants d'inventaire représentent la valeur des pertes ou écarts d'inventaire qui sont déduits du salaire du gérant. Ces montants sont enregistrés dans la table des dettes des gérants.</p>
                                    <p><strong>Source :</strong> Somme des champs <code>inventory_month</code> dans la table <code>manager_debts</code> pour le gérant pour le mois et l'année sélectionnés.</p>
                                <?php else: ?>
                                    <p>Inventory shortages represent the value of inventory losses or discrepancies that are deducted from the manager's salary. These amounts are recorded in the manager debts table.</p>
                                    <p><strong>Source:</strong> Sum of <code>inventory_month</code> fields in the <code>manager_debts</code> table for the manager for the selected month and year.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Salary Advance -->
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="headingSalaryAdvance">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseSalaryAdvance" aria-expanded="false" aria-controls="collapseSalaryAdvance">
                                <?php echo $_SESSION['lang'] == 'fr' ? 'AVANCE SUR SALAIRE' : 'SALARY ADVANCE'; ?>
                            </button>
                        </h2>
                        <div id="collapseSalaryAdvance" class="accordion-collapse collapse" aria-labelledby="headingSalaryAdvance" data-bs-parent="#salaryColumnsAccordion">
                            <div class="accordion-body">
                                <?php if ($_SESSION['lang'] == 'fr'): ?>
                                    <p>Les avances sur salaire sont des montants déjà versés au gérant qui sont déduits du salaire final. Ces avances sont enregistrées dans la table des dettes des gérants.</p>
                                    <p><strong>Source :</strong> Somme des champs <code>salary_advance</code> dans la table <code>manager_debts</code> pour le gérant pour le mois et l'année sélectionnés.</p>
                                <?php else: ?>
                                    <p>Salary advances are amounts already paid to the manager that are deducted from the final salary. These advances are recorded in the manager debts table.</p>
                                    <p><strong>Source:</strong> Sum of <code>salary_advance</code> fields in the <code>manager_debts</code> table for the manager for the selected month and year.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Sanctions -->
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="headingSanctions">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseSanctions" aria-expanded="false" aria-controls="collapseSanctions">
                                <?php echo $_SESSION['lang'] == 'fr' ? 'SANCTION' : 'SANCTION'; ?>
                            </button>
                        </h2>
                        <div id="collapseSanctions" class="accordion-collapse collapse" aria-labelledby="headingSanctions" data-bs-parent="#salaryColumnsAccordion">
                            <div class="accordion-body">
                                <?php if ($_SESSION['lang'] == 'fr'): ?>
                                    <p>Les sanctions représentent des montants déduits du salaire en raison de problèmes disciplinaires ou de non-respect des politiques de l'entreprise.</p>
                                    <p><strong>Source :</strong> Somme des champs <code>sanction</code> dans la table <code>manager_debts</code> pour le gérant pour le mois et l'année sélectionnés.</p>
                                <?php else: ?>
                                    <p>Sanctions represent amounts deducted from the salary due to disciplinary issues or non-compliance with company policies.</p>
                                    <p><strong>Source:</strong> Sum of <code>sanction</code> fields in the <code>manager_debts</code> table for the manager for the selected month and year.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Register Difference -->
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="headingRegisterDiff">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseRegisterDiff" aria-expanded="false" aria-controls="collapseRegisterDiff">
                                <?php echo $_SESSION['lang'] == 'fr' ? 'ECART CAISSE' : 'REGISTER DIFFERENCE'; ?>
                            </button>
                        </h2>
                        <div id="collapseRegisterDiff" class="accordion-collapse collapse" aria-labelledby="headingRegisterDiff" data-bs-parent="#salaryColumnsAccordion">
                            <div class="accordion-body">
                                <?php if ($_SESSION['lang'] == 'fr'): ?>
                                    <p>Les écarts de caisse représentent les différences entre les montants enregistrés dans le système et les montants réellement présents dans la caisse. Ces écarts sont déduits du salaire du gérant.</p>
                                    <p><strong>Source :</strong> Somme des champs <code>cash_discrepancy</code> dans la table <code>manager_debts</code> pour le gérant pour le mois et l'année sélectionnés.</p>
                                <?php else: ?>
                                    <p>Register differences represent the discrepancies between amounts recorded in the system and amounts actually present in the cash register. These differences are deducted from the manager's salary.</p>
                                    <p><strong>Source:</strong> Sum of <code>cash_discrepancy</code> fields in the <code>manager_debts</code> table for the manager for the selected month and year.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Net Salary -->
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="headingNetSalary">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseNetSalary" aria-expanded="false" aria-controls="collapseNetSalary">
                                <?php echo $_SESSION['lang'] == 'fr' ? 'NET A PAYER' : 'NET SALARY'; ?>
                            </button>
                        </h2>
                        <div id="collapseNetSalary" class="accordion-collapse collapse" aria-labelledby="headingNetSalary" data-bs-parent="#salaryColumnsAccordion">
                            <div class="accordion-body">
                                <?php if ($_SESSION['lang'] == 'fr'): ?>
                                    <p>Le montant net à payer représente le salaire final du gérant après avoir additionné toutes les primes et soustrait toutes les déductions.</p>
                                    <p><strong>Formule :</strong></p>
                                    <pre>Salaire de base 
+ Prime de vente 
+ Prime d'évaluation 
+ Congé payé 
+ Prime d'années de service 
- Manquant d'inventaire 
- Avance sur salaire 
- Sanctions 
- Écart de caisse
= Net à payer</pre>
                                <?php else: ?>
                                    <p>The net salary represents the manager's final pay after adding all bonuses and subtracting all deductions.</p>
                                    <p><strong>Formula:</strong></p>
                                    <pre>Base salary 
+ Sales bonus 
+ Evaluation bonus 
+ Paid leave 
+ Years of service bonus 
- Inventory shortage 
- Salary advance 
- Sanctions 
- Register difference
= Net salary</pre>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <?php echo $_SESSION['lang'] == 'fr' ? 'Fermer' : 'Close'; ?>
                </button>
            </div>
        </div>
    </div>
</div>

</body>
</html>
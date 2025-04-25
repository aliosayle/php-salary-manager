<?php
// Set error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
error_log("assistant-manager-salary-report.php - Page loaded");

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
$assistantManagers = [];
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
    error_log("assistant-manager-salary-report.php - Error fetching shops: " . $e->getMessage());
    $errors[] = "Error fetching shops: " . $e->getMessage();
}

// Get salary data for assistant managers
try {
    // Query to get assistant manager data
    $query = "
        SELECT 
            s.id AS shop_id, 
            s.name AS shop_name, 
            e.id AS assistant_manager_id,
            e.full_name AS assistant_manager_name,
            e.base_salary AS base_salary,
            
            -- Get evaluation data for the assistant manager
            (SELECT ev.grade 
             FROM assistant_managers_evaluations ev 
             WHERE ev.assistant_manager_id = e.id
             AND MONTH(ev.evaluation_month) = ? 
             AND YEAR(ev.evaluation_month) = ?
             LIMIT 1) AS grade,
             
            -- Get evaluation bonus amount
            (SELECT ev.prime_amount 
             FROM assistant_managers_evaluations ev 
             WHERE ev.assistant_manager_id = e.id
             AND MONTH(ev.evaluation_month) = ? 
             AND YEAR(ev.evaluation_month) = ?
             LIMIT 1) AS evaluation_bonus,
             
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
             
            -- Check if assistant manager is entitled to paid leave
            (SELECT CASE WHEN TIMESTAMPDIFF(MONTH, e.recruitment_date, CURDATE()) >= 12 THEN true ELSE false END) AS has_paid_leave,
            
            -- Check if assistant manager is eligible for yearly/5-year/10-year bonus
            (SELECT
                CASE
                    -- Check if this is the employee's 10-year anniversary (exact month and year)
                    WHEN TIMESTAMPDIFF(YEAR, e.recruitment_date, CONCAT(?,'/',?,'/01')) = 10 
                         AND MONTH(e.recruitment_date) = ? THEN 'ten_year'
                    
                    -- Check if this is the employee's 5-year anniversary (exact month and year)
                    WHEN TIMESTAMPDIFF(YEAR, e.recruitment_date, CONCAT(?,'/',?,'/01')) = 5 
                         AND MONTH(e.recruitment_date) = ? THEN 'five_year'
                    
                    -- Not an anniversary month
                    ELSE NULL
                END
            ) AS years_bonus,
            
            -- Get the employment years for the assistant manager
            (SELECT TIMESTAMPDIFF(YEAR, e.recruitment_date, CONCAT(?,'/',?,'/01'))) AS employment_years,
            
            -- Get the recruitment month of the assistant manager
            (SELECT MONTH(e.recruitment_date)) AS recruitment_month
            
        FROM 
            shops s
            LEFT JOIN employee_shops es ON s.id = es.shop_id
            LEFT JOIN employees e ON es.employee_id = e.id AND e.post_id = 'cf0ca194-1abc-11f0-99a1-cc28aa53b74d' -- Assistant Manager post ID
        WHERE
            e.id IS NOT NULL
        ORDER BY 
            s.name ASC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([
        $selectedMonth, $selectedYear,   // Grade
        $selectedMonth, $selectedYear,   // Evaluation bonus
        $selectedMonth, $selectedYear,   // Salary advances
        $selectedMonth, $selectedYear,   // Sanctions
        $selectedYear, $selectedMonth, $selectedMonth,   // 10-year anniversary check with month
        $selectedYear, $selectedMonth, $selectedMonth,   // 5-year anniversary check with month
        $selectedYear, $selectedMonth    // Employment years calculation
    ]);
    
    $assistantManagers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process each assistant manager to calculate salary components
    foreach ($assistantManagers as &$assistantManager) {
        // 1. Base Salary
        $baseSalary = $assistantManager['base_salary'] ?? 0;
        
        // 2. Evaluation Bonus (already fetched from the assistant_managers_evaluations table)
        $evaluationBonus = $assistantManager['evaluation_bonus'] ?? 0;
        
        // 3. Paid Leave
        $paidLeaveAmount = 0;
        if ($assistantManager['has_paid_leave']) {
            // Assuming paid leave is a percentage of base salary
            $paidLeaveAmount = $baseSalary * 0.08; // Example: 8% of base salary
        }
        
        // 4. Years of Service Bonus (5-year/10-year)
        $yearsBonus = 0;
        $employmentYears = $assistantManager['employment_years'] ?? 0;
        
        if ($employmentYears >= 1) {
            // Default: No yearly bonus for assistant managers, only 5/10 year milestones
            if ($assistantManager['years_bonus'] === 'ten_year') {
                // 10th year: They get two times their base salary
                $yearsBonus = $baseSalary * 2;
            } else if ($assistantManager['years_bonus'] === 'five_year') {
                // 5th year: They get one times their base salary
                $yearsBonus = $baseSalary;
            }
        }
        
        // 5. Deductions
        $salaryAdvance = $assistantManager['salary_advance'] ?? 0;
        $sanctions = $assistantManager['sanctions'] ?? 0;
        
        // 6. Calculate Total Salary
        $totalSalary = $baseSalary + $evaluationBonus + $paidLeaveAmount + $yearsBonus - $salaryAdvance - $sanctions;
        
        // Store all calculated values
        $assistantManager['base_salary'] = $baseSalary;
        $assistantManager['evaluation_bonus'] = $evaluationBonus;
        $assistantManager['paid_leave'] = $paidLeaveAmount;
        $assistantManager['years_bonus'] = $yearsBonus;
        $assistantManager['salary_advance'] = $salaryAdvance;
        $assistantManager['sanctions'] = $sanctions;
        $assistantManager['total_salary'] = $totalSalary;
    }
    
} catch (PDOException $e) {
    error_log("assistant-manager-salary-report.php - Database error: " . $e->getMessage());
    $errors[] = "Database error: " . $e->getMessage();
}

// Page title
$page_title = $_SESSION['lang'] == 'fr' ? 'Rapport de Salaires des Aides Gérants' : 'Assistant Manager Salary Report';
$page_description = $_SESSION['lang'] == 'fr' ? 'Calcul des salaires pour les aides gérants' : 'Salary calculation for assistant managers';
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
        
        /* Badge styles for grades */
        .grade-badge {
            font-size: 0.85rem;
            font-weight: bold;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
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
                            <form method="GET" action="assistant-manager-salary-report.php" class="row g-3 align-items-center">
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
                        ? 'SALAIRES AIDES GERANTS IBA FER ' . strtoupper($month_names[$selectedMonth]) . ' ' . $selectedYear
                        : 'ASSISTANT MANAGER SALARIES REPORT ' . strtoupper($month_names[$selectedMonth]) . ' ' . $selectedYear; ?></h2>
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
                                                ? 'SALAIRES AIDES GERANTS IBA FER ' . strtoupper($month_names[$selectedMonth]) . ' ' . $selectedYear
                                                : 'ASSISTANT MANAGER SALARIES REPORT ' . strtoupper($month_names[$selectedMonth]) . ' ' . $selectedYear;
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
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table id="salary-table" class="table table-bordered table-striped salary-table">
                                        <thead class="table-warning">
                                            <tr>
                                                <th><?php echo $_SESSION['lang'] == 'fr' ? 'MAGASIN' : 'STORE'; ?></th>
                                                <th><?php echo $_SESSION['lang'] == 'fr' ? 'AIDES GERANTS' : 'ASSISTANT MANAGERS'; ?></th>
                                                <th><?php echo $_SESSION['lang'] == 'fr' ? 'SAL BASE$' : 'BASE SALARY'; ?></th>
                                                <th><?php echo $_SESSION['lang'] == 'fr' ? 'PRIME/NOTE' : 'EVAL BONUS'; ?></th>
                                                <th><?php echo $_SESSION['lang'] == 'fr' ? 'CONGE PAYER' : 'PAID LEAVE'; ?></th>
                                                <th><?php echo $_SESSION['lang'] == 'fr' ? 'PRIME 05/10 ANS' : '5/10 YR BONUS'; ?></th>
                                                <th><?php echo $_SESSION['lang'] == 'fr' ? 'AVANCE SUR SALAIRE' : 'SALARY ADVANCE'; ?></th>
                                                <th><?php echo $_SESSION['lang'] == 'fr' ? 'SANCTION' : 'SANCTION'; ?></th>
                                                <th><?php echo $_SESSION['lang'] == 'fr' ? 'TOTAL' : 'TOTAL'; ?></th>
                                                <th><?php echo $_SESSION['lang'] == 'fr' ? 'SIGNATURE' : 'SIGNATURE'; ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($assistantManagers)): ?>
                                                <tr>
                                                    <td colspan="10" class="text-center">
                                                        <?php echo $_SESSION['lang'] == 'fr' ? 'Aucun aide gérant trouvé pour cette période.' : 'No assistant managers found for this period.'; ?>
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($assistantManagers as $assistantManager): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($assistantManager['shop_name']); ?></td>
                                                        <td><?php echo htmlspecialchars($assistantManager['assistant_manager_name']); ?></td>
                                                        <td class="currency"><?php echo number_format($assistantManager['base_salary'], 2); ?></td>
                                                        <td class="currency"><?php echo number_format($assistantManager['evaluation_bonus'], 2); ?></td>
                                                        <td class="currency"><?php echo number_format($assistantManager['paid_leave'], 2); ?></td>
                                                        <td class="currency"><?php echo number_format($assistantManager['years_bonus'], 2); ?></td>
                                                        <td class="currency"><?php echo number_format($assistantManager['salary_advance'], 2); ?></td>
                                                        <td class="currency"><?php echo number_format($assistantManager['sanctions'], 2); ?></td>
                                                        <td class="currency"><strong><?php echo number_format($assistantManager['total_salary'], 2); ?></strong></td>
                                                        <td class="signature-cell"></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
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
        // Initialize DataTable with export options
        var dataTable = $('#salary-table').DataTable({
            dom: 'Bfrtip',
            buttons: [
                {
                    extend: 'excel',
                    title: '<?php echo $_SESSION['lang'] == 'fr' 
                        ? 'SALAIRES AIDES GERANTS IBA FER ' . strtoupper($month_names[$selectedMonth]) . ' ' . $selectedYear
                        : 'ASSISTANT MANAGER SALARIES REPORT ' . strtoupper($month_names[$selectedMonth]) . ' ' . $selectedYear; ?>',
                    className: 'btn btn-sm btn-success d-none excel-export-btn',
                    exportOptions: {
                        columns: [0, 1, 2, 3, 4, 5, 6, 7, 8]
                    }
                },
                {
                    extend: 'pdf',
                    title: '<?php echo $_SESSION['lang'] == 'fr' 
                        ? 'SALAIRES AIDES GERANTS IBA FER ' . strtoupper($month_names[$selectedMonth]) . ' ' . $selectedYear
                        : 'ASSISTANT MANAGER SALARIES REPORT ' . strtoupper($month_names[$selectedMonth]) . ' ' . $selectedYear; ?>',
                    orientation: 'landscape',
                    pageSize: 'LEGAL',
                    className: 'btn btn-sm btn-danger d-none pdf-export-btn',
                    exportOptions: {
                        columns: [0, 1, 2, 3, 4, 5, 6, 7, 8]
                    }
                },
                {
                    extend: 'print',
                    title: '<?php echo $_SESSION['lang'] == 'fr' 
                        ? 'SALAIRES AIDES GERANTS IBA FER ' . strtoupper($month_names[$selectedMonth]) . ' ' . $selectedYear
                        : 'ASSISTANT MANAGER SALARIES REPORT ' . strtoupper($month_names[$selectedMonth]) . ' ' . $selectedYear; ?>',
                    className: 'btn btn-sm btn-info d-none print-export-btn',
                    exportOptions: {
                        columns: [0, 1, 2, 3, 4, 5, 6, 7, 8]
                    },
                    customize: function(win) {
                        $(win.document.body).find('table').addClass('display').css('font-size', '12px');
                        $(win.document.body).find('table thead th').css('text-align', 'center');
                        $(win.document.body).find('table thead th').css('background-color', '#f8f9fa');
                        $(win.document.body).find('table thead th').css('font-weight', 'bold');
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

</body>
</html>
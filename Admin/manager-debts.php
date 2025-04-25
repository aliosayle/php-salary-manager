<?php
// Start output buffering
ob_start();

// Start a PHP session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: auth-login.php");
    exit;
}

// Include required files
include "layouts/config.php";
include "layouts/helpers.php";
include "layouts/session.php";

// Include translations
include_once "layouts/translations.php";

// Check if user has permission to manage manager debts
if (!hasPermission('manage_manager_debts')) {
    $_SESSION['error_message'] = __('no_permission_manage_manager_debts');
    header('Location: index.php');
    exit();
}

// Define fallback translation function if not already defined
if (!function_exists('__')) {
    function __($key) {
        return $key;
    }
}

// Check if the user has permission to access this page
requirePermission('manage_employees');

// Get current month/year for default filter values
$currentMonth = date('m');
$currentYear = date('Y');

// Get open months from the months table
$openMonths = [];
try {
    $monthsStmt = $pdo->query("SELECT * FROM months WHERE is_open = 1 ORDER BY year DESC, month DESC");
    $openMonths = $monthsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Default to most recent open month if available
    if (!empty($openMonths)) {
        $latestMonth = $openMonths[0];
        $defaultMonth = $latestMonth['month'];
        $defaultYear = $latestMonth['year'];
    } else {
        $defaultMonth = $currentMonth;
        $defaultYear = $currentYear;
    }
} catch (PDOException $e) {
    $_SESSION["error_message"] = __('error_fetching_open_months') . ": " . $e->getMessage();
    $defaultMonth = $currentMonth;
    $defaultYear = $currentYear;
}

// Use the values from URL parameters if available, otherwise use defaults
$selectedMonth = isset($_GET['month']) ? intval($_GET['month']) : $defaultMonth;
$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : $defaultYear;
$selectedShop = isset($_GET['shop_id']) ? $_GET['shop_id'] : '';
$selectedEmployee = isset($_GET['employee_id']) ? $_GET['employee_id'] : '';

// Set query string for default filter redirect if no filters are applied
if (!isset($_GET['month']) && !isset($_GET['year']) && !empty($openMonths)) {
    $defaultQueryString = "month=$defaultMonth&year=$defaultYear";
    header("location: manager-debts.php?$defaultQueryString");
    exit;
}

// Get all shops for dropdown and reference
$shops = [];
try {
    $shopsStmt = $pdo->query("SELECT id, name FROM shops ORDER BY name");
    $shops = $shopsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_SESSION["error_message"] = __('error_fetching_shops') . ": " . $e->getMessage();
}

// Get all employees for dropdown and initial view
$allEmployees = [];
try {
    $employeesStmt = $pdo->query("
        SELECT 
            e.id, 
            e.full_name,
            p.title as position
        FROM employees e
        JOIN posts p ON e.post_id = p.id
        WHERE e.end_of_service_date IS NULL OR e.end_of_service_date > CURDATE()
        ORDER BY e.full_name
    ");
    $allEmployees = $employeesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_SESSION["error_message"] = __('error_fetching_employees') . ": " . $e->getMessage();
}

// Helper function to check if a month/year combination is open
function checkMonthOpen($openMonths, $month, $year) {
    foreach ($openMonths as $openMonth) {
        if ($openMonth['month'] == $month && $openMonth['year'] == $year) {
            return true;
        }
    }
    return false;
}

// Helper function to get previous month's remaining balance for an employee in a shop
function getPreviousMonthBalance($pdo, $employeeId, $shopId, $month, $year) {
    try {
        // Convert month/year to date for comparison
        $currentDate = date('Y-m-d', strtotime("$year-$month-01"));
        
        // Get the most recent debt record for this employee/shop that is before the current month
        $stmt = $pdo->prepare("
            SELECT remaining_balance 
            FROM manager_debts 
            WHERE employee_id = ? 
            AND shop_id = ? 
            AND evaluation_month < ?
            ORDER BY evaluation_month DESC
            LIMIT 1
        ");
        $stmt->execute([$employeeId, $shopId, $currentDate]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Return previous month's remaining balance or 0 if no previous records
        return $result ? floatval($result['remaining_balance']) : 0;
    } catch (PDOException $e) {
        error_log(__('error_fetching_previous_month_balance') . ": " . $e->getMessage());
        return 0;
    }
}

// Add this function to update future debt records when a debt's remaining balance changes
function updateFutureDebts($pdo, $employeeId, $shopId, $evaluationMonth, $remainingBalance) {
    try {
        // Get all future debt records for this employee/shop after the current month
        $stmt = $pdo->prepare("
            SELECT id, evaluation_month, previous_month_balance, 
                   inventory_month, salary_advance, sanction, cash_discrepancy, 
                   tranche
            FROM manager_debts 
            WHERE employee_id = ? 
            AND shop_id = ?
            AND evaluation_month > ?
            ORDER BY evaluation_month ASC
        ");
        $stmt->execute([$employeeId, $shopId, $evaluationMonth]);
        $futureDebts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($futureDebts)) {
            return; // No future records to update
        }
        
        // For each future debt, update previous_month_balance and recalculate totals
        $previousBalance = $remainingBalance;
        
        foreach ($futureDebts as $debt) {
            // Set the previous month balance to the remaining balance of the previous month
            $previousMonthBalance = $previousBalance;
            
            // Recalculate the total and remaining balance
            $total = $previousMonthBalance + floatval($debt['inventory_month']) + 
                    floatval($debt['salary_advance']) + floatval($debt['sanction']) + 
                    floatval($debt['cash_discrepancy']);
            
            $remaining = $total - floatval($debt['tranche']);
            
            // Update the debt record
            $updateStmt = $pdo->prepare("
                UPDATE manager_debts 
                SET previous_month_balance = ?, total = ?, remaining_balance = ?
                WHERE id = ?
            ");
            $updateStmt->execute([$previousMonthBalance, $total, $remaining, $debt['id']]);
            
            // Set the remaining balance as the previous balance for the next iteration
            $previousBalance = $remaining;
        }
        
        return true;
    } catch (PDOException $e) {
        error_log(__('error_updating_future_debts') . ": " . $e->getMessage());
        return false;
    }
}

// Function to automatically generate debt records for a new month
function generateDebtRecordsForNewMonth($pdo, $month, $year) {
    try {
        // Format the target month date
        $monthDate = date('Y-m-d', strtotime("$year-$month-01"));
        
        // Get the previous month's date
        $prevMonthDate = date('Y-m-d', strtotime("$monthDate -1 month"));
        $prevMonth = date('m', strtotime($prevMonthDate));
        $prevYear = date('Y', strtotime($prevMonthDate));
        
        // Log the generation process
        error_log(__('generating_debt_records_for') . " $month/$year " . __('based_on') . " $prevMonth/$prevYear");
        
        // Query to get all manager/assistant manager employees and their shops
        $employeesQuery = "
            SELECT DISTINCT e.id as employee_id, s.id as shop_id
            FROM employees e
            JOIN posts p ON e.post_id = p.id
            JOIN employee_shops es ON e.id = es.employee_id
            JOIN shops s ON es.shop_id = s.id
            WHERE (p.title LIKE '%Manager%' OR p.title LIKE '%Gérant%')
            AND (e.end_of_service_date IS NULL OR e.end_of_service_date > CURDATE())
        ";
        
        $employeesStmt = $pdo->query($employeesQuery);
        $employees = $employeesStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Counter for records created
        $recordsCreated = 0;
        
        // For each employee-shop combination
        foreach ($employees as $employee) {
            $employeeId = $employee['employee_id'];
            $shopId = $employee['shop_id'];
            
            // Check if a debt record already exists for this employee/shop/month
            $checkStmt = $pdo->prepare("
                SELECT id FROM manager_debts 
                WHERE employee_id = ? AND shop_id = ? AND evaluation_month = ?
            ");
            $checkStmt->execute([$employeeId, $shopId, $monthDate]);
            
            // Skip if a record already exists
            if ($checkStmt->rowCount() > 0) {
                continue;
            }
            
            // Get the previous month's remaining balance
            $prevBalanceStmt = $pdo->prepare("
                SELECT remaining_balance 
                FROM manager_debts 
                WHERE employee_id = ? 
                AND shop_id = ? 
                AND evaluation_month < ?
                ORDER BY evaluation_month DESC
                LIMIT 1
            ");
            $prevBalanceStmt->execute([$employeeId, $shopId, $monthDate]);
            $prevBalance = $prevBalanceStmt->fetch(PDO::FETCH_ASSOC);
            
            // Default to 0 if no previous record exists
            $previousMonthBalance = $prevBalance ? floatval($prevBalance['remaining_balance']) : 0;
            
            // Insert new debt record with previous balance
            $insertStmt = $pdo->prepare("
                INSERT INTO manager_debts 
                (employee_id, shop_id, evaluation_month, previous_month_balance, 
                inventory_month, salary_advance, sanction, cash_discrepancy, 
                total, tranche, remaining_balance) 
                VALUES (?, ?, ?, ?, 0, 0, 0, 0, ?, 0, ?)
            ");
            
            // Total and remaining are equal to previous_month_balance since other values are 0
            $insertStmt->execute([
                $employeeId, $shopId, $monthDate, $previousMonthBalance, 
                $previousMonthBalance, $previousMonthBalance
            ]);
            
            $recordsCreated++;
        }
        
        return ['success' => true, 'records_created' => $recordsCreated];
    } catch (PDOException $e) {
        error_log(__('error_generating_debt_records') . ": " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Process add/edit debt form submissions
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["action"])) {
    $action = $_POST["action"];
    $month = $_POST["evaluation_month"];
    $year = $_POST["evaluation_year"];
    
    // Get either from the hidden inputs or from the dropdown selects
    // Check the hidden fields first, then fall back to the dropdown selects
    $shopId = isset($_POST["selected_shop_id"]) && !empty($_POST["selected_shop_id"]) ? 
              $_POST["selected_shop_id"] : 
              (isset($_POST["shop_id"]) ? $_POST["shop_id"] : '');
              
    $employeeId = isset($_POST["selected_employee_id"]) && !empty($_POST["selected_employee_id"]) ? 
                 $_POST["selected_employee_id"] : 
                 (isset($_POST["employee_id"]) ? $_POST["employee_id"] : '');
    
    $previousBalance = floatval($_POST["previous_month_balance"]);
    $inventoryMonth = floatval($_POST["inventory_month"]);
    $salaryAdvance = floatval($_POST["salary_advance"]);
    $sanction = floatval($_POST["sanction"]);
    $cashDiscrepancy = floatval($_POST["cash_discrepancy"]);
    $tranche = floatval($_POST["tranche"]);
    
    // Calculate totals
    $total = $previousBalance + $inventoryMonth + $salaryAdvance + $sanction + $cashDiscrepancy;
    $remainingBalance = $total - $tranche;
    
    // Format the date for database insertion
    $evaluationMonth = date('Y-m-d', strtotime($year . '-' . $month . '-01'));
    
    // Get previous month for reference
    $prevMonthDate = date('Y-m-d', strtotime($evaluationMonth . ' -1 month'));
    $prevMonth = date('m', strtotime($prevMonthDate));
    $prevYear = date('Y', strtotime($prevMonthDate));

    try {
        // Verify the shop exists
        $shopCheckStmt = $pdo->prepare("SELECT id FROM shops WHERE id = ?");
        $shopCheckStmt->execute([$shopId]);
        if ($shopCheckStmt->rowCount() == 0) {
            throw new PDOException(__('shop_not_found'));
        }
        
        // Verify the employee exists
        $employeeCheckStmt = $pdo->prepare("SELECT id FROM employees WHERE id = ?");
        $employeeCheckStmt->execute([$employeeId]);
        if ($employeeCheckStmt->rowCount() == 0) {
            throw new PDOException(__('employee_not_found'));
        }
        
        if ($action === "add_debt") {
            // Check if a debt record already exists for this employee/shop/month
            $checkStmt = $pdo->prepare("
                SELECT id FROM manager_debts 
                WHERE employee_id = ? AND shop_id = ? AND evaluation_month = ?
            ");
            $checkStmt->execute([$employeeId, $shopId, $evaluationMonth]);
            
            if ($checkStmt->rowCount() > 0) {
                throw new PDOException(__('debt_record_already_exists'));
            }
            
            // Automatically get previous month's balance
            $calculatedPreviousBalance = getPreviousMonthBalance($pdo, $employeeId, $shopId, $month, $year);
            
            // Use the calculated previous balance if not manually set or if user chose to use it
            if ($previousBalance == 0 || isset($_POST['use_calculated_balance'])) {
                $previousBalance = $calculatedPreviousBalance;
            }
            
            // Recalculate total with updated previous balance
            $total = $previousBalance + $inventoryMonth + $salaryAdvance + $sanction + $cashDiscrepancy;
            $remainingBalance = $total - $tranche;
            
            // Insert new debt record
            $stmt = $pdo->prepare("
                INSERT INTO manager_debts 
                (employee_id, shop_id, evaluation_month, previous_month_balance, inventory_month, 
                salary_advance, sanction, cash_discrepancy, total, tranche, remaining_balance) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $employeeId, $shopId, $evaluationMonth, 
                $previousBalance, $inventoryMonth, $salaryAdvance, $sanction, $cashDiscrepancy,
                $total, $tranche, $remainingBalance
            ]);
            
            $_SESSION["success_message"] = __("debt_record_created");
        } 
        elseif ($action === "edit_debt") {
            $debtId = $_POST["debt_id"];
            
            // Get current debt values before update to check if remaining balance changes
            $currentDebtStmt = $pdo->prepare("
                SELECT remaining_balance, evaluation_month FROM manager_debts WHERE id = ?
            ");
            $currentDebtStmt->execute([$debtId]);
            $currentDebt = $currentDebtStmt->fetch(PDO::FETCH_ASSOC);
            $oldRemainingBalance = $currentDebt ? floatval($currentDebt['remaining_balance']) : 0;
            $debtMonth = $currentDebt ? $currentDebt['evaluation_month'] : $evaluationMonth;
            
            // Get calculated previous balance for comparison
            $calculatedPreviousBalance = getPreviousMonthBalance($pdo, $employeeId, $shopId, $month, $year);
            
            // If user chose to use calculated balance
            if (isset($_POST['use_calculated_balance'])) {
                $previousBalance = $calculatedPreviousBalance;
                
                // Recalculate total with updated previous balance
                $total = $previousBalance + $inventoryMonth + $salaryAdvance + $sanction + $cashDiscrepancy;
                $remainingBalance = $total - $tranche;
            }
            
            // Update existing debt record
            $stmt = $pdo->prepare("
                UPDATE manager_debts 
                SET previous_month_balance = ?, inventory_month = ?, salary_advance = ?,
                    sanction = ?, cash_discrepancy = ?, total = ?, tranche = ?, remaining_balance = ?
                WHERE id = ?
            ");
            
            $stmt->execute([
                $previousBalance, $inventoryMonth, $salaryAdvance, $sanction, $cashDiscrepancy,
                $total, $tranche, $remainingBalance, $debtId
            ]);
            
            // If remaining balance has changed, update all future months
            if ($oldRemainingBalance != $remainingBalance) {
                updateFutureDebts($pdo, $employeeId, $shopId, $debtMonth, $remainingBalance);
            }
            
            $_SESSION["success_message"] = __("debt_record_updated");
        }
        
        // Redirect to refresh the page
        header("location: manager-debts.php?month=$month&year=$year" . 
               ($selectedShop ? "&shop_id=$selectedShop" : "") . 
               ($selectedEmployee ? "&employee_id=$selectedEmployee" : ""));
        exit;
        
    } catch (PDOException $e) {
        $_SESSION["error_message"] = __('error_processing_debt_record') . ": " . $e->getMessage();
    }
}

// Get debts based on selected month and year
try {
    // Create date string for evaluation_month comparison
    $targetDate = date('Y-m-d', strtotime("$selectedYear-$selectedMonth-01"));
    
    $stmt = $pdo->prepare("
        SELECT 
            e.id as employee_id, 
            e.full_name, 
            e.post_id, 
            s.id as shop_id, 
            s.name as shop_name,
            md.id as debt_id, 
            COALESCE(md.previous_month_balance, 0) as previous_month_balance, 
            COALESCE(md.inventory_month, 0) as inventory_month,
            COALESCE(md.salary_advance, 0) as salary_advance, 
            COALESCE(md.sanction, 0) as sanction, 
            COALESCE(md.cash_discrepancy, 0) as cash_discrepancy,
            COALESCE(md.total, 0) as total, 
            COALESCE(md.tranche, 0) as tranche, 
            COALESCE(md.remaining_balance, 0) as remaining,
            COALESCE((
                SELECT COALESCE(prev_md.remaining_balance, 0)
                FROM manager_debts prev_md
                WHERE prev_md.employee_id = e.id
                AND prev_md.shop_id = s.id
                AND prev_md.evaluation_month < :target_date
                ORDER BY prev_md.evaluation_month DESC
                LIMIT 1
            ), 0) as calculated_previous_balance
        FROM employees e
        JOIN posts p ON e.post_id = p.id
        JOIN employee_shops es ON e.id = es.employee_id
        JOIN shops s ON es.shop_id = s.id
        LEFT JOIN manager_debts md ON e.id = md.employee_id 
            AND s.id = md.shop_id
            AND MONTH(md.evaluation_month) = :month 
            AND YEAR(md.evaluation_month) = :year
        WHERE (p.title LIKE '%Manager%' OR p.title LIKE '%Gérant%')
        AND (e.end_of_service_date IS NULL OR e.end_of_service_date > CURDATE())
        ORDER BY s.name, e.full_name
    ");
    
    $stmt->bindParam(':month', $selectedMonth, PDO::PARAM_INT);
    $stmt->bindParam(':year', $selectedYear, PDO::PARAM_INT);
    $stmt->bindParam(':target_date', $targetDate, PDO::PARAM_STR);
    $stmt->execute();
    
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log(__('error_fetching_manager_debts') . ": " . $e->getMessage());
    $_SESSION['error_message'] = __('error_fetching_data');
    $employees = [];
}

?>

<?php include 'layouts/head-main.php'; ?>

<head>
    <title><?php echo __('manager_debts'); ?> | <?php echo __('employee_manager_system'); ?></title>
    <?php include 'layouts/head.php'; ?>
    
    <!-- DataTables CSS -->
    <link href="assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/libs/datatables.net-buttons-bs4/css/buttons.bootstrap4.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css" rel="stylesheet" type="text/css" />
    
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
                        <div class="page-title-box d-flex align-items-center justify-content-between">
                            <h4 class="mb-0 font-size-18">
                                <?php
                                if (isset($_GET['month']) || isset($_GET['year'])) {
                                    echo __('manager_debts_month_title') . " " . 
                                         sprintf("%02d", $selectedMonth) . "-" . $selectedYear;
                                } else {
                                    echo __('manager_debts');
                                }
                                ?>
                            </h4>

                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php"><?php echo __('dashboard'); ?></a></li>
                                    <li class="breadcrumb-item active"><?php echo __('manager_debts'); ?></li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <?php include 'layouts/notification.php'; ?>

                <!-- Manager Debts List -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h4 class="card-title">
                                        <?php
                                        // If filters are applied, show the filtered period
                                        if (isset($_GET['month']) || isset($_GET['year']) || isset($_GET['shop_id']) || isset($_GET['employee_id'])) {
                                            echo sprintf(__('manager_debts_for'), date('F', mktime(0, 0, 0, $selectedMonth, 1)), $selectedYear);
                                        } else {
                                            // Otherwise show a list of all employees
                                            echo __('all_employees');
                                        }
                                        ?>
                                    </h4>
                                    <div>
                                        <!-- Toggle filters button -->
                                        <button class="btn btn-secondary me-2" type="button" data-bs-toggle="collapse" data-bs-target="#filterSection" aria-expanded="false" aria-controls="filterSection">
                                            <i class="bx bx-filter-alt me-1"></i> <?php echo __('show_filters'); ?>
                                        </button>
                                        
                                        <!-- Add new debt button -->
                                        <button type="button" class="btn btn-primary" id="addGeneralDebtBtn">
                                            <i class="bx bx-plus me-1"></i> <?php echo __('add_new_debt'); ?>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php
                                // Toggle display of the filter section
                                $showFilters = isset($_GET['month']) || isset($_GET['year']) || isset($_GET['shop_id']) || isset($_GET['employee_id']);
                                ?>
                                
                                <!-- Collapsible Filter Section -->
                                <div class="collapse <?php echo $showFilters ? 'show' : ''; ?> mb-4" id="filterSection">
                                    <div class="card card-body bg-light">
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <h5 class="mb-0"><?php echo __('filter_manager_debts'); ?></h5>
                                            <?php if ($showFilters): ?>
                                            <a href="manager-debts.php" class="btn btn-sm btn-outline-secondary">
                                                <i class="bx bx-x"></i> <?php echo __('clear_filters'); ?>
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                        <form method="GET" action="manager-debts.php" class="row g-3 align-items-end">
                                            <div class="col-md-2">
                                                <label for="month" class="form-label"><?php echo __('month'); ?></label>
                                                <select class="form-select" id="month" name="month">
                                                    <?php if (empty($openMonths)): ?>
                                                        <!-- If no open months, show all months but disable them -->
                                                        <?php 
                                                        for ($i = 1; $i <= 12; $i++): 
                                                            $isDisabled = true; // All disabled since no months are open
                                                        ?>
                                                            <option value="<?php echo $i; ?>" 
                                                                    <?php echo $i == $selectedMonth ? 'selected' : ''; ?>
                                                                    <?php echo $isDisabled ? 'disabled' : ''; ?>>
                                                                <?php echo date('F', mktime(0, 0, 0, $i, 1)); ?>
                                                            </option>
                                                        <?php endfor; ?>
                                                        
                                                        <option value="" selected disabled><?php echo __('no_open_months'); ?></option>
                                                    <?php else: ?>
                                                        <!-- Show only open months -->
                                                        <?php 
                                                        $availableMonths = [];
                                                        $availableYears = [];
                                                        
                                                        // Group open months by year
                                                        foreach ($openMonths as $month) {
                                                            if (!in_array($month['year'], $availableYears)) {
                                                                $availableYears[] = $month['year'];
                                                            }
                                                            
                                                            if (!isset($availableMonths[$month['year']])) {
                                                                $availableMonths[$month['year']] = [];
                                                            }
                                                            
                                                            $availableMonths[$month['year']][] = $month['month'];
                                                        }
                                                        
                                                        // Display open months, grouped by year
                                                        foreach ($availableYears as $year):
                                                            if (count($availableYears) > 1):
                                                        ?>
                                                            <optgroup label="<?php echo $year; ?>">
                                                        <?php 
                                                            endif;
                                                            foreach ($availableMonths[$year] as $monthNum): 
                                                        ?>
                                                            <option value="<?php echo $monthNum; ?>" 
                                                                    <?php echo ($monthNum == $selectedMonth && $year == $selectedYear) ? 'selected' : ''; ?>
                                                                    data-year="<?php echo $year; ?>">
                                                                <?php echo date('F', mktime(0, 0, 0, $monthNum, 1)); ?>
                                                            </option>
                                                        <?php 
                                                            endforeach;
                                                            if (count($availableYears) > 1):
                                                        ?>
                                                            </optgroup>
                                                        <?php 
                                                            endif;
                                                        endforeach; 
                                                        ?>
                                                    <?php endif; ?>
                                                </select>
                                            </div>
                                            
                                            <div class="col-md-2">
                                                <label for="year" class="form-label"><?php echo __('year'); ?></label>
                                                <select class="form-select" id="year" name="year">
                                                    <?php if (empty($openMonths)): ?>
                                                        <!-- If no open months, show years but disable them -->
                                                        <?php 
                                                        $startYear = 2020;
                                                        $endYear = date('Y');
                                                        for ($i = $endYear; $i >= $startYear; $i--): 
                                                        ?>
                                                            <option value="<?php echo $i; ?>" <?php echo $i == $selectedYear ? 'selected' : ''; ?> disabled>
                                                                <?php echo $i; ?>
                                                            </option>
                                                        <?php endfor; ?>
                                                    <?php else: ?>
                                                        <!-- Show only years with open months -->
                                                        <?php foreach ($availableYears as $year): ?>
                                                            <option value="<?php echo $year; ?>" <?php echo $year == $selectedYear ? 'selected' : ''; ?>>
                                                                <?php echo $year; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </select>
                                            </div>
                                            
                                            <div class="col-md-3">
                                                <label for="shop_id" class="form-label"><?php echo __('shop'); ?></label>
                                                <select class="form-select" id="shop_id" name="shop_id">
                                                    <option value=""><?php echo __('all_shops'); ?></option>
                                                    <?php foreach ($shops as $shop): ?>
                                                        <option value="<?php echo $shop['id']; ?>" <?php echo $shop['id'] == $selectedShop ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($shop['name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            
                                            <div class="col-md-3">
                                                <label for="employee_id" class="form-label"><?php echo __('employee'); ?></label>
                                                <select class="form-select" id="employee_id" name="employee_id">
                                                    <option value=""><?php echo __('all_employees'); ?></option>
                                                    <?php foreach ($allEmployees as $employee): ?>
                                                        <option value="<?php echo $employee['id']; ?>" <?php echo $employee['id'] == $selectedEmployee ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($employee['full_name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            
                                            <div class="col-md-2">
                                                <button type="submit" class="btn btn-primary w-100"><?php echo __('apply_filters'); ?></button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                                
                                <?php
                                // Determine if filters are applied
                                $filtersApplied = isset($_GET['month']) || isset($_GET['year']) || isset($_GET['shop_id']) || isset($_GET['employee_id']);
                                $displayedEmployees = [];
                                
                                if ($filtersApplied) {
                                    // Create date string for evaluation_month comparison
                                    $targetDate = date('Y-m-d', strtotime("$selectedYear-$selectedMonth-01"));
                                    
                                    $sql = "
                                        SELECT 
                                            e.id as employee_id,
                                            e.full_name,
                                            s.id as shop_id,
                                            s.name as shop_name,
                                            p.title as position,
                                            md.id,
                                            COALESCE(md.previous_month_balance, 0) as previous_month_balance,
                                            COALESCE(md.inventory_month, 0) as inventory_month,
                                            COALESCE(md.salary_advance, 0) as salary_advance,
                                            COALESCE(md.sanction, 0) as sanction,
                                            COALESCE(md.cash_discrepancy, 0) as cash_discrepancy,
                                            COALESCE(md.total, 0) as total,
                                            COALESCE(md.tranche, 0) as tranche,
                                            COALESCE(md.remaining_balance, 0) as remaining_balance,
                                            COALESCE((
                                                SELECT COALESCE(prev_md.remaining_balance, 0)
                                                FROM manager_debts prev_md
                                                WHERE prev_md.employee_id = e.id
                                                AND prev_md.shop_id = s.id
                                                AND prev_md.evaluation_month < ?
                                                ORDER BY prev_md.evaluation_month DESC
                                                LIMIT 1
                                            ), 0) as calculated_previous_balance
                                        FROM employees e
                                        JOIN posts p ON e.post_id = p.id
                                        LEFT JOIN employee_shops es ON e.id = es.employee_id
                                        LEFT JOIN shops s ON es.shop_id = s.id
                                        LEFT JOIN (
                                            SELECT * FROM manager_debts 
                                            WHERE MONTH(evaluation_month) = ? AND YEAR(evaluation_month) = ?
                                        ) md ON e.id = md.employee_id AND s.id = md.shop_id
                                        WHERE (p.title LIKE '%Manager%' OR p.title LIKE '%Gérant%')
                                          AND (e.end_of_service_date IS NULL OR e.end_of_service_date > CURDATE())
                                    ";
                                    
                                    $params = [$targetDate, $selectedMonth, $selectedYear];
                                    
                                    if (!empty($selectedShop)) {
                                        $sql .= " AND s.id = ?";
                                        $params[] = $selectedShop;
                                    }
                                    
                                    if (!empty($selectedEmployee)) {
                                        $sql .= " AND e.id = ?";
                                        $params[] = $selectedEmployee;
                                    }
                                    
                                    $sql .= " ORDER BY e.full_name";
                                    
                                    try {
                                        $stmt = $pdo->prepare($sql);
                                        if ($stmt === false) {
                                            throw new PDOException(__('failed_prepare_statement') . ": " . print_r($pdo->errorInfo(), true));
                                        }
                                        
                                        if ($stmt->execute($params) === false) {
                                            throw new PDOException(__('failed_execute_statement') . ": " . print_r($stmt->errorInfo(), true));
                                        }
                                        
                                        $displayedEmployees = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                    } catch (PDOException $e) {
                                        error_log(__('error_filtered_query') . ": " . $e->getMessage());
                                        error_log("SQL: " . $sql);
                                        error_log("Params: " . print_r($params, true));
                                        echo '<div class="alert alert-danger">' . __('error_fetching_data') . ': ' . $e->getMessage() . '</div>';
                                    }
                                } else {
                                    // No filters applied, show all employees with shops
                                    $sql = "
                                        SELECT 
                                            e.id as employee_id,
                                            e.full_name,
                                            s.id as shop_id,
                                            s.name as shop_name,
                                            p.title as position,
                                            p.id as position_id,
                                            0 as previous_month_balance,
                                            0 as inventory_month,
                                            0 as salary_advance,
                                            0 as sanction,
                                            0 as cash_discrepancy,
                                            0 as total,
                                            0 as tranche,
                                            0 as remaining_balance
                                        FROM employees e
                                        JOIN posts p ON e.post_id = p.id
                                        LEFT JOIN employee_shops es ON e.id = es.employee_id
                                        LEFT JOIN shops s ON es.shop_id = s.id
                                        WHERE (p.title LIKE '%Manager%' OR p.title LIKE '%Gérant%')
                                          AND (e.end_of_service_date IS NULL OR e.end_of_service_date > CURDATE())
                                        ORDER BY e.full_name, s.name
                                    ";
                                    
                                    try {
                                        $stmt = $pdo->prepare($sql);
                                        if ($stmt === false) {
                                            throw new PDOException(__('failed_prepare_unfiltered_statement') . ": " . print_r($pdo->errorInfo(), true));
                                        }
                                        
                                        if ($stmt->execute() === false) {
                                            throw new PDOException(__('failed_execute_unfiltered_statement') . ": " . print_r($stmt->errorInfo(), true));
                                        }
                                        
                                        $displayedEmployees = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                    } catch (PDOException $e) {
                                        error_log(__('error_unfiltered_query') . ": " . $e->getMessage());
                                        error_log("SQL: " . $sql);
                                        echo '<div class="alert alert-danger">' . __('error_fetching_data') . ': ' . $e->getMessage() . '</div>';
                                    }
                                }
                                
                                if (empty($displayedEmployees)) {
                                    echo '<div class="alert alert-info">' . __('no_employees_found') . '</div>';
                                } else {
                                ?>
                                <div class="card my-4">
                                    <div class="card-header d-flex justify-content-between">
                                        <h5>
                                            <?php 
                                            if ($filtersApplied) {
                                                echo __('manager_debts') . ' - ' . date('F', mktime(0, 0, 0, $selectedMonth, 1)) . ' ' . $selectedYear;
                                            } else {
                                                echo __('employee_list');
                                            }
                                            ?>
                                        </h5>
                                        <?php if ($filtersApplied): ?>
                                        <div>
                                            <button class="btn btn-sm btn-primary print-btn">
                                                <i class="bx bx-printer"></i> <?php echo __('print'); ?>
                                            </button>
                                            <button class="btn btn-sm btn-success" id="exportToExcel">
                                                <i class="bx bx-export"></i> <?php echo __('export_excel'); ?>
                                            </button>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="card-body p-0">
                                        <?php if (!$filtersApplied): ?>
                                        <div class="alert alert-info m-3">
                                            <?php echo __('please_select_month_year_to_view_debts'); ?>
                                        </div>
                                        <?php else: ?>
                                        <div class="table-responsive" style="max-height: 600px; overflow-y: auto; position: relative;">
                                            <table class="table table-bordered table-sm table-hover m-0" id="debtsTable">
                                                <thead>
                                                    <tr class="text-center bg-light sticky-top">
                                                        <th style="width: 40px"><?php echo __('row_number'); ?></th>
                                                        <th><?php echo __('name_surname'); ?></th>
                                                        <th><?php echo __('shop'); ?></th>
                                                        <th><?php echo __('previous_month_balance'); ?></th>
                                                        <th><?php echo __('inventory_month'); ?></th>
                                                        <th><?php echo __('salary_advance'); ?></th>
                                                        <th><?php echo __('sanction'); ?></th>
                                                        <th><?php echo __('cash_discrepancy'); ?></th>
                                                        <th><?php echo __('total'); ?></th>
                                                        <th><?php echo __('payment'); ?></th>
                                                        <th><?php echo __('remaining_balance'); ?></th>
                                                        <th class="no-print no-export" style="width: 100px"><?php echo __('actions'); ?></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php 
                                                    $rowNumber = 1;
                                                    foreach ($displayedEmployees as $employee): 
                                                        $hasDebt = $filtersApplied ? !empty($employee['id']) : false;
                                                    ?>
                                                    <tr>
                                                        <td class="text-center"><?php echo $rowNumber++; ?></td>
                                                        <td><?php echo htmlspecialchars($employee['full_name']); ?></td>
                                                        <td><?php echo !empty($employee['shop_name']) ? htmlspecialchars($employee['shop_name']) : '<span class="text-danger">' . __('not_assigned') . '</span>'; ?></td>
                                                        <td class="text-end <?php echo ($employee['previous_month_balance'] != $employee['calculated_previous_balance'] && $hasDebt) ? 'bg-warning' : ''; ?>">
                                                            <?php 
                                                            // Display calculated previous balance if no debt record exists yet
                                                            // Add null coalescing operator to ensure we always have a numeric value
                                                            echo number_format($hasDebt ? ($employee['previous_month_balance'] ?? 0) : ($employee['calculated_previous_balance'] ?? 0), 2); 
                                                            ?>
                                                            <?php if ($employee['previous_month_balance'] != $employee['calculated_previous_balance'] && $hasDebt): ?>
                                                                <small class="text-danger d-block">
                                                                    <?php echo __('expected'); ?>: <?php echo number_format($employee['calculated_previous_balance'] ?? 0, 2); ?>
                                                                </small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-end"><?php echo number_format($employee['inventory_month'] ?? 0, 2); ?></td>
                                                        <td class="text-end"><?php echo number_format($employee['salary_advance'] ?? 0, 2); ?></td>
                                                        <td class="text-end"><?php echo number_format($employee['sanction'] ?? 0, 2); ?></td>
                                                        <td class="text-end"><?php echo number_format($employee['cash_discrepancy'] ?? 0, 2); ?></td>
                                                        <td class="text-end"><?php echo number_format($employee['total'] ?? 0, 2); ?></td>
                                                        <td class="text-end"><?php echo number_format($employee['tranche'] ?? 0, 2); ?></td>
                                                        <td class="text-end"><?php echo number_format($employee['remaining_balance'] ?? 0, 2); ?></td>
                                                        <td class="text-center no-print">
                                                            <?php if ($filtersApplied): ?>
                                                                <?php if ($hasDebt): ?>
                                                                    <button type="button" class="btn btn-sm btn-primary edit-debt-btn" 
                                                                        data-id="<?php echo $employee['id']; ?>"
                                                                        data-employee-id="<?php echo $employee['employee_id']; ?>"
                                                                        data-shop-id="<?php echo $employee['shop_id']; ?>"
                                                                        data-previous-month-balance="<?php echo $employee['previous_month_balance'] ?? 0; ?>"
                                                                        data-calculated-previous-balance="<?php echo $employee['calculated_previous_balance'] ?? 0; ?>"
                                                                        data-inventory-month="<?php echo $employee['inventory_month'] ?? 0; ?>"
                                                                        data-salary-advance="<?php echo $employee['salary_advance'] ?? 0; ?>"
                                                                        data-sanction="<?php echo $employee['sanction'] ?? 0; ?>"
                                                                        data-cash-discrepancy="<?php echo $employee['cash_discrepancy'] ?? 0; ?>"
                                                                        data-tranche="<?php echo $employee['tranche'] ?? 0; ?>">
                                                                        <i class="bx bx-edit"></i>
                                                                    </button>
                                                                <?php else: ?>
                                                                    <?php if (!empty($employee['shop_id'])): ?>
                                                                    <button type="button" class="btn btn-sm btn-success add-debt-btn"
                                                                        data-employee-id="<?php echo $employee['employee_id']; ?>"
                                                                        data-shop-id="<?php echo $employee['shop_id']; ?>">
                                                                        <i class="bx bx-plus"></i>
                                                                    </button>
                                                                    <?php else: ?>
                                                                    <button type="button" class="btn btn-sm btn-secondary" disabled title="<?php echo __('employee_needs_shop'); ?>">
                                                                        <i class="bx bx-store-alt"></i>
                                                                    </button>
                                                                    <?php endif; ?>
                                                                <?php endif; ?>
                                                            <?php else: ?>
                                                                <?php if (!empty($employee['shop_id'])): ?>
                                                                <button type="button" class="btn btn-sm btn-secondary view-employee-btn"
                                                                    data-employee-id="<?php echo $employee['employee_id']; ?>">
                                                                    <i class="bx bx-show"></i>
                                                                </button>
                                                                <?php else: ?>
                                                                <button type="button" class="btn btn-sm btn-warning" title="<?php echo __('employee_needs_shop'); ?>" disabled>
                                                                    <i class="bx bx-store-alt"></i>
                                                                </button>
                                                                <?php endif; ?>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php 
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
                
            </div>
        </div>

        <!-- Add Debt Modal -->
        <div class="modal fade" id="addDebtModal" tabindex="-1" aria-labelledby="addDebtModalTitle" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="addDebtModalTitle"><?php echo __('add_debt'); ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <form id="addDebtForm" method="POST" action="">
                            <input type="hidden" name="action" value="add_debt">
                            <input type="hidden" name="evaluation_month" value="<?php echo $selectedMonth; ?>">
                            <input type="hidden" name="evaluation_year" value="<?php echo $selectedYear; ?>">
                            
                            <div class="form-group employee-selection-group">
                                <label for="employee_id"><?php echo __('employee'); ?></label>
                                <select class="form-control" id="employee_id" name="employee_id">
                                    <option value=""><?php echo __('select_employee'); ?></option>
                                    <?php foreach ($allEmployees as $employee): ?>
                                        <option value="<?php echo $employee['id']; ?>"><?php echo $employee['full_name']; ?> (<?php echo $employee['position']; ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group shop-selection-group">
                                <label for="shop_id"><?php echo __('shop'); ?></label>
                                <select class="form-control" id="shop_id" name="shop_id">
                                    <option value=""><?php echo __('select_shop'); ?></option>
                                    <?php foreach ($shops as $shop): ?>
                                        <option value="<?php echo $shop['id']; ?>"><?php echo htmlspecialchars($shop['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group employee-display" style="display: none;">
                                <label><?php echo __('employee'); ?>:</label>
                                <p id="selected_employee_display" class="form-control-static font-weight-bold"></p>
                                <input type="hidden" id="selected_employee_id" name="selected_employee_id">
                            </div>
                            
                            <div class="form-group shop-display" style="display: none;">
                                <label><?php echo __('shop'); ?>:</label>
                                <p id="selected_shop_display" class="form-control-static font-weight-bold"></p>
                                <input type="hidden" id="selected_shop_id" name="selected_shop_id">
                            </div>
                            
                            <div class="form-group">
                                <label for="previous_month_balance"><?php echo __('previous_month_balance'); ?></label>
                                <input type="number" step="0.01" class="form-control" id="previous_month_balance" name="previous_month_balance" value="0">
                                <small class="form-text text-muted" id="previous_balance_info">
                                    <?php echo __('automatic_balance_calculation_info'); ?>
                                </small>
                            </div>
                            
                            <div class="form-group">
                                <label for="inventory_month"><?php echo __('inventory_month'); ?></label>
                                <input type="number" step="0.01" class="form-control" id="inventory_month" name="inventory_month" value="0">
                            </div>
                            
                            <div class="form-group">
                                <label for="salary_advance"><?php echo __('salary_advance'); ?></label>
                                <input type="number" step="0.01" class="form-control" id="salary_advance" name="salary_advance" value="0">
                            </div>
                            
                            <div class="form-group">
                                <label for="sanction"><?php echo __('sanction'); ?></label>
                                <input type="number" step="0.01" class="form-control" id="sanction" name="sanction" value="0">
                            </div>
                            
                            <div class="form-group">
                                <label for="cash_discrepancy"><?php echo __('cash_discrepancy'); ?></label>
                                <input type="number" step="0.01" class="form-control" id="cash_discrepancy" name="cash_discrepancy" value="0">
                            </div>
                            
                            <div class="form-group">
                                <label for="total_debt"><?php echo __('total'); ?></label>
                                <input type="number" step="0.01" class="form-control" id="total_debt" name="total" value="0" readonly>
                            </div>
                            
                            <div class="form-group">
                                <label for="tranche"><?php echo __('payment'); ?></label>
                                <input type="number" step="0.01" class="form-control" id="tranche" name="tranche" value="0">
                            </div>
                            
                            <div class="form-group">
                                <label for="remaining"><?php echo __('remaining'); ?></label>
                                <input type="number" step="0.01" class="form-control" id="remaining" name="remaining" value="0" readonly>
                            </div>
                            
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                                <button type="submit" class="btn btn-primary"><?php echo __('save'); ?></button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit Debt Modal -->
        <div class="modal fade" id="editDebtModal" tabindex="-1" aria-labelledby="editDebtModalTitle" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editDebtModalTitle"><?php echo __('edit_debt'); ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <form id="editDebtForm" method="POST" action="">
                            <input type="hidden" name="action" value="edit_debt">
                            <input type="hidden" id="edit_debt_id" name="debt_id">
                            <input type="hidden" id="edit_employee_id" name="employee_id">
                            <input type="hidden" id="edit_shop_id" name="shop_id">
                            <input type="hidden" name="evaluation_month" value="<?php echo $selectedMonth; ?>">
                            <input type="hidden" name="evaluation_year" value="<?php echo $selectedYear; ?>">
                            
                            <div class="form-group">
                                <label for="edit_previous_month_balance"><?php echo __('previous_month_balance'); ?></label>
                                <div class="input-group">
                                    <input type="number" step="0.01" class="form-control" id="edit_previous_month_balance" name="previous_month_balance" value="0">
                                    <input type="hidden" id="edit_calculated_previous_balance" value="0">
                                    <input type="hidden" id="use_calculated_balance_flag" name="use_calculated_balance" value="0">
                                    <button type="button" class="btn btn-outline-info" id="use_calculated_balance">
                                        <i class="bx bx-refresh"></i> <?php echo __('use_calculated'); ?>
                                    </button>
                                </div>
                                <small class="form-text text-muted">
                                    <?php echo __('changing_previous_balance_warning'); ?>
                                </small>
                                <div id="previous_balance_difference" class="alert alert-warning mt-2" style="display: none;">
                                    <?php echo __('calculated_balance_differs'); ?>: <span id="calculated_balance_value">0.00</span>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="edit_inventory_month"><?php echo __('inventory_month'); ?></label>
                                <input type="number" step="0.01" class="form-control" id="edit_inventory_month" name="inventory_month" value="0">
                            </div>
                            
                            <div class="form-group">
                                <label for="edit_salary_advance"><?php echo __('salary_advance'); ?></label>
                                <input type="number" step="0.01" class="form-control" id="edit_salary_advance" name="salary_advance" value="0">
                            </div>
                            
                            <div class="form-group">
                                <label for="edit_sanction"><?php echo __('sanction'); ?></label>
                                <input type="number" step="0.01" class="form-control" id="edit_sanction" name="sanction" value="0">
                            </div>
                            
                            <div class="form-group">
                                <label for="edit_cash_discrepancy"><?php echo __('cash_discrepancy'); ?></label>
                                <input type="number" step="0.01" class="form-control" id="edit_cash_discrepancy" name="cash_discrepancy" value="0">
                            </div>
                            
                            <div class="form-group">
                                <label for="edit_total"><?php echo __('total'); ?></label>
                                <input type="number" step="0.01" class="form-control" id="edit_total" name="total" value="0" readonly>
                            </div>
                            
                            <div class="form-group">
                                <label for="edit_tranche"><?php echo __('payment'); ?></label>
                                <input type="number" step="0.01" class="form-control" id="edit_tranche" name="tranche" value="0">
                            </div>
                            
                            <div class="form-group">
                                <label for="edit_remaining"><?php echo __('remaining'); ?></label>
                                <input type="number" step="0.01" class="form-control" id="edit_remaining" name="remaining" value="0" readonly>
                            </div>
                            
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                                <button type="submit" class="btn btn-primary"><?php echo __('save'); ?></button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <?php include 'layouts/footer.php'; ?>
    </div>
    <!-- end main content-->

</div>
<!-- END layout-wrapper -->

<?php include 'layouts/right-sidebar.php'; ?>
<?php include 'layouts/vendor-scripts.php'; ?>

<!-- Required datatable js -->
<script src="assets/libs/datatables.net/js/jquery.dataTables.min.js"></script>
<script src="assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js"></script>

<!-- Responsive examples -->
<script src="assets/libs/datatables.net-responsive/js/dataTables.responsive.min.js"></script>
<script src="assets/libs/datatables.net-responsive-bs4/js/responsive.bootstrap4.min.js"></script>

<style>
/* Custom styles for the DataTable */
.sticky-top {
    position: sticky;
    top: 0;
    z-index: 10;
}

/* Fix for header detaching issue */
.dataTables_scrollHead {
    overflow: visible !important;
}

/* Keep header attached to table */
.dataTables_scrollHead table {
    border-bottom: 0 !important;
}

.dataTables_scrollBody {
    border-top: 0 !important;
}

.table-responsive {
    border-radius: 0 0 0.25rem 0.25rem;
}

#debtsTable {
    margin-bottom: 0 !important;
}

#debtsTable thead th {
    background-color: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    /* Let DataTables handle the sticky positioning instead */
    z-index: 5;
}

#debtsTable tbody tr:hover {
    background-color: rgba(0,0,0,.05);
}

.table-sm td, .table-sm th {
    padding: 0.5rem;
}

.card-body.p-0 .alert {
    margin: 1rem;
}

/* Custom search box styling */
.dataTables_filter {
    margin: 10px 15px;
    text-align: right;
}

.dataTables_info {
    margin: 10px 15px;
}

/* Export buttons styling */
.dt-buttons {
    margin: 10px 15px;
}

.dt-buttons .btn {
    margin-right: 5px;
}
</style>

<script>
    $(document).ready(function() {
        // Initialize DataTable with export buttons only if table exists
        if ($('#debtsTable').length) {
            var table = $('#debtsTable').DataTable({
                dom: '<"row"<"col-md-6"B><"col-md-6"f>>rti',
                paging: false,
                scrollY: '500px',
                scrollCollapse: true,
                autoWidth: true,
                fixedHeader: {
                    header: true,
                    headerOffset: $('.header-navbar').height()
                },
                buttons: [
                    {
                        extend: 'excel',
                        title: '<?php echo __("manager_debts"); ?> - <?php echo $selectedMonth; ?>/<?php echo $selectedYear; ?>',
                        className: 'btn btn-success btn-sm',
                        exportOptions: {
                            columns: ':not(.no-export)'
                        }
                    },
                    {
                        extend: 'print',
                        title: '<?php echo __("manager_debts"); ?> - <?php echo $selectedMonth; ?>/<?php echo $selectedYear; ?>',
                        className: 'btn btn-info btn-sm',
                        exportOptions: {
                            columns: ':not(.no-export)'
                        }
                    }
                ],
                language: {
                    search: '<?php echo __("search"); ?>:',
                    info: '<?php echo __("showing"); ?> _START_ <?php echo __("to"); ?> _END_ <?php echo __("of"); ?> _TOTAL_ <?php echo __("entries"); ?>'
                }
            });
            
            // Connect export buttons
            $('#exportToExcel').on('click', function() {
                $('.buttons-excel').click();
            });
            
            $('.print-btn').on('click', function() {
                $('.buttons-print').click();
            });
            
            // Add window resize event handler to redraw the table
            $(window).on('resize', function() {
                if (table) {
                    table.columns.adjust().draw();
                }
            });
            
            // Adjust columns when table is drawn
            table.on('draw', function() {
                table.columns.adjust();
            });
        }

        // Calculate totals for Add Debt form
        function calculateAddTotal() {
            var previousBalance = parseFloat($('#previous_month_balance').val()) || 0;
            var inventory = parseFloat($('#inventory_month').val()) || 0;
            var salaryAdvance = parseFloat($('#salary_advance').val()) || 0;
            var sanction = parseFloat($('#sanction').val()) || 0;
            var cashDiscrepancy = parseFloat($('#cash_discrepancy').val()) || 0;
            var payment = parseFloat($('#tranche').val()) || 0;
            
            var total = previousBalance + inventory + salaryAdvance + sanction + cashDiscrepancy;
            var remaining = total - payment;
            
            $('#total_debt').val(total.toFixed(2));
            $('#remaining').val(remaining.toFixed(2));
        }
        
        // Calculate totals for Edit Debt form
        function calculateEditTotal() {
            var previousBalance = parseFloat($('#edit_previous_month_balance').val()) || 0;
            var inventory = parseFloat($('#edit_inventory_month').val()) || 0;
            var salaryAdvance = parseFloat($('#edit_salary_advance').val()) || 0;
            var sanction = parseFloat($('#edit_sanction').val()) || 0;
            var cashDiscrepancy = parseFloat($('#edit_cash_discrepancy').val()) || 0;
            var payment = parseFloat($('#edit_tranche').val()) || 0;
            
            var total = previousBalance + inventory + salaryAdvance + sanction + cashDiscrepancy;
            var remaining = total - payment;
            
            $('#edit_total').val(total.toFixed(2));
            $('#edit_remaining').val(remaining.toFixed(2));
        }

        // Attach event listeners for Add Debt form
        $('#previous_month_balance, #inventory_month, #salary_advance, #sanction, #cash_discrepancy, #tranche').on('input', calculateAddTotal);

        // Attach event listeners for Edit Debt form
        $('#edit_previous_month_balance, #edit_inventory_month, #edit_salary_advance, #edit_sanction, #edit_cash_discrepancy, #edit_tranche').on('input', calculateEditTotal);

        // Initialize calculations when forms load
        $('#addDebtModal').on('shown.bs.modal', calculateAddTotal);
        $('#editDebtModal').on('shown.bs.modal', calculateEditTotal);

        // Initialize Edit Modal with correct data
        $('.edit-debt-btn').click(function() {
            var debtId = $(this).data('id');
            var employeeId = $(this).data('employee-id');
            var shopId = $(this).data('shop-id');
            var previousBalance = $(this).data('previous-month-balance');
            var calculatedPreviousBalance = $(this).data('calculated-previous-balance');
            var inventory = $(this).data('inventory-month');
            var salaryAdvance = $(this).data('salary-advance');
            var sanction = $(this).data('sanction');
            var cashDiscrepancy = $(this).data('cash-discrepancy');
            var tranche = $(this).data('tranche');
            
            $('#edit_debt_id').val(debtId);
            $('#edit_employee_id').val(employeeId);
            $('#edit_shop_id').val(shopId);
            $('#edit_previous_month_balance').val(previousBalance);
            $('#edit_calculated_previous_balance').val(calculatedPreviousBalance);
            $('#edit_inventory_month').val(inventory);
            $('#edit_salary_advance').val(salaryAdvance);
            $('#edit_sanction').val(sanction);
            $('#edit_cash_discrepancy').val(cashDiscrepancy);
            $('#edit_tranche').val(tranche);
            
            calculateEditTotal();
            
            // Show the modal using Bootstrap 5
            editDebtModal.show();
        });
        
        // Function to get previous month's balance
        function fetchPreviousMonthBalance(employeeId, shopId) {
            if (!employeeId || !shopId) return;
            
            $('#previous_balance_info').html("<?php echo addslashes(__('loading_previous_balance')); ?>");
            
            $.ajax({
                url: 'ajax-handlers/get-previous-balance.php',
                type: 'GET',
                data: {
                    employee_id: employeeId,
                    shop_id: shopId,
                    month: <?php echo $selectedMonth; ?>,
                    year: <?php echo $selectedYear; ?>
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#previous_month_balance').val(response.balance);
                        calculateAddTotal();
                        
                        // Display informative message about the balance source
                        if (response.balance > 0) {
                            $('#previous_balance_info').html(
                                '<span class="text-info">' + 
                                "<?php echo addslashes(__('previous_balance_from')); ?> " + 
                                response.from_month + ': ' + 
                                response.balance.toFixed(2) + 
                                '</span>'
                            );
                        } else {
                            $('#previous_balance_info').html(
                                '<span class="text-success">' + 
                                "<?php echo addslashes(__('no_previous_balance')); ?>" + 
                                '</span>'
                            );
                        }
                    } else {
                        $('#previous_balance_info').html(
                            '<span class="text-danger">' + 
                            "<?php echo addslashes(__('error_loading_balance')); ?>: " + 
                            (response.error || 'Unknown error') + 
                            '</span>'
                        );
                    }
                },
                error: function(xhr, status, error) {
                    console.error("<?php echo addslashes(__('error_fetching_previous_month_balance')); ?>:", error);
                    $('#previous_balance_info').html(
                        '<span class="text-danger">' + 
                        "<?php echo addslashes(__('error_loading_balance')); ?>: " + 
                        error + 
                        '</span>'
                    );
                }
            });
        }
        
        // Initialize modals with Bootstrap 5
        var addDebtModal = new bootstrap.Modal(document.getElementById('addDebtModal'));
        var editDebtModal = new bootstrap.Modal(document.getElementById('editDebtModal'));
        
        // Reset form when modal is closed
        $('#addDebtModal').on('hidden.bs.modal', function () {
            $('#addDebtForm')[0].reset();
        });
        
        $('#editDebtModal').on('hidden.bs.modal', function () {
            $('#editDebtForm')[0].reset();
        });
        
        // Handle the add debt button from a table row
        $('.add-debt-btn').click(function() {
            var employeeId = $(this).data('employee-id');
            var shopId = $(this).data('shop-id');
            var employeeName = $(this).closest('tr').find('td:nth-child(2)').text();
            var shopName = $(this).closest('tr').find('td:nth-child(3)').text();
            
            // When adding from a table row, hide the selection fields and show the static display
            $('.employee-selection-group, .shop-selection-group').hide();
            $('.employee-display, .shop-display').show();
            
            // Set the employee and shop names for display
            $('#selected_employee_display').text(employeeName);
            $('#selected_shop_display').text(shopName);
            
            // Set the hidden input values
            $('#selected_employee_id').val(employeeId);
            $('#selected_shop_id').val(shopId);
            
            // Auto-fetch previous month's balance
            fetchPreviousMonthBalance(employeeId, shopId);
            
            calculateAddTotal();
            
            // Show the modal using Bootstrap 5
            addDebtModal.show();
        });
        
        // Handle the general add debt button (not from a row)
        $('#addGeneralDebtBtn').click(function() {
            // Show the selection fields and hide the static display
            $('.employee-selection-group, .shop-selection-group').show();
            $('.employee-display, .shop-display').hide();
            
            // Clear the hidden input values
            $('#selected_employee_id, #selected_shop_id').val('');
            
            // Reset form fields
            $('#employee_id, #shop_id').val('');
            $('#previous_month_balance, #inventory_month, #salary_advance, #sanction, #cash_discrepancy, #tranche').val('0');
            $('#previous_balance_info').html('');
            
            calculateAddTotal();
            
            // Show the modal using Bootstrap 5
            addDebtModal.show();
        });

        // Add event handler for when employee or shop selection changes
        $('#employee_id, #shop_id').change(function() {
            var employeeId = $('#employee_id').val();
            var shopId = $('#shop_id').val();
            
            if (employeeId && shopId) {
                fetchPreviousMonthBalance(employeeId, shopId);
            }
        });

        // Add handler for the "use calculated balance" button
        $('#use_calculated_balance').click(function() {
            var calculatedBalance = parseFloat($('#edit_calculated_previous_balance').val()) || 0;
            $('#edit_previous_month_balance').val(calculatedBalance.toFixed(2));
            $('#use_calculated_balance_flag').val(1);
            calculateEditTotal();
        });
        
        // Set up the edit form to display warning if balance differs from calculated
        $('#editDebtModal').on('shown.bs.modal', function() {
            var currentBalance = parseFloat($('#edit_previous_month_balance').val()) || 0;
            var calculatedBalance = parseFloat($('#edit_calculated_previous_balance').val()) || 0;
            
            $('#calculated_balance_value').text(calculatedBalance.toFixed(2));
            
            if (Math.abs(currentBalance - calculatedBalance) > 0.01) {
                $('#previous_balance_difference').show();
            } else {
                $('#previous_balance_difference').hide();
            }
        });

        // Add form submission handler
        $('#addDebtForm').on('submit', function(e) {
            e.preventDefault(); // Prevent default form submission
            
            var isValid = true;
            var errorMessage = '';
            
            // Validate employee selection
            var employeeId = '';
            if ($('.employee-selection-group').is(':visible')) {
                employeeId = $('#employee_id').val();
                if (!employeeId) {
                    isValid = false;
                    errorMessage += '<?php echo __("please_select_employee"); ?>\n';
                    $('#employee_id').addClass('is-invalid');
                } else {
                    $('#employee_id').removeClass('is-invalid');
                }
            } else {
                employeeId = $('#selected_employee_id').val();
                if (!employeeId) {
                    isValid = false;
                    errorMessage += '<?php echo __("employee_id_missing"); ?>\n';
                }
            }
            
            // Validate shop selection
            var shopId = '';
            if ($('.shop-selection-group').is(':visible')) {
                shopId = $('#shop_id').val();
                if (!shopId) {
                    isValid = false;
                    errorMessage += '<?php echo __("please_select_shop"); ?>\n';
                    $('#shop_id').addClass('is-invalid');
                } else {
                    $('#shop_id').removeClass('is-invalid');
                }
            } else {
                shopId = $('#selected_shop_id').val();
                if (!shopId) {
                    isValid = false;
                    errorMessage += '<?php echo __("shop_id_missing"); ?>\n';
                }
            }
            
            // Show errors or submit
            if (isValid) {
                // Manually construct form data
                var formData = new FormData(this);
                
                // Remove any validation classes before submitting
                $('#employee_id, #shop_id').removeClass('is-invalid');
                
                // Actually submit the form using the traditional method
                this.submit();
            } else {
                // Show error alert
                alert(errorMessage);
                return false;
            }
        });

        // Similar handler for edit form
        $('#editDebtForm').on('submit', function(e) {
            e.preventDefault();
            
            // Edit form already has hidden fields, so validation is simpler
            var isValid = true;
            
            if (!$('#edit_debt_id').val()) {
                isValid = false;
                alert('<?php echo __("debt_id_missing"); ?>');
            }
            
            if (isValid) {
                $(this)[0].submit();
            }
        });
    });
</script>

<!-- Required Page JS -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize DataTables
        $('#debts-datatable').DataTable({
            responsive: true,
            language: {
                search: "<?php echo __('search'); ?>:",
                lengthMenu: "<?php echo __('show'); ?> _MENU_ <?php echo __('entries'); ?>",
                info: "<?php echo __('showing'); ?> _START_ <?php echo __('to'); ?> _END_ <?php echo __('of'); ?> _TOTAL_ <?php echo __('entries'); ?>",
                infoEmpty: "<?php echo __('showing'); ?> 0 <?php echo __('to'); ?> 0 <?php echo __('of'); ?> 0 <?php echo __('entries'); ?>",
                infoFiltered: "(<?php echo __('filtered_from'); ?> _MAX_ <?php echo __('total_entries'); ?>)",
                emptyTable: "<?php echo __('no_data_available_in_table'); ?>",
                zeroRecords: "<?php echo __('no_matching_records_found'); ?>",
                paginate: {
                    first: "<?php echo __('first'); ?>",
                    last: "<?php echo __('last'); ?>",
                    next: "<?php echo __('next'); ?>",
                    previous: "<?php echo __('previous'); ?>"
                }
            }
        });
        
        // We don't need to call calculateTotal here as we're using the modal-specific calculation functions
    });
    
    // Update calculateTotal function to match actual form fields in the modal
    function calculateTotal() {
        // Only run if we're on a page with these elements
        const previousBalanceElem = document.querySelector('[name="previous_month_balance"]');
        const salaryAdvanceElem = document.querySelector('[name="salary_advance"]');
        const inventoryMonthElem = document.querySelector('[name="inventory_month"]');
        const sanctionElem = document.querySelector('[name="sanction"]');
        const cashDiscrepancyElem = document.querySelector('[name="cash_discrepancy"]');
        const totalDebtElem = document.getElementById('total_debt');
        
        // If elements don't exist, exit early
        if (!previousBalanceElem || !totalDebtElem) {
            return;
        }
        
        const previousBalance = parseFloat(previousBalanceElem.value) || 0;
        const salaryAdvance = parseFloat(salaryAdvanceElem ? salaryAdvanceElem.value : 0) || 0;
        const inventoryMonth = parseFloat(inventoryMonthElem ? inventoryMonthElem.value : 0) || 0;
        const sanction = parseFloat(sanctionElem ? sanctionElem.value : 0) || 0;
        const cashDiscrepancy = parseFloat(cashDiscrepancyElem ? cashDiscrepancyElem.value : 0) || 0;
        
        const total = previousBalance + salaryAdvance + inventoryMonth + sanction + cashDiscrepancy;
        totalDebtElem.value = total.toFixed(2);
    }
    
    // Show debt details in modal
    function showDebtDetails(debtJson) {
        const debt = JSON.parse(debtJson);
        
        // Set values in modal
        document.getElementById('view_employee').textContent = debt.employee_name;
        document.getElementById('view_shop').textContent = debt.shop_name;
        document.getElementById('view_month').textContent = debt.month_name;
        document.getElementById('view_year').textContent = debt.evaluation_year;
        
        document.getElementById('view_previous_balance').textContent = formatCurrency(debt.previous_month_balance);
        document.getElementById('view_salary_advance').textContent = formatCurrency(debt.salary_advance);
        document.getElementById('view_uniform_cost').textContent = formatCurrency(debt.uniform_cost);
        document.getElementById('view_damages_cost').textContent = formatCurrency(debt.damages_cost);
        document.getElementById('view_other_deductions').textContent = formatCurrency(debt.other_deductions);
        document.getElementById('view_total').textContent = formatCurrency(debt.total);
        
        // Status with badge
        const statusBadgeClass = debt.status === 'paid' ? 'bg-success' : 'bg-warning';
        document.getElementById('view_status').innerHTML = `<span class="badge ${statusBadgeClass}">${debt.status === 'paid' ? '<?php echo __("status_paid"); ?>' : '<?php echo __("status_pending"); ?>'}</span>`;
        
        // Payment date
        const paymentDateContainer = document.getElementById('payment_date_container');
        if (debt.status === 'paid' && debt.payment_date) {
            paymentDateContainer.style.display = 'block';
            document.getElementById('view_payment_date').textContent = debt.formatted_payment_date;
        } else {
            paymentDateContainer.style.display = 'none';
        }
        
        // Notes
        const notesContainer = document.getElementById('notes_container');
        if (debt.notes) {
            notesContainer.style.display = 'block';
            document.getElementById('view_notes').textContent = debt.notes;
        } else {
            notesContainer.style.display = 'none';
        }
        
        // Show modal
        const viewDebtModal = new bootstrap.Modal(document.getElementById('viewDebtModal'));
        viewDebtModal.show();
    }
    
    // Format currency
    function formatCurrency(amount) {
        return new Intl.NumberFormat('<?php echo __("locale_code"); ?>', { 
            style: 'currency', 
            currency: '<?php echo __("currency_code"); ?>' 
        }).format(amount);
    }
</script>
</body>
</html>
<?php
// End output buffering and send content to browser
ob_end_flush();
?>
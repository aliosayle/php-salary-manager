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
include_once "layouts/translations.php";

// Check if the user has permission to access this page
requirePermission('manage_employees');

// Get current month/year for default filter values
$currentMonth = date('m');
$currentYear = date('Y');
$selectedMonth = isset($_GET['month']) ? intval($_GET['month']) : intval($currentMonth);
$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : intval($currentYear);
$selectedShop = isset($_GET['shop_id']) ? $_GET['shop_id'] : '';

// Get open months from the months table
$openMonths = [];
try {
    $monthsStmt = $pdo->query("SELECT * FROM months WHERE is_open = 1 ORDER BY year DESC, month ASC");
    $openMonths = $monthsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If no open months or selected month is not open, default to the most recent open month
    if (!empty($openMonths) && !checkMonthOpen($openMonths, $selectedMonth, $selectedYear)) {
        $latestMonth = $openMonths[0];
        $selectedMonth = $latestMonth['month'];
        $selectedYear = $latestMonth['year'];
    }
} catch (PDOException $e) {
    $_SESSION["error_message"] = "Error fetching open months: " . $e->getMessage();
}

// Helper function to check if a month/year combination is in the open months list
function checkMonthOpen($openMonths, $month, $year) {
    foreach ($openMonths as $openMonth) {
        if ($openMonth['month'] == $month && $openMonth['year'] == $year) {
            return true;
        }
    }
    return false;
}

// Fetch all score ranges from the total_ranges table
try {
    $ranges = [];
    $rangesStmt = $pdo->query("SELECT * FROM total_ranges ORDER BY min_value ASC");
    $ranges = $rangesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_SESSION["error_message"] = "Error fetching score ranges: " . $e->getMessage();
}

// Process batch evaluation submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["action"]) && $_POST["action"] == "batch_evaluate") {
    $evaluations = $_POST["evaluations"];
    $month = $_POST["evaluation_month"];
    $year = $_POST["evaluation_year"];
    $successCount = 0;
    $errorCount = 0;
    
    // Begin transaction
    try {
        $pdo->beginTransaction();
        
        foreach ($evaluations as $employeeId => $data) {
            // Skip employees with no data entered
            if (empty($data['attendance']) && empty($data['cleanliness']) && 
                empty($data['unloading']) && empty($data['sales']) && 
                empty($data['order_management']) && empty($data['stock_sheet']) && 
                empty($data['inventory']) && empty($data['machine_and_team']) && 
                empty($data['management']) && empty($data['ranking'])) {
                continue;
            }
            
            // Set default values of 0 for any missing criteria
            $attendance = !empty($data['attendance']) ? $data['attendance'] : 0;
            $cleanliness = !empty($data['cleanliness']) ? $data['cleanliness'] : 0;
            $unloading = !empty($data['unloading']) ? $data['unloading'] : 0;
            $sales = !empty($data['sales']) ? $data['sales'] : 0;
            $order_management = !empty($data['order_management']) ? $data['order_management'] : 0;
            $stock_sheet = !empty($data['stock_sheet']) ? $data['stock_sheet'] : 0;
            $inventory = !empty($data['inventory']) ? $data['inventory'] : 0;
            $machine_and_team = !empty($data['machine_and_team']) ? $data['machine_and_team'] : 0;
            $management = !empty($data['management']) ? $data['management'] : 0;
            $ranking = !empty($data['ranking']) ? $data['ranking'] : 0;
            $manualBonusAmount = !empty($data['bonus_amount']) ? floatval($data['bonus_amount']) : 0;
            
            // Calculate total score
            $totalScore = $attendance + $cleanliness + $unloading + $sales + 
                          $order_management + $stock_sheet + $inventory + $machine_and_team + $management;
            
            // Calculate the bonus amount based on the total score (if not manually set)
            $bonusAmount = $manualBonusAmount;
            if ($bonusAmount == 0) {
                foreach ($ranges as $range) {
                    if ($totalScore >= $range['min_value'] && $totalScore <= $range['max_value']) {
                        $bonusAmount = $range['amount'];
                        break;
                    }
                }
            }
            
            // Verify the employee exists
            $checkEmployeeStmt = $pdo->prepare("SELECT id FROM employees WHERE id = ?");
            $checkEmployeeStmt->execute([$employeeId]);
            
            if ($checkEmployeeStmt->rowCount() === 0) {
                $errorCount++;
                continue;
            }
            
            // Check if an evaluation already exists for this employee/month/year
            $checkStmt = $pdo->prepare("
                SELECT id FROM employee_evaluations 
                WHERE employee_id = ? AND MONTH(evaluation_month) = ? AND YEAR(evaluation_month) = ?
            ");
            $checkStmt->execute([$employeeId, $month, $year]);
            
            if ($checkStmt->rowCount() > 0) {
                // Update existing evaluation
                $evaluationId = $checkStmt->fetchColumn();
                $stmt = $pdo->prepare("
                    UPDATE employee_evaluations 
                    SET attendance = ?, cleanliness = ?, unloading = ?, 
                        sales = ?, order_management = ?, stock_sheet = ?,
                        inventory = ?, machine_and_team = ?, management = ?, ranking = ?,
                        total_score = ?, bonus_amount = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $attendance, $cleanliness, $unloading, $sales, 
                    $order_management, $stock_sheet, $inventory, $machine_and_team,
                    $management, $ranking, $totalScore, $bonusAmount, $evaluationId
                ]);
            } else {
                // Create new evaluation
                $stmt = $pdo->prepare("
                    INSERT INTO employee_evaluations 
                    (employee_id, evaluation_month, attendance, cleanliness, 
                    unloading, sales, order_management, stock_sheet, inventory,
                    machine_and_team, management, ranking, total_score, bonus_amount) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                // Create evaluation date as the first day of the selected month
                $evaluationMonth = date('Y-m-d', strtotime($year . '-' . $month . '-01'));
                
                $stmt->execute([
                    $employeeId, $evaluationMonth, $attendance, $cleanliness, 
                    $unloading, $sales, $order_management, $stock_sheet,
                    $inventory, $machine_and_team, $management, $ranking, $totalScore, $bonusAmount
                ]);
            }
            
            $successCount++;
        }
        
        // Commit the transaction
        $pdo->commit();
        
        if ($successCount > 0) {
            $_SESSION["success_message"] = sprintf(__('evaluations_saved_success'), $successCount);
        }
        if ($errorCount > 0) {
            $_SESSION["error_message"] = sprintf(__('evaluations_save_failed'), $errorCount);
        }
        
        // Redirect to refresh the page
        header("location: batch-evaluations.php?month=$month&year=$year" . ($selectedShop ? "&shop_id=$selectedShop" : ""));
        exit;
        
    } catch (PDOException $e) {
        // Roll back the transaction
        $pdo->rollBack();
        $_SESSION["error_message"] = "Error saving evaluations: " . $e->getMessage();
    }
}

// Get list of shops for the filter
$shopsStmt = $pdo->query("SELECT id, name FROM shops ORDER BY name");
$shops = $shopsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch employees based on filters
try {
    $sql = "
        SELECT e.id, e.full_name, e.reference, p.title as position, s.name as shop_name, 
            ev.id as evaluation_id, ev.attendance, ev.cleanliness, ev.unloading,
            ev.sales, ev.order_management, ev.stock_sheet, ev.inventory,
            ev.machine_and_team, ev.management, ev.ranking, ev.total_score
        FROM employees e
        INNER JOIN posts p ON e.post_id = p.id
        INNER JOIN employee_shops es ON e.id = es.employee_id
        INNER JOIN shops s ON es.shop_id = s.id
        LEFT JOIN (
            SELECT * FROM employee_evaluations 
            WHERE MONTH(evaluation_month) = ? AND YEAR(evaluation_month) = ?
        ) ev ON e.id = ev.employee_id
        WHERE e.post_id = '04b5ce3e-1aaf-11f0-99a1-cc28aa53b74d'
    ";
    
    $params = [$selectedMonth, $selectedYear];
    
    if (!empty($selectedShop)) {
        $sql .= " AND es.shop_id = ?";
        $params[] = $selectedShop;
    }
    
    $sql .= " ORDER BY s.name, e.full_name";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $_SESSION["error_message"] = "Error fetching employees: " . $e->getMessage();
    $employees = [];
}

?>

<?php include 'layouts/head-main.php'; ?>

<head>
    <title><?php echo __('batch_evaluations'); ?> | <?php echo __('employee_manager_system'); ?></title>
    <?php include 'layouts/head.php'; ?>
    
    <!-- DataTables CSS -->
    <link href="assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/libs/datatables.net-buttons-bs4/css/buttons.bootstrap4.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/libs/datatables.net-fixedheader-bs4/css/fixedHeader.bootstrap4.min.css" rel="stylesheet" type="text/css" />
    
    <?php include 'layouts/head-style.php'; ?>
    
    <style>
        .input-score {
            width: 40px;
            text-align: center;
            padding: 0.2rem;
            font-size: 0.9rem;
        }
        
        .score-cell {
            width: 50px;
            text-align: center;
            padding: 0.5rem 0.25rem;
        }
        
        .highlight-row {
            background-color: #f8f9fa;
        }
        
        .table-fixed-header {
            position: sticky;
            top: 0;
            background-color: #fff;
            z-index: 2;
            box-shadow: 0 2px 2px -1px rgba(0,0,0,0.1);
        }
        
        /* Score cell colors */
        .score-1, .score-2, .score-3 { color: #dc3545; }
        .score-4, .score-5 { color: #ffc107; }
        .score-6, .score-7 { color: #0d6efd; }
        .score-8, .score-9, .score-10 { color: #198754; }
        
        /* Card scroll styles */
        .card-scroll {
            max-height: 70vh;
            overflow-y: auto;
            overflow-x: hidden;
        }
        
        .employee-name-cell {
            min-width: 150px;
            max-width: 180px;
        }
        
        .position-cell, .shop-cell {
            min-width: 90px;
            max-width: 110px;
        }
        
        /* Table styles */
        #evaluations-table th, 
        #evaluations-table td {
            white-space: nowrap;
            vertical-align: middle;
        }
        
        #evaluations-table {
            width: 100% !important;
        }

        /* Datatables search styles */
        .dataTables_filter {
            margin-bottom: 15px;
        }
        
        /* Make the table horizontal scroll on mobile */
        @media (max-width: 992px) {
            .table-responsive {
                overflow-x: auto;
            }
        }

        /* Sticky header styling */
        .table-responsive {
            max-height: 600px;
            overflow-y: auto;
            position: relative;
        }

        #evaluations-table thead th {
            background-color: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
            position: sticky;
            top: 0;
            z-index: 5;
        }

        #evaluations-table {
            margin-bottom: 0 !important;
        }

        .card-body.p-0 .alert {
            margin: 1rem;
        }

        .table-hover tbody tr:hover {
            background-color: rgba(0,0,0,.05);
        }

        /* Custom styles for the DataTable */
        .table-responsive {
            max-height: 600px;
            overflow-y: auto;
            position: relative;
        }

        #evaluations-table thead th {
            background-color: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
            position: sticky;
            top: 0;
            z-index: 5;
        }

        #evaluations-table {
            margin-bottom: 0 !important;
        }

        .card-body.p-0 .alert {
            margin: 1rem;
        }

        .highlight-row {
            background-color: rgba(0, 0, 0, 0.03);
        }

        .table-hover tbody tr:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }

        /* Make sure inputs are visible above the sticky header */
        input.form-control, select.form-select {
            position: relative;
            z-index: 1;
        }

        /* Score cells styling */
        .score-cell {
            width: 70px;
            text-align: center;
        }

        .employee-name-cell {
            min-width: 160px;
        }

        .position-cell, .shop-cell {
            min-width: 120px;
        }
    </style>
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
                            <h4 class="mb-0 font-size-18"><?php echo __('batch_evaluations'); ?></h4>

                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php"><?php echo __('dashboard'); ?></a></li>
                                    <li class="breadcrumb-item"><a href="employee-evaluations.php"><?php echo __('evaluations'); ?></a></li>
                                    <li class="breadcrumb-item active"><?php echo __('batch_evaluations'); ?></li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <?php if (isset($_SESSION['success_message']) || isset($_SESSION['error_message'])): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-<?php echo isset($_SESSION['success_message']) ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
                            <?php 
                            echo isset($_SESSION['success_message']) ? $_SESSION['success_message'] : $_SESSION['error_message']; 
                            // Clear the messages
                            unset($_SESSION['success_message']);
                            unset($_SESSION['error_message']);
                            ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Filter section -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-8">
                                        <h4 class="card-title mb-4"><?php echo __('filter_employees'); ?></h4>
                                        
                                        <form method="GET" action="batch-evaluations.php" class="row g-3 align-items-end">
                                            <div class="col-md-3">
                                                <label for="month" class="form-label"><?php echo __('month'); ?></label>
                                                <select class="form-select" id="month" name="month">
                                                    <?php if (empty($openMonths)): ?>
                                                        <!-- If no open months, show all months but disable them -->
                                                        <?php 
                                                        $currentMonthNum = date('n');
                                                        $currentYear = date('Y');
                                                        
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
                                            
                                            <div class="col-md-3">
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
                                                <button type="submit" class="btn btn-primary w-100"><?php echo __('apply_filters'); ?></button>
                                            </div>
                                        </form>
                                    </div>
                                    <div class="col-md-4 d-flex align-items-end">
                                        <div class="w-100">
                                            <a href="employee-evaluations.php?month=<?php echo $selectedMonth; ?>&year=<?php echo $selectedYear; ?><?php echo $selectedShop ? "&shop_id=$selectedShop" : ""; ?>" class="btn btn-info w-100">
                                                <i class="bx bx-user me-1"></i> <?php echo __('switch_to_individual_evaluations'); ?>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Main content -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <h4 class="card-title">
                                            <?php echo sprintf(__('batch_evaluations_for'), date('F', mktime(0, 0, 0, $selectedMonth, 1)), $selectedYear); ?>
                                        </h4>
                                    </div>
                                    <div class="col text-end">
                                        <div class="d-flex justify-content-end align-items-center">
                                            <div class="btn-group me-3">
                                                <button type="button" class="btn btn-success btn-sm" id="exportTemplate">
                                                    <i class="bx bx-download me-1"></i> <?php echo __('download_template'); ?>
                                                </button>
                                                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#importModal">
                                                    <i class="bx bx-upload me-1"></i> <?php echo __('import_evaluations'); ?>
                                                </button>
                                            </div>
                                            <?php if (!empty($ranges)): ?>
                                                <div class="me-3">
                                                    <?php foreach ($ranges as $range): ?>
                                                        <?php 
                                                        $badgeClass = "";
                                                        if ($range['min_value'] >= 8) {
                                                            $badgeClass = "bg-success";
                                                        } elseif ($range['min_value'] >= 6) {
                                                            $badgeClass = "bg-primary";
                                                        } elseif ($range['min_value'] >= 4) {
                                                            $badgeClass = "bg-warning";
                                                        } else {
                                                            $badgeClass = "bg-danger";
                                                        }
                                                        ?>
                                                        <span class="badge <?php echo $badgeClass; ?> mx-1">
                                                            <?php echo $range['min_value'] . '-' . $range['max_value']; ?>
                                                        </span>
                                                        <span class="me-2">$<?php echo number_format($range['amount'], 2); ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (empty($selectedMonth) || empty($selectedYear)): ?>
                                    <div class="alert alert-info">
                                        <?php echo __('select_month_year_for_evaluations'); ?>
                                    </div>
                                <?php else: ?>
                                    <form method="POST" action="batch-evaluations.php" id="batch-evaluation-form">
                                        <input type="hidden" name="action" value="batch_evaluate">
                                        <input type="hidden" name="evaluation_month" value="<?php echo $selectedMonth; ?>">
                                        <input type="hidden" name="evaluation_year" value="<?php echo $selectedYear; ?>">
                                        
                                        <div class="table-responsive" style="max-height: 600px; overflow-y: auto; position: relative;">
                                            <table id="evaluations-table" class="table table-bordered table-hover table-sm m-0">
                                                <thead>
                                                    <tr class="bg-light">
                                                        <th class="employee-name-cell"><?php echo __('employee'); ?></th>
                                                        <th class="position-cell"><?php echo __('position'); ?></th>
                                                        <th class="position-cell">Reference</th>
                                                        <th class="shop-cell"><?php echo __('shop'); ?></th>
                                                        <th class="score-cell"><?php echo __('attendance_abbr'); ?></th>
                                                        <th class="score-cell"><?php echo __('cleanliness_abbr'); ?></th>
                                                        <th class="score-cell"><?php echo __('unloading_abbr'); ?></th>
                                                        <th class="score-cell"><?php echo __('sales_abbr'); ?></th>
                                                        <th class="score-cell"><?php echo __('order_management_abbr'); ?></th>
                                                        <th class="score-cell"><?php echo __('stock_sheet_abbr'); ?></th>
                                                        <th class="score-cell"><?php echo __('inventory_abbr'); ?></th>
                                                        <th class="score-cell"><?php echo __('team_abbr'); ?></th>
                                                        <th class="score-cell"><?php echo __('management_abbr'); ?></th>
                                                        <th class="score-cell"><?php echo __('ranking_abbr'); ?></th>
                                                        <th class="score-cell"><?php echo __('total'); ?></th>
                                                        <th class="score-cell"><?php echo __('bonus'); ?> ($)</th>
                                                        <th class="score-cell"><?php echo __('status'); ?></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php $rowIndex = 0; ?>
                                                    <?php foreach ($employees as $employee): ?>
                                                    <?php $rowIndex++; ?>
                                                    <tr class="<?php echo $rowIndex % 2 === 0 ? 'highlight-row' : ''; ?>">
                                                        <td class="employee-name-cell">
                                                            <?php echo htmlspecialchars($employee['full_name']); ?>
                                                            <input type="hidden" name="employee_ids[]" value="<?php echo $employee['id']; ?>">
                                                        </td>
                                                        <td class="position-cell"><?php echo htmlspecialchars($employee['position'] ?? 'N/A'); ?></td>
                                                        <td class="position-cell"><?php echo htmlspecialchars($employee['reference'] ?? 'N/A'); ?></td>
                                                        <td class="shop-cell"><?php echo htmlspecialchars($employee['shop_name'] ?? 'N/A'); ?></td>
                                                        
                                                        <!-- Attendance -->
                                                        <td class="score-cell">
                                                            <input type="number" class="form-control input-score score-input" 
                                                                   name="evaluations[<?php echo $employee['id']; ?>][attendance]" 
                                                                   min="0" max="10" step="1" 
                                                                   value="<?php echo isset($employee['attendance']) ? $employee['attendance'] : ''; ?>"
                                                                   data-employee-id="<?php echo $employee['id']; ?>"
                                                                   data-field="attendance">
                                                        </td>
                                                        
                                                        <!-- Cleanliness -->
                                                        <td class="score-cell">
                                                            <input type="number" class="form-control input-score score-input" 
                                                                   name="evaluations[<?php echo $employee['id']; ?>][cleanliness]" 
                                                                   min="0" max="10" step="1" 
                                                                   value="<?php echo isset($employee['cleanliness']) ? $employee['cleanliness'] : ''; ?>"
                                                                   data-employee-id="<?php echo $employee['id']; ?>"
                                                                   data-field="cleanliness">
                                                        </td>
                                                        
                                                        <!-- Unloading -->
                                                        <td class="score-cell">
                                                            <input type="number" class="form-control input-score score-input" 
                                                                   name="evaluations[<?php echo $employee['id']; ?>][unloading]" 
                                                                   min="0" max="10" step="1" 
                                                                   value="<?php echo isset($employee['unloading']) ? $employee['unloading'] : ''; ?>"
                                                                   data-employee-id="<?php echo $employee['id']; ?>"
                                                                   data-field="unloading">
                                                        </td>
                                                        
                                                        <!-- Sales -->
                                                        <td class="score-cell">
                                                            <input type="number" class="form-control input-score score-input" 
                                                                   name="evaluations[<?php echo $employee['id']; ?>][sales]" 
                                                                   min="0" max="10" step="1" 
                                                                   value="<?php echo isset($employee['sales']) ? $employee['sales'] : ''; ?>"
                                                                   data-employee-id="<?php echo $employee['id']; ?>"
                                                                   data-field="sales">
                                                        </td>
                                                        
                                                        <!-- Order Management -->
                                                        <td class="score-cell">
                                                            <input type="number" class="form-control input-score score-input" 
                                                                   name="evaluations[<?php echo $employee['id']; ?>][order_management]" 
                                                                   min="0" max="10" step="1" 
                                                                   value="<?php echo isset($employee['order_management']) ? $employee['order_management'] : ''; ?>"
                                                                   data-employee-id="<?php echo $employee['id']; ?>"
                                                                   data-field="order_management">
                                                        </td>
                                                        
                                                        <!-- Stock Sheet -->
                                                        <td class="score-cell">
                                                            <input type="number" class="form-control input-score score-input" 
                                                                   name="evaluations[<?php echo $employee['id']; ?>][stock_sheet]" 
                                                                   min="0" max="10" step="1" 
                                                                   value="<?php echo isset($employee['stock_sheet']) ? $employee['stock_sheet'] : ''; ?>"
                                                                   data-employee-id="<?php echo $employee['id']; ?>"
                                                                   data-field="stock_sheet">
                                                        </td>
                                                        
                                                        <!-- Inventory -->
                                                        <td class="score-cell">
                                                            <input type="number" class="form-control input-score score-input" 
                                                                   name="evaluations[<?php echo $employee['id']; ?>][inventory]" 
                                                                   min="0" max="10" step="1" 
                                                                   value="<?php echo isset($employee['inventory']) ? $employee['inventory'] : ''; ?>"
                                                                   data-employee-id="<?php echo $employee['id']; ?>"
                                                                   data-field="inventory">
                                                        </td>
                                                        
                                                        <!-- Machine and Team -->
                                                        <td class="score-cell">
                                                            <input type="number" class="form-control input-score score-input" 
                                                                   name="evaluations[<?php echo $employee['id']; ?>][machine_and_team]" 
                                                                   min="0" max="10" step="1" 
                                                                   value="<?php echo isset($employee['machine_and_team']) ? $employee['machine_and_team'] : ''; ?>"
                                                                   data-employee-id="<?php echo $employee['id']; ?>"
                                                                   data-field="machine_and_team">
                                                        </td>
                                                        
                                                        <!-- Management -->
                                                        <td class="score-cell">
                                                            <input type="number" class="form-control input-score score-input" 
                                                                   name="evaluations[<?php echo $employee['id']; ?>][management]" 
                                                                   min="0" max="10" step="1" 
                                                                   value="<?php echo isset($employee['management']) ? $employee['management'] : ''; ?>"
                                                                   data-employee-id="<?php echo $employee['id']; ?>"
                                                                   data-field="management">
                                                        </td>
                                                        
                                                        <!-- Ranking -->
                                                        <td class="score-cell">
                                                            <input type="number" class="form-control input-score score-input" 
                                                                   name="evaluations[<?php echo $employee['id']; ?>][ranking]" 
                                                                   min="0" max="10" step="1" 
                                                                   value="<?php echo isset($employee['ranking']) ? $employee['ranking'] : ''; ?>"
                                                                   data-employee-id="<?php echo $employee['id']; ?>"
                                                                   data-field="ranking">
                                                        </td>
                                                        
                                                        <!-- Total Score (calculated automatically) -->
                                                        <td class="score-cell">
                                                            <div class="d-flex align-items-center justify-content-center">
                                                                <span id="total-<?php echo $employee['id']; ?>" class="total-score me-1" style="font-size: 0.85rem;">
                                                                    <?php 
                                                                        if (isset($employee['total_score'])) {
                                                                            echo $employee['total_score'];
                                                                        } else {
                                                                            echo '-';
                                                                        }
                                                                    ?>
                                                                </span>
                                                            </div>
                                                        </td>
                                                        
                                                        <!-- Bonus Amount (can be manually edited) -->
                                                        <td class="score-cell">
                                                            <input type="number" class="form-control input-score bonus-input" 
                                                                   name="evaluations[<?php echo $employee['id']; ?>][bonus_amount]" 
                                                                   min="0" step="0.01" 
                                                                   data-employee-id="<?php echo $employee['id']; ?>"
                                                                   data-field="bonus_amount"
                                                                   placeholder="Auto"
                                                                   title="<?php echo __('leave_empty_for_auto'); ?>">
                                                            <span id="auto-bonus-<?php echo $employee['id']; ?>" class="auto-bonus d-none"></span>
                                                        </td>
                                                        
                                                        <!-- Status -->
                                                        <td class="text-center">
                                                            <?php if ($employee['evaluation_id']): ?>
                                                                <span class="badge bg-success"><?php echo __('evaluated'); ?></span>
                                                            <?php else: ?>
                                                                <span class="badge bg-warning"><?php echo __('pending'); ?></span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        
                                        <div class="mt-4 d-flex justify-content-end">
                                            <button type="submit" class="btn btn-primary" id="save-all-btn">
                                                <i class="bx bx-save me-1"></i> <?php echo __('save_all_evaluations'); ?>
                                            </button>
                                        </div>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

            </div> <!-- container-fluid -->
        </div>
        <!-- End Page-content -->

        <?php include 'layouts/footer.php'; ?>
    </div>
    <!-- end main content-->

</div>
<!-- END layout-wrapper -->

<?php include 'layouts/right-sidebar.php'; ?>
<?php include 'layouts/vendor-scripts.php'; ?>

<!-- Required libraries -->
<script src="assets/libs/datatables.net/js/jquery.dataTables.min.js"></script>
<script src="assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js"></script>
<script src="assets/libs/datatables.net-fixedheader/js/dataTables.fixedHeader.min.js"></script>

<!-- Responsive examples -->
<script src="assets/libs/datatables.net-responsive/js/dataTables.responsive.min.js"></script>
<script src="assets/libs/datatables.net-responsive-bs4/js/responsive.bootstrap4.min.js"></script>

<script>
    $(document).ready(function() {
        // Handle month selection to update year
        $('#month').on('change', function() {
            var selectedOption = $(this).find('option:selected');
            var year = selectedOption.data('year');
            
            if (year) {
                $('#year').val(year);
            }
        });
        
        // Handle form submission to include the year
        $('form[action="batch-evaluations.php"]').on('submit', function() {
            // Ensure the form submits with the correct year/month combination
            var selectedMonth = $('#month').val();
            var selectedYear = $('#year').val();
            
            // Update hidden inputs
            $('input[name="evaluation_month"]').val(selectedMonth);
            $('input[name="evaluation_year"]').val(selectedYear);
        });
        
        // Initialize DataTable
        var batchTable = $('#evaluations-table').DataTable({
            paging: false,
            scrollY: '500px',
            scrollCollapse: true,
            fixedHeader: true,
            order: [[0, 'asc']],
            language: {
                search: '<?php echo __("search"); ?>:',
                info: '<?php echo __("showing"); ?> _START_ <?php echo __("to"); ?> _END_ <?php echo __("of"); ?> _TOTAL_ <?php echo __("entries"); ?>'
            },
            columnDefs: [
                { targets: -1, orderable: false } // Disable ordering on the last column (actions)
            ]
        });
        
        // Handle score calculation
        $('.score-input').on('input', function() {
            calculateTotalScore($(this).data('employee-id'));
        });
        
        // Initialize total scores
        $('[data-employee-id]').each(function() {
            var employeeId = $(this).data('employee-id');
            if (employeeId) {
                calculateTotalScore(employeeId);
            }
        });
        
        // Calculate total score for an employee
        function calculateTotalScore(employeeId) {
            var total = 0;
            
            // Add up all scores except ranking and bonus_amount
            $('input[data-employee-id="' + employeeId + '"]').each(function() {
                if ($(this).data('field') !== 'ranking' && $(this).data('field') !== 'bonus_amount') {
                    var val = parseInt($(this).val()) || 0;
                    total += val;
                }
            });
            
            // Update total display
            $('#total-' + employeeId).text(total);
            
            // Calculate and update bonus amount
            calculateBonusAmount(employeeId, total);
            
            // Update status based on total
            if (total > 0) {
                $('#status-' + employeeId).html('<span class="badge bg-success"><?php echo __("evaluated"); ?></span>');
            } else {
                $('#status-' + employeeId).html('<span class="badge bg-warning"><?php echo __("pending"); ?></span>');
            }
        }
        
        // Calculate the automatic bonus amount based on the total score
        function calculateBonusAmount(employeeId, totalScore) {
            // Get the ranges from PHP
            var ranges = <?php echo json_encode($ranges); ?>;
            var bonus = 0;
            
            // Find matching range and get the amount
            for (var i = 0; i < ranges.length; i++) {
                var range = ranges[i];
                if (totalScore >= range.min_value && totalScore <= range.max_value) {
                    bonus = range.amount;
                    break;
                }
            }
            
            // Store the automatic bonus for reference
            $('#auto-bonus-' + employeeId).text(bonus.toFixed(2));
            
            // Update placeholder to show the automatic amount
            $('input[data-employee-id="' + employeeId + '"][data-field="bonus_amount"]')
                .attr('placeholder', bonus.toFixed(2));
            
            return bonus;
        }
    });
</script>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1" aria-labelledby="importModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="importModalLabel"><?php echo __('import_evaluations'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="ajax-handlers/import-evaluations.php" method="post" enctype="multipart/form-data" id="importForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="excelFile" class="form-label"><?php echo __('select_excel_file'); ?></label>
                        <input type="file" class="form-control" id="excelFile" name="excelFile" accept=".xlsx, .xls" required>
                    </div>
                    <input type="hidden" name="month" value="<?php echo $selectedMonth; ?>">
                    <input type="hidden" name="year" value="<?php echo $selectedYear; ?>">
                    <div class="alert alert-info">
                        <?php echo __('import_instructions'); ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo __('import'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Export script -->
<script>
    $(document).ready(function() {
        // Handle export template button click
        $('#exportTemplate').on('click', function() {
            // Get current month and year
            var month = <?php echo $selectedMonth; ?>;
            var year = <?php echo $selectedYear; ?>;
            var shopId = '<?php echo $selectedShop; ?>';
            
            // Redirect to export handler
            window.location.href = 'ajax-handlers/export-evaluations-template.php?month=' + month + '&year=' + year + '&shop_id=' + shopId;
        });
        
        // Handle import form submission
        $('#importForm').on('submit', function(e) {
            e.preventDefault();
            
            var formData = new FormData(this);
            
            $.ajax({
                url: $(this).attr('action'),
                type: 'POST',
                data: formData,
                contentType: false,
                processData: false,
                success: function(response) {
                    try {
                        var result = JSON.parse(response);
                        if (result.success) {
                            // Close modal
                            $('#importModal').modal('hide');
                            
                            // Show success message
                            alert(result.message);
                            
                            // Reload page to reflect changes
                            location.reload();
                        } else {
                            // Show error message
                            alert(result.message);
                        }
                    } catch (e) {
                        alert('<?php echo __("import_error"); ?>');
                        console.error(response);
                    }
                },
                error: function() {
                    alert('<?php echo __("import_error"); ?>');
                }
            });
        });
    });
</script>

<?php include 'layouts/footer.php'; ?>
</div>
<!-- end main content-->

</div>
<!-- END layout-wrapper -->

<?php include 'layouts/right-sidebar.php'; ?>
<?php include 'layouts/vendor-scripts.php'; ?>
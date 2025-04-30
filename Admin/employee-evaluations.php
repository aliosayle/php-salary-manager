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

// Check if the user has permission to access this page
requirePermission('manage_employees');

// Get current month/year for default filter values
$currentMonth = date('m');
$currentYear = date('Y');
$selectedMonth = isset($_GET['month']) ? intval($_GET['month']) : intval($currentMonth);
$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : intval($currentYear);
$selectedShop = isset($_GET['shop_id']) ? $_GET['shop_id'] : '';

// Fetch all score ranges from the total_ranges table
try {
    $ranges = [];
    $rangesStmt = $pdo->query("SELECT * FROM total_ranges ORDER BY min_value ASC");
    $ranges = $rangesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_SESSION["error_message"] = "Error fetching score ranges: " . $e->getMessage();
}

// Process evaluation submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["action"]) && $_POST["action"] == "evaluate") {
    $employeeId = $_POST["employee_id"];
    $month = $_POST["evaluation_month"];
    $year = $_POST["evaluation_year"];
    $attendance = $_POST["attendance"];
    $cleanliness = $_POST["cleanliness"];
    $unloading = $_POST["unloading"];
    $sales = $_POST["sales"];
    $order_management = $_POST["order_management"];
    $stock_sheet = $_POST["stock_sheet"];
    $inventory = $_POST["inventory"];
    $machine_and_team = $_POST["machine_and_team"];
    $management = $_POST["management"];
    $ranking = $_POST["ranking"];
    $comments = $_POST["comments"] ?? '';
    
    // Fix for empty bonus amount - convert empty strings to null or 0
    $bonusAmount = (!empty($_POST["bonus_amount"]) || $_POST["bonus_amount"] === '0') 
                  ? floatval($_POST["bonus_amount"]) 
                  : 0;
    
    // Calculate total score (sum of all metrics except ranking)
    $totalScore = $attendance + $cleanliness + $unloading + $sales + 
                  $order_management + $stock_sheet + $inventory + $machine_and_team + $management;
    
    try {
        // First, verify the employee exists
        $checkEmployeeStmt = $pdo->prepare("SELECT id FROM employees WHERE id = ?");
        $checkEmployeeStmt->execute([$employeeId]);
        
        if ($checkEmployeeStmt->rowCount() === 0) {
            $_SESSION["error_message"] = "Error: Employee not found. Please try again.";
            header("location: employee-evaluations.php?month=$month&year=$year" . ($selectedShop ? "&shop_id=$selectedShop" : ""));
            exit;
        }
        
        // Calculate the bonus amount based on the total score
        if ($bonusAmount == 0) {
            foreach ($ranges as $range) {
                if ($totalScore >= $range['min_value'] && $totalScore <= $range['max_value']) {
                    $bonusAmount = $range['amount'];
                    break;
                }
            }
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
            
            $_SESSION["success_message"] = "Evaluation updated successfully for " . getEmployeeName($pdo, $employeeId);
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
            
            $_SESSION["success_message"] = "Evaluation added successfully for " . getEmployeeName($pdo, $employeeId);
        }
        
        // Redirect to refresh the page
        header("location: employee-evaluations.php?month=$month&year=$year" . ($selectedShop ? "&shop_id=$selectedShop" : ""));
        exit;
        
    } catch (PDOException $e) {
        $_SESSION["error_message"] = "Error saving evaluation: " . $e->getMessage();
    }
}

// Get list of shops for the filter
$shopsStmt = $pdo->query("SELECT id, name FROM shops ORDER BY name");
$shops = $shopsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch employees based on filters
try {
    $sql = "
        SELECT e.id, e.full_name, e.reference, p.title as position, s.name as shop_name, 
            ev.total_score, ev.ranking, ev.id as evaluation_id
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

// Helper function to get employee name
function getEmployeeName($pdo, $employeeId) {
    $stmt = $pdo->prepare("SELECT full_name FROM employees WHERE id = ?");
    $stmt->execute([$employeeId]);
    return $stmt->fetchColumn();
}

// Helper function to get rating color class
function getRatingColorClass($rating) {
    if ($rating === null) return '';
    if ($rating >= 8) return 'text-success';
    if ($rating >= 6) return 'text-primary';
    if ($rating >= 4) return 'text-warning';
    return 'text-danger';
}

// Helper function to get rating badge class
function getRatingBadgeClass($rating) {
    if ($rating === null) return 'bg-secondary';
    if ($rating >= 8) return 'bg-success';
    if ($rating >= 6) return 'bg-primary';
    if ($rating >= 4) return 'bg-warning';
    return 'bg-danger';
}

// Get rating amount based on total score
function getRatingAmount($pdo, $totalScore) {
    try {
        $stmt = $pdo->prepare("
            SELECT amount 
            FROM total_ranges 
            WHERE ? BETWEEN min_value AND max_value 
            LIMIT 1
        ");
        $stmt->execute([$totalScore]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ? $result['amount'] : 0;
    } catch (PDOException $e) {
        return 0;
    }
}
?>

<?php include 'layouts/head-main.php'; ?>

<head>
    <title>Employee Evaluations | Employee Manager System</title>
    <?php include 'layouts/head.php'; ?>
    
    <!-- DataTables CSS -->
    <link href="assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/libs/datatables.net-buttons-bs4/css/buttons.bootstrap4.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css" rel="stylesheet" type="text/css" />
    
    <?php include 'layouts/head-style.php'; ?>
    
    <style>
        /* Add some enhanced styling for the native select dropdown */
        select.form-select {
            padding: 0.47rem 1.75rem 0.47rem 0.75rem;
            font-size: 0.8125rem;
            font-weight: 400;
            line-height: 1.5;
            color: #495057;
            background-color: #fff;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23343a40' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M2 5l6 6 6-6'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 16px 12px;
            border: 1px solid #ced4da;
            border-radius: 0.25rem;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
        }
        
        select.form-select:focus {
            border-color: #86b7fe;
            outline: 0;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }
        
        /* Other existing styles */
        .rating-star {
            color: #ffb822;
        }
        .rating-input {
            display: none;
        }
        .rating-label {
            cursor: pointer;
            font-size: 1.5rem;
            color: #ccc;
        }
        .rating-label.selected, .rating-label:hover, .rating-label:hover ~ .rating-label {
            color: #ffb822;
        }
        .rating-group {
            display: flex;
            flex-direction: row-reverse;
            justify-content: flex-end;
        }
        .evaluation-badge {
            font-size: 0.85rem;
            font-weight: bold;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        /* New professional rating styles */
        .rating-slider-container {
            padding: 10px 5px;
        }
        
        .rating-slider {
            width: 100%;
            height: 6px;
            appearance: none;
            border-radius: 5px;
            background: #ddd;
            outline: none;
        }
        
        .rating-slider::-webkit-slider-thumb {
            appearance: none;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: #2c6dd5;
            cursor: pointer;
            transition: background .15s ease-in-out;
        }
        
        .rating-slider::-moz-range-thumb {
            width: 18px;
            height: 18px;
            border: 0;
            border-radius: 50%;
            background: #2c6dd5;
            cursor: pointer;
            transition: background .15s ease-in-out;
        }
        
        .rating-slider::-webkit-slider-thumb:hover {
            background: #1a4fa0;
        }
        
        .rating-slider::-moz-range-thumb:hover {
            background: #1a4fa0;
        }
        
        .rating-value {
            font-size: 1.5rem;
            font-weight: bold;
            margin-bottom: 5px;
            text-align: center;
        }
        
        .rating-label-display {
            color: #555;
            font-size: 0.8rem;
            text-align: center;
        }
        
        .rating-scale {
            display: flex;
            justify-content: space-between;
            padding: 0;
            margin-top: 5px;
            font-size: 0.7rem;
            color: #777;
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
                            <h4 class="mb-0 font-size-18"><?php echo __('employee_evaluations'); ?></h4>

                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php"><?php echo __('dashboard'); ?></a></li>
                                    <li class="breadcrumb-item active"><?php echo __('employee_evaluations'); ?></li>
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
                                        <h4 class="card-title mb-4"><?php echo __('filter_evaluations'); ?></h4>
                                        
                                        <form method="GET" action="employee-evaluations.php" class="row g-3 align-items-end">
                                            <div class="col-md-3">
                                                <label for="month" class="form-label"><?php echo __('month'); ?></label>
                                                <select class="form-select" id="month" name="month">
                                                    <?php 
                                                    $currentMonthNum = date('n'); // Current month as a number (1-12)
                                                    $currentYear = date('Y'); // Current year
                                                    
                                                    for ($i = 1; $i <= 12; $i++): 
                                                        // Disable future months in current year
                                                        $isDisabled = ($selectedYear == $currentYear && $i > $currentMonthNum);
                                                    ?>
                                                        <option value="<?php echo $i; ?>" 
                                                                <?php echo $i == $selectedMonth ? 'selected' : ''; ?>
                                                                <?php echo $isDisabled ? 'disabled' : ''; ?>>
                                                            <?php echo date('F', mktime(0, 0, 0, $i, 1)); ?>
                                                        </option>
                                                    <?php endfor; ?>
                                                </select>
                                            </div>
                                            
                                            <div class="col-md-3">
                                                <label for="year" class="form-label"><?php echo __('year'); ?></label>
                                                <select class="form-select" id="year" name="year">
                                                    <?php 
                                                    $startYear = 2020;
                                                    $endYear = date('Y'); // Current year is the max
                                                    for ($i = $endYear; $i >= $startYear; $i--): 
                                                    ?>
                                                        <option value="<?php echo $i; ?>" <?php echo $i == $selectedYear ? 'selected' : ''; ?>>
                                                            <?php echo $i; ?>
                                                        </option>
                                                    <?php endfor; ?>
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
                                            <a href="batch-evaluations.php?month=<?php echo $selectedMonth; ?>&year=<?php echo $selectedYear; ?><?php echo $selectedShop ? "&shop_id=$selectedShop" : ""; ?>" class="btn btn-info w-100">
                                                <i class="bx bx-grid me-1"></i> <?php echo __('switch_to_batch_evaluations'); ?>
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
                                <div class="d-flex justify-content-between align-items-center">
                                    <h4 class="card-title">
                                        <?php echo sprintf(__('employee_evaluations_for'), date('F', mktime(0, 0, 0, $selectedMonth, 1)), $selectedYear); ?>
                                    </h4>
                                    <div class="d-flex align-items-center">
                                        <div class="me-3">
                                            <?php if (!empty($ranges)): ?>
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
                                            <?php else: ?>
                                                <span class="badge bg-success me-1">8-10</span> <?php echo __('rating_excellent'); ?>
                                                <span class="badge bg-primary mx-1">6-7.9</span> <?php echo __('rating_good'); ?>
                                                <span class="badge bg-warning mx-1">4-5.9</span> <?php echo __('rating_needs_improvement'); ?>
                                                <span class="badge bg-danger mx-1">0-3.9</span> <?php echo __('rating_poor'); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table id="evaluations-table" class="table table-bordered table-hover">
                                        <thead>
                                            <tr>
                                                <th><?php echo __('employee'); ?></th>
                                                <th><?php echo __('position'); ?></th>
                                                <th>Reference</th>
                                                <th><?php echo __('shop'); ?></th>
                                                <th><?php echo __('status'); ?></th>
                                                <th><?php echo __('rating'); ?></th>
                                                <th><?php echo __('actions'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($employees as $employee): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($employee['full_name']); ?></td>
                                                <td><?php echo htmlspecialchars($employee['position'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($employee['reference'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($employee['shop_name'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <?php if ($employee['evaluation_id']): ?>
                                                        <span class="badge bg-success"><?php echo __('evaluated'); ?></span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning"><?php echo __('pending'); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($employee['total_score']): ?>
                                                        <div class="d-flex align-items-center justify-content-center">
                                                            <div class="evaluation-badge <?php echo getRatingBadgeClass($employee['total_score'] / 10); ?> me-2" style="font-size: 0.85rem;">
                                                                <?php echo $employee['total_score'] / 10; ?>
                                                            </div>
                                                            <?php
                                                            // Find and display the bonus amount
                                                            $bonus = 0;
                                                            foreach ($ranges as $range) {
                                                                if ($employee['total_score'] / 10 >= $range['min_value'] && $employee['total_score'] / 10 <= $range['max_value']) {
                                                                    $bonus = $range['amount'];
                                                                    break;
                                                                }
                                                            }
                                                            ?>
                                                            <span class="badge bg-info" style="font-size: 0.75rem;">$<?php echo number_format($bonus, 2); ?></span>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary"><?php echo __('not_available'); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-primary evaluate-btn" 
                                                            data-id="<?php echo $employee['id']; ?>"
                                                            data-name="<?php echo htmlspecialchars($employee['full_name']); ?>"
                                                            data-evaluation-id="<?php echo $employee['evaluation_id']; ?>"
                                                            data-bs-toggle="modal" data-bs-target="#evaluateModal">
                                                        <?php echo $employee['evaluation_id'] ? __('update') : __('evaluate'); ?>
                                                    </button>
                                                    
                                                    <?php if ($employee['evaluation_id']): ?>
                                                    <button type="button" class="btn btn-sm btn-info view-btn" 
                                                            data-id="<?php echo $employee['evaluation_id']; ?>"
                                                            data-bs-toggle="modal" data-bs-target="#viewEvaluationModal">
                                                        <i class="bx bx-show"></i> <?php echo __('view'); ?>
                                                    </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
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

<!-- Evaluate Modal -->
<div class="modal fade" id="evaluateModal" tabindex="-1" aria-labelledby="evaluateModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="evaluateModalLabel"><?php echo __('evaluate_employee'); ?>: <span id="employee-name"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="employee-evaluations.php" id="evaluation-form">
                <input type="hidden" name="action" value="evaluate">
                <input type="hidden" name="employee_id" id="employee_id">
                <input type="hidden" name="evaluation_month" value="<?php echo $selectedMonth; ?>">
                <input type="hidden" name="evaluation_year" value="<?php echo $selectedYear; ?>">
                
                <div class="modal-body">
                    <div class="row mb-4">
                        <div class="col-md-12">
                            <div class="alert alert-info">
                                <i class="bx bx-info-circle me-2"></i>
                                <?php echo sprintf(__('evaluating_for_period'), '<strong>' . date('F', mktime(0, 0, 0, $selectedMonth, 1)) . ' ' . $selectedYear . '</strong>'); ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title"><?php echo __('category_attendance'); ?></h5>
                                    <p class="card-text text-muted mb-3"><?php echo __('category_attendance_desc'); ?></p>
                                    
                                    <div class="rating-slider-container">
                                        <div class="d-flex align-items-center justify-content-center mb-2">
                                            <div class="rating-value" id="attendance-value">5</div>
                                            <input type="number" class="form-control ms-2 rating-number" 
                                                   id="attendance-number" min="1" max="10" value="5" 
                                                   style="width: 60px; text-align: center;" 
                                                   data-slider-id="attendance-slider">
                                        </div>
                                        <input type="range" class="rating-slider" id="attendance-slider" name="attendance" min="1" max="10" value="5" required>
                                        <div class="rating-scale">
                                            <span><?php echo __('rating_poor'); ?></span>
                                            <span><?php echo __('rating_fair'); ?></span>
                                            <span><?php echo __('rating_good'); ?></span>
                                            <span><?php echo __('rating_very_good'); ?></span>
                                            <span><?php echo __('rating_excellent'); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title"><?php echo __('category_cleanliness'); ?></h5>
                                    <p class="card-text text-muted mb-3"><?php echo __('category_cleanliness_desc'); ?></p>
                                    
                                    <div class="rating-slider-container">
                                        <div class="d-flex align-items-center justify-content-center mb-2">
                                            <div class="rating-value" id="cleanliness-value">5</div>
                                            <input type="number" class="form-control ms-2 rating-number" 
                                                   id="cleanliness-number" min="1" max="10" value="5" 
                                                   style="width: 60px; text-align: center;" 
                                                   data-slider-id="cleanliness-slider">
                                        </div>
                                        <input type="range" class="rating-slider" id="cleanliness-slider" name="cleanliness" min="1" max="10" value="5" required>
                                        <div class="rating-scale">
                                            <span><?php echo __('rating_poor'); ?></span>
                                            <span><?php echo __('rating_fair'); ?></span>
                                            <span><?php echo __('rating_good'); ?></span>
                                            <span><?php echo __('rating_very_good'); ?></span>
                                            <span><?php echo __('rating_excellent'); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title"><?php echo __('category_unloading'); ?></h5>
                                    <p class="card-text text-muted mb-3"><?php echo __('category_unloading_desc'); ?></p>
                                    
                                    <div class="rating-slider-container">
                                        <div class="d-flex align-items-center justify-content-center mb-2">
                                            <div class="rating-value" id="unloading-value">5</div>
                                            <input type="number" class="form-control ms-2 rating-number" 
                                                   id="unloading-number" min="1" max="10" value="5" 
                                                   style="width: 60px; text-align: center;" 
                                                   data-slider-id="unloading-slider">
                                        </div>
                                        <input type="range" class="rating-slider" id="unloading-slider" name="unloading" min="1" max="10" value="5" required>
                                        <div class="rating-scale">
                                            <span><?php echo __('rating_poor'); ?></span>
                                            <span><?php echo __('rating_fair'); ?></span>
                                            <span><?php echo __('rating_good'); ?></span>
                                            <span><?php echo __('rating_very_good'); ?></span>
                                            <span><?php echo __('rating_excellent'); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title"><?php echo __('category_sales'); ?></h5>
                                    <p class="card-text text-muted mb-3"><?php echo __('category_sales_desc'); ?></p>
                                    
                                    <div class="rating-slider-container">
                                        <div class="d-flex align-items-center justify-content-center mb-2">
                                            <div class="rating-value" id="sales-value">5</div>
                                            <input type="number" class="form-control ms-2 rating-number" 
                                                   id="sales-number" min="1" max="10" value="5" 
                                                   style="width: 60px; text-align: center;" 
                                                   data-slider-id="sales-slider">
                                        </div>
                                        <input type="range" class="rating-slider" id="sales-slider" name="sales" min="1" max="10" value="5" required>
                                        <div class="rating-scale">
                                            <span><?php echo __('rating_poor'); ?></span>
                                            <span><?php echo __('rating_fair'); ?></span>
                                            <span><?php echo __('rating_good'); ?></span>
                                            <span><?php echo __('rating_very_good'); ?></span>
                                            <span><?php echo __('rating_excellent'); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title"><?php echo __('category_order_management'); ?></h5>
                                    <p class="card-text text-muted mb-3"><?php echo __('category_order_management_desc'); ?></p>
                                    
                                    <div class="rating-slider-container">
                                        <div class="d-flex align-items-center justify-content-center mb-2">
                                            <div class="rating-value" id="order_management-value">5</div>
                                            <input type="number" class="form-control ms-2 rating-number" 
                                                   id="order_management-number" min="1" max="10" value="5" 
                                                   style="width: 60px; text-align: center;" 
                                                   data-slider-id="order_management-slider">
                                        </div>
                                        <input type="range" class="rating-slider" id="order_management-slider" name="order_management" min="1" max="10" value="5" required>
                                        <div class="rating-scale">
                                            <span><?php echo __('rating_poor'); ?></span>
                                            <span><?php echo __('rating_fair'); ?></span>
                                            <span><?php echo __('rating_good'); ?></span>
                                            <span><?php echo __('rating_very_good'); ?></span>
                                            <span><?php echo __('rating_excellent'); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title"><?php echo __('category_stock_sheet'); ?></h5>
                                    <p class="card-text text-muted mb-3"><?php echo __('category_stock_sheet_desc'); ?></p>
                                    
                                    <div class="rating-slider-container">
                                        <div class="d-flex align-items-center justify-content-center mb-2">
                                            <div class="rating-value" id="stock_sheet-value">5</div>
                                            <input type="number" class="form-control ms-2 rating-number" 
                                                   id="stock_sheet-number" min="1" max="10" value="5" 
                                                   style="width: 60px; text-align: center;" 
                                                   data-slider-id="stock_sheet-slider">
                                        </div>
                                        <input type="range" class="rating-slider" id="stock_sheet-slider" name="stock_sheet" min="1" max="10" value="5" required>
                                        <div class="rating-scale">
                                            <span><?php echo __('rating_poor'); ?></span>
                                            <span><?php echo __('rating_fair'); ?></span>
                                            <span><?php echo __('rating_good'); ?></span>
                                            <span><?php echo __('rating_very_good'); ?></span>
                                            <span><?php echo __('rating_excellent'); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title"><?php echo __('category_inventory'); ?></h5>
                                    <p class="card-text text-muted mb-3"><?php echo __('category_inventory_desc'); ?></p>
                                    
                                    <div class="rating-slider-container">
                                        <div class="d-flex align-items-center justify-content-center mb-2">
                                            <div class="rating-value" id="inventory-value">5</div>
                                            <input type="number" class="form-control ms-2 rating-number" 
                                                   id="inventory-number" min="1" max="10" value="5" 
                                                   style="width: 60px; text-align: center;" 
                                                   data-slider-id="inventory-slider">
                                        </div>
                                        <input type="range" class="rating-slider" id="inventory-slider" name="inventory" min="1" max="10" value="5" required>
                                        <div class="rating-scale">
                                            <span><?php echo __('rating_poor'); ?></span>
                                            <span><?php echo __('rating_fair'); ?></span>
                                            <span><?php echo __('rating_good'); ?></span>
                                            <span><?php echo __('rating_very_good'); ?></span>
                                            <span><?php echo __('rating_excellent'); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title"><?php echo __('category_machine_and_team'); ?></h5>
                                    <p class="card-text text-muted mb-3"><?php echo __('category_machine_and_team_desc'); ?></p>
                                    
                                    <div class="rating-slider-container">
                                        <div class="d-flex align-items-center justify-content-center mb-2">
                                            <div class="rating-value" id="machine_and_team-value">5</div>
                                            <input type="number" class="form-control ms-2 rating-number" 
                                                   id="machine_and_team-number" min="1" max="10" value="5" 
                                                   style="width: 60px; text-align: center;" 
                                                   data-slider-id="machine_and_team-slider">
                                        </div>
                                        <input type="range" class="rating-slider" id="machine_and_team-slider" name="machine_and_team" min="1" max="10" value="5" required>
                                        <div class="rating-scale">
                                            <span><?php echo __('rating_poor'); ?></span>
                                            <span><?php echo __('rating_fair'); ?></span>
                                            <span><?php echo __('rating_good'); ?></span>
                                            <span><?php echo __('rating_very_good'); ?></span>
                                            <span><?php echo __('rating_excellent'); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title"><?php echo __('category_management'); ?></h5>
                                    <p class="card-text text-muted mb-3"><?php echo __('category_management_desc'); ?></p>
                                    
                                    <div class="rating-slider-container">
                                        <div class="d-flex align-items-center justify-content-center mb-2">
                                            <div class="rating-value" id="management-value">5</div>
                                            <input type="number" class="form-control ms-2 rating-number" 
                                                   id="management-number" min="1" max="10" value="5" 
                                                   style="width: 60px; text-align: center;" 
                                                   data-slider-id="management-slider">
                                        </div>
                                        <input type="range" class="rating-slider" id="management-slider" name="management" min="1" max="10" value="5" required>
                                        <div class="rating-scale">
                                            <span><?php echo __('rating_poor'); ?></span>
                                            <span><?php echo __('rating_fair'); ?></span>
                                            <span><?php echo __('rating_good'); ?></span>
                                            <span><?php echo __('rating_very_good'); ?></span>
                                            <span><?php echo __('rating_excellent'); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title"><?php echo __('category_overall_ranking'); ?></h5>
                                    <p class="card-text text-muted mb-3"><?php echo __('category_overall_ranking_desc'); ?></p>
                                    
                                    <div class="rating-slider-container">
                                        <div class="d-flex align-items-center justify-content-center mb-2">
                                            <div class="rating-value" id="ranking-value">5</div>
                                            <input type="number" class="form-control ms-2 rating-number" 
                                                   id="ranking-number" min="1" max="10" value="5" 
                                                   style="width: 60px; text-align: center;" 
                                                   data-slider-id="ranking-slider">
                                        </div>
                                        <input type="range" class="rating-slider" id="ranking-slider" name="ranking" min="1" max="10" value="5" required>
                                        <div class="rating-scale">
                                            <span><?php echo __('rating_poor'); ?></span>
                                            <span><?php echo __('rating_fair'); ?></span>
                                            <span><?php echo __('rating_good'); ?></span>
                                            <span><?php echo __('rating_very_good'); ?></span>
                                            <span><?php echo __('rating_excellent'); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title"><?php echo __('bonus_amount'); ?> <i class="bx bx-info-circle" data-bs-toggle="tooltip" title="<?php echo __('bonus_amount_tooltip'); ?>"></i></h5>
                                    
                                    <div class="mb-3">
                                        <div class="d-flex align-items-center">
                                            <div id="calculated-bonus-container" class="me-3">
                                                <label class="form-label"><?php echo __('calculated_bonus'); ?></label>
                                                <div class="input-group">
                                                    <span class="input-group-text">$</span>
                                                    <input type="text" class="form-control" id="calculated-bonus" readonly>
                                                </div>
                                                <small class="text-muted"><?php echo __('automatically_calculated'); ?></small>
                                            </div>
                                            <div id="manual-bonus-container">
                                                <label for="bonus_amount" class="form-label"><?php echo __('manual_bonus'); ?></label>
                                                <div class="input-group">
                                                    <span class="input-group-text">$</span>
                                                    <input type="number" class="form-control" id="bonus_amount" name="bonus_amount" min="0" step="0.01">
                                                </div>
                                                <small class="text-muted"><?php echo __('leave_empty_for_auto'); ?></small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="comments" class="form-label"><?php echo __('comments_feedback'); ?></label>
                        <textarea class="form-control" id="comments" name="comments" rows="4" placeholder="<?php echo __('provide_detailed_feedback_and_recommendations_for_improvement'); ?>"></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo __('submit_evaluation'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Evaluation Modal -->
<div class="modal fade" id="viewEvaluationModal" tabindex="-1" aria-labelledby="viewEvaluationModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewEvaluationModalLabel"><?php echo __('evaluation_details'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="evaluation-details">
                    <div class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2"><?php echo __('loading_evaluation_details'); ?></p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('close'); ?></button>
            </div>
        </div>
    </div>
</div>

<?php include 'layouts/right-sidebar.php'; ?>

<?php include 'layouts/vendor-scripts.php'; ?>

<!-- Required libraries -->
<script src="assets/libs/datatables.net/js/jquery.dataTables.min.js"></script>
<script src="assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize DataTable
        if ($.fn.DataTable) {
            $('#evaluations-table').DataTable({
                responsive: true,
                lengthChange: false,
                pageLength: 25,
                language: {
                    search: "_INPUT_",
                    searchPlaceholder: "Search employee..."
                }
            });
        }
        
        // Add client-side validation for month/year
        const yearSelect = document.getElementById('year');
        const monthSelect = document.getElementById('month');
        
        yearSelect.addEventListener('change', updateMonthOptions);
        
        function updateMonthOptions() {
            const selectedYear = parseInt(yearSelect.value);
            const currentYear = <?php echo date('Y'); ?>;
            const currentMonth = <?php echo date('n'); ?>;
            
            Array.from(monthSelect.options).forEach((option, index) => {
                const monthNum = index + 1;
                if (selectedYear === currentYear && monthNum > currentMonth) {
                    option.disabled = true;
                } else {
                    option.disabled = false;
                }
            });
            
            // If a now-disabled month is selected, change to current month
            if (selectedYear === currentYear && parseInt(monthSelect.value) > currentMonth) {
                monthSelect.value = currentMonth;
            }
        }
        
        // Run once on page load
        updateMonthOptions();
        
        // Handle evaluate button click
        $('.evaluate-btn').on('click', function() {
            const employeeId = $(this).data('id');
            const employeeName = $(this).data('name');
            const evaluationId = $(this).data('evaluation-id');
            
            $('#employee_id').val(employeeId);
            $('#employee-name').text(employeeName);
            
            // Reset form
            $('#evaluation-form')[0].reset();
            
            // Reset slider values and update display
            initializeAllSliders();
            
            // If updating an existing evaluation, fetch the data
            if (evaluationId) {
                fetchEvaluationData(evaluationId, true);
            }
            
            // Set focus to the first rating field for immediate keyboard entry
            setTimeout(() => {
                $('#attendance-number').focus().select();
            }, 500);
        });
        
        // Handle view button click
        $('.view-btn').on('click', function() {
            const evaluationId = $(this).data('id');
            fetchEvaluationData(evaluationId, false);
        });
        
        // Setup all sliders and number inputs
        initializeAllSliders();
        setupNumberInputs();
        
        // Add keyboard navigation shortcut for form submission
        $('#evaluation-form').on('keydown', function(e) {
            // Ctrl+Enter submits the form
            if (e.ctrlKey && e.key === 'Enter') {
                $(this).submit();
            }
        });
    });
    
    // Setup number input fields for direct keyboard entry
    function setupNumberInputs() {
        // Get all number inputs
        const numberInputs = document.querySelectorAll('.rating-number');
        
        numberInputs.forEach(input => {
            // When number changes, update slider
            input.addEventListener('input', function() {
                const value = this.value;
                if (value < 1) this.value = 1;
                if (value > 10) this.value = 10;
                
                const sliderId = this.getAttribute('data-slider-id');
                const slider = document.getElementById(sliderId);
                const valueDisplay = document.getElementById(sliderId.replace('-slider', '-value'));
                
                if (slider && valueDisplay) {
                    slider.value = this.value;
                    valueDisplay.textContent = this.value;
                    updateSliderAppearance(slider);
                }
            });
            
            // Auto-select text on focus for easy replacement
            input.addEventListener('focus', function() {
                this.select();
            });
            
            // Handle keyboard shortcuts
            input.addEventListener('keydown', function(e) {
                // Arrow Up/Down increments/decrements by 1
                if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (this.value < 10) {
                        this.value = parseInt(this.value) + 1;
                        this.dispatchEvent(new Event('input'));
                    }
                } 
                else if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (this.value > 1) {
                        this.value = parseInt(this.value) - 1;
                        this.dispatchEvent(new Event('input'));
                    }
                }
                // Numbers 1-9 directly set the value
                else if (!e.altKey && !e.ctrlKey && !e.shiftKey && /^[1-9]$/.test(e.key)) {
                    e.preventDefault();
                    this.value = e.key;
                    this.dispatchEvent(new Event('input'));
                    // Move to next field if user presses a number
                    const allInputs = Array.from(document.querySelectorAll('.rating-number'));
                    const currentIndex = allInputs.indexOf(this);
                    if (currentIndex < allInputs.length - 1) {
                        setTimeout(() => {
                            allInputs[currentIndex + 1].focus();
                            allInputs[currentIndex + 1].select();
                        }, 50);
                    }
                }
                // Tab key processed normally
            });
        });
    }
    
    // New simpler implementation for sliders
    function initializeAllSliders() {
        // Get all slider inputs
        const sliders = document.querySelectorAll('.rating-slider');
        
        // Process each slider
        sliders.forEach(slider => {
            const sliderId = slider.id;
            const valueId = sliderId.replace('-slider', '-value');
            const numberId = sliderId.replace('-slider', '-number');
            const valueDisplay = document.getElementById(valueId);
            const numberInput = document.getElementById(numberId);
            
            if (!valueDisplay || !numberInput) return;
            
            // Set initial value
            valueDisplay.textContent = slider.value;
            numberInput.value = slider.value;
            updateSliderAppearance(slider);
            
            // Clear previous event listeners if any
            slider.removeEventListener('input', handleSliderInput);
            
            // Add new event listener
            slider.addEventListener('input', function() {
                valueDisplay.textContent = this.value;
                numberInput.value = this.value;
                updateSliderAppearance(this);
            });
        });
    }
    
    // Handler function for slider input
    function handleSliderInput(event) {
        const slider = event.target;
        const sliderId = slider.id;
        const valueId = sliderId.replace('-slider', '-value');
        const numberId = sliderId.replace('-slider', '-number');
        const valueDisplay = document.getElementById(valueId);
        const numberInput = document.getElementById(numberId);
        
        if (valueDisplay) {
            valueDisplay.textContent = slider.value;
        }
        
        if (numberInput) {
            numberInput.value = slider.value;
        }
        
        updateSliderAppearance(slider);
    }
    
    // Update slider appearance based on value
    function updateSliderAppearance(slider) {
        // Remove all existing classes
        slider.classList.remove('bg-danger', 'bg-warning', 'bg-primary', 'bg-success');
        
        // Add appropriate class based on value
        const value = parseInt(slider.value);
        if (value <= 3) {
            slider.classList.add('bg-danger');
        } else if (value <= 5) {
            slider.classList.add('bg-warning');
        } else if (value <= 7) {
            slider.classList.add('bg-primary');
        } else {
            slider.classList.add('bg-success');
        }
    }
    
    // Function to fetch evaluation data
    function fetchEvaluationData(evaluationId, forEdit) {
        $.ajax({
            url: 'ajax-handlers/get-evaluation.php',
            type: 'GET',
            data: {
                id: evaluationId
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    if (forEdit) {
                        // Populate the edit form
                        populateEvaluationForm(response.evaluation);
                    } else {
                        // Populate the view modal
                        populateEvaluationView(response.evaluation);
                    }
                } else {
                    alert('Error loading evaluation: ' + response.message);
                }
            },
            error: function(xhr, status, error) {
                alert('Error loading evaluation: ' + error);
            }
        });
    }
    
    // Function to populate evaluation form
    function populateEvaluationForm(evaluation) {
        const fields = [
            'attendance', 'cleanliness', 'unloading', 'sales', 'order_management',
            'stock_sheet', 'inventory', 'machine_and_team', 'management', 'ranking'
        ];
        
        fields.forEach(field => {
            if (evaluation[field]) {
                const slider = document.getElementById(field + '-slider');
                const valueDisplay = document.getElementById(field + '-value');
                const numberInput = document.getElementById(field + '-number');
                
                if (slider && valueDisplay && numberInput) {
                    slider.value = evaluation[field];
                    valueDisplay.textContent = evaluation[field];
                    numberInput.value = evaluation[field];
                    updateSliderAppearance(slider);
                }
            }
        });
        
        // Set the bonus amount if available
        if (evaluation.bonus_amount) {
            $('#bonus_amount').val(evaluation.bonus_amount);
        }
        
        // Calculate and show the automatically calculated bonus
        const totalScore = evaluation.total_score / 10;
        calculateAutomaticBonus(totalScore);
        
        // Set comments
        $('#comments').val(evaluation.comments || '');
    }
    
    // Function to calculate automatic bonus based on total score
    function calculateAutomaticBonus(totalScore) {
        if (!totalScore) {
            // If no total score, calculate it from the current slider values
            totalScore = calculateTotalScore();
        }
        
        // Get the ranges from PHP
        const ranges = <?php echo json_encode($ranges); ?>;
        let calculatedBonus = 0;
        
        // Find matching range and get the amount
        for (const range of ranges) {
            if (totalScore >= range.min_value && totalScore <= range.max_value) {
                calculatedBonus = range.amount;
                break;
            }
        }
        
        // Display the calculated bonus
        $('#calculated-bonus').val(calculatedBonus.toFixed(2));
        
        return calculatedBonus;
    }
    
    // Function to calculate total score
    function calculateTotalScore() {
        const fields = [
            'attendance', 'cleanliness', 'unloading', 'sales', 'order_management',
            'stock_sheet', 'inventory', 'machine_and_team', 'management'
        ];
        
        let totalScore = 0;
        fields.forEach(field => {
            const slider = document.getElementById(field + '-slider');
            if (slider) {
                totalScore += parseInt(slider.value);
            }
        });
        
        return totalScore / 10; // Convert to 0-10 scale
    }
    
    // Initialize tooltips
    $(function () {
        $('[data-bs-toggle="tooltip"]').tooltip();
    });
    
    // Add event listener for real-time score calculation and automatic bonus update
    document.addEventListener('DOMContentLoaded', function() {
        const ratingSliders = document.querySelectorAll('.rating-slider');
        
        // Update automatic bonus when any slider changes
        ratingSliders.forEach(slider => {
            slider.addEventListener('input', function() {
                const totalScore = calculateTotalScore();
                calculateAutomaticBonus(totalScore);
            });
        });
        
        // Calculate bonus immediately when the evaluate modal is opened
        $('#evaluateModal').on('shown.bs.modal', function() {
            // Calculate the bonus amount based on default values
            setTimeout(function() {
                calculateAutomaticBonus(calculateTotalScore());
            }, 100);
        });
    });
    
    // Function to populate the view evaluation modal
    function populateEvaluationView(evaluation) {
        console.log("Populating view with evaluation data:", evaluation);
        
        // Format the date if available
        let formattedDate = 'N/A';
        if (evaluation.evaluation_month) {
            const dateParts = evaluation.evaluation_month.split('-');
            const year = dateParts[0];
            const month = new Date(year, dateParts[1]-1, 1).toLocaleString('default', { month: 'long' });
            formattedDate = month + ' ' + year;
        }
        
        // Calculate total score as a decimal
        const totalScore = evaluation.total_score / 10;
        
        // Build HTML content for the view modal
        let html = `
            <div class="mb-4">
                <h5>${evaluation.employee_name || 'Employee'}</h5>
                <div class="text-muted">${evaluation.position || 'N/A'} at ${evaluation.shop_name || 'N/A'}</div>
                <div class="text-muted">Period: ${formattedDate}</div>
            </div>
            
            <div class="table-responsive mb-4">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th class="text-center" style="width: 100px;">Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Attendance</td>
                            <td class="text-center"><span class="badge ${getBadgeClassForView(evaluation.attendance)}">${evaluation.attendance}</span></td>
                        </tr>
                        <tr>
                            <td>Cleanliness</td>
                            <td class="text-center"><span class="badge ${getBadgeClassForView(evaluation.cleanliness)}">${evaluation.cleanliness}</span></td>
                        </tr>
                        <tr>
                            <td>Unloading</td>
                            <td class="text-center"><span class="badge ${getBadgeClassForView(evaluation.unloading)}">${evaluation.unloading}</span></td>
                        </tr>
                        <tr>
                            <td>Sales</td>
                            <td class="text-center"><span class="badge ${getBadgeClassForView(evaluation.sales)}">${evaluation.sales}</span></td>
                        </tr>
                        <tr>
                            <td>Order Management</td>
                            <td class="text-center"><span class="badge ${getBadgeClassForView(evaluation.order_management)}">${evaluation.order_management}</span></td>
                        </tr>
                        <tr>
                            <td>Stock Sheet</td>
                            <td class="text-center"><span class="badge ${getBadgeClassForView(evaluation.stock_sheet)}">${evaluation.stock_sheet}</span></td>
                        </tr>
                        <tr>
                            <td>Inventory</td>
                            <td class="text-center"><span class="badge ${getBadgeClassForView(evaluation.inventory)}">${evaluation.inventory}</span></td>
                        </tr>
                        <tr>
                            <td>Machine and Team</td>
                            <td class="text-center"><span class="badge ${getBadgeClassForView(evaluation.machine_and_team)}">${evaluation.machine_and_team}</span></td>
                        </tr>
                        <tr>
                            <td>Management</td>
                            <td class="text-center"><span class="badge ${getBadgeClassForView(evaluation.management)}">${evaluation.management}</span></td>
                        </tr>
                        <tr>
                            <td>Overall Ranking</td>
                            <td class="text-center"><span class="badge ${getBadgeClassForView(evaluation.ranking)}">${evaluation.ranking}</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">Total Score</h5>
                            <div class="text-center">
                                <div class="display-4 ${getTotalScoreColorClass(totalScore)}" style="font-weight: bold;">
                                    ${totalScore.toFixed(1)}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">Bonus Amount</h5>
                            <div class="text-center">
                                <div class="display-4" style="font-weight: bold;">
                                    $${parseFloat(evaluation.bonus_amount || 0).toFixed(2)}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        if (evaluation.comments) {
            html += `
            <div class="card mb-0">
                <div class="card-header">
                    <h5 class="card-title mb-0">Comments</h5>
                </div>
                <div class="card-body">
                    ${evaluation.comments}
                </div>
            </div>
            `;
        }
        
        // Display the HTML content
        $('#evaluation-details').html(html);
    }
    
    // Helper functions for the view modal
    function getBadgeClassForView(score) {
        if (score >= 8) return 'bg-success';
        if (score >= 6) return 'bg-primary';
        if (score >= 4) return 'bg-warning';
        return 'bg-danger';
    }
    
    // Helper function to get text color class based on total score
    function getTotalScoreColorClass(score) {
        if (score >= 8) return 'text-success';
        if (score >= 6) return 'text-primary';
        if (score >= 4) return 'text-warning';
        return 'text-danger';
    }
</script>

</body>
</html>
<?php
// End output buffering and send content to browser
ob_end_flush();
?>
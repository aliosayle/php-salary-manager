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
$selectedMonth = isset($_GET['month']) ? intval($_GET['month']) : intval($currentMonth);
$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : intval($currentYear);

// Get open months from the months table
$openMonths = [];
try {
    $monthsStmt = $pdo->query("SELECT * FROM months WHERE is_open = 1 ORDER BY year DESC, month ASC");
    $openMonths = $monthsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If no open months or selected month is not open, default to the most recent open month
    if (!empty($openMonths)) {
        // Check if the selected month is open using the isMonthOpen function from helpers.php
        $isOpen = isMonthOpen($selectedMonth, $selectedYear);
        if (!$isOpen) {
            $latestMonth = $openMonths[0];
            $selectedMonth = $latestMonth['month'];
            $selectedYear = $latestMonth['year'];
        }
    }
} catch (PDOException $e) {
    $_SESSION["error_message"] = "Error fetching open months: " . $e->getMessage();
}

// Process batch evaluation submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["action"]) && $_POST["action"] == "batch_evaluate") {
    $month = $_POST["evaluation_month"];
    $year = $_POST["evaluation_year"];
    $evaluations = $_POST["evaluations"];
    $successCount = 0;
    $errorCount = 0;
    
    try {
        $pdo->beginTransaction();
        
        foreach ($evaluations as $shopId => $data) {
            // Skip empty entries
            if (empty($data['assistant_manager_id']) || empty($data['grade']) || !isset($data['prime_amount'])) {
                continue;
            }
            
            $assistantManagerId = $data['assistant_manager_id'];
            $responsibleId = $data['responsible_id'] ?? null;
            $grade = $data['grade'];
            $primeAmount = $data['prime_amount'];
            
            // Check if an evaluation already exists for this shop/month/year
            $evaluationMonth = date('Y-m-d', strtotime($year . '-' . $month . '-01'));
            
            $checkStmt = $pdo->prepare("
                SELECT id FROM assistant_managers_evaluations 
                WHERE shop_id = ? AND evaluation_month = ?
            ");
            $checkStmt->execute([$shopId, $evaluationMonth]);
            
            if ($checkStmt->rowCount() > 0) {
                // Update existing evaluation
                $evaluationId = $checkStmt->fetchColumn();
                $stmt = $pdo->prepare("
                    UPDATE assistant_managers_evaluations 
                    SET assistant_manager_id = ?, grade = ?, prime_amount = ?, responsible_id = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $assistantManagerId, $grade, $primeAmount, $responsibleId, $evaluationId
                ]);
            } else {
                // Create new evaluation
                $stmt = $pdo->prepare("
                    INSERT INTO assistant_managers_evaluations 
                    (shop_id, assistant_manager_id, grade, prime_amount, responsible_id, evaluation_month) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $shopId, $assistantManagerId, $grade, $primeAmount, $responsibleId, $evaluationMonth
                ]);
            }
            
            $successCount++;
        }
        
        $pdo->commit();
        
        if ($successCount > 0) {
            $_SESSION["success_message"] = sprintf(__('evaluations_saved_success'), $successCount);
        }
        if ($errorCount > 0) {
            $_SESSION["error_message"] = sprintf(__('evaluations_save_failed'), $errorCount);
        }
        
        // Redirect to refresh the page
        header("location: assistant-managers-batch-evaluations.php?month=$month&year=$year");
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

// Fetch shops with assistant managers
try {
    $sql = "
        SELECT 
            s.id as shop_id, 
            s.name as shop_name,
            e.id as assistant_manager_id,
            e.full_name as assistant_manager_name,
            e.reference as assistant_manager_reference,
            r.employee_id as responsible_id,
            resp.full_name as responsible_name,
            ev.id as evaluation_id,
            ev.grade,
            ev.prime_amount
        FROM shops s
        LEFT JOIN employee_shops es ON s.id = es.shop_id
        LEFT JOIN employees e ON es.employee_id = e.id AND e.post_id = 'cf0ca194-1abc-11f0-99a1-cc28aa53b74d'
        LEFT JOIN shop_responsibles r ON s.id = r.shop_id
        LEFT JOIN employees resp ON r.employee_id = resp.id
        LEFT JOIN (
            SELECT * FROM assistant_managers_evaluations 
            WHERE MONTH(evaluation_month) = ? AND YEAR(evaluation_month) = ?
        ) ev ON s.id = ev.shop_id
        WHERE e.id IS NOT NULL
        ORDER BY s.name
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$selectedMonth, $selectedYear]);
    $shops_with_managers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all employees with Assistant Manager position (for dropdown)
    $assistantManagersStmt = $pdo->prepare("
        SELECT e.id, e.full_name, e.reference, es.shop_id
        FROM employees e
        JOIN posts p ON e.post_id = p.id
        LEFT JOIN employee_shops es ON e.id = es.employee_id
        WHERE p.id = 'cf0ca194-1abc-11f0-99a1-cc28aa53b74d'
        ORDER BY e.full_name
    ");
    $assistantManagersStmt->execute();
    $assistantManagers = $assistantManagersStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group assistant managers by shop
    $assistantManagersByShop = [];
    foreach ($assistantManagers as $manager) {
        if (!empty($manager['shop_id'])) {
            if (!isset($assistantManagersByShop[$manager['shop_id']])) {
                $assistantManagersByShop[$manager['shop_id']] = [];
            }
            $assistantManagersByShop[$manager['shop_id']][] = $manager;
        }
    }
    
    // Get all responsibles (for dropdown)
    $responsiblesStmt = $pdo->query("
        SELECT r.employee_id, e.full_name, r.shop_id 
        FROM shop_responsibles r
        JOIN employees e ON r.employee_id = e.id
        ORDER BY e.full_name
    ");
    $responsibles = $responsiblesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group responsibles by shop
    $responsiblesByShop = [];
    foreach ($responsibles as $responsible) {
        $responsiblesByShop[$responsible['shop_id']] = $responsible;
    }
    
} catch (PDOException $e) {
    $_SESSION["error_message"] = "Error fetching data: " . $e->getMessage();
    $shops_with_managers = [];
    $assistantManagers = [];
    $responsibles = [];
    $assistantManagersByShop = [];
    $responsiblesByShop = [];
}

?>

<?php include 'layouts/head-main.php'; ?>

<head>
    <title><?php echo __('assistant_manager_batch_evaluations'); ?> | <?php echo __('employee_manager_system'); ?></title>
    <?php include 'layouts/head.php'; ?>
    
    <!-- DataTables CSS -->
    <link href="assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/libs/datatables.net-buttons-bs4/css/buttons.bootstrap4.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/libs/datatables.net-fixedheader-bs4/css/fixedHeader.bootstrap4.min.css" rel="stylesheet" type="text/css" />
    
    <?php include 'layouts/head-style.php'; ?>
    
    <style>
        .grade-select {
            width: 70px;
            text-align: center;
            padding: 0.2rem;
            font-size: 0.9rem;
        }
        
        .prime-input {
            width: 100px;
            text-align: right;
            padding: 0.2rem;
            font-size: 0.9rem;
        }
        
        .shop-cell {
            min-width: 150px;
            max-width: 180px;
        }
        
        .assistant-manager-cell {
            min-width: 150px;
            max-width: 180px;
        }
        
        .responsible-cell {
            min-width: 150px;
            max-width: 180px;
        }
        
        .reference-cell {
            min-width: 100px;
        }
        
        .grade-cell, .prime-cell, .status-cell {
            width: 120px;
            text-align: center;
        }
        
        /* Highlight every other row */
        .highlight-row {
            background-color: rgba(0, 0, 0, 0.03);
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
        
        .table-responsive {
            max-height: 600px;
            overflow-y: auto;
        }
        
        /* Space below the form */
        form {
            margin-bottom: 2rem;
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
                        <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                            <h4 class="mb-sm-0 font-size-18"><?php echo __('assistant_manager_batch_evaluations'); ?></h4>

                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php"><?php echo __('dashboard'); ?></a></li>
                                    <li class="breadcrumb-item"><a href="assistant-managers-evaluations.php"><?php echo __('assistant_manager_evaluations'); ?></a></li>
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
                                        <h4 class="card-title mb-4"><?php echo __('filter_evaluations'); ?></h4>
                                        
                                        <form method="GET" action="assistant-managers-batch-evaluations.php" class="row g-3 align-items-end">
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
                                                <button type="submit" class="btn btn-primary w-100"><?php echo __('apply_filters'); ?></button>
                                            </div>
                                            
                                            <div class="col-md-3">
                                                <a href="assistant-managers-evaluations.php?month=<?php echo $selectedMonth; ?>&year=<?php echo $selectedYear; ?>" class="btn btn-info w-100">
                                                    <i class="bx bx-user me-1"></i> <?php echo __('switch_to_individual_evaluations'); ?>
                                                </a>
                                            </div>
                                        </form>
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
                                        <?php echo sprintf(__('assistant_manager_batch_evaluations_for'), date('F', mktime(0, 0, 0, $selectedMonth, 1)), $selectedYear); ?>
                                    </h4>
                                    <div class="d-flex align-items-center">
                                        <div class="me-3">
                                            <span class="badge bg-success mx-1">A+</span>
                                            <span class="badge bg-primary mx-1">A</span>
                                            <span class="badge bg-info mx-1">B</span>
                                            <span class="badge bg-warning mx-1">C</span>
                                            <span class="badge bg-danger mx-1">D</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (empty($openMonths)): ?>
                                    <div class="alert alert-warning">
                                        <?php echo __('no_open_months_warning'); ?>
                                    </div>
                                <?php elseif (empty($shops_with_managers)): ?>
                                    <div class="alert alert-info">
                                        <?php echo __('no_assistant_managers_assigned'); ?>
                                    </div>
                                <?php else: ?>
                                    <form method="POST" action="assistant-managers-batch-evaluations.php" id="batch-evaluation-form">
                                        <input type="hidden" name="action" value="batch_evaluate">
                                        <input type="hidden" name="evaluation_month" value="<?php echo $selectedMonth; ?>">
                                        <input type="hidden" name="evaluation_year" value="<?php echo $selectedYear; ?>">
                                        
                                        <div class="table-responsive">
                                            <table id="evaluations-table" class="table table-bordered table-hover">
                                                <thead>
                                                    <tr class="bg-light">
                                                        <th class="shop-cell"><?php echo __('shop'); ?></th>
                                                        <th class="assistant-manager-cell"><?php echo __('assistant_manager'); ?></th>
                                                        <th class="reference-cell"><?php echo __('reference'); ?></th>
                                                        <th class="responsible-cell"><?php echo __('responsible'); ?></th>
                                                        <th class="grade-cell"><?php echo __('grade'); ?></th>
                                                        <th class="prime-cell"><?php echo __('prime_amount'); ?></th>
                                                        <th class="status-cell"><?php echo __('status'); ?></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php $rowIndex = 0; ?>
                                                    <?php foreach ($shops_with_managers as $shop): ?>
                                                    <?php $rowIndex++; ?>
                                                    <tr class="<?php echo $rowIndex % 2 === 0 ? 'highlight-row' : ''; ?>">
                                                        <!-- Shop -->
                                                        <td class="shop-cell">
                                                            <?php echo htmlspecialchars($shop['shop_name']); ?>
                                                            <input type="hidden" name="shop_ids[]" value="<?php echo $shop['shop_id']; ?>">
                                                        </td>
                                                        
                                                        <!-- Assistant Manager -->
                                                        <td class="assistant-manager-cell">
                                                            <input type="hidden" name="evaluations[<?php echo $shop['shop_id']; ?>][assistant_manager_id]" 
                                                                   value="<?php echo $shop['assistant_manager_id']; ?>">
                                                            <?php echo htmlspecialchars($shop['assistant_manager_name'] ?? __('not_assigned')); ?>
                                                        </td>
                                                        
                                                        <!-- Reference -->
                                                        <td class="reference-cell">
                                                            <?php echo htmlspecialchars($shop['assistant_manager_reference'] ?? 'N/A'); ?>
                                                        </td>
                                                        
                                                        <!-- Responsible -->
                                                        <td class="responsible-cell">
                                                            <input type="hidden" name="evaluations[<?php echo $shop['shop_id']; ?>][responsible_id]" 
                                                                   value="<?php echo $shop['responsible_id']; ?>">
                                                            <?php echo htmlspecialchars($shop['responsible_name'] ?? __('not_assigned')); ?>
                                                        </td>
                                                        
                                                        <!-- Grade -->
                                                        <td class="grade-cell">
                                                            <select class="form-select grade-select" 
                                                                    name="evaluations[<?php echo $shop['shop_id']; ?>][grade]"
                                                                    data-shop-id="<?php echo $shop['shop_id']; ?>">
                                                                <option value=""><?php echo __('select'); ?></option>
                                                                <option value="A+" <?php echo $shop['grade'] === 'A+' ? 'selected' : ''; ?>>A+</option>
                                                                <option value="A" <?php echo $shop['grade'] === 'A' ? 'selected' : ''; ?>>A</option>
                                                                <option value="B" <?php echo $shop['grade'] === 'B' ? 'selected' : ''; ?>>B</option>
                                                                <option value="C" <?php echo $shop['grade'] === 'C' ? 'selected' : ''; ?>>C</option>
                                                                <option value="D" <?php echo $shop['grade'] === 'D' ? 'selected' : ''; ?>>D</option>
                                                            </select>
                                                        </td>
                                                        
                                                        <!-- Prime Amount -->
                                                        <td class="prime-cell">
                                                            <div class="input-group">
                                                                <span class="input-group-text">$</span>
                                                                <input type="number" class="form-control prime-input" 
                                                                       name="evaluations[<?php echo $shop['shop_id']; ?>][prime_amount]" 
                                                                       step="0.01" min="0" 
                                                                       value="<?php echo $shop['prime_amount'] ?? ''; ?>"
                                                                       id="prime-<?php echo $shop['shop_id']; ?>">
                                                            </div>
                                                        </td>
                                                        
                                                        <!-- Status -->
                                                        <td class="status-cell">
                                                            <?php if ($shop['evaluation_id']): ?>
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

<script>
    $(document).ready(function() {
        // Initialize datatable
        $('#evaluations-table').DataTable({
            paging: false,
            ordering: true,
            info: true,
            searching: true,
            responsive: true,
            scrollY: '500px',
            scrollCollapse: true,
            "dom": '<"top"f>rt<"bottom"i>'
        });
        
        // Handle month selection to update year
        $('#month').on('change', function() {
            var selectedOption = $(this).find('option:selected');
            var year = selectedOption.data('year');
            
            if (year) {
                $('#year').val(year);
            }
        });
        
        // Handle grade selection to set recommended prime amount
        $('.grade-select').on('change', function() {
            var shopId = $(this).data('shop-id');
            var grade = $(this).val();
            var primeAmount = 0;
            
            switch(grade) {
                case 'A+':
                    primeAmount = 500.00;
                    break;
                case 'A':
                    primeAmount = 400.00;
                    break;
                case 'B':
                    primeAmount = 300.00;
                    break;
                case 'C':
                    primeAmount = 200.00;
                    break;
                case 'D':
                    primeAmount = 100.00;
                    break;
            }
            
            $('#prime-' + shopId).val(primeAmount.toFixed(2));
        });
        
        // Form validation before submission
        $('#batch-evaluation-form').on('submit', function(e) {
            var hasValues = false;
            
            // Check if any row has values
            $('.grade-select').each(function() {
                if ($(this).val() !== '') {
                    hasValues = true;
                    return false; // Break the loop
                }
            });
            
            if (!hasValues) {
                e.preventDefault();
                alert('<?php echo __("please_enter_at_least_one_evaluation"); ?>');
                return false;
            }
            
            // Validate rows that have grades entered
            var isValid = true;
            $('.grade-select').each(function() {
                var shopId = $(this).data('shop-id');
                var grade = $(this).val();
                var primeAmount = $('#prime-' + shopId).val();
                
                // If grade is selected, make sure prime amount is also entered
                if (grade && !primeAmount) {
                    isValid = false;
                    $('#prime-' + shopId).addClass('is-invalid');
                } else {
                    $('#prime-' + shopId).removeClass('is-invalid');
                }
            });
            
            if (!isValid) {
                e.preventDefault();
                alert('<?php echo __("please_enter_prime_amounts_for_all_evaluated_shops"); ?>');
                return false;
            }
        });
    });
</script>

</body>
</html>
<?php
// End output buffering and send content to browser
ob_end_flush();
?>
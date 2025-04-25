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
$selectedShop = isset($_GET['shop_id']) ? $_GET['shop_id'] : '';

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

// Process evaluation submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["action"]) && $_POST["action"] == "evaluate") {
    $shopId = $_POST["shop_id"];
    $assistantManagerId = $_POST["assistant_manager_id"];
    $responsibleId = $_POST["responsible_id"];
    $month = $_POST["evaluation_month"];
    $year = $_POST["evaluation_year"];
    $grade = $_POST["grade"];
    $primeAmount = $_POST["prime_amount"];
    
    try {
        // Verify the shop exists
        $shopCheckStmt = $pdo->prepare("SELECT id FROM shops WHERE id = ?");
        $shopCheckStmt->execute([$shopId]);
        if ($shopCheckStmt->rowCount() == 0) {
            throw new PDOException("Shop not found. Please select a valid shop.");
        }
        
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
            
            $_SESSION["success_message"] = __("assistant_manager_evaluation_updated");
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
            
            $_SESSION["success_message"] = __("assistant_manager_evaluation_created");
        }
        
        // Redirect to refresh the page
        header("location: assistant-managers-evaluations.php?month=$month&year=$year" . ($selectedShop ? "&shop_id=$selectedShop" : ""));
        exit;
        
    } catch (PDOException $e) {
        $_SESSION["error_message"] = "Error saving evaluation: " . $e->getMessage();
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
            r.employee_id as responsible_id,
            resp.full_name as responsible_name,
            ev.id as evaluation_id,
            ev.grade,
            ev.prime_amount
        FROM shops s
        LEFT JOIN employee_shops es ON s.id = es.shop_id
        LEFT JOIN employees e ON es.employee_id = e.id
        LEFT JOIN posts p ON e.post_id = p.id
        LEFT JOIN shop_responsibles r ON s.id = r.shop_id
        LEFT JOIN employees resp ON r.employee_id = resp.id
        LEFT JOIN (
            SELECT * FROM assistant_managers_evaluations 
            WHERE MONTH(evaluation_month) = ? AND YEAR(evaluation_month) = ?
        ) ev ON s.id = ev.shop_id
        WHERE p.title = 'Assistant Manager'
    ";
    
    $params = [$selectedMonth, $selectedYear];
    
    if (!empty($selectedShop)) {
        $sql .= " AND s.id = ?";
        $params[] = $selectedShop;
    }
    
    $sql .= " ORDER BY s.name";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $shops_with_managers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all employee with Assistant Manager position (for dropdown)
    $assistantManagersStmt = $pdo->prepare("
        SELECT e.id, e.full_name, es.shop_id
        FROM employees e
        JOIN posts p ON e.post_id = p.id
        LEFT JOIN employee_shops es ON e.id = es.employee_id
        WHERE p.title = 'Assistant Manager'
        ORDER BY e.full_name
    ");
    $assistantManagersStmt->execute();
    $assistantManagers = $assistantManagersStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all responsibles (for dropdown)
    $responsiblesStmt = $pdo->query("
        SELECT r.employee_id, e.full_name, r.shop_id 
        FROM shop_responsibles r
        JOIN employees e ON r.employee_id = e.id
        ORDER BY e.full_name
    ");
    $responsibles = $responsiblesStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $_SESSION["error_message"] = "Error fetching data: " . $e->getMessage();
    $shops_with_managers = [];
    $assistantManagers = [];
    $responsibles = [];
}

// Note: The getGradeColorClass() function is now used from helpers.php instead of being defined here

?>

<?php include 'layouts/head-main.php'; ?>

<head>
    <title><?php echo __('assistant_manager_evaluations'); ?> | <?php echo __('employee_manager_system'); ?></title>
    <?php include 'layouts/head.php'; ?>
    
    <!-- DataTables CSS -->
    <link href="assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/libs/datatables.net-buttons-bs4/css/buttons.bootstrap4.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css" rel="stylesheet" type="text/css" />
    
    <?php include 'layouts/head-style.php'; ?>
    
    <style>
        .grade-badge {
            font-size: 1rem;
            font-weight: bold;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
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
                            <h4 class="mb-0 font-size-18"><?php echo __('assistant_manager_evaluations'); ?></h4>

                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php"><?php echo __('dashboard'); ?></a></li>
                                    <li class="breadcrumb-item active"><?php echo __('assistant_manager_evaluations'); ?></li>
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
                                        
                                        <form method="GET" action="assistant-managers-evaluations.php" class="row g-3 align-items-end">
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
                                            <a href="assistant-managers-batch-evaluations.php?month=<?php echo $selectedMonth; ?>&year=<?php echo $selectedYear; ?><?php echo $selectedShop ? '&shop_id='.$selectedShop : ''; ?>" class="btn btn-info w-100">
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
                                        <?php echo sprintf(__('assistant_manager_evaluations_for'), date('F', mktime(0, 0, 0, $selectedMonth, 1)), $selectedYear); ?>
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
                                <div class="table-responsive">
                                    <table id="evaluations-table" class="table table-bordered table-hover">
                                        <thead>
                                            <tr>
                                                <th><?php echo __('shop'); ?></th>
                                                <th><?php echo __('assistant_manager'); ?></th>
                                                <th><?php echo __('responsible'); ?></th>
                                                <th><?php echo __('grade'); ?></th>
                                                <th><?php echo __('prime_amount'); ?></th>
                                                <th><?php echo __('status'); ?></th>
                                                <th><?php echo __('actions'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($shops_with_managers as $shop): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($shop['shop_name']); ?></td>
                                                <td><?php echo htmlspecialchars($shop['assistant_manager_name'] ?? __('not_assigned')); ?></td>
                                                <td><?php echo htmlspecialchars($shop['responsible_name'] ?? __('not_assigned')); ?></td>
                                                <td class="text-center">
                                                    <?php if ($shop['evaluation_id']): ?>
                                                        <span class="badge grade-badge <?php echo getGradeColorClass($shop['grade']); ?>">
                                                            <?php echo $shop['grade']; ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($shop['evaluation_id']): ?>
                                                        $<?php echo number_format($shop['prime_amount'], 2); ?>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($shop['evaluation_id']): ?>
                                                        <span class="badge bg-success"><?php echo __('evaluated'); ?></span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning"><?php echo __('pending'); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <button type="button" class="btn btn-primary btn-sm evaluate-btn" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#evaluationModal"
                                                            data-shop-id="<?php echo $shop['shop_id']; ?>"
                                                            data-shop-name="<?php echo htmlspecialchars($shop['shop_name']); ?>"
                                                            data-assistant-manager-id="<?php echo $shop['assistant_manager_id']; ?>"
                                                            data-assistant-manager-name="<?php echo htmlspecialchars($shop['assistant_manager_name'] ?? __('not_assigned')); ?>"
                                                            data-responsible-id="<?php echo $shop['responsible_id']; ?>"
                                                            data-responsible-name="<?php echo htmlspecialchars($shop['responsible_name'] ?? __('not_assigned')); ?>"
                                                            data-grade="<?php echo $shop['grade']; ?>"
                                                            data-prime-amount="<?php echo $shop['prime_amount']; ?>"
                                                            data-evaluation-id="<?php echo $shop['evaluation_id']; ?>">
                                                        <?php echo $shop['evaluation_id'] ? __('edit') : __('evaluate'); ?>
                                                    </button>
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

            </div>
        </div>

        <!-- Evaluation Modal -->
        <div class="modal fade" id="evaluationModal" tabindex="-1" aria-labelledby="evaluationModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="evaluationModalLabel"><?php echo __('evaluate_assistant_manager'); ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form method="POST" action="assistant-managers-evaluations.php">
                        <div class="modal-body">
                            <input type="hidden" name="action" value="evaluate">
                            <input type="hidden" name="evaluation_month" value="<?php echo $selectedMonth; ?>">
                            <input type="hidden" name="evaluation_year" value="<?php echo $selectedYear; ?>">
                            <input type="hidden" name="shop_id" id="modal-shop-id">
                            <input type="hidden" name="assistant_manager_id" id="hidden-assistant-manager-id">
                            <input type="hidden" name="responsible_id" id="hidden-responsible-id">
                            
                            <div class="mb-3">
                                <label class="form-label"><?php echo __('shop'); ?></label>
                                <p class="form-control-static fw-bold fs-5" id="modal-shop-name"></p>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label"><?php echo __('assistant_manager'); ?></label>
                                <p class="form-control-static fw-bold fs-5" id="display-assistant-manager"></p>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label"><?php echo __('responsible'); ?></label>
                                <p class="form-control-static fw-bold fs-5" id="display-responsible"></p>
                            </div>
                            
                            <div class="mb-3">
                                <label for="grade" class="form-label"><?php echo __('grade'); ?></label>
                                <select class="form-select" id="grade" name="grade" required>
                                    <option value=""><?php echo __('select_grade'); ?></option>
                                    <option value="A+">A+</option>
                                    <option value="A">A</option>
                                    <option value="B">B</option>
                                    <option value="C">C</option>
                                    <option value="D">D</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="prime_amount" class="form-label"><?php echo __('prime_amount'); ?></label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" class="form-control" id="prime_amount" name="prime_amount" 
                                           step="0.01" min="0" required>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                            <button type="submit" class="btn btn-primary"><?php echo __('save'); ?></button>
                        </div>
                    </form>
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
        
        // Handle evaluation modal
        $('.evaluate-btn').on('click', function() {
            // Set shop details
            var shopId = $(this).data('shop-id');
            var shopName = $(this).data('shop-name');
            var assistantManagerId = $(this).data('assistant-manager-id');
            var assistantManagerName = $(this).data('assistant-manager-name');
            var responsibleId = $(this).data('responsible-id');
            var responsibleName = $(this).data('responsible-name');
            var grade = $(this).data('grade');
            var primeAmount = $(this).data('prime-amount');
            var evaluationId = $(this).data('evaluation-id');
            
            console.log("Modal data:", {
                shopId, shopName, 
                assistantManagerId, assistantManagerName,
                responsibleId, responsibleName,
                grade, primeAmount, evaluationId
            });
            
            // Set values in modal
            $('#modal-shop-id').val(shopId);
            $('#modal-shop-name').text(shopName || '-');
            $('#hidden-assistant-manager-id').val(assistantManagerId);
            $('#hidden-responsible-id').val(responsibleId);
            $('#display-assistant-manager').text(assistantManagerName || '-');
            $('#display-responsible').text(responsibleName || '-');
            $('#grade').val(grade);
            $('#prime_amount').val(primeAmount);
            
            // Set modal title based on whether we're editing or creating
            if (evaluationId) {
                $('#evaluationModalLabel').text(`<?php echo addslashes(__('edit_assistant_manager_evaluation')); ?>`);
            } else {
                $('#evaluationModalLabel').text(`<?php echo addslashes(__('evaluate_assistant_manager')); ?>`);
            }
        });
        
        // Grade selection presets prime amounts
        $('#grade').on('change', function() {
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
            
            $('#prime_amount').val(primeAmount);
        });
    });
</script>
</body>
</html>
<?php
// End output buffering and send content to browser
ob_end_flush();
?>
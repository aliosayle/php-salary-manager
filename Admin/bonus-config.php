<?php
include 'layouts/session.php';
include 'layouts/head-main.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error_message'] = __('not_logged_in');
    header('Location: login.php');
    exit;
}

// Check permission
if (!hasPermission('manage_bonus_config')) {
    $_SESSION['error_message'] = __('no_permission');
    header('Location: index.php');
    exit;
}

// Initialize variables
$errorMsg = [];
$minSales = '';
$maxSales = '';
$bonusPercentage = '';
$bonusConfigId = '';

// Process delete request
if (isset($_GET['delete']) && !empty($_GET['id'])) {
    $configId = $_GET['id'];
    
    try {
        $stmt = $pdo->prepare("DELETE FROM bonus_config WHERE id = ?");
        $result = $stmt->execute([$configId]);
        
        if ($result) {
            $_SESSION['success_message'] = __('bonus_config_deleted');
        } else {
            $_SESSION['error_message'] = __('bonus_config_delete_error');
        }
    } catch (PDOException $e) {
        error_log("Error deleting bonus config: " . $e->getMessage());
        $_SESSION['error_message'] = __('database_error');
    }
    
    header('Location: bonus-config.php');
    exit;
}

// Process edit request
if (isset($_GET['edit']) && !empty($_GET['id'])) {
    $bonusConfigId = $_GET['id'];
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM bonus_config WHERE id = ?");
        $stmt->execute([$bonusConfigId]);
        
        if ($stmt->rowCount() > 0) {
            $bonusConfig = $stmt->fetch(PDO::FETCH_ASSOC);
            $minSales = $bonusConfig['min_sales'];
            $maxSales = $bonusConfig['max_sales'];
            $bonusPercentage = $bonusConfig['bonus_percentage'];
        } else {
            $_SESSION['error_message'] = __('record_not_found');
            header('Location: bonus-config.php');
            exit;
        }
    } catch (PDOException $e) {
        error_log("Error fetching bonus config: " . $e->getMessage());
        $_SESSION['error_message'] = __('database_error');
        header('Location: bonus-config.php');
        exit;
    }
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate inputs
    $minSales = isset($_POST['min_sales']) ? trim($_POST['min_sales']) : '';
    $maxSales = isset($_POST['max_sales']) ? trim($_POST['max_sales']) : '';
    $bonusPercentage = isset($_POST['bonus_percentage']) ? trim($_POST['bonus_percentage']) : '';
    $bonusConfigId = isset($_POST['bonus_config_id']) ? $_POST['bonus_config_id'] : '';
    
    if (empty($minSales) || !is_numeric($minSales) || $minSales < 0) {
        $errorMsg[] = __('min_sales_invalid');
    }
    
    if (empty($maxSales) || !is_numeric($maxSales) || $maxSales <= 0) {
        $errorMsg[] = __('max_sales_invalid');
    }
    
    if (!empty($minSales) && !empty($maxSales) && $minSales >= $maxSales) {
        $errorMsg[] = __('min_sales_greater_than_max');
    }
    
    if (empty($bonusPercentage) || !is_numeric($bonusPercentage) || $bonusPercentage < 0 || $bonusPercentage > 100) {
        $errorMsg[] = __('bonus_percentage_invalid');
    }
    
    // Check for overlapping ranges
    if (empty($errorMsg)) {
        try {
            $params = [$minSales, $maxSales];
            $whereClause = "";
            
            if (!empty($bonusConfigId)) {
                $whereClause = " AND id != ?";
                $params[] = $bonusConfigId;
            }
            
            $stmt = $pdo->prepare("SELECT id FROM bonus_config WHERE 
                                  (min_sales <= ? AND max_sales >= ?) OR
                                  (min_sales <= ? AND max_sales >= ?) OR
                                  (min_sales >= ? AND max_sales <= ?)" . $whereClause);
            
            $params[] = $minSales;
            $params[] = $minSales;
            $params[] = $maxSales;
            $params[] = $maxSales;
            
            $stmt->execute($params);
            
            if ($stmt->rowCount() > 0) {
                $errorMsg[] = __('overlapping_ranges');
            }
        } catch (PDOException $e) {
            error_log("Error checking overlapping ranges: " . $e->getMessage());
            $errorMsg[] = __('database_error');
        }
    }
    
    // Save to database if no errors
    if (empty($errorMsg)) {
        try {
            if (empty($bonusConfigId)) {
                // Insert new record
                $stmt = $pdo->prepare("INSERT INTO bonus_config (min_sales, max_sales, bonus_percentage) VALUES (?, ?, ?)");
                $result = $stmt->execute([$minSales, $maxSales, $bonusPercentage]);
                
                if ($result) {
                    $_SESSION['success_message'] = __('bonus_config_added');
                    header('Location: bonus-config.php');
                    exit;
                } else {
                    $errorMsg[] = __('save_error');
                }
            } else {
                // Update existing record
                $stmt = $pdo->prepare("UPDATE bonus_config SET min_sales = ?, max_sales = ?, bonus_percentage = ? WHERE id = ?");
                $result = $stmt->execute([$minSales, $maxSales, $bonusPercentage, $bonusConfigId]);
                
                if ($result) {
                    $_SESSION['success_message'] = __('bonus_config_updated');
                    header('Location: bonus-config.php');
                    exit;
                } else {
                    $errorMsg[] = __('update_error');
                }
            }
        } catch (PDOException $e) {
            error_log("Error saving bonus config: " . $e->getMessage());
            $errorMsg[] = __('database_error');
        }
    }
}

// Fetch all bonus configurations
$bonusConfigs = [];
try {
    $stmt = $pdo->query("SELECT * FROM bonus_config ORDER BY min_sales ASC");
    $bonusConfigs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching bonus configs: " . $e->getMessage());
    $_SESSION['error_message'] = __('database_error');
}

?>

<title><?php echo __('bonus_configuration'); ?> | <?php echo __('employee_manager_system'); ?></title>

<?php include 'layouts/head-style.php'; ?>

<body data-layout="vertical" data-sidebar="dark">

    <!-- Begin page -->
    <div id="layout-wrapper">

        <?php include 'layouts/menu.php'; ?>

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
                                <h4 class="mb-0"><?php echo __('bonus_configuration'); ?></h4>

                                <div class="page-title-right">
                                    <ol class="breadcrumb m-0">
                                        <li class="breadcrumb-item"><a href="javascript: void(0);"><?php echo __('home'); ?></a></li>
                                        <li class="breadcrumb-item active"><?php echo __('bonus_configuration'); ?></li>
                                    </ol>
                                </div>

                            </div>
                        </div>
                    </div>
                    <!-- end page title -->

                    <?php include 'layouts/notification.php'; ?>

                    <div class="row">
                        <div class="col-lg-12">
                            <div class="card">
                                <div class="card-header">
                                    <h4 class="card-title"><?php echo empty($bonusConfigId) ? __('add_bonus_config') : __('edit_bonus_config'); ?></h4>
                                </div>
                                <div class="card-body">
                                    <?php if (!empty($errorMsg)): ?>
                                        <div class="alert alert-danger">
                                            <ul class="mb-0">
                                                <?php foreach ($errorMsg as $error): ?>
                                                    <li><?php echo $error; ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>

                                    <form action="bonus-config.php" method="POST">
                                        <input type="hidden" name="bonus_config_id" value="<?php echo $bonusConfigId; ?>">
                                        
                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label for="min_sales" class="form-label"><?php echo __('min_sales'); ?> <span class="text-danger">*</span></label>
                                                    <div class="input-group">
                                                        <span class="input-group-text"><?php echo getCurrencySymbol(); ?></span>
                                                        <input type="number" class="form-control" id="min_sales" name="min_sales" required min="0" step="0.01" value="<?php echo $minSales; ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label for="max_sales" class="form-label"><?php echo __('max_sales'); ?> <span class="text-danger">*</span></label>
                                                    <div class="input-group">
                                                        <span class="input-group-text"><?php echo getCurrencySymbol(); ?></span>
                                                        <input type="number" class="form-control" id="max_sales" name="max_sales" required min="0" step="0.01" value="<?php echo $maxSales; ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label for="bonus_percentage" class="form-label"><?php echo __('bonus_percentage'); ?> <span class="text-danger">*</span></label>
                                                    <div class="input-group">
                                                        <input type="number" class="form-control" id="bonus_percentage" name="bonus_percentage" required min="0" max="100" step="0.01" value="<?php echo $bonusPercentage; ?>">
                                                        <span class="input-group-text">%</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="mt-4">
                                            <button type="submit" class="btn btn-primary"><?php echo __('save'); ?></button>
                                            <?php if (!empty($bonusConfigId)): ?>
                                                <a href="bonus-config.php" class="btn btn-secondary"><?php echo __('cancel'); ?></a>
                                            <?php endif; ?>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-lg-12">
                            <div class="card">
                                <div class="card-header">
                                    <h4 class="card-title"><?php echo __('bonus_configurations_list'); ?></h4>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table id="bonus-config-table" class="table table-striped table-bordered dt-responsive nowrap">
                                            <thead>
                                                <tr>
                                                    <th><?php echo __('min_sales'); ?></th>
                                                    <th><?php echo __('max_sales'); ?></th>
                                                    <th><?php echo __('bonus_percentage'); ?></th>
                                                    <th><?php echo __('actions'); ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($bonusConfigs as $config): ?>
                                                    <tr>
                                                        <td><?php echo getCurrencySymbol() . ' ' . number_format($config['min_sales'], 2); ?></td>
                                                        <td><?php echo getCurrencySymbol() . ' ' . number_format($config['max_sales'], 2); ?></td>
                                                        <td><?php echo number_format($config['bonus_percentage'], 2) . '%'; ?></td>
                                                        <td>
                                                            <a href="bonus-config.php?edit=1&id=<?php echo $config['id']; ?>" class="btn btn-sm btn-primary">
                                                                <i class="fas fa-edit"></i> <?php echo __('edit'); ?>
                                                            </a>
                                                            <button type="button" class="btn btn-sm btn-danger delete-config" data-id="<?php echo $config['id']; ?>" data-bs-toggle="modal" data-bs-target="#deleteModal">
                                                                <i class="fas fa-trash-alt"></i> <?php echo __('delete'); ?>
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

                </div> <!-- container-fluid -->
            </div>
            <!-- End Page-content -->

            <!-- Delete Modal -->
            <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="deleteModalLabel"><?php echo __('confirm_delete'); ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <?php echo __('delete_bonus_config_confirm'); ?>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                            <a href="#" id="confirmDelete" class="btn btn-danger"><?php echo __('delete'); ?></a>
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
            // Initialize DataTable
            $('#bonus-config-table').DataTable({
                language: {
                    url: 'assets/libs/datatables.net/languages/' + 
                         (document.documentElement.lang === 'fr' ? 'French' : 'English') + '.json'
                }
            });
            
            // Handle delete confirmation
            $('.delete-config').on('click', function() {
                var configId = $(this).data('id');
                $('#confirmDelete').attr('href', 'bonus-config.php?delete=1&id=' + configId);
            });
            
            // Validation for min_sales and max_sales
            $('#max_sales').on('input', function() {
                var minSales = parseFloat($('#min_sales').val());
                var maxSales = parseFloat($(this).val());
                
                if (minSales >= maxSales) {
                    $(this).addClass('is-invalid');
                } else {
                    $(this).removeClass('is-invalid');
                }
            });
            
            $('#min_sales').on('input', function() {
                var minSales = parseFloat($(this).val());
                var maxSales = parseFloat($('#max_sales').val());
                
                if (minSales >= maxSales && maxSales > 0) {
                    $(this).addClass('is-invalid');
                } else {
                    $(this).removeClass('is-invalid');
                }
            });
        });
    </script>

</body>
</html> 
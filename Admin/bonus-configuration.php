<?php
// Set up error logging
ini_set('display_errors', 1);
ini_set('error_log', __DIR__ . '/layouts/logs/error.log');
error_log("Bonus-configuration.php - Page load started");

// Include required files first
require_once "layouts/config.php";
require_once "layouts/helpers.php";
require_once "layouts/translations.php"; // Include translations before using the __() function
require_once "layouts/session.php"; // This includes session validation

// After session.php has validated the session, we can continue with the page

// Double-check if user is logged in
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    error_log("Bonus-configuration.php - User not logged in, redirecting to login page");
    // Store current URL for redirect after login
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    header("location: auth-login.php");
    exit;
}

// Check if user has permission to manage bonus configuration
if (!hasPermission('manage_bonus_configuration')) {
    $_SESSION['error_message'] = __('no_permission_manage_bonus');
    header('Location: index.php');
    exit;
}

// Initialize variables
$errors = [];
$configData = [];

// Fetch existing configuration
try {
    $sql = "SELECT * FROM bonus_tiers ORDER BY min_sales ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $configData = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_SESSION['error_message'] = __('error_loading_bonus_config') . ': ' . $e->getMessage();
}

// Handle form submission (Add/Edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'add') {
        // Get form data
        $min_sales = $_POST['min_sales'] ?? '';
        $bonus_percent = $_POST['bonus_percent'] ?? '';
        
        // Validate inputs
        if (empty($min_sales)) {
            $errors['min_sales'] = __('min_sales_required');
        } elseif (!is_numeric($min_sales) || $min_sales < 0) {
            $errors['min_sales'] = __('min_sales_invalid');
        }
        
        if (empty($bonus_percent)) {
            $errors['bonus_percent'] = __('bonus_percentage_required');
        } elseif (!is_numeric($bonus_percent) || $bonus_percent < 0 || $bonus_percent > 100) {
            $errors['bonus_percent'] = __('bonus_percentage_invalid');
        }
        
        // Check if min_sales already exists (it's the primary key)
        if (empty($errors)) {
            $checkSql = "SELECT COUNT(*) FROM bonus_tiers WHERE min_sales = :min_sales";
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute([':min_sales' => $min_sales]);
            if ($checkStmt->fetchColumn() > 0) {
                $errors['min_sales'] = __('min_sales_exists');
            }
        }
        
        // If no errors, proceed with adding
        if (empty($errors)) {
            try {
                // Insert new configuration
                $insertSql = "INSERT INTO bonus_tiers (min_sales, bonus_percent) 
                              VALUES (:min_sales, :bonus_percent)";
                $insertStmt = $pdo->prepare($insertSql);
                $insertStmt->execute([
                    ':min_sales' => $min_sales,
                    ':bonus_percent' => $bonus_percent
                ]);
                
                $_SESSION['success_message'] = __('bonus_config_added_success');
                header('Location: bonus-configuration.php');
                exit;
                
            } catch (PDOException $e) {
                $_SESSION['error_message'] = __('bonus_config_add_error') . ': ' . $e->getMessage();
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['min_sales'])) {
        // Delete configuration
        try {
            $deleteSql = "DELETE FROM bonus_tiers WHERE min_sales = :min_sales";
            $deleteStmt = $pdo->prepare($deleteSql);
            $deleteStmt->execute([':min_sales' => $_POST['min_sales']]);
            
            $_SESSION['success_message'] = __('bonus_config_deleted_success');
            header('Location: bonus-configuration.php');
            exit;
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = __('bonus_config_delete_error') . ': ' . $e->getMessage();
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'edit' && isset($_POST['old_min_sales'])) {
        // Edit configuration
        $old_min_sales = $_POST['old_min_sales'];
        $min_sales = $_POST['min_sales'] ?? '';
        $bonus_percent = $_POST['bonus_percent'] ?? '';
        
        // Validate inputs
        if (empty($min_sales)) {
            $errors['min_sales'] = __('min_sales_required');
        } elseif (!is_numeric($min_sales) || $min_sales < 0) {
            $errors['min_sales'] = __('min_sales_invalid');
        }
        
        if (empty($bonus_percent)) {
            $errors['bonus_percent'] = __('bonus_percentage_required');
        } elseif (!is_numeric($bonus_percent) || $bonus_percent < 0 || $bonus_percent > 100) {
            $errors['bonus_percent'] = __('bonus_percentage_invalid');
        }
        
        // Check if min_sales already exists (only if changing the min_sales value)
        if (empty($errors) && $old_min_sales != $min_sales) {
            $checkSql = "SELECT COUNT(*) FROM bonus_tiers WHERE min_sales = :min_sales";
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute([':min_sales' => $min_sales]);
            if ($checkStmt->fetchColumn() > 0) {
                $errors['min_sales'] = __('min_sales_exists');
            }
        }
        
        // If no errors, proceed with updating
        if (empty($errors)) {
            try {
                // Delete the old record and insert a new one since min_sales is the primary key
                $pdo->beginTransaction();
                
                // Delete old record
                $deleteSql = "DELETE FROM bonus_tiers WHERE min_sales = :old_min_sales";
                $deleteStmt = $pdo->prepare($deleteSql);
                $deleteStmt->execute([':old_min_sales' => $old_min_sales]);
                
                // Insert new record
                $insertSql = "INSERT INTO bonus_tiers (min_sales, bonus_percent) 
                              VALUES (:min_sales, :bonus_percent)";
                $insertStmt = $pdo->prepare($insertSql);
                $insertStmt->execute([
                    ':min_sales' => $min_sales,
                    ':bonus_percent' => $bonus_percent
                ]);
                
                $pdo->commit();
                
                $_SESSION['success_message'] = __('bonus_config_updated_success');
                header('Location: bonus-configuration.php');
                exit;
                
            } catch (PDOException $e) {
                $pdo->rollBack();
                $_SESSION['error_message'] = __('bonus_config_update_error') . ': ' . $e->getMessage();
            }
        }
    }
    
    // Refresh data if there were errors
    if (!empty($errors)) {
        try {
            $sql = "SELECT * FROM bonus_tiers ORDER BY min_sales ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $configData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Already have an error message, no need to add another
        }
    }
}
?>

<?php include 'layouts/head-main.php'; ?>

<head>
    <title><?php echo __('bonus_configuration'); ?> | <?php echo __('employee_manager_system'); ?></title>
    
    <?php include 'layouts/head.php'; ?>
    <?php include 'layouts/head-style.php'; ?>
    
    <!-- Custom CSS to fix spacing issue -->
    <style>
        .page-content {
            padding-top: 2rem !important;
        }
        .page-title-box {
            padding-bottom: 1rem !important;
            margin-bottom: 1rem !important;
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
                            <h4 class="mb-sm-0 font-size-18"><?php echo __('bonus_configuration'); ?></h4>

                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php"><?php echo __('dashboard'); ?></a></li>
                                    <li class="breadcrumb-item active"><?php echo __('bonus_configuration'); ?></li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <!-- Display error/warning/success messages -->
                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php 
                            echo $_SESSION['error_message']; 
                            unset($_SESSION['error_message']);
                        ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['warning_message'])): ?>
                    <div class="alert alert-warning alert-dismissible fade show" role="alert">
                        <?php 
                            echo $_SESSION['warning_message']; 
                            unset($_SESSION['warning_message']);
                        ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php 
                            echo $_SESSION['success_message']; 
                            unset($_SESSION['success_message']);
                        ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Add Configuration Form -->
                <div class="row">
                    <div class="col-lg-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4"><?php echo __('add_bonus_configuration'); ?></h4>
                                
                                <!-- Form -->
                                <form method="post" class="needs-validation" novalidate>
                                    <input type="hidden" name="action" value="add">
                                    <div class="row">
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label"><?php echo __('min_sales_amount'); ?></label>
                                            <div class="input-group">
                                                <span class="input-group-text"><?php echo getCurrencySymbol(); ?></span>
                                                <input type="number" class="form-control" name="min_sales" 
                                                       value="<?php echo isset($_POST['min_sales']) ? htmlspecialchars($_POST['min_sales']) : ''; ?>" 
                                                       step="1" min="0" required>
                                            </div>
                                            <?php if (isset($errors['min_sales'])): ?>
                                                <div class="invalid-feedback d-block"><?php echo $errors['min_sales']; ?></div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label"><?php echo __('bonus_percentage'); ?></label>
                                            <div class="input-group">
                                                <input type="number" class="form-control" name="bonus_percent" 
                                                       value="<?php echo isset($_POST['bonus_percent']) ? htmlspecialchars($_POST['bonus_percent']) : ''; ?>" 
                                                       step="0.01" min="0" max="100" required>
                                                <span class="input-group-text">%</span>
                                            </div>
                                            <?php if (isset($errors['bonus_percent'])): ?>
                                                <div class="invalid-feedback d-block"><?php echo $errors['bonus_percent']; ?></div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="col-md-4 mb-3 d-flex align-items-end">
                                            <button type="submit" class="btn btn-primary"><?php echo __('add_configuration'); ?></button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Configuration List -->
                <div class="row">
                    <div class="col-lg-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4"><?php echo __('bonus_configurations'); ?></h4>
                                
                                <?php if (empty($configData)): ?>
                                    <div class="alert alert-info"><?php echo __('no_bonus_configurations'); ?></div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-centered table-nowrap mb-0">
                                            <thead>
                                                <tr>
                                                    <th><?php echo __('min_sales_amount'); ?></th>
                                                    <th><?php echo __('bonus_percentage'); ?></th>
                                                    <th><?php echo __('actions'); ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($configData as $config): ?>
                                                    <tr>
                                                        <td><?php echo formatCurrency($config['min_sales']); ?></td>
                                                        <td><?php echo number_format($config['bonus_percent'], 2); ?>%</td>
                                                        <td>
                                                            <button type="button" class="btn btn-primary btn-sm edit-config" 
                                                                    data-bs-toggle="modal" data-bs-target="#editConfigModal" 
                                                                    data-min="<?php echo $config['min_sales']; ?>"
                                                                    data-percentage="<?php echo $config['bonus_percent']; ?>">
                                                                <i class="bx bx-edit-alt"></i> <?php echo __('edit'); ?>
                                                            </button>
                                                            <button type="button" class="btn btn-danger btn-sm delete-config" 
                                                                    data-bs-toggle="modal" data-bs-target="#deleteConfigModal" 
                                                                    data-min="<?php echo $config['min_sales']; ?>">
                                                                <i class="bx bx-trash"></i> <?php echo __('delete'); ?>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
            <!-- container-fluid -->
        </div>
        <!-- End Page-content -->

        <?php include 'layouts/footer.php'; ?>
    </div>
    <!-- end main content-->

</div>
<!-- END layout-wrapper -->

<?php include 'layouts/right-sidebar.php'; ?>
<?php include 'layouts/vendor-scripts.php'; ?>

<!-- Edit Configuration Modal -->
<div class="modal fade" id="editConfigModal" tabindex="-1" aria-labelledby="editConfigModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editConfigModalLabel"><?php echo __('edit_bonus_configuration'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" id="editConfigForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="old_min_sales" id="edit_old_min_sales">
                    
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('min_sales_amount'); ?></label>
                        <div class="input-group">
                            <span class="input-group-text"><?php echo getCurrencySymbol(); ?></span>
                            <input type="number" class="form-control" name="min_sales" id="edit_min_sales" step="1" min="0" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('bonus_percentage'); ?></label>
                        <div class="input-group">
                            <input type="number" class="form-control" name="bonus_percent" id="edit_bonus_percent" step="0.01" min="0" max="100" required>
                            <span class="input-group-text">%</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('close'); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo __('update'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Configuration Modal -->
<div class="modal fade" id="deleteConfigModal" tabindex="-1" aria-labelledby="deleteConfigModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteConfigModalLabel"><?php echo __('delete_bonus_configuration'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php echo __('confirm_delete_bonus_configuration'); ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                <form method="post">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="min_sales" id="delete_min_sales">
                    <button type="submit" class="btn btn-danger"><?php echo __('delete'); ?></button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    $(document).ready(function() {
        // Form validation
        $('form.needs-validation').submit(function(event) {
            var form = $(this);
            if (form[0].checkValidity() === false) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.addClass('was-validated');
        });
        
        // Edit configuration modal
        $('.edit-config').click(function() {
            var min = $(this).data('min');
            var percentage = $(this).data('percentage');
            
            $('#edit_old_min_sales').val(min);
            $('#edit_min_sales').val(min);
            $('#edit_bonus_percent').val(percentage);
        });
        
        // Delete configuration modal
        $('.delete-config').click(function() {
            var min = $(this).data('min');
            $('#delete_min_sales').val(min);
        });
    });
</script>

</body>
</html> 
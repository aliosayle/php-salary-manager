<?php
// Set up error logging
ini_set('display_errors', 1);
ini_set('error_log', __DIR__ . '/layouts/logs/error.log');
error_log("Currencies.php - Page load started");

// Initialize the session
session_start();

// Include required files
include "layouts/config.php";
include "layouts/translations.php"; // Include translations

/**
 * Validate request fields against a whitelist
 * 
 * @param array $allowedFields List of allowed field names
 * @param array $requestData The request data to validate
 * @param string $method The request method (POST, GET)
 * @return array Sanitized data containing only whitelisted fields
 */
function validateRequestFields($allowedFields, $requestData, $method = 'POST') {
    $sanitizedData = [];
    
    // Log for debugging
    error_log("Validating " . $method . " request fields: " . json_encode($requestData));
    
    // Check for unexpected fields
    $unexpectedFields = array_diff(array_keys($requestData), array_merge($allowedFields, [$method . '_token']));
    if (!empty($unexpectedFields)) {
        error_log("WARNING: Unexpected " . $method . " fields detected: " . implode(', ', $unexpectedFields));
    }
    
    // Extract and sanitize only allowed fields
    foreach ($allowedFields as $field) {
        if (isset($requestData[$field])) {
            // Basic sanitization - implement more specific sanitization as needed
            $sanitizedData[$field] = is_string($requestData[$field]) ? 
                trim($requestData[$field]) : $requestData[$field];
        }
    }
    
    return $sanitizedData;
}

// Check if user is logged in
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    error_log("Currencies.php - User not logged in, redirecting to login page");
    // Store current URL for redirect after login
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    header("location: auth-login.php");
    exit;
}

// Create PDO connection
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";port=" . DB_PORT,
        DB_USER,
        DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Connection failed: " . $e->getMessage());
}

// Verify user permissions from database
$has_view_permission = false;
$has_add_permission = false;
$has_edit_permission = false;
$has_delete_permission = false;
$has_set_main_permission = false;

if (isset($_SESSION['user_id']) && isset($_SESSION['role_id'])) {
    try {
        // Get permissions for user's role from database
        $stmt = $pdo->prepare("
            SELECT p.description_eng 
            FROM permissions p
            JOIN permissions_per_role ppr ON p.id = ppr.permission_id
            WHERE ppr.role_id = ? AND ppr.is_granted = TRUE
        ");
        $stmt->execute([$_SESSION['role_id']]);
        $db_permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Check for specific permissions
        $has_view_permission = in_array('currency_view', $db_permissions);
        $has_add_permission = in_array('currency_add', $db_permissions);
        $has_edit_permission = in_array('currency_edit', $db_permissions);
        $has_delete_permission = in_array('currency_delete', $db_permissions);
        $has_set_main_permission = in_array('currency_set_main', $db_permissions);
        
        // Log permissions for debugging
        error_log("Currencies.php - Permissions for role_id {$_SESSION['role_id']}: view=$has_view_permission, add=$has_add_permission, edit=$has_edit_permission, delete=$has_delete_permission, set_main=$has_set_main_permission");
    } catch (PDOException $e) {
        error_log("Error checking permissions: " . $e->getMessage());
    }
}

// Check if user has permission to view currencies
if (!$has_view_permission) {
    error_log("Currencies.php - User doesn't have permission to view currencies");
    $_SESSION['error_message'] = __('currencies_error_no_permission');
    header("location: index.php");
    exit;
}

// Check if dataset is selected
if (!isset($_SESSION['active_dataset'])) {
    error_log("Currencies.php - No active dataset selected");
    $_SESSION['error_message'] = __('currencies_error_no_dataset');
    header("location: index.php");
    exit;
}

$dataset_id = $_SESSION['active_dataset']['id'];
$dataset_name = $_SESSION['active_dataset']['name'];

// Handle Add Currency
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_currency']) && $has_add_permission) {
    try {
        // Define allowed fields for this form
        $allowedFields = ['add_currency', 'code', 'name', 'symbol', 'decimal_places'];
        
        // Validate and sanitize request fields
        $sanitizedData = validateRequestFields($allowedFields, $_POST);
        
        // Process only whitelisted fields
        $code = strtoupper(trim($sanitizedData['code']));
        $name = trim($sanitizedData['name']);
        $symbol = trim($sanitizedData['symbol']);
        $decimal_places = (int)$sanitizedData['decimal_places'];
        
        // Check if currency already exists for this dataset
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM currencies WHERE dataset_id = ? AND code = ?");
        $stmt->execute([$dataset_id, $code]);
        
        if ($stmt->fetchColumn() > 0) {
            $error_message = __('currencies_error_code_exists', null, ['code' => $code]);
        } else {
            // Insert new currency with a try-catch block to handle any specific insertion errors
            try {
                // Generate UUID for the new currency
                $stmt = $pdo->prepare("SELECT UUID() AS uuid");
                $stmt->execute();
                $uuid = $stmt->fetch(PDO::FETCH_ASSOC)['uuid'];
                
                $stmt = $pdo->prepare("
                    INSERT INTO currencies (id, dataset_id, code, name, symbol, decimal_places) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                
                if ($stmt->execute([$uuid, $dataset_id, $code, $name, $symbol, $decimal_places])) {
                    $currency_id = $uuid;
                    
                    // Generate UUID for the new rate
                    $stmt = $pdo->prepare("SELECT UUID() AS uuid");
                    $stmt->execute();
                    $rate_uuid = $stmt->fetch(PDO::FETCH_ASSOC)['uuid'];
                    
                    // Add initial rate = 1 for today
                    $stmt = $pdo->prepare("
                        INSERT INTO currency_rates (id, dataset_id, currency_id, rate_date, rate)
                        VALUES (?, ?, ?, CURDATE(), 1.0)
                    ");
                    
                    if ($stmt->execute([$rate_uuid, $dataset_id, $currency_id])) {
                        // Check if this is the first currency for the dataset - if so, set as main
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM currencies WHERE dataset_id = ?");
                        $stmt->execute([$dataset_id]);
                        
                        if ($stmt->fetchColumn() == 1) {
                            $stmt = $pdo->prepare("UPDATE currencies SET is_main = 1 WHERE id = ?");
                            $stmt->execute([$currency_id]);
                        }
                        
                        $success_message = __('currencies_added_success', null, ['code' => $code]);
                    } else {
                        // If rate insertion fails, we still created the currency
                        $success_message = __('currencies_added_no_rate', null, ['code' => $code]);
                    }
                } else {
                    $error_message = __('currencies_error_add_failed');
                }
            } catch (PDOException $e) {
                error_log("Error during specific currency operation: " . $e->getMessage());
                $error_message = __('currencies_error_database') . ': ' . $e->getMessage();
            }
        }
    } catch (PDOException $e) {
        error_log("Error adding currency: " . $e->getMessage());
        $error_message = __('currencies_error_database') . ': ' . $e->getMessage();
    }
}

// Handle Edit Currency
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_currency']) && $has_edit_permission) {
    try {
        // Define allowed fields for this form
        $allowedFields = ['edit_currency', 'currency_id', 'name', 'symbol', 'decimal_places'];
        
        // Validate and sanitize request fields
        $sanitizedData = validateRequestFields($allowedFields, $_POST);
        
        // Process only whitelisted fields
        $currency_id = trim($sanitizedData['currency_id']);
        $name = trim($sanitizedData['name']);
        $symbol = trim($sanitizedData['symbol']);
        $decimal_places = (int)$sanitizedData['decimal_places'];
        
        // Check if currency exists and belongs to this dataset
        $stmt = $pdo->prepare("SELECT id FROM currencies WHERE id = ? AND dataset_id = ?");
        $stmt->execute([$currency_id, $dataset_id]);
        
        if ($stmt->fetch()) {
            // Update currency
            $stmt = $pdo->prepare("
                UPDATE currencies 
                SET name = ?, symbol = ?, decimal_places = ? 
                WHERE id = ? AND dataset_id = ?
            ");
            
            if ($stmt->execute([$name, $symbol, $decimal_places, $currency_id, $dataset_id])) {
                $success_message = __('currencies_updated_success');
            } else {
                $error_message = __('currencies_error_update_failed');
            }
        } else {
            $error_message = __('currencies_error_not_found');
        }
    } catch (PDOException $e) {
        error_log("Error updating currency: " . $e->getMessage());
        $error_message = __('currencies_error_database') . ': ' . $e->getMessage();
    }
}

// Handle Set Main Currency
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['set_main_currency']) && $has_set_main_permission) {
    try {
        // Define allowed fields for this form
        $allowedFields = ['set_main_currency', 'currency_id'];
        
        // Validate and sanitize request fields
        $sanitizedData = validateRequestFields($allowedFields, $_POST);
        
        // Process only whitelisted fields
        $currency_id = trim($sanitizedData['currency_id']);
        
        // Check if currency exists and belongs to this dataset
        $stmt = $pdo->prepare("SELECT id FROM currencies WHERE id = ? AND dataset_id = ?");
        $stmt->execute([$currency_id, $dataset_id]);
        
        if ($stmt->fetch()) {
            // Begin transaction
            $pdo->beginTransaction();
            
            // Reset all currencies to non-main
            $stmt = $pdo->prepare("UPDATE currencies SET is_main = 0 WHERE dataset_id = ?");
            $stmt->execute([$dataset_id]);
            
            // Set selected currency as main
            $stmt = $pdo->prepare("UPDATE currencies SET is_main = 1 WHERE id = ?");
            $stmt->execute([$currency_id]);
            
            // Commit transaction
            $pdo->commit();
            
            $success_message = __('currencies_main_updated');
        } else {
            $error_message = __('currencies_error_not_found');
        }
    } catch (PDOException $e) {
        // Rollback transaction on error
        $pdo->rollBack();
        error_log("Error setting main currency: " . $e->getMessage());
        $error_message = __('currencies_error_database') . ': ' . $e->getMessage();
    }
}

// Handle Delete Currency
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_currency']) && $has_delete_permission) {
    try {
        // Define allowed fields for this form
        $allowedFields = ['delete_currency', 'currency_id'];
        
        // Validate and sanitize request fields
        $sanitizedData = validateRequestFields($allowedFields, $_POST);
        
        // Process only whitelisted fields
        $currency_id = trim($sanitizedData['currency_id']);
        
        // Check if currency exists and belongs to this dataset
        $stmt = $pdo->prepare("SELECT is_main FROM currencies WHERE id = ? AND dataset_id = ?");
        $stmt->execute([$currency_id, $dataset_id]);
        $currency = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($currency) {
            if ($currency['is_main']) {
                $error_message = __('currencies_error_delete_main');
            } else {
                // Begin transaction
                $pdo->beginTransaction();
                
                // First delete currency rates
                $stmt = $pdo->prepare("DELETE FROM currency_rates WHERE currency_id = ? AND dataset_id = ?");
                $stmt->execute([$currency_id, $dataset_id]);
                
                // Then delete the currency
                $stmt = $pdo->prepare("DELETE FROM currencies WHERE id = ? AND dataset_id = ?");
                
                if ($stmt->execute([$currency_id, $dataset_id])) {
                    // Commit transaction
                    $pdo->commit();
                    $success_message = __('currencies_deleted_success');
                } else {
                    // Rollback transaction on error
                    $pdo->rollBack();
                    $error_message = __('currencies_error_delete_failed');
                }
            }
        } else {
            $error_message = __('currencies_error_not_found');
        }
    } catch (PDOException $e) {
        // Rollback transaction on error
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error deleting currency: " . $e->getMessage());
        $error_message = __('currencies_error_database') . ': ' . $e->getMessage();
    }
}

// Handle Add Currency Rate
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_rate']) && $has_edit_permission) {
    try {
        // Define allowed fields for this form
        $allowedFields = ['add_rate', 'currency_id', 'rate_date', 'rate'];
        
        // Validate and sanitize request fields
        $sanitizedData = validateRequestFields($allowedFields, $_POST);
        
        // Process only whitelisted fields
        $currency_id = trim($sanitizedData['currency_id']);
        $rate_date = $sanitizedData['rate_date'];
        $rate = (float)$sanitizedData['rate'];
        
        // Check if currency exists and belongs to this dataset
        $stmt = $pdo->prepare("SELECT id FROM currencies WHERE id = ? AND dataset_id = ?");
        $stmt->execute([$currency_id, $dataset_id]);
        
        if ($stmt->fetch()) {
            // Begin transaction
            $pdo->beginTransaction();
            
            try {
                // Check if rate for this date already exists
                $stmt = $pdo->prepare("
                    SELECT id FROM currency_rates 
                    WHERE dataset_id = ? AND currency_id = ? AND rate_date = ?
                ");
                $stmt->execute([$dataset_id, $currency_id, $rate_date]);
                $existing_rate = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existing_rate) {
                    // Update existing rate
                    $stmt = $pdo->prepare("
                        UPDATE currency_rates 
                        SET rate = ? 
                        WHERE id = ?
                    ");
                    $stmt->execute([$rate, $existing_rate['id']]);
                } else {
                    // Generate UUID for the new rate
                    $stmt = $pdo->prepare("SELECT UUID() AS uuid");
                    $stmt->execute();
                    $rate_uuid = $stmt->fetch(PDO::FETCH_ASSOC)['uuid'];
                    
                    // Insert new rate
                    $stmt = $pdo->prepare("
                        INSERT INTO currency_rates (id, dataset_id, currency_id, rate_date, rate)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$rate_uuid, $dataset_id, $currency_id, $rate_date, $rate]);
                }
                
                // Commit transaction
                $pdo->commit();
                
                $success_message = __('currencies_rate_updated', null, ['date' => date('Y-m-d', strtotime($rate_date))]);
            } catch (PDOException $e) {
                // Rollback transaction on error
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e; // Re-throw to be caught by outer catch block
            }
        } else {
            $error_message = __('currencies_error_not_found');
        }
    } catch (PDOException $e) {
        error_log("Error updating currency rate: " . $e->getMessage());
        $error_message = __('currencies_error_database') . ': ' . $e->getMessage();
    }
}

// Fetch currencies for this dataset
try {
    $stmt = $pdo->prepare("
        SELECT c.*, 
            (SELECT rate FROM currency_rates WHERE currency_id = c.id AND dataset_id = c.dataset_id ORDER BY rate_date DESC LIMIT 1) as current_rate
        FROM currencies c 
        WHERE c.dataset_id = ? 
        ORDER BY c.is_main DESC, c.code
    ");
    $stmt->execute([$dataset_id]);
    $currencies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get main currency
    $main_currency = null;
    foreach ($currencies as $currency) {
        if ($currency['is_main']) {
            $main_currency = $currency;
            break;
        }
    }
} catch (PDOException $e) {
    error_log("Error fetching currencies: " . $e->getMessage());
    $currencies = [];
    $main_currency = null;
}

// Include page header
include 'layouts/head-main.php';
?>

<head>
    <title><?php echo __('currencies_management'); ?> | Rental Management System</title>
    <?php include 'layouts/head.php'; ?>
    <?php include 'layouts/head-style.php'; ?>
    <!-- Custom CSS to fix spacing issue -->
    <style>
        .page-content {
            padding-top: 0.5rem !important;
        }
        .page-title-box {
            padding-bottom: 0.5rem !important;
            margin-bottom: 0.5rem !important;
        }
    </style>
</head>

<?php include 'layouts/body.php'; ?>

<!-- Begin page -->
<div id="layout-wrapper">
    
    <?php include 'layouts/header.php'; ?>
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
                        <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                            <h4 class="mb-sm-0 font-size-18"><?php echo __('currencies_management'); ?></h4>

                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="javascript: void(0);"><?php echo __('administration'); ?></a></li>
                                    <li class="breadcrumb-item active"><?php echo __('currencies_management'); ?></li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <?php if (isset($success_message) || isset($error_message)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-<?php echo isset($success_message) ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
                            <?php echo isset($success_message) ? $success_message : $error_message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="card-title mb-0"><?php echo __('currencies_for_dataset'); ?> <?php echo htmlspecialchars($dataset_name); ?></h5>
                                    <?php if ($has_add_permission): ?>
                                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCurrencyModal">
                                        <i class="bx bx-plus me-1"></i> <?php echo __('currencies_add'); ?>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (empty($currencies)): ?>
                                <div class="alert alert-info" role="alert">
                                    <?php echo __('currencies_empty_list'); ?>
                                </div>
                                <?php else: ?>
                                <div class="row mb-4">
                                    <div class="col-md-6">
                                        <div class="alert alert-info mb-0">
                                            <h5 class="alert-heading"><?php echo __('currencies_main'); ?></h5>
                                            <p class="mb-0">
                                                <?php if ($main_currency): ?>
                                                <strong><?php echo htmlspecialchars($main_currency['code']); ?></strong> - 
                                                <?php echo htmlspecialchars($main_currency['name']); ?> 
                                                (<?php echo htmlspecialchars($main_currency['symbol']); ?>)
                                                <?php else: ?>
                                                <?php echo __('currencies_no_main'); ?>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="table-responsive">
                                    <table id="currencies-table" class="table table-striped table-bordered dt-responsive nowrap">
                                        <thead>
                                            <tr>
                                                <th></th>
                                                <th><?php echo __('currencies_code'); ?></th>
                                                <th><?php echo __('currencies_name'); ?></th>
                                                <th><?php echo __('currencies_symbol'); ?></th>
                                                <th><?php echo __('currencies_decimal_places'); ?></th>
                                                <th><?php echo __('currencies_current_rate'); ?></th>
                                                <th><?php echo __('currencies_status'); ?></th>
                                                <th><?php echo __('currencies_actions'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($currencies as $currency): ?>
                                            <tr>
                                                <td></td>
                                                <td><?php echo htmlspecialchars($currency['code']); ?></td>
                                                <td><?php echo htmlspecialchars($currency['name']); ?></td>
                                                <td><?php echo htmlspecialchars($currency['symbol']); ?></td>
                                                <td><?php echo $currency['decimal_places']; ?></td>
                                                <td><?php echo isset($currency['current_rate']) ? number_format($currency['current_rate'], 10) : __('not_available'); ?></td>
                                                <td>
                                                    <?php if ($currency['is_main']): ?>
                                                    <span class="badge bg-success"><?php echo __('currencies_status_main'); ?></span>
                                                    <?php else: ?>
                                                    <span class="badge bg-info"><?php echo __('currencies_status_secondary'); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="d-flex gap-1">
                                                        <?php if ($has_edit_permission): ?>
                                                        <button type="button" class="btn btn-sm btn-primary edit-currency" 
                                                            data-id="<?php echo $currency['id']; ?>" 
                                                            data-code="<?php echo htmlspecialchars($currency['code']); ?>" 
                                                            data-name="<?php echo htmlspecialchars($currency['name']); ?>"
                                                            data-symbol="<?php echo htmlspecialchars($currency['symbol']); ?>"
                                                            data-decimal-places="<?php echo $currency['decimal_places']; ?>"
                                                            title="<?php echo __('currencies_edit'); ?>"
                                                            data-toggle="tooltip"
                                                            data-placement="top">
                                                            <i class="bx bx-edit"></i>
                                                        </button>
                                                        
                                                        <button type="button" class="btn btn-sm btn-info add-rate" 
                                                            data-id="<?php echo $currency['id']; ?>" 
                                                            data-code="<?php echo htmlspecialchars($currency['code']); ?>"
                                                            title="<?php echo __('currencies_add_rate'); ?>"
                                                            data-toggle="tooltip"
                                                            data-placement="top">
                                                            <i class="bx bx-chart"></i>
                                                        </button>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($has_set_main_permission && !$currency['is_main']): ?>
                                                        <button type="button" class="btn btn-sm btn-success set-main"
                                                            data-id="<?php echo $currency['id']; ?>" 
                                                            data-code="<?php echo htmlspecialchars($currency['code']); ?>"
                                                            title="<?php echo __('currencies_set_main'); ?>"
                                                            data-toggle="tooltip"
                                                            data-placement="top">
                                                            <i class="bx bx-star"></i>
                                                        </button>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($has_delete_permission && !$currency['is_main']): ?>
                                                        <button type="button" class="btn btn-sm btn-danger delete-currency"
                                                            data-id="<?php echo $currency['id']; ?>" 
                                                            data-code="<?php echo htmlspecialchars($currency['code']); ?>"
                                                            title="<?php echo __('currencies_delete'); ?>"
                                                            data-toggle="tooltip"
                                                            data-placement="top">
                                                            <i class="bx bx-trash"></i>
                                                        </button>
                                                        <?php endif; ?>
                                                        
                                                        <button type="button" class="btn btn-sm btn-secondary view-history" 
                                                            data-id="<?php echo $currency['id']; ?>" 
                                                            data-code="<?php echo htmlspecialchars($currency['code']); ?>"
                                                            title="<?php echo __('currencies_view_history'); ?>"
                                                            data-toggle="tooltip"
                                                            data-placement="top">
                                                            <i class="bx bx-history"></i>
                                                        </button>
                                                    </div>
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
                
            </div> <!-- container-fluid -->
        </div>
        <!-- End Page-content -->
        
        <?php include 'layouts/footer.php'; ?>
    </div>
    <!-- end main content-->
</div>
<!-- END layout-wrapper -->

<!-- Add Currency Modal -->
<div class="modal fade" id="addCurrencyModal" tabindex="-1" aria-labelledby="addCurrencyModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addCurrencyModalLabel"><?php echo __('currencies_add_title'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="code" class="form-label"><?php echo __('currencies_code'); ?></label>
                        <input type="text" class="form-control" id="code" name="code" maxlength="10" placeholder="e.g. USD" required>
                        <div class="invalid-feedback">
                            <?php echo __('currencies_error_provide_code'); ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="name" class="form-label"><?php echo __('currencies_name'); ?></label>
                        <input type="text" class="form-control" id="name" name="name" maxlength="100" placeholder="e.g. US Dollar" required>
                        <div class="invalid-feedback">
                            <?php echo __('currencies_error_provide_name'); ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="symbol" class="form-label"><?php echo __('currencies_symbol'); ?></label>
                        <input type="text" class="form-control" id="symbol" name="symbol" maxlength="10" placeholder="e.g. $" required>
                        <div class="invalid-feedback">
                            <?php echo __('currencies_error_provide_symbol'); ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="decimal_places" class="form-label"><?php echo __('currencies_decimal_places'); ?></label>
                        <input type="number" class="form-control" id="decimal_places" name="decimal_places" min="0" max="10" value="2" required>
                        <div class="invalid-feedback">
                            <?php echo __('currencies_error_provide_decimal_places'); ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" name="add_currency" class="btn btn-primary"><?php echo __('currencies_add'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Currency Modal -->
<div class="modal fade" id="editCurrencyModal" tabindex="-1" aria-labelledby="editCurrencyModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editCurrencyModalLabel"><?php echo __('currencies_edit_title'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" id="edit_currency_id" name="currency_id">
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('currencies_code'); ?></label>
                        <input type="text" class="form-control" id="edit_code" readonly>
                        <small class="text-muted"><?php echo __('currencies_code_readonly'); ?></small>
                    </div>
                    <div class="mb-3">
                        <label for="edit_name" class="form-label"><?php echo __('currencies_name'); ?></label>
                        <input type="text" class="form-control" id="edit_name" name="name" maxlength="100" required>
                        <div class="invalid-feedback">
                            <?php echo __('currencies_error_provide_name'); ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_symbol" class="form-label"><?php echo __('currencies_symbol'); ?></label>
                        <input type="text" class="form-control" id="edit_symbol" name="symbol" maxlength="10" required>
                        <div class="invalid-feedback">
                            <?php echo __('currencies_error_provide_symbol'); ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_decimal_places" class="form-label"><?php echo __('currencies_decimal_places'); ?></label>
                        <input type="number" class="form-control" id="edit_decimal_places" name="decimal_places" min="0" max="10" required>
                        <div class="invalid-feedback">
                            <?php echo __('currencies_error_provide_decimal_places'); ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" name="edit_currency" class="btn btn-primary"><?php echo __('save'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Rate Modal -->
<div class="modal fade" id="addRateModal" tabindex="-1" aria-labelledby="addRateModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addRateModalLabel"><?php echo __('currencies_add_rate_for'); ?> <span id="rate_currency_code"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" id="rate_currency_id" name="currency_id">
                    <div class="mb-3">
                        <label for="rate_date" class="form-label"><?php echo __('currencies_rate_date'); ?></label>
                        <input type="date" class="form-control" id="rate_date" name="rate_date" value="<?php echo date('Y-m-d'); ?>" required>
                        <div class="invalid-feedback">
                            <?php echo __('currencies_error_provide_date'); ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="rate" class="form-label"><?php echo __('currencies_exchange_rate'); ?></label>
                        <div class="input-group">
                            <span class="input-group-text">1 <?php echo $main_currency ? htmlspecialchars($main_currency['code']) : __('currencies_main_label'); ?> =</span>
                            <input type="number" class="form-control" id="rate" name="rate" step="0.0000000001" min="0.0000000001" required>
                            <span class="input-group-text" id="rate_currency_code_addon"></span>
                        </div>
                        <div class="invalid-feedback">
                            <?php echo __('currencies_error_provide_rate'); ?>
                        </div>
                        <small class="text-muted mt-1"><?php echo __('currencies_rate_explanation'); ?></small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" name="add_rate" class="btn btn-primary"><?php echo __('save'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Set Main Currency Modal -->
<div class="modal fade" id="setMainModal" tabindex="-1" aria-labelledby="setMainModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="setMainModalLabel"><?php echo __('currencies_set_main_title'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" id="main_currency_id" name="currency_id">
                    <p><?php echo __('currencies_set_main_confirm'); ?> <strong id="main_currency_code"></strong>?</p>
                    <div class="alert alert-info">
                        <i class="bx bx-info-circle me-1"></i> 
                        <?php echo __('currencies_set_main_info'); ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" name="set_main_currency" class="btn btn-success"><?php echo __('currencies_set_main_button'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Currency Modal -->
<div class="modal fade" id="deleteCurrencyModal" tabindex="-1" aria-labelledby="deleteCurrencyModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteCurrencyModalLabel"><?php echo __('currencies_delete_title'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" id="delete_currency_id" name="currency_id">
                    <p><?php echo __('currencies_delete_confirm'); ?> <strong id="delete_currency_code"></strong>?</p>
                    <div class="alert alert-danger">
                        <i class="bx bx-error-circle me-1"></i> 
                        <?php echo __('currencies_delete_warning'); ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" name="delete_currency" class="btn btn-danger"><?php echo __('delete'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View History Modal -->
<div class="modal fade" id="historyModal" tabindex="-1" aria-labelledby="historyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="historyModalLabel"><?php echo __('currencies_history_title'); ?> <span id="history_currency_code"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="history-loading" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden"><?php echo __('loading'); ?></span>
                    </div>
                    <p class="mt-2"><?php echo __('currencies_loading_history'); ?></p>
                </div>
                <div id="history-error" class="alert alert-danger d-none"></div>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="history-date-picker" class="form-label"><?php echo __('currencies_select_date'); ?></label>
                            <input type="date" class="form-control" id="history-date-picker" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
                        <button id="load-rate-for-date" class="btn btn-primary"><?php echo __('currencies_load_rate'); ?></button>
                    </div>
                </div>
                
                <div id="history-content" class="d-none">
                    <div class="alert alert-info">
                        <div class="row">
                            <div class="col-md-6">
                                <strong><?php echo __('currencies_rate_date'); ?>:</strong> 
                                <span id="display-selected-date"><?php echo date('Y-m-d'); ?></span>
                            </div>
                            <div class="col-md-6">
                                <strong><?php echo __('currencies_rate'); ?>:</strong> 
                                <span id="display-selected-rate">-</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div id="history-empty" class="alert alert-info d-none">
                    <?php echo __('currencies_no_rate_for_date'); ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('close'); ?></button>
            </div>
        </div>
    </div>
</div>

<?php include 'layouts/vendor-scripts.php'; ?>

<!-- Required datatable js -->
<script src="assets/libs/datatables.net/js/jquery.dataTables.min.js"></script>
<script src="assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js"></script>
<script src="assets/libs/datatables.net-responsive/js/dataTables.responsive.min.js"></script>

<script>
    $(document).ready(function() {
        // Debug
        console.log("Document ready, initializing table and buttons");
        
        // Initialize DataTable
        var table = $('#currencies-table').DataTable({
            responsive: {
                details: {
                    type: 'column',
                    target: 'tr'
                }
            },
            columnDefs: [{
                className: 'control',
                orderable: false,
                targets: 0
            }],
            order: [[1, 'asc']], // Sort by code
            language: {
                paginate: {
                    first: "<?php echo str_replace("'", "", __('First')); ?>",
                    last: "<?php echo str_replace("'", "", __('Last')); ?>",
                    next: "<?php echo str_replace("'", "", __('Next')); ?>",
                    previous: "<?php echo str_replace("'", "", __('Previous')); ?>"
                },
                search: "<?php echo str_replace("'", "", __('Search')); ?>:",
                lengthMenu: "<?php echo str_replace("'", "", __('Show')); ?> _MENU_ <?php echo str_replace("'", "", __('entries')); ?>",
                info: "<?php echo str_replace("'", "", __('Showing')); ?> _START_ <?php echo str_replace("'", "", __('to')); ?> _END_ <?php echo str_replace("'", "", __('of')); ?> _TOTAL_ <?php echo str_replace("'", "", __('entries')); ?>",
                infoEmpty: "<?php echo str_replace("'", "", __('No entries to show')); ?>",
                infoFiltered: "(<?php echo str_replace("'", "", __('filtered from')); ?> _MAX_ <?php echo str_replace("'", "", __('total entries')); ?>)",
                emptyTable: "<?php echo str_replace("'", "", __('No data available in table')); ?>",
                zeroRecords: "<?php echo str_replace("'", "", __('No matching records found')); ?>"
            }
        });

        // Initialize tooltips
        $('[data-toggle="tooltip"]').tooltip();

        // Form validation
        $('form').submit(function(e) {
            var form = $(this)[0];
            if (form.checkValidity() === false) {
                e.preventDefault();
                e.stopPropagation();
            }
            form.classList.add('was-validated');
        });

        // Direct event handlers - don't use delegation with initEventHandlers
        
        // Edit Currency
        $(document).on('click', '.edit-currency', function() {
            console.log("Edit currency clicked");
            var id = $(this).data('id');
            var code = $(this).data('code');
            var name = $(this).data('name');
            var symbol = $(this).data('symbol');
            var decimalPlaces = $(this).data('decimal-places');
            
            $('#edit_currency_id').val(id);
            $('#edit_code').val(code);
            $('#edit_name').val(name);
            $('#edit_symbol').val(symbol);
            $('#edit_decimal_places').val(decimalPlaces);
            
            $('#editCurrencyModal').modal('show');
        });

        // Add Rate
        $(document).on('click', '.add-rate', function() {
            console.log("Add rate clicked");
            var id = $(this).data('id');
            var code = $(this).data('code');
            
            $('#rate_currency_id').val(id);
            $('#rate_currency_code').text(code);
            $('#rate_currency_code_addon').text(code);
            
            $('#addRateModal').modal('show');
        });

        // Set Main Currency
        $(document).on('click', '.set-main', function() {
            console.log("Set main clicked");
            var id = $(this).data('id');
            var code = $(this).data('code');
            
            $('#main_currency_id').val(id);
            $('#main_currency_code').text(code);
            
            $('#setMainModal').modal('show');
        });

        // Delete Currency
        $(document).on('click', '.delete-currency', function() {
            console.log("Delete currency clicked");
            var id = $(this).data('id');
            var code = $(this).data('code');
            
            $('#delete_currency_id').val(id);
            $('#delete_currency_code').text(code);
            
            $('#deleteCurrencyModal').modal('show');
        });

        // View Currency History
        $(document).on('click', '.view-history', function() {
            console.log("View history clicked");
            var id = $(this).data('id');
            var code = $(this).data('code');
            
            $('#history_currency_code').text(code);
            $('#history-loading').removeClass('d-none');
            $('#history-content').addClass('d-none');
            $('#history-error').addClass('d-none');
            $('#history-empty').addClass('d-none');
            
            // Set today's date as default
            var today = new Date().toISOString().split('T')[0];
            $('#history-date-picker').val(today);
            $('#display-selected-date').text(today);
            
            // Store the currency ID and code globally for the modal
            $('#historyModal').data('currency-id', id);
            $('#historyModal').data('currency-code', code);
            
            // Load rate for today's date
            loadRateForDate(id, today);
            
            $('#historyModal').modal('show');
        });
        
        // Handle date change and load rate for the selected date
        $('#load-rate-for-date').on('click', function() {
            var currencyId = $('#historyModal').data('currency-id');
            var selectedDate = $('#history-date-picker').val();
            
            loadRateForDate(currencyId, selectedDate);
        });
        
        // Function to load rate for a specific date
        function loadRateForDate(currencyId, date) {
            $('#history-loading').removeClass('d-none');
            $('#history-content').addClass('d-none');
            $('#history-error').addClass('d-none');
            $('#history-empty').addClass('d-none');
            
            $('#display-selected-date').text(date);
            
            // Load currency rate with AJAX
            $.ajax({
                url: 'ajax-currency-history.php',
                type: 'GET',
                data: { 
                    currency_id: currencyId,
                    dataset_id: '<?php echo $dataset_id; ?>',
                    date: date
                },
                dataType: 'json',
                success: function(response) {
                    console.log("AJAX success", response);
                    $('#history-loading').addClass('d-none');
                    
                    if (response.status === 'success') {
                        if (response.rate !== undefined && response.rate !== null) {
                            // Display the rate for the selected date
                            $('#display-selected-rate').text(parseFloat(response.rate).toFixed(10));
                            $('#history-content').removeClass('d-none');
                        } else {
                            // No rate found for the selected date
                            $('#history-empty').removeClass('d-none');
                        }
                    } else {
                        $('#history-error').text(response.message || <?php echo json_encode(__('currencies_error_generic')); ?>).removeClass('d-none');
                    }
                },
                error: function(xhr, status, error) {
                    console.error("AJAX error", xhr, status, error);
                    $('#history-loading').addClass('d-none');
                    $('#history-error').text(<?php echo json_encode(__('currencies_error_loading_history')); ?> + ': ' + error).removeClass('d-none');
                }
            });
        }

        // Auto-refresh page after a successful operation to ensure table is updated
        <?php if (isset($success_message)): ?>
        // Delay reload to allow success message to be seen
        // Add a flag in sessionStorage to prevent reload loops
        if (!sessionStorage.getItem('just_reloaded')) {
            sessionStorage.setItem('just_reloaded', 'true');
            setTimeout(function() {
                location.reload();
            }, 1500);
        } else {
            // Clear the flag after the page has loaded
            setTimeout(function() {
                sessionStorage.removeItem('just_reloaded');
            }, 500);
        }
        <?php endif; ?>
    });
</script>

</body>
</html> 
 
 
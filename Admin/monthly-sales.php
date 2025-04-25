<?php
// Start output buffering
ob_start();

// Set up error logging
ini_set('display_errors', 1);
ini_set('error_log', __DIR__ . '/layouts/logs/error.log');
error_log("Monthly-sales.php - Page load started");

// Start a PHP session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    error_log("Monthly-sales.php - User not logged in, redirecting to login page");
    // Store current URL for redirect after login
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    header("location: auth-login.php");
    exit;
}

// Include required files
require_once "layouts/config.php";
require_once "layouts/helpers.php";
require_once "layouts/session.php";

// Include translations
require_once "layouts/translations.php";

// Check if user has permission to manage monthly sales
if (!hasPermission('manage_monthly_sales')) {
    error_log("Monthly-sales.php - User does not have permission to manage monthly sales");
    $_SESSION['error_message'] = __('no_permission_manage_monthly_sales');
    header('Location: index.php');
    exit;
}

// Handle error translations
function getErrorTranslation($errorMessage) {
    switch ($errorMessage) {
        case "Please select a shop":
            return __('shop_required');
        case "Please select a valid month":
            return __('valid_month_required');
        case "Please select a valid year":
            return __('valid_year_required');
        case "Sales amount must be greater than zero":
            return __('sales_amount_greater_than_zero');
        case "Database error":
            return __('database_error');
        case "Invalid data for deletion":
            return __('invalid_data_deletion');
        case "Error fetching shops":
            return __('error_fetching_shops');
        case "Error fetching sales data":
            return __('error_fetching_sales_data');
        case "Month not open for editing":
            return __('month_not_open_for_editing');
        default:
            return $errorMessage;
    }
}

// Initialize variables
$errors = [];
$months = getMonthsList();
$years = getYearsList(4);
$currentMonth = (int)date('m');
$currentYear = (int)date('Y');
$selectedMonth = isset($_GET['month']) ? (int)$_GET['month'] : $currentMonth;
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : $currentYear;
$selectedShop = isset($_GET['shop_id']) ? $_GET['shop_id'] : '';

// Handle AJAX request for bonus calculation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'calculate_bonus') {
    $salesAmount = isset($_POST['sales_amount']) ? (float)$_POST['sales_amount'] : 0;
    
    $bonusPercent = getBonusPercentage($salesAmount, $pdo);
    $bonusAmount = calculateBonusAmount($salesAmount, $pdo);
    
    // Return JSON response
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'bonus_percent' => $bonusPercent,
        'bonus_amount' => $bonusAmount,
        'bonus_formatted' => getCurrencySymbol() . number_format($bonusAmount, 2)
    ]);
    exit;
}

// Function to get bonus percentage based on sales amount
function getBonusPercentage($salesAmount, $pdo) {
    try {
        if ($salesAmount <= 0) {
            return 0;
        }
        
        $stmt = $pdo->prepare("SELECT bonus_percent FROM bonus_tiers 
                              WHERE :sales_amount >= min_sales 
                              ORDER BY min_sales DESC LIMIT 1");
        $stmt->execute([':sales_amount' => $salesAmount]);
        
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            return $row['bonus_percent'];
        }
        
        return 0;
    } catch (PDOException $e) {
        error_log("Error calculating bonus percentage: " . $e->getMessage());
        return 0;
    }
}

// Function to calculate bonus amount based on sales amount
function calculateBonusAmount($salesAmount, $pdo) {
    $bonusPercent = getBonusPercentage($salesAmount, $pdo);
    return $salesAmount * ($bonusPercent / 100);
}

// Fetch open months
$openMonths = [];
try {
    $stmtOpenMonths = $pdo->prepare("SELECT month, year FROM months WHERE is_open = 1 ORDER BY year DESC, month DESC");
    $stmtOpenMonths->execute();
    $openMonthsResults = $stmtOpenMonths->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($openMonthsResults as $month) {
        $openMonths[] = [
            'month' => (int)$month['month'],
            'year' => (int)$month['year']
        ];
    }
    
    error_log("Monthly-sales.php - Successfully fetched " . count($openMonths) . " open months");
} catch (PDOException $e) {
    error_log("Monthly-sales.php - Error fetching open months: " . $e->getMessage());
    $errors[] = __('error_fetching_open_months') . ": " . $e->getMessage();
}

// If no open months, include current month for display
if (empty($openMonths)) {
    $openMonths[] = [
        'month' => $currentMonth,
        'year' => $currentYear
    ];
}

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle batch save operation
    if (isset($_POST['batch_save']) && isset($_POST['sales'])) {
        $month = isset($_POST['selected_month']) ? (int)$_POST['selected_month'] : $currentMonth;
        $year = isset($_POST['selected_year']) ? (int)$_POST['selected_year'] : $currentYear;
        $salesData = $_POST['sales'];
        $successCount = 0;
        $errorCount = 0;
        
        error_log("Monthly-sales.php - Batch save operation started for month: $month, year: $year");
        
        // Check if the month is open
        $isMonthOpen = false;
        foreach ($openMonths as $openMonth) {
            if ($openMonth['month'] == $month && $openMonth['year'] == $year) {
                $isMonthOpen = true;
                break;
            }
        }
        
        if (!$isMonthOpen) {
            $errors[] = __('month_not_open_for_editing');
            error_log("Monthly-sales.php - Attempt to save data for closed month: $month/$year");
        } else {
            // Create sales_month date
            $salesMonth = sprintf('%04d-%02d-01', $year, $month);
            
            // Begin transaction
            $pdo->beginTransaction();
            
            try {
                foreach ($salesData as $shopId => $amount) {
                    // Skip empty values
                    if ($amount === '' || $amount === null) {
                        continue;
                    }
                    
                    $amount = (float)$amount;
                    
                    // Validate amount
                    if ($amount <= 0) {
                        continue;
                    }
                    
                    // Check if record already exists
                    $checkStmt = $pdo->prepare("SELECT id FROM monthly_sales WHERE shop_id = :shop_id AND sales_month = :sales_month");
                    $checkStmt->execute([
                        ':shop_id' => $shopId,
                        ':sales_month' => $salesMonth
                    ]);
                    
                    if ($checkStmt->rowCount() > 0) {
                        // Update existing record
                        $record = $checkStmt->fetch(PDO::FETCH_ASSOC);
                        $updateStmt = $pdo->prepare("UPDATE monthly_sales SET sales_amount = :sales_amount, created_at = NOW() WHERE id = :id");
                        $updateStmt->execute([
                            ':sales_amount' => $amount,
                            ':id' => $record['id']
                        ]);
                    } else {
                        // Insert new record
                        $insertStmt = $pdo->prepare("INSERT INTO monthly_sales (id, shop_id, sales_month, sales_amount, created_at) VALUES (UUID(), :shop_id, :sales_month, :sales_amount, NOW())");
                        $insertStmt->execute([
                            ':shop_id' => $shopId,
                            ':sales_month' => $salesMonth,
                            ':sales_amount' => $amount
                        ]);
                    }
                    
                    $successCount++;
                }
                
                // Commit transaction
                $pdo->commit();
                
                if ($successCount > 0) {
                    $_SESSION['success_message'] = sprintf(__('batch_sales_saved_success'), $successCount);
                    error_log("Monthly-sales.php - Batch save successful, $successCount records saved");
                } else {
                    $_SESSION['info_message'] = __('no_sales_data_changed');
                    error_log("Monthly-sales.php - Batch save operation: no data changed");
                }
                
                // Redirect to avoid form resubmission
                header("Location: monthly-sales.php?month=$month&year=$year");
                exit;
            } catch (PDOException $e) {
                // Rollback transaction on error
                $pdo->rollBack();
                error_log("Monthly-sales.php - Database error during batch save: " . $e->getMessage());
                $errors[] = __('database_error') . ": " . $e->getMessage();
            }
        }
    }
    
    // Handle sales record addition or update
    if (isset($_POST['add_sales']) || isset($_POST['update_sales'])) {
        $shopId = isset($_POST['shop_id']) ? $_POST['shop_id'] : '';
        $month = isset($_POST['month']) ? (int)$_POST['month'] : 0;
        $year = isset($_POST['year']) ? (int)$_POST['year'] : 0;
        $salesAmount = isset($_POST['sales_amount']) ? (float)$_POST['sales_amount'] : 0;
        
        // Validate inputs
        if (empty($shopId)) {
            $errors[] = "Please select a shop";
        }
        
        if ($month === 0 || $month < 1 || $month > 12) {
            $errors[] = "Please select a valid month";
        }
        
        if ($year === 0) {
            $errors[] = "Please select a valid year";
        }
        
        if ($salesAmount <= 0) {
            $errors[] = "Sales amount must be greater than zero";
        }
        
        if (empty($errors)) {
            try {
                // Create sales_month date
                $salesMonth = sprintf('%04d-%02d-01', $year, $month); // First day of month

                // Check if record already exists
                $checkStmt = $pdo->prepare("SELECT * FROM monthly_sales WHERE shop_id = :shop_id AND sales_month = :sales_month");
                $checkStmt->execute([
                    ':shop_id' => $shopId,
                    ':sales_month' => $salesMonth
                ]);
                
                if ($checkStmt->rowCount() > 0) {
                    // Update existing record
                    $updateStmt = $pdo->prepare("UPDATE monthly_sales SET sales_amount = :sales_amount, created_at = NOW() WHERE shop_id = :shop_id AND sales_month = :sales_month");
                    $updateStmt->execute([
                        ':sales_amount' => $salesAmount,
                        ':shop_id' => $shopId,
                        ':sales_month' => $salesMonth
                    ]);
                    
                    $_SESSION['success_message'] = __('sales_record_updated_success');
                    error_log("Monthly-sales.php - Sales record updated successfully for shop: $shopId, month: $month, year: $year");
                } else {
                    // Insert new record
                    $insertStmt = $pdo->prepare("INSERT INTO monthly_sales (id, shop_id, sales_month, sales_amount, created_at) VALUES (UUID(), :shop_id, :sales_month, :sales_amount, NOW())");
                    $insertStmt->execute([
                        ':shop_id' => $shopId,
                        ':sales_month' => $salesMonth,
                        ':sales_amount' => $salesAmount
                    ]);
                    
                    $_SESSION['success_message'] = __('sales_record_added_success');
                    error_log("Monthly-sales.php - Sales record added successfully for shop: $shopId, month: $month, year: $year");
                }
                
                // Redirect to avoid form resubmission
                header("Location: monthly-sales.php?shop_id=$shopId&month=$month&year=$year");
                exit;
            } catch (PDOException $e) {
                error_log("Monthly-sales.php - Database error when processing sales record: " . $e->getMessage());
                $errors[] = __('database_error') . ": " . $e->getMessage();
            }
        } else {
            // Translate errors
            $errors = array_map('getErrorTranslation', $errors);
        }
    }
    
    // Handle sales record deletion
    if (isset($_POST['delete_sales'])) {
        $shopId = isset($_POST['shop_id']) ? $_POST['shop_id'] : '';
        $month = isset($_POST['month']) ? (int)$_POST['month'] : 0;
        $year = isset($_POST['year']) ? (int)$_POST['year'] : 0;
        
        error_log("Monthly-sales.php - Delete operation requested for shop: $shopId, month: $month, year: $year");
        
        if (!empty($shopId) && $month > 0 && $year > 0) {
            // Check if the month is open
            $isMonthOpen = false;
            foreach ($openMonths as $openMonth) {
                if ($openMonth['month'] == $month && $openMonth['year'] == $year) {
                    $isMonthOpen = true;
                    break;
                }
            }
            
            if (!$isMonthOpen) {
                $errors[] = __('month_not_open_for_editing');
                error_log("Monthly-sales.php - Attempt to delete data for closed month: $month/$year");
            } else {
                try {
                    // Create sales_month date
                    $salesMonth = sprintf('%04d-%02d-01', $year, $month); // First day of month
                    
                    $deleteStmt = $pdo->prepare("DELETE FROM monthly_sales WHERE shop_id = :shop_id AND sales_month = :sales_month");
                    $deleteStmt->execute([
                        ':shop_id' => $shopId,
                        ':sales_month' => $salesMonth
                    ]);
                    
                    if ($deleteStmt->rowCount() > 0) {
                        $_SESSION['success_message'] = __('sales_record_deleted_success');
                        error_log("Monthly-sales.php - Sales record deleted successfully for shop: $shopId, month: $month, year: $year");
                    } else {
                        $_SESSION['error_message'] = __('no_sales_record_found');
                        error_log("Monthly-sales.php - No sales record found to delete for shop: $shopId, month: $month, year: $year");
                    }
                    
                    // Redirect to avoid form resubmission
                    header("Location: monthly-sales.php");
                    exit;
                } catch (PDOException $e) {
                    error_log("Monthly-sales.php - Database error when deleting sales record: " . $e->getMessage());
                    $errors[] = __('database_error') . ": " . $e->getMessage();
                }
            }
        } else {
            error_log("Monthly-sales.php - Invalid data for deletion: shop: $shopId, month: $month, year: $year");
            $errors[] = __('invalid_data_deletion');
        }
    }
}

// Fetch shops
$shops = [];
try {
    $shopStmt = $pdo->prepare("SELECT id, name FROM shops ORDER BY name ASC");
    $shopStmt->execute();
    $shops = $shopStmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Monthly-sales.php - Successfully fetched " . count($shops) . " shops");
} catch (PDOException $e) {
    error_log("Monthly-sales.php - Error fetching shops: " . $e->getMessage());
    $errors[] = __('error_fetching_shops') . ": " . $e->getMessage();
}

// Fetch all bonus tiers for the key table
$bonusTiers = [];
try {
    $bonusTiersStmt = $pdo->prepare("SELECT min_sales, bonus_percent FROM bonus_tiers ORDER BY min_sales ASC");
    $bonusTiersStmt->execute();
    $bonusTiers = $bonusTiersStmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Monthly-sales.php - Successfully fetched " . count($bonusTiers) . " bonus tiers");
} catch (PDOException $e) {
    error_log("Monthly-sales.php - Error fetching bonus tiers: " . $e->getMessage());
    // Not showing this error to user since it's just for the key table
}

// Fetch all shops with their sales data (if exists)
$allShopsWithSales = [];
try {
    // Build the query based on filters
    $query = "
        SELECT s.id as shop_id, s.name as shop_name, 
               ms.id as sales_id, ms.sales_amount,
               COALESCE(MONTH(ms.sales_month), :default_month) as month,
               COALESCE(YEAR(ms.sales_month), :default_year) as year,
               MAX(e.full_name) as manager_name
        FROM shops s
        LEFT JOIN (
            SELECT * FROM monthly_sales 
            WHERE (1=1)
    ";
    
    // Add month and year conditions in the subquery
    $subQueryConditions = [];
    $params = [
        ':default_month' => $selectedMonth ?: $currentMonth,
        ':default_year' => $selectedYear ?: $currentYear
    ];
    
    if (!empty($selectedMonth)) {
        $subQueryConditions[] = "MONTH(sales_month) = :selected_month";
        $params[':selected_month'] = $selectedMonth;
    }
    
    if (!empty($selectedYear)) {
        $subQueryConditions[] = "YEAR(sales_month) = :selected_year";
        $params[':selected_year'] = $selectedYear;
    }
    
    if (!empty($subQueryConditions)) {
        $query .= " AND " . implode(" AND ", $subQueryConditions);
    }
    
    // Complete the query
    $query .= "
        ) ms ON s.id = ms.shop_id
        LEFT JOIN employee_shops es ON s.id = es.shop_id
        LEFT JOIN employees e ON es.employee_id = e.id
        LEFT JOIN posts p ON e.post_id = p.id AND (LOWER(p.title) LIKE '%manager%' OR LOWER(p.title) LIKE '%gerant%')
        WHERE 1=1
    ";
    
    // Add shop filter if provided
    if (!empty($selectedShop)) {
        $query .= " AND s.id = :shop_id";
        $params[':shop_id'] = $selectedShop;
    }
    
    // Group by to avoid duplicate rows for shops
    $query .= " GROUP BY s.id, ms.id";
    
    // Add order by
    $query .= " ORDER BY s.name ASC";
    
    error_log("Monthly-sales.php - Executing shop sales query with params: " . json_encode($params));
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $allShopsWithSales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("Monthly-sales.php - Successfully fetched " . count($allShopsWithSales) . " shop sales records");
} catch (PDOException $e) {
    error_log("Monthly-sales.php - Error fetching sales data: " . $e->getMessage());
    $errors[] = __('error_fetching_sales_data') . ": " . $e->getMessage();
}

// Page title
$page_title = $_SESSION['lang'] == 'fr' ? 'Ventes Mensuelles' : 'Monthly Sales';
$page_description = $_SESSION['lang'] == 'fr' ? 'Gérer et suivre les ventes mensuelles pour tous les magasins' : 'Manage and track monthly sales for all shops';
?>

<?php include 'layouts/head-main.php'; ?>

<head>
    <title><?php echo $page_title; ?> | <?php echo __('employee_manager_system'); ?></title>
    <?php include 'layouts/head.php'; ?>
    
    <!-- DataTables CSS -->
    <link href="assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/libs/datatables.net-buttons-bs4/css/buttons.bootstrap4.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css" rel="stylesheet" type="text/css" />
    
    <style>
        .table-card {
            border-radius: 4px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        .table-card .card-header {
            border-radius: 4px 4px 0 0;
            background-color: #f8f9fa;
            padding: 15px 20px;
        }
        .table-responsive {
            scrollbar-width: thin;
        }
        .table-responsive::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        .table-responsive::-webkit-scrollbar-track {
            background: #f1f1f1;
        }
        .table-responsive::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 3px;
        }
        .table-responsive::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
        .sticky-top {
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .table-striped tbody tr:nth-of-type(odd) {
            background-color: rgba(0,0,0,.02);
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
                            <h4 class="mb-sm-0 font-size-18"><?php echo $page_title; ?></h4>

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
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title"><?php echo $_SESSION['lang'] == 'fr' ? 'Filtrer les Ventes Mensuelles' : 'Filter Monthly Sales'; ?></h4>
                                
                                <form method="get" id="filterForm" action="monthly-sales.php">
                                    <div class="row mb-3">
                                        <div class="col-md-4">
                                            <label for="shop_id" class="form-label"><?php echo $_SESSION['lang'] == 'fr' ? 'Magasin' : 'Shop'; ?></label>
                                            <select class="form-select" id="shop_id" name="shop_id">
                                                <option value=""><?php echo $_SESSION['lang'] == 'fr' ? 'Tous les Magasins' : 'All Shops'; ?></option>
                                                <?php foreach ($shops as $shop): ?>
                                                    <option value="<?php echo $shop['id']; ?>" <?php echo ($selectedShop == $shop['id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($shop['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="month" class="form-label"><?php echo $_SESSION['lang'] == 'fr' ? 'Mois' : 'Month'; ?></label>
                                            <select class="form-select" id="month" name="month">
                                                <option value=""><?php echo $_SESSION['lang'] == 'fr' ? 'Tous les Mois' : 'All Months'; ?></option>
                                                <?php
                                                // Create an array to store unique years from open months
                                                $openYears = [];
                                                
                                                foreach ($openMonths as $openMonth): 
                                                    // Add year to unique years array if not already present
                                                    if (!in_array($openMonth['year'], $openYears)) {
                                                        $openYears[] = $openMonth['year'];
                                                    }
                                                ?>
                                                    <option value="<?php echo $openMonth['month']; ?>" 
                                                            data-year="<?php echo $openMonth['year']; ?>" 
                                                            <?php echo ($selectedMonth == $openMonth['month'] && $selectedYear == $openMonth['year']) ? 'selected' : ''; ?>>
                                                        <?php echo getMonthName($openMonth['month']) . ' ' . $openMonth['year']; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="year" class="form-label"><?php echo $_SESSION['lang'] == 'fr' ? 'Année' : 'Year'; ?></label>
                                            <select class="form-select" id="year" name="year">
                                                <option value=""><?php echo $_SESSION['lang'] == 'fr' ? 'Tous les Années' : 'All Years'; ?></option>
                                                <?php
                                                // Sort years in descending order (newest first)
                                                rsort($openYears);
                                                
                                                foreach ($openYears as $year): 
                                                ?>
                                                    <option value="<?php echo $year; ?>" <?php echo ($selectedYear == $year) ? 'selected' : ''; ?>>
                                                        <?php echo $year; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-12">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="bx bx-filter-alt me-1"></i> <?php echo $_SESSION['lang'] == 'fr' ? 'Appliquer les Filtres' : 'Apply Filters'; ?>
                                            </button>
                                            <a href="monthly-sales.php" class="btn btn-secondary ms-2">
                                                <i class="bx bx-reset me-1"></i> <?php echo $_SESSION['lang'] == 'fr' ? 'Réinitialiser' : 'Reset'; ?>
                                            </a>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-12">
                        <div class="card table-card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <div>
                                    <h4 class="card-title mb-0"><?php echo $_SESSION['lang'] == 'fr' ? 'Enregistrements de Ventes Mensuelles' : 'Monthly Sales Records'; ?></h4>
                                    <p class="text-muted mb-0"><?php echo $_SESSION['lang'] == 'fr' ? 'Description des enregistrements de ventes mensuelles' : 'Description of monthly sales records'; ?></p>
                                </div>
                                <div>
                                    <button type="button" id="save-all-sales" class="btn btn-primary">
                                        <i class="bx bx-save me-1"></i> <?php echo $_SESSION['lang'] == 'fr' ? 'Enregistrer Toutes les Ventes' : 'Save All Sales'; ?>
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <form id="batch-sales-form" method="post" action="monthly-sales.php">
                                    <input type="hidden" name="batch_save" value="1">
                                    <input type="hidden" name="selected_month" value="<?php echo $selectedMonth ?: $currentMonth; ?>">
                                    <input type="hidden" name="selected_year" value="<?php echo $selectedYear ?: $currentYear; ?>">
                                    
                                    <div class="table-responsive" style="max-height: 700px; overflow-y: auto;">
                                        <table id="sales-table" class="table table-striped table-bordered nowrap" style="border-collapse: collapse; border-spacing: 0; width: 100%;">
                                            <thead class="thead-light sticky-top" style="position: sticky; top: 0; z-index: 1; background-color: #f8f9fa;">
                                                <tr>
                                                    <th><?php echo $_SESSION['lang'] == 'fr' ? 'Magasin' : 'Shop'; ?></th>
                                                    <th><?php echo $_SESSION['lang'] == 'fr' ? 'Gérant' : 'Manager'; ?></th>
                                                    <th><?php echo $_SESSION['lang'] == 'fr' ? 'Mois' : 'Month'; ?></th>
                                                    <th><?php echo $_SESSION['lang'] == 'fr' ? 'Année' : 'Year'; ?></th>
                                                    <th><?php echo $_SESSION['lang'] == 'fr' ? 'Montant des Ventes' : 'Sales Amount'; ?></th>
                                                    <th><?php echo $_SESSION['lang'] == 'fr' ? 'Montant du Bonus' : 'Bonus Amount'; ?></th>
                                                    <th><?php echo $_SESSION['lang'] == 'fr' ? 'Actions' : 'Actions'; ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                $month = $selectedMonth ?: $currentMonth;
                                                $year = $selectedYear ?: $currentYear;
                                                foreach ($allShopsWithSales as $shop): 
                                                ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($shop['shop_name']); ?></td>
                                                        <td><?php echo htmlspecialchars($shop['manager_name'] ?? ($_SESSION['lang'] == 'fr' ? 'Aucun gérant assigné' : 'No manager assigned')); ?></td>
                                                        <td><?php echo getMonthName($shop['month']); ?></td>
                                                        <td><?php echo $shop['year']; ?></td>
                                                        <td>
                                                            <div class="input-group">
                                                                <span class="input-group-text"><?php echo getCurrencySymbol(); ?></span>
                                                                <input type="number" 
                                                                       class="form-control sales-amount-input" 
                                                                       name="sales[<?php echo $shop['shop_id']; ?>]" 
                                                                       value="<?php echo isset($shop['sales_amount']) ? $shop['sales_amount'] : ''; ?>" 
                                                                       min="0" 
                                                                       step="0.01" 
                                                                       placeholder="<?php echo __('enter_amount'); ?>">
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <?php 
                                                                $salesAmount = isset($shop['sales_amount']) ? $shop['sales_amount'] : 0;
                                                                $bonusAmount = calculateBonusAmount($salesAmount, $pdo);
                                                                echo formatCurrency($bonusAmount);
                                                            ?>
                                                        </td>
                                                        <td>
                                                            <?php if (isset($shop['sales_id'])): ?>
                                                            <button type="button" class="btn btn-sm btn-danger delete-sales"
                                                                    data-shop-id="<?php echo $shop['shop_id']; ?>"
                                                                    data-month="<?php echo $shop['month']; ?>"
                                                                    data-year="<?php echo $shop['year']; ?>"
                                                                    data-shop-name="<?php echo htmlspecialchars($shop['shop_name']); ?>">
                                                                <i class="bx bx-trash"></i> <?php echo $_SESSION['lang'] == 'fr' ? 'Supprimer' : 'Delete'; ?>
                                                            </button>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Bonus Percentage Key Table -->
                <div class="row mt-4">
                    <div class="col-md-6 col-lg-4">
                        <div class="card">
                            <div class="card-header bg-light">
                                <h5 class="card-title mb-0"><?php echo $_SESSION['lang'] == 'fr' ? 'Clé de Pourcentage de Bonus' : 'Bonus Percentage Key'; ?></h5>
                                <p class="text-muted small mb-0"><?php echo $_SESSION['lang'] == 'fr' ? 'Seuils de ventes et leurs pourcentages de bonus correspondants' : 'Sales thresholds and their corresponding bonus percentages'; ?></p>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered table-striped">
                                        <thead class="bg-light">
                                            <tr>
                                                <th><?php echo $_SESSION['lang'] == 'fr' ? 'Montant Min. des Ventes' : 'Min Sales Amount'; ?></th>
                                                <th><?php echo $_SESSION['lang'] == 'fr' ? 'Pourcentage de Bonus' : 'Bonus Percentage'; ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($bonusTiers)): ?>
                                                <tr>
                                                    <td colspan="2" class="text-center"><?php echo $_SESSION['lang'] == 'fr' ? 'Aucune configuration de bonus trouvée' : 'No bonus configurations found'; ?></td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($bonusTiers as $tier): ?>
                                                    <tr>
                                                        <td class="font-weight-bold"><?php echo formatCurrency($tier['min_sales']); ?></td>
                                                        <td><span class="badge bg-primary"><?php echo number_format($tier['bonus_percent'], 2); ?>%</span></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="mt-2 small text-muted">
                                    <i class="bx bx-info-circle me-1"></i> 
                                    <?php echo $_SESSION['lang'] == 'fr' ? 'Le bonus est calculé sur la base du niveau le plus élevé où le montant des ventes atteint ou dépasse le seuil minimum' : 'Bonus is calculated based on the highest tier where sales amount meets or exceeds the minimum threshold'; ?>
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

<!-- Delete Sales Confirmation Modal -->
<div class="modal fade" id="deleteSalesModal" tabindex="-1" aria-labelledby="deleteSalesModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteSalesModalLabel"><?php echo $_SESSION['lang'] == 'fr' ? 'Supprimer l\'Enregistrement de Ventes' : 'Delete Sales Record'; ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" id="deleteSalesForm" action="monthly-sales.php">
                <div class="modal-body">
                    <p><?php echo $_SESSION['lang'] == 'fr' ? 'Êtes-vous sûr de vouloir supprimer cet enregistrement de ventes?' : 'Are you sure you want to delete this sales record?'; ?></p>
                    <p id="delete_sales_details" class="text-danger"></p>
                    <input type="hidden" id="delete_shop_id" name="shop_id">
                    <input type="hidden" id="delete_month" name="month">
                    <input type="hidden" id="delete_year" name="year">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo $_SESSION['lang'] == 'fr' ? 'Annuler' : 'Cancel'; ?></button>
                    <button type="submit" name="delete_sales" class="btn btn-danger"><?php echo $_SESSION['lang'] == 'fr' ? 'Supprimer' : 'Delete'; ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'layouts/right-sidebar.php'; ?>
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

<script>
    // IMPORTANT: All user-facing strings MUST use the PHP __() translation function to support multiple languages
    $(document).ready(function() {
        // Initialize DataTables
        $('#sales-table').DataTable({
            responsive: false,
            scrollY: "400px",
            scrollCollapse: true,
            paging: false,
            searching: true,
            info: true,
            fixedHeader: true,
            dom: 'Bfrtip',
            buttons: [
                'copy', 'csv', 'excel', 'pdf', 'print'
            ],
            language: {
                search: "<?php echo __('search'); ?>:",
                info: "<?php echo __('showing'); ?> _START_ <?php echo __('to'); ?> _END_ <?php echo __('of'); ?> _TOTAL_ <?php echo __('entries'); ?>",
            }
        });
        
        // Link month and year selection
        $('#month').on('change', function() {
            const selectedOption = $(this).find('option:selected');
            const yearValue = selectedOption.data('year');
            
            // If a month with specific year is selected, update the year dropdown
            if (yearValue) {
                $('#year').val(yearValue);
            }
        });
        
        // Save all button
        $('#save-all-sales').on('click', function() {
            // Validate inputs
            var hasError = false;
            $('.sales-amount-input').each(function() {
                var value = $(this).val();
                if (value !== "" && parseFloat(value) <= 0) {
                    hasError = true;
                    $(this).addClass('is-invalid');
                } else {
                    $(this).removeClass('is-invalid');
                }
            });
            
            if (hasError) {
                alert('<?php echo __("sales_amount_greater_than_zero"); ?>');
                return;
            }
            
            // Submit the form
            $('#batch-sales-form').submit();
        });
        
        // Handle input validation on change
        $('.sales-amount-input').on('change', function() {
            var value = $(this).val();
            var salesAmount = parseFloat(value) || 0;
            
            // Basic validation
            if (value !== "" && salesAmount <= 0) {
                $(this).addClass('is-invalid');
            } else {
                $(this).removeClass('is-invalid');
            }
            
            // Get the bonus amount cell (next sibling of parent td)
            var bonusCell = $(this).closest('td').next('td');
            
            // AJAX call to calculate the bonus amount
            if (salesAmount > 0) {
                $.ajax({
                    url: 'monthly-sales.php',
                    type: 'POST',
                    data: {
                        action: 'calculate_bonus',
                        sales_amount: salesAmount
                    },
                    success: function(response) {
                        try {
                            var result = JSON.parse(response);
                            if (result.success) {
                                bonusCell.text(result.bonus_formatted);
                            }
                        } catch (e) {
                            console.error('Error parsing AJAX response:', e);
                        }
                    },
                    error: function() {
                        console.error('AJAX request failed');
                    }
                });
            } else {
                // Clear the bonus amount if sales is 0 or invalid
                bonusCell.text('<?php echo getCurrencySymbol(); ?>0.00');
            }
        });
        
        // Delete sales record modal
        $('.delete-sales').on('click', function() {
            var shopId = $(this).data('shop-id');
            var shopName = $(this).data('shop-name');
            var month = $(this).data('month');
            var year = $(this).data('year');
            
            $('#delete_shop_id').val(shopId);
            $('#delete_month').val(month);
            $('#delete_year').val(year);
            $('#delete_sales_details').text(shopName + ' - ' + getMonthName(month) + ' ' + year);
            
            $('#deleteSalesModal').modal('show');
        });
        
        // Helper function to get month name
        function getMonthName(monthNum) {
            var months = {
                1: '<?php echo __("January"); ?>',
                2: '<?php echo __("February"); ?>',
                3: '<?php echo __("March"); ?>',
                4: '<?php echo __("April"); ?>',
                5: '<?php echo __("May"); ?>',
                6: '<?php echo __("June"); ?>',
                7: '<?php echo __("July"); ?>',
                8: '<?php echo __("August"); ?>',
                9: '<?php echo __("September"); ?>',
                10: '<?php echo __("October"); ?>',
                11: '<?php echo __("November"); ?>',
                12: '<?php echo __("December"); ?>'
            };
            return months[monthNum] || '';
        }
    });
</script>
</body>
</html>
<?php
// End output buffering and send content to browser
ob_end_flush();
?> 
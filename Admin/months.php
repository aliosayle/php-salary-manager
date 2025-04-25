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

// Include required files in the correct order
include "layouts/config.php";
include "layouts/translations.php";

// Check if user has permission to manage months
if (!hasPermission('manage_settings')) {
    $_SESSION['error_message'] = __('no_permission_manage_months');
    header('Location: index.php');
    exit();
}

// Initialize variables
$errors = [];
$success = false;
$months = [];
$month_names = [
    1 => __('january'),
    2 => __('february'),
    3 => __('march'),
    4 => __('april'),
    5 => __('may'),
    6 => __('june'),
    7 => __('july'),
    8 => __('august'),
    9 => __('september'),
    10 => __('october'),
    11 => __('november'),
    12 => __('december')
];

// Function to ensure the store_management_snapshots table exists
function ensure_snapshot_table_exists($pdo) {
    try {
        // Check if table exists
        $stmt = $pdo->prepare("SHOW TABLES LIKE 'store_management_snapshots'");
        $stmt->execute();
        $tableExists = $stmt->fetchColumn();
        
        if (!$tableExists) {
            // Create the table if it doesn't exist
            $createTableSQL = "CREATE TABLE IF NOT EXISTS `store_management_snapshots` (
                `id` varchar(36) NOT NULL,
                `snapshot_date` date NOT NULL,
                `shop_id` varchar(36) NOT NULL,
                `shop_name` text NOT NULL,
                `shop_location` text,
                `manager_id` varchar(36) DEFAULT NULL,
                `manager_name` text,
                `manager_recruitment_date` date DEFAULT NULL,
                `manager_end_date` date DEFAULT NULL,
                `manager_recommender` text,
                `manager_position` text,
                `assistant_id` varchar(36) DEFAULT NULL,
                `assistant_name` text,
                `assistant_recruitment_date` date DEFAULT NULL,
                `assistant_end_date` date DEFAULT NULL,
                `assistant_position` text,
                `opened_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;";
            
            $pdo->exec($createTableSQL);
            
            // Create UUID trigger if it doesn't exist
            $createTriggerSQL = "
            CREATE TRIGGER IF NOT EXISTS `before_insert_generate_uuid` BEFORE INSERT ON `store_management_snapshots` FOR EACH ROW BEGIN
                IF NEW.id IS NULL THEN
                    SET NEW.id = UUID();
                END IF;
            END";
            
            $pdo->exec($createTriggerSQL);
            
            error_log("Created store_management_snapshots table");
        }
        return true;
    } catch (PDOException $e) {
        error_log("Error ensuring snapshot table exists: " . $e->getMessage());
        return false;
    }
}

// Function to take a snapshot of store management data for the previous month
function take_store_management_snapshot($pdo, $currentMonth, $currentYear) {
    try {
        // Ensure the snapshot table exists
        ensure_snapshot_table_exists($pdo);
        
        // Calculate previous month
        $prevMonth = $currentMonth - 1;
        $prevYear = $currentYear;
        if ($prevMonth <= 0) {
            $prevMonth = 12;
            $prevYear--;
        }
        
        // Format snapshot date (last day of previous month)
        $snapshotDate = date('Y-m-d', strtotime($prevYear . '-' . $prevMonth . '-01 +1 month -1 day'));
        
        // Check if a snapshot already exists for this date
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM store_management_snapshots WHERE DATE_FORMAT(snapshot_date, '%Y-%m') = ?");
        $checkStmt->execute([sprintf('%04d-%02d', $prevYear, $prevMonth)]);
        if ($checkStmt->fetchColumn() > 0) {
            // Snapshot already exists for this month
            return ['success' => true, 'message' => "Snapshot already exists for " . date('F Y', strtotime($snapshotDate))];
        }
        
        // Query to get all current shop management data (same as in store-management-report.php)
        $query = "
            SELECT 
                s.id AS shop_id, 
                s.name AS shop_name, 
                s.location AS shop_location,
                
                -- Manager information using aggregation to deal with GROUP BY
                MAX(manager.id) AS manager_id,
                MAX(manager.full_name) AS manager_name,
                MAX(manager.recruitment_date) AS manager_recruitment_date,
                MAX(manager.end_of_service_date) AS manager_end_date,
                MAX(manager_rec.name) AS manager_recommender,
                MAX(manager_post.title) AS manager_position,
                
                -- Assistant Manager information using aggregation
                MAX(am.id) AS assistant_id,
                MAX(am.full_name) AS assistant_name,
                MAX(am.recruitment_date) AS assistant_recruitment_date,
                MAX(am.end_of_service_date) AS assistant_end_date,
                MAX(am_post.title) AS assistant_position
                
            FROM 
                shops s
                
                -- Left join to get the manager using employee_shops and specific post_id
                LEFT JOIN employee_shops es_mgr ON s.id = es_mgr.shop_id
                LEFT JOIN employees manager ON es_mgr.employee_id = manager.id 
                    AND manager.post_id = '04b5ce3e-1aaf-11f0-99a1-cc28aa53b74d'
                LEFT JOIN posts manager_post ON manager.post_id = manager_post.id
                LEFT JOIN recommenders manager_rec ON manager.recommended_by_id = manager_rec.id
                
                -- Assistant manager joins
                LEFT JOIN employee_shops es_am ON s.id = es_am.shop_id
                LEFT JOIN employees am ON es_am.employee_id = am.id
                    AND am.post_id = 'cf0ca194-1abc-11f0-99a1-cc28aa53b74d'
                LEFT JOIN posts am_post ON am.post_id = am_post.id
            
            GROUP BY s.id, s.name, s.location
            ORDER BY s.name ASC
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $shops = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($shops)) {
            return ['success' => false, 'message' => "No shops found to snapshot"];
        }
        
        // Insert each shop's data as a snapshot record
        $insertQuery = "
            INSERT INTO store_management_snapshots (
                snapshot_date, shop_id, shop_name, shop_location, 
                manager_id, manager_name, manager_recruitment_date, manager_end_date, 
                manager_recommender, manager_position,
                assistant_id, assistant_name, assistant_recruitment_date, 
                assistant_end_date, assistant_position
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            )
        ";
        
        $insertStmt = $pdo->prepare($insertQuery);
        $recordsCreated = 0;
        
        foreach ($shops as $shop) {
            $insertStmt->execute([
                $snapshotDate,
                $shop['shop_id'],
                $shop['shop_name'],
                $shop['shop_location'],
                $shop['manager_id'],
                $shop['manager_name'],
                $shop['manager_recruitment_date'],
                $shop['manager_end_date'],
                $shop['manager_recommender'],
                $shop['manager_position'],
                $shop['assistant_id'],
                $shop['assistant_name'],
                $shop['assistant_recruitment_date'],
                $shop['assistant_end_date'],
                $shop['assistant_position']
            ]);
            $recordsCreated++;
        }
        
        return [
            'success' => true, 
            'message' => "Created " . $recordsCreated . " snapshot records for " . date('F Y', strtotime($snapshotDate))
        ];
    } catch (PDOException $e) {
        error_log("Error taking store management snapshot: " . $e->getMessage());
        return ['success' => false, 'message' => "Database error: " . $e->getMessage()];
    }
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Create new month
    if (isset($_POST['action']) && $_POST['action'] === 'create') {
        $year = isset($_POST['year']) ? (int)$_POST['year'] : null;
        $month = isset($_POST['month']) ? (int)$_POST['month'] : null;
        $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
        
        // Validate inputs
        if (!$year || $year < 2000 || $year > 2100) {
            $errors[] = __('invalid_year');
        }
        
        if (!$month || $month < 1 || $month > 12) {
            $errors[] = __('invalid_month');
        }
        
        if (empty($errors)) {
            // Check if month already exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM months WHERE year = ? AND month = ?");
            $stmt->execute([$year, $month]);
            $exists = $stmt->fetchColumn();
            
            if ($exists) {
                $errors[] = __('month_already_exists');
            } else {
                try {
                    $stmt = $pdo->prepare("INSERT INTO months (id, year, month, notes) VALUES (UUID(), ?, ?, ?)");
                    $result = $stmt->execute([$year, $month, $notes]);
                    
                    if ($result) {
                        $_SESSION['success_message'] = __('month_created_successfully');
                        header('Location: months.php');
                        exit();
                    } else {
                        $errors[] = __('month_creation_failed');
                    }
                } catch (PDOException $e) {
                    $errors[] = __('database_error') . ': ' . $e->getMessage();
                }
            }
        }
    }
    
    // Open month
    if (isset($_POST['action']) && $_POST['action'] === 'open') {
        $id = isset($_POST['id']) ? $_POST['id'] : null;
        
        if (!$id) {
            $errors[] = __('invalid_month_id');
        } else {
            try {
                $stmt = $pdo->prepare("SELECT year, month FROM months WHERE id = ?");
                $stmt->execute([$id]);
                $monthData = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($monthData) {
                    $snapshotResult = take_store_management_snapshot($pdo, $monthData['month'], $monthData['year']);
                    
                    if (!$snapshotResult['success']) {
                        $errors[] = $snapshotResult['message'];
                    }
                }
                
                $stmt = $pdo->prepare("UPDATE months SET is_open = 1, opened_at = NOW(), closed_at = NULL WHERE id = ?");
                $result = $stmt->execute([$id]);
                
                if ($result) {
                    $_SESSION['success_message'] = __('month_opened_successfully');
                    header('Location: months.php');
                    exit();
                } else {
                    $errors[] = __('month_open_failed');
                }
            } catch (PDOException $e) {
                $errors[] = __('database_error') . ': ' . $e->getMessage();
            }
        }
    }
    
    // Close month
    if (isset($_POST['action']) && $_POST['action'] === 'close') {
        $id = isset($_POST['id']) ? $_POST['id'] : null;
        
        if (!$id) {
            $errors[] = __('invalid_month_id');
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE months SET is_open = 0, closed_at = NOW() WHERE id = ?");
                $result = $stmt->execute([$id]);
                
                if ($result) {
                    $_SESSION['success_message'] = __('month_closed_successfully');
                    header('Location: months.php');
                    exit();
                } else {
                    $errors[] = __('month_close_failed');
                }
            } catch (PDOException $e) {
                $errors[] = __('database_error') . ': ' . $e->getMessage();
            }
        }
    }
    
    // Update notes
    if (isset($_POST['action']) && $_POST['action'] === 'update_notes') {
        $id = isset($_POST['id']) ? $_POST['id'] : null;
        $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
        
        if (!$id) {
            $errors[] = __('invalid_month_id');
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE months SET notes = ? WHERE id = ?");
                $result = $stmt->execute([$notes, $id]);
                
                if ($result) {
                    $_SESSION['success_message'] = __('notes_updated_successfully');
                    header('Location: months.php');
                    exit();
                } else {
                    $errors[] = __('notes_update_failed');
                }
            } catch (PDOException $e) {
                $errors[] = __('database_error') . ': ' . $e->getMessage();
            }
        }
    }
}

// Fetch months
try {
    // Modified to use opened_at for sorting, most recent first
    $stmt = $pdo->prepare("SELECT * FROM months ORDER BY opened_at DESC, year DESC, month DESC");
    $stmt->execute();
    $months = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errors[] = __('database_error') . ': ' . $e->getMessage();
}

// Calculate the next available month
$nextMonth = date('n');
$nextYear = date('Y');

// Check if the current month already exists
$currentMonthExists = false;
foreach ($months as $month) {
    if ($month['month'] == $nextMonth && $month['year'] == $nextYear) {
        $currentMonthExists = true;
        break;
    }
}

// If current month exists, calculate next month
if ($currentMonthExists) {
    $nextMonth += 1;
    if ($nextMonth > 12) {
        $nextMonth = 1;
        $nextYear += 1;
    }
}
?>

<?php include 'layouts/head-main.php'; ?>

<head>
    <title><?php echo __('month_management'); ?> | <?php echo __('employee_manager_system'); ?></title>
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
                        <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                            <h4 class="mb-sm-0 font-size-18"><?php echo __('month_management'); ?></h4>

                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php"><?php echo __('dashboard'); ?></a></li>
                                    <li class="breadcrumb-item active"><?php echo __('month_management'); ?></li>
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

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo $error; ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-4">
                                    <h4 class="card-title"><?php echo __('months'); ?></h4>
                                    <button type="button" class="btn btn-primary waves-effect waves-light" data-bs-toggle="modal" data-bs-target="#createMonthModal">
                                        <i class="bx bx-plus font-size-16 align-middle me-2"></i> <?php echo __('add_month'); ?>
                                    </button>
                                </div>

                                <div class="table-responsive">
                                    <table id="months-table" class="table table-bordered dt-responsive nowrap w-100">
                                        <thead>
                                            <tr>
                                                <th><?php echo __('year'); ?></th>
                                                <th><?php echo __('month'); ?></th>
                                                <th><?php echo __('status'); ?></th>
                                                <th><?php echo __('opened_at'); ?></th>
                                                <th><?php echo __('closed_at'); ?></th>
                                                <th><?php echo __('notes'); ?></th>
                                                <th><?php echo __('actions'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($months as $month): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($month['year']); ?></td>
                                                    <td><?php echo htmlspecialchars($month_names[$month['month']]); ?></td>
                                                    <td>
                                                        <?php if ($month['is_open']): ?>
                                                            <span class="badge bg-success"><?php echo __('open'); ?></span>
                                                        <?php else: ?>
                                                            <span class="badge bg-danger"><?php echo __('closed'); ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo $month['opened_at'] ? date('Y-m-d H:i', strtotime($month['opened_at'])) : '-'; ?></td>
                                                    <td><?php echo $month['closed_at'] ? date('Y-m-d H:i', strtotime($month['closed_at'])) : '-'; ?></td>
                                                    <td>
                                                        <?php if (!empty($month['notes'])): ?>
                                                            <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewNotesModal" data-id="<?php echo $month['id']; ?>" data-notes="<?php echo htmlspecialchars($month['notes']); ?>">
                                                                <i class="bx bx-show"></i> <?php echo __('view'); ?>
                                                            </button>
                                                        <?php else: ?>
                                                            <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="modal" data-bs-target="#addNotesModal" data-id="<?php echo $month['id']; ?>">
                                                                <i class="bx bx-plus"></i> <?php echo __('add'); ?>
                                                            </button>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!$month['is_open']): ?>
                                                            <form method="post" class="d-inline" onsubmit="return confirm('<?php echo __('confirm_open_month'); ?>');">
                                                                <input type="hidden" name="action" value="open">
                                                                <input type="hidden" name="id" value="<?php echo $month['id']; ?>">
                                                                <button type="submit" class="btn btn-sm btn-success">
                                                                    <i class="bx bx-lock-open"></i> <?php echo __('open'); ?>
                                                                </button>
                                                            </form>
                                                        <?php else: ?>
                                                            <form method="post" class="d-inline" onsubmit="return confirm('<?php echo __('confirm_close_month'); ?>');">
                                                                <input type="hidden" name="action" value="close">
                                                                <input type="hidden" name="id" value="<?php echo $month['id']; ?>">
                                                                <button type="submit" class="btn btn-sm btn-danger">
                                                                    <i class="bx bx-lock"></i> <?php echo __('close'); ?>
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                        
                                                        <?php if (!empty($month['notes'])): ?>
                                                            <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editNotesModal" data-id="<?php echo $month['id']; ?>" data-notes="<?php echo htmlspecialchars($month['notes']); ?>">
                                                                <i class="bx bx-edit"></i> <?php echo __('edit_notes'); ?>
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
            </div>
        </div>

        <!-- Create Month Modal -->
        <div class="modal fade" id="createMonthModal" tabindex="-1" aria-labelledby="createMonthModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="createMonthModalLabel"><?php echo __('add_month'); ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form method="post">
                        <div class="modal-body">
                            <input type="hidden" name="action" value="create">
                            
                            <div class="mb-3">
                                <label for="year" class="form-label"><?php echo __('year'); ?></label>
                                <input type="number" class="form-control" id="year" name="year" min="2000" max="2100" value="<?php echo $nextYear; ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="month" class="form-label"><?php echo __('month'); ?></label>
                                <select class="form-select" id="month" name="month" required>
                                    <option value="<?php echo $nextMonth; ?>" selected><?php echo $month_names[$nextMonth]; ?></option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="notes" class="form-label"><?php echo __('notes'); ?></label>
                                <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
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

        <!-- View Notes Modal -->
        <div class="modal fade" id="viewNotesModal" tabindex="-1" aria-labelledby="viewNotesModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="viewNotesModalLabel"><?php echo __('view_notes'); ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div id="notes-content"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('close'); ?></button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Notes Modal -->
        <div class="modal fade" id="addNotesModal" tabindex="-1" aria-labelledby="addNotesModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="addNotesModalLabel"><?php echo __('add_notes'); ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form method="post">
                        <div class="modal-body">
                            <input type="hidden" name="action" value="update_notes">
                            <input type="hidden" name="id" id="add-notes-id">
                            
                            <div class="mb-3">
                                <label for="add-notes" class="form-label"><?php echo __('notes'); ?></label>
                                <textarea class="form-control" id="add-notes" name="notes" rows="5" required></textarea>
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

        <!-- Edit Notes Modal -->
        <div class="modal fade" id="editNotesModal" tabindex="-1" aria-labelledby="editNotesModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editNotesModalLabel"><?php echo __('edit_notes'); ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form method="post">
                        <div class="modal-body">
                            <input type="hidden" name="action" value="update_notes">
                            <input type="hidden" name="id" id="edit-notes-id">
                            
                            <div class="mb-3">
                                <label for="edit-notes" class="form-label"><?php echo __('notes'); ?></label>
                                <textarea class="form-control" id="edit-notes" name="notes" rows="5" required></textarea>
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
        $('#months-table').DataTable({
            order: [[0, 'desc'], [1, 'desc']]
        });
        
        // View notes modal
        $('#viewNotesModal').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget);
            var notes = button.data('notes');
            $('#notes-content').html(notes.replace(/\n/g, '<br>'));
        });
        
        // Add notes modal
        $('#addNotesModal').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget);
            var id = button.data('id');
            $('#add-notes-id').val(id);
        });
        
        // Edit notes modal
        $('#editNotesModal').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget);
            var id = button.data('id');
            var notes = button.data('notes');
            $('#edit-notes-id').val(id);
            $('#edit-notes').val(notes);
        });
    });
</script>
</body>
</html>
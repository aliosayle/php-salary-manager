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
include "layouts/translations.php";

// Process delete operation
if (isset($_GET["action"]) && $_GET["action"] == "delete" && isset($_GET["id"])) {
    $postId = $_GET["id"];
    
    try {
        // Check if the position is being used by any employee
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE post_id = ?");
        $checkStmt->execute([$postId]);
        $count = $checkStmt->fetchColumn();
        
        if ($count > 0) {
            $_SESSION["error_message"] = sprintf(__('position_delete_employees_error'), $count);
            header("location: posts.php");
            exit;
        }
        
        // Begin transaction
        $pdo->beginTransaction();
        
        // Delete the position record
        $stmt = $pdo->prepare("DELETE FROM posts WHERE id = ?");
        $stmt->execute([$postId]);
        
        // Commit transaction
        $pdo->commit();
        
        $_SESSION["success_message"] = __('position_deleted_success');
        header("location: posts.php");
        exit;
    } catch (PDOException $e) {
        // Rollback transaction
        $pdo->rollBack();
        
        $_SESSION["error_message"] = __('position_delete_error') . ": " . $e->getMessage();
        header("location: posts.php");
        exit;
    }
}

// Process add/edit operation via modal
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Check if we're adding or editing
    $isEdit = isset($_POST["post_id"]) && !empty($_POST["post_id"]);
    $postId = $isEdit ? trim($_POST["post_id"]) : null;
    
    // Validate title
    if (empty(trim($_POST["title"]))) {
        $_SESSION["error_message"] = __('position_title_required');
        header("location: posts.php");
        exit;
    }
    
    $title = trim($_POST["title"]);
    
    try {
        // Begin transaction
        $pdo->beginTransaction();
        
        if ($isEdit) {
            // Update existing position
            $stmt = $pdo->prepare("UPDATE posts SET title = ? WHERE id = ?");
            $stmt->execute([$title, $postId]);
            $message = __('position_updated_success');
        } else {
            // Add new position
            $stmt = $pdo->prepare("INSERT INTO posts (title) VALUES (?)");
            $stmt->execute([$title]);
            $message = __('position_added_success');
        }
        
        // Commit transaction
        $pdo->commit();
        
        $_SESSION["success_message"] = $message;
        header("location: posts.php");
        exit;
    } catch (PDOException $e) {
        // Rollback transaction
        $pdo->rollBack();
        
        $_SESSION["error_message"] = __('error') . ": " . $e->getMessage();
        header("location: posts.php");
        exit;
    }
}

// Get search parameter
$searchTerm = isset($_GET["search"]) ? trim($_GET["search"]) : "";

// Pagination settings
$page = isset($_GET["page"]) ? (int)$_GET["page"] : 1;
$recordsPerPage = 10;
$offset = ($page - 1) * $recordsPerPage;

// Build the SQL query
$sql = "SELECT p.*, (SELECT COUNT(*) FROM employees e WHERE e.post_id = p.id) as employee_count 
        FROM posts p
        WHERE 1=1";

$params = [];

if (!empty($searchTerm)) {
    $sql .= " AND p.title LIKE ?";
    $params[] = "%{$searchTerm}%";
}

// Get total records for pagination
$countSql = str_replace("p.*, (SELECT COUNT(*) FROM employees e WHERE e.post_id = p.id) as employee_count", "COUNT(*) as total", $sql);
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRecords = $countStmt->fetch(PDO::FETCH_ASSOC)["total"];
$totalPages = ceil($totalRecords / $recordsPerPage);

// Add sorting and pagination to main query
$sql .= " ORDER BY p.title ASC LIMIT $offset, $recordsPerPage";

// Execute the main query
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$positions = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php include 'layouts/head-main.php'; ?>

<head>
    <title><?php echo __('positions'); ?> | <?php echo __('employee_manager_system'); ?></title>
    <?php include 'layouts/head.php'; ?>
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
                            <h4 class="mb-sm-0 font-size-18"><?php echo __('positions'); ?></h4>

                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="javascript: void(0);"><?php echo __('employee_manager'); ?></a></li>
                                    <li class="breadcrumb-item active"><?php echo __('positions'); ?></li>
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

                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h4 class="card-title"><?php echo __('position_list'); ?></h4>
                                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPositionModal">
                                        <i class="bx bx-plus me-1"></i> <?php echo __('add_new_position'); ?>
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row mb-4">
                                    <div class="col-md-6">
                                        <form method="GET" action="posts.php" class="d-flex gap-2">
                                            <div class="input-group">
                                                <input type="text" class="form-control" id="search" name="search" placeholder="<?php echo __('search_by_title'); ?>" value="<?php echo htmlspecialchars($searchTerm); ?>">
                                                <button class="btn btn-outline-secondary" type="submit">
                                                    <i class="bx bx-search-alt"></i>
                                                </button>
                                            </div>
                                            <?php if (!empty($searchTerm)): ?>
                                            <a href="posts.php" class="btn btn-outline-secondary">
                                                <i class="bx bx-x"></i> <?php echo __('clear'); ?>
                                            </a>
                                            <?php endif; ?>
                                        </form>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th scope="col" style="width: 70px;">#</th>
                                                <th scope="col"><?php echo __('position_title'); ?></th>
                                                <th scope="col" style="width: 120px;"><?php echo __('employees'); ?></th>
                                                <th scope="col" style="width: 150px;"><?php echo __('actions'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (count($positions) > 0): ?>
                                                <?php foreach ($positions as $index => $position): ?>
                                                <tr>
                                                    <td><?php echo $offset + $index + 1; ?></td>
                                                    <td><?php echo htmlspecialchars($position['title']); ?></td>
                                                    <td>
                                                        <span class="badge bg-primary"><?php echo $position['employee_count']; ?></span>
                                                    </td>
                                                    <td>
                                                        <div class="d-flex gap-2">
                                                            <button type="button" class="btn btn-primary btn-sm" onclick="editPosition('<?php echo $position['id']; ?>', '<?php echo htmlspecialchars(addslashes($position['title'])); ?>')">
                                                                <i class="bx bx-edit"></i>
                                                            </button>
                                                            <?php 
                                                            // Don't show delete button for manager or assistant manager positions
                                                            if ($position['id'] != 'cf0ca194-1abc-11f0-99a1-cc28aa53b74d' && $position['title'] != 'Manager'): 
                                                            ?>
                                                            <button type="button" class="btn btn-danger btn-sm <?php echo ($position['employee_count'] > 0) ? 'disabled' : ''; ?>" 
                                                                    <?php if ($position['employee_count'] == 0): ?>
                                                                    onclick="confirmDelete('<?php echo $position['id']; ?>', '<?php echo htmlspecialchars(addslashes($position['title'])); ?>')"
                                                                    <?php endif; ?>
                                                                    data-bs-toggle="tooltip" title="<?php echo ($position['employee_count'] > 0) ? __('cannot_delete_assigned_position') : __('delete_position'); ?>">
                                                                <i class="bx bx-trash"></i>
                                                            </button>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="4" class="text-center"><?php echo __('no_positions_found'); ?></td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Pagination -->
                                <?php if ($totalPages > 1): ?>
                                <div class="d-flex justify-content-end mt-3">
                                    <ul class="pagination">
                                        <?php if ($page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($searchTerm); ?>">
                                                <i class="mdi mdi-chevron-left"></i>
                                            </a>
                                        </li>
                                        <?php endif; ?>

                                        <?php
                                        $startPage = max(1, $page - 2);
                                        $endPage = min($totalPages, $startPage + 4);
                                        if ($endPage - $startPage < 4) {
                                            $startPage = max(1, $endPage - 4);
                                        }
                                        
                                        for ($i = $startPage; $i <= $endPage; $i++):
                                        ?>
                                        <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($searchTerm); ?>">
                                                <?php echo $i; ?>
                                            </a>
                                        </li>
                                        <?php endfor; ?>

                                        <?php if ($page < $totalPages): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($searchTerm); ?>">
                                                <i class="mdi mdi-chevron-right"></i>
                                            </a>
                                        </li>
                                        <?php endif; ?>
                                    </ul>
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

<!-- Add/Edit Position Modal -->
<div class="modal fade" id="addPositionModal" tabindex="-1" aria-labelledby="addPositionModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="positionForm" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                <input type="hidden" name="post_id" id="post_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle"><?php echo __('add_new_position'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="title" class="form-label"><?php echo __('position_title'); ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="title" name="title" required>
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

<!-- Delete confirmation modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteModalLabel"><?php echo __('confirm_delete'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php echo __('delete_position_confirm'); ?> <span id="positionName"></span>?
                <p class="text-danger mt-2 mb-0"><?php echo __('action_cannot_be_undone'); ?></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                <a href="#" id="confirmDeleteBtn" class="btn btn-danger"><?php echo __('delete'); ?></a>
            </div>
        </div>
    </div>
</div>

<?php include 'layouts/vendor-scripts.php'; ?>

<script>
    // Function to show edit modal with position data
    function editPosition(id, title) {
        document.getElementById('post_id').value = id;
        document.getElementById('title').value = title;
        document.getElementById('modalTitle').textContent = '<?php echo __('edit_position'); ?>';
        
        var modal = new bootstrap.Modal(document.getElementById('addPositionModal'));
        modal.show();
    }
    
    // Reset form when modal is closed
    document.getElementById('addPositionModal').addEventListener('hidden.bs.modal', function () {
        document.getElementById('positionForm').reset();
        document.getElementById('post_id').value = '';
        document.getElementById('modalTitle').textContent = '<?php echo __('add_new_position'); ?>';
    });
    
    // Function to show delete confirmation modal
    function confirmDelete(id, name) {
        document.getElementById('positionName').textContent = name;
        document.getElementById('confirmDeleteBtn').href = 'posts.php?action=delete&id=' + id;
        
        var deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
        deleteModal.show();
    }
    
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    });
    
    <?php if (isset($_SESSION['open_position_modal']) && $_SESSION['open_position_modal']): ?>
    // Auto-open the modal when redirected from new-position.php
    document.addEventListener('DOMContentLoaded', function() {
        var modal = new bootstrap.Modal(document.getElementById('addPositionModal'));
        modal.show();
        <?php unset($_SESSION['open_position_modal']); ?>
    });
    <?php endif; ?>
</script>

</body>
</html>
<?php ob_end_flush(); ?>
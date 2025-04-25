<?php
// Start output buffering
ob_start();

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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
include "layouts/translations.php";  // Added translation file include

// Process delete operation
if (isset($_GET["action"]) && $_GET["action"] == "delete" && isset($_GET["id"])) {
    $recommenderId = $_GET["id"];
    
    try {
        // Check if the recommender is being used by any employee
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE recommended_by_id = ?");
        $checkStmt->execute([$recommenderId]);
        $count = $checkStmt->fetchColumn();
        
        if ($count > 0) {
            $_SESSION["error_message"] = sprintf(__('recommender_delete_employees_error'), $count);
            header("location: recommenders.php");
            exit;
        }
        
        // Begin transaction
        $pdo->beginTransaction();
        
        // Delete the recommender record
        $stmt = $pdo->prepare("DELETE FROM recommenders WHERE id = ?");
        $stmt->execute([$recommenderId]);
        
        // Commit transaction
        $pdo->commit();
        
        $_SESSION["success_message"] = __('recommender_deleted_success');
        header("location: recommenders.php");
        exit;
    } catch (PDOException $e) {
        // Rollback transaction
        $pdo->rollBack();
        
        $_SESSION["error_message"] = __('recommender_delete_error') . ": " . $e->getMessage();
        header("location: recommenders.php");
        exit;
    }
}

// Process add/edit operation via modal
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Check if we're adding or editing
    $isEdit = isset($_POST["recommender_id"]) && !empty($_POST["recommender_id"]);
    $recommenderId = $isEdit ? trim($_POST["recommender_id"]) : null;
    
    // Validate name
    if (empty(trim($_POST["name"]))) {
        $_SESSION["error_message"] = __('recommender_name_required');
        header("location: recommenders.php");
        exit;
    }
    
    $name = trim($_POST["name"]);
    $relation = !empty(trim($_POST["relation"])) ? trim($_POST["relation"]) : null;
    $contact = !empty(trim($_POST["contact"])) ? trim($_POST["contact"]) : null;
    
    try {
        // Begin transaction
        $pdo->beginTransaction();
        
        if ($isEdit) {
            // Update existing recommender
            $stmt = $pdo->prepare("UPDATE recommenders SET name = ?, relation = ?, contact = ? WHERE id = ?");
            $stmt->execute([$name, $relation, $contact, $recommenderId]);
            $message = __('recommender_updated_success');
        } else {
            // Add new recommender
            $stmt = $pdo->prepare("INSERT INTO recommenders (name, relation, contact) VALUES (?, ?, ?)");
            $stmt->execute([$name, $relation, $contact]);
            $message = __('recommender_added_success');
        }
        
        // Commit transaction
        $pdo->commit();
        
        $_SESSION["success_message"] = $message;
        header("location: recommenders.php");
        exit;
    } catch (PDOException $e) {
        // Rollback transaction
        $pdo->rollBack();
        
        $_SESSION["error_message"] = __('error') . ": " . $e->getMessage();
        header("location: recommenders.php");
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
$sql = "SELECT r.*, (SELECT COUNT(*) FROM employees WHERE recommended_by_id = r.id) as employee_count 
        FROM recommenders r
        WHERE 1=1";

$params = [];

if (!empty($searchTerm)) {
    $sql .= " AND (r.name LIKE ? OR r.relation LIKE ? OR r.contact LIKE ?)";
    $params[] = "%{$searchTerm}%";
    $params[] = "%{$searchTerm}%";
    $params[] = "%{$searchTerm}%";
}

// Get total records for pagination
$countSql = str_replace("r.*, (SELECT COUNT(*) FROM employees WHERE recommended_by_id = r.id) as employee_count", "COUNT(*) as total", $sql);
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRecords = $countStmt->fetch(PDO::FETCH_ASSOC)["total"];
$totalPages = ceil($totalRecords / $recordsPerPage);

// Add sorting and pagination to main query
$sql .= " ORDER BY r.name ASC LIMIT $offset, $recordsPerPage";

// Execute the main query
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$recommenders = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php include 'layouts/head-main.php'; ?>

<head>
    <title><?php echo __('recommenders'); ?> | <?php echo __('employee_manager_system'); ?></title>
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
                            <h4 class="mb-sm-0 font-size-18"><?php echo __('recommenders'); ?></h4>

                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="javascript: void(0);"><?php echo __('employee_manager'); ?></a></li>
                                    <li class="breadcrumb-item active"><?php echo __('recommenders'); ?></li>
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
                                    <h4 class="card-title"><?php echo __('all_recommenders'); ?></h4>
                                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addRecommenderModal">
                                        <i class="bx bx-plus me-1"></i> <?php echo __('add_new_recommender'); ?>
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row mb-4">
                                    <div class="col-md-6">
                                        <form method="GET" action="recommenders.php" class="d-flex gap-2">
                                            <div class="input-group">
                                                <input type="text" class="form-control" id="search" name="search" placeholder="<?php echo __('search_recommenders'); ?>" value="<?php echo htmlspecialchars($searchTerm); ?>">
                                                <button class="btn btn-outline-secondary" type="submit">
                                                    <i class="bx bx-search-alt"></i>
                                                </button>
                                            </div>
                                            <?php if (!empty($searchTerm)): ?>
                                            <a href="recommenders.php" class="btn btn-outline-secondary">
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
                                                <th scope="col"><?php echo __('recommender_name'); ?></th>
                                                <th scope="col"><?php echo __('recommender_relation'); ?></th>
                                                <th scope="col"><?php echo __('recommender_contact'); ?></th>
                                                <th scope="col"><?php echo __('employees'); ?></th>
                                                <th scope="col" style="width: 150px;"><?php echo __('actions'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (count($recommenders) > 0): ?>
                                                <?php foreach ($recommenders as $index => $recommender): ?>
                                                <tr>
                                                    <td><?php echo $offset + $index + 1; ?></td>
                                                    <td><?php echo htmlspecialchars($recommender['name']); ?></td>
                                                    <td><?php echo htmlspecialchars($recommender['relation'] ?? __('not_specified')); ?></td>
                                                    <td><?php echo htmlspecialchars($recommender['contact'] ?? __('not_specified')); ?></td>
                                                    <td>
                                                        <span class="badge bg-primary"><?php echo $recommender['employee_count']; ?></span>
                                                    </td>
                                                    <td>
                                                        <div class="d-flex gap-2">
                                                            <button type="button" class="btn btn-primary btn-sm" onclick="editRecommender('<?php echo $recommender['id']; ?>', '<?php echo htmlspecialchars(addslashes($recommender['name'])); ?>', '<?php echo htmlspecialchars(addslashes($recommender['relation'] ?? '')); ?>', '<?php echo htmlspecialchars(addslashes($recommender['contact'] ?? '')); ?>')">
                                                                <i class="bx bx-edit"></i>
                                                            </button>
                                                            <button type="button" class="btn btn-danger btn-sm <?php echo ($recommender['employee_count'] > 0) ? 'disabled' : ''; ?>" 
                                                                    <?php if ($recommender['employee_count'] == 0): ?>
                                                                    onclick="confirmDelete('<?php echo $recommender['id']; ?>', '<?php echo htmlspecialchars(addslashes($recommender['name'])); ?>')"
                                                                    <?php endif; ?>
                                                                    data-bs-toggle="tooltip" title="<?php echo ($recommender['employee_count'] > 0) ? __('cannot_delete_linked_recommender') : __('delete_recommender'); ?>">
                                                                <i class="bx bx-trash"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="6" class="text-center"><?php echo __('no_recommenders_found'); ?></td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <?php if ($totalPages > 1): ?>
                                <div class="row mt-4">
                                    <div class="col-sm-6">
                                        <div>
                                            <?php echo __('showing'); ?> <?php echo $offset + 1; ?> <?php echo __('to'); ?> <?php echo min($offset + $recordsPerPage, $totalRecords); ?> <?php echo __('of'); ?> <?php echo $totalRecords; ?> <?php echo __('entries'); ?>
                                        </div>
                                    </div>
                                    <div class="col-sm-6">
                                        <ul class="pagination float-end">
                                            <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                                <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo !empty($searchTerm) ? '&search=' . urlencode($searchTerm) : ''; ?>"><?php echo __('previous'); ?></a>
                                            </li>
                                            
                                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                            <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                                                <a class="page-link" href="?page=<?php echo $i; ?><?php echo !empty($searchTerm) ? '&search=' . urlencode($searchTerm) : ''; ?>"><?php echo $i; ?></a>
                                            </li>
                                            <?php endfor; ?>
                                            
                                            <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                                                <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo !empty($searchTerm) ? '&search=' . urlencode($searchTerm) : ''; ?>"><?php echo __('next'); ?></a>
                                            </li>
                                        </ul>
                                    </div>
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

<!-- Add/Edit Recommender Modal -->
<div class="modal fade" id="addRecommenderModal" tabindex="-1" aria-labelledby="addRecommenderModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="recommenderForm" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                <input type="hidden" name="recommender_id" id="recommender_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle"><?php echo __('add_new_recommender'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="name" class="form-label"><?php echo __('recommender_name'); ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="relation" class="form-label"><?php echo __('recommender_relation'); ?></label>
                        <input type="text" class="form-control" id="relation" name="relation">
                    </div>
                    <div class="mb-3">
                        <label for="contact" class="form-label"><?php echo __('recommender_contact'); ?></label>
                        <input type="text" class="form-control" id="contact" name="contact">
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

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?php echo __('confirm_delete'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p><?php echo __('delete_recommender_confirm'); ?> <strong id="recommenderName"></strong>?</p>
                <p class="text-danger"><?php echo __('action_cannot_be_undone'); ?></p>
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
    // Function to show edit modal with recommender data
    function editRecommender(id, name, relation, contact) {
        document.getElementById('recommender_id').value = id;
        document.getElementById('name').value = name;
        document.getElementById('relation').value = relation;
        document.getElementById('contact').value = contact;
        document.getElementById('modalTitle').textContent = '<?php echo __('edit_recommender'); ?>';
        
        var modal = new bootstrap.Modal(document.getElementById('addRecommenderModal'));
        modal.show();
    }
    
    // Reset form when modal is closed
    document.getElementById('addRecommenderModal').addEventListener('hidden.bs.modal', function () {
        document.getElementById('recommenderForm').reset();
        document.getElementById('recommender_id').value = '';
        document.getElementById('modalTitle').textContent = '<?php echo __('add_new_recommender'); ?>';
    });
    
    // Function to show delete confirmation modal
    function confirmDelete(id, name) {
        document.getElementById('recommenderName').textContent = name;
        document.getElementById('confirmDeleteBtn').href = 'recommenders.php?action=delete&id=' + id;
        
        var deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
        deleteModal.show();
    }
    
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    });
    
    <?php if (isset($_SESSION['open_recommender_modal']) && $_SESSION['open_recommender_modal']): ?>
    // Auto-open the modal when redirected from new-recommender.php
    document.addEventListener('DOMContentLoaded', function() {
        var modal = new bootstrap.Modal(document.getElementById('addRecommenderModal'));
        modal.show();
        <?php unset($_SESSION['open_recommender_modal']); ?>
    });
    <?php endif; ?>
</script>

</body>
</html>
<?php ob_end_flush(); ?> 
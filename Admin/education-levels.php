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
    $educationId = $_GET["id"];
    
    try {
        // Check if the education level is being used by any employee
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE education_level_id = ?");
        $checkStmt->execute([$educationId]);
        $count = $checkStmt->fetchColumn();
        
        if ($count > 0) {
            $_SESSION["error_message"] = sprintf(__('education_level_delete_employees_error'), $count);
            header("location: education-levels.php");
            exit;
        }
        
        // Begin transaction
        $pdo->beginTransaction();
        
        // Delete the education level record
        $stmt = $pdo->prepare("DELETE FROM education_levels WHERE id = ?");
        $stmt->execute([$educationId]);
        
        // Commit transaction
        $pdo->commit();
        
        $_SESSION["success_message"] = __('education_level_deleted_success');
        header("location: education-levels.php");
        exit;
    } catch (PDOException $e) {
        // Rollback transaction
        $pdo->rollBack();
        
        $_SESSION["error_message"] = __('education_level_delete_error') . ": " . $e->getMessage();
        header("location: education-levels.php");
        exit;
    }
}

// Process add/edit operation via modal
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Check if we're adding or editing
    $isEdit = isset($_POST["education_id"]) && !empty($_POST["education_id"]);
    $educationId = $isEdit ? trim($_POST["education_id"]) : null;
    
    // Validate degree name
    if (empty(trim($_POST["degree_name"]))) {
        $_SESSION["error_message"] = __('degree_name_required');
        header("location: education-levels.php");
        exit;
    }
    
    $degree_name = trim($_POST["degree_name"]);
    $institution = !empty(trim($_POST["institution"])) ? trim($_POST["institution"]) : null;
    $graduation_year = !empty(trim($_POST["graduation_year"])) ? trim($_POST["graduation_year"]) : null;
    
    // Validate graduation year if provided
    if ($graduation_year !== null) {
        if (!is_numeric($graduation_year) || $graduation_year < 1900 || $graduation_year > date('Y')) {
            $_SESSION["error_message"] = __('invalid_graduation_year');
            header("location: education-levels.php");
            exit;
        }
    }
    
    try {
        // Begin transaction
        $pdo->beginTransaction();
        
        if ($isEdit) {
            // Update existing education level
            $stmt = $pdo->prepare("UPDATE education_levels SET degree_name = ?, institution = ?, graduation_year = ? WHERE id = ?");
            $stmt->execute([$degree_name, $institution, $graduation_year, $educationId]);
            $message = __('education_level_updated_success');
        } else {
            // Add new education level
            $stmt = $pdo->prepare("INSERT INTO education_levels (degree_name, institution, graduation_year) VALUES (?, ?, ?)");
            $stmt->execute([$degree_name, $institution, $graduation_year]);
            $message = __('education_level_added_success');
        }
        
        // Commit transaction
        $pdo->commit();
        
        $_SESSION["success_message"] = $message;
        header("location: education-levels.php");
        exit;
    } catch (PDOException $e) {
        // Rollback transaction
        $pdo->rollBack();
        
        $_SESSION["error_message"] = __('error') . ": " . $e->getMessage();
        header("location: education-levels.php");
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
$sql = "SELECT e.*, (SELECT COUNT(*) FROM employees WHERE education_level_id = e.id) as employee_count 
        FROM education_levels e
        WHERE 1=1";

$params = [];

if (!empty($searchTerm)) {
    $sql .= " AND (e.degree_name LIKE ? OR e.institution LIKE ?)";
    $params[] = "%{$searchTerm}%";
    $params[] = "%{$searchTerm}%";
}

// Get total records for pagination
$countSql = str_replace("e.*, (SELECT COUNT(*) FROM employees WHERE education_level_id = e.id) as employee_count", "COUNT(*) as total", $sql);
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRecords = $countStmt->fetch(PDO::FETCH_ASSOC)["total"];
$totalPages = ceil($totalRecords / $recordsPerPage);

// Add sorting and pagination to main query
$sql .= " ORDER BY e.degree_name ASC LIMIT $offset, $recordsPerPage";

// Execute the main query
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$educationLevels = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php include 'layouts/head-main.php'; ?>

<head>
    <title><?php echo __('education_levels'); ?> | <?php echo __('employee_manager_system'); ?></title>
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
                            <h4 class="mb-sm-0 font-size-18"><?php echo __('education_levels'); ?></h4>

                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="javascript: void(0);"><?php echo __('employee_manager'); ?></a></li>
                                    <li class="breadcrumb-item active"><?php echo __('education_levels'); ?></li>
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
                                    <h4 class="card-title"><?php echo __('all_education_levels'); ?></h4>
                                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addEducationModal">
                                        <i class="bx bx-plus me-1"></i> <?php echo __('add_new_education_level'); ?>
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row mb-4">
                                    <div class="col-md-6">
                                        <form method="GET" action="education-levels.php" class="d-flex gap-2">
                                            <div class="input-group">
                                                <input type="text" class="form-control" id="search" name="search" placeholder="<?php echo __('search_education_levels'); ?>" value="<?php echo htmlspecialchars($searchTerm); ?>">
                                                <button class="btn btn-outline-secondary" type="submit">
                                                    <i class="bx bx-search-alt"></i>
                                                </button>
                                            </div>
                                            <?php if (!empty($searchTerm)): ?>
                                            <a href="education-levels.php" class="btn btn-outline-secondary">
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
                                                <th scope="col"><?php echo __('degree_name'); ?></th>
                                                <th scope="col"><?php echo __('institution'); ?></th>
                                                <th scope="col"><?php echo __('graduation_year'); ?></th>
                                                <th scope="col" style="width: 120px;"><?php echo __('employees'); ?></th>
                                                <th scope="col" style="width: 150px;"><?php echo __('actions'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (count($educationLevels) > 0): ?>
                                                <?php foreach ($educationLevels as $index => $education): ?>
                                                <tr>
                                                    <td><?php echo $offset + $index + 1; ?></td>
                                                    <td><?php echo htmlspecialchars($education['degree_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($education['institution'] ?? __('not_specified')); ?></td>
                                                    <td><?php echo htmlspecialchars($education['graduation_year'] ?? __('not_specified')); ?></td>
                                                    <td>
                                                        <span class="badge bg-primary"><?php echo $education['employee_count']; ?></span>
                                                    </td>
                                                    <td>
                                                        <div class="d-flex gap-2">
                                                            <button type="button" class="btn btn-primary btn-sm" onclick="editEducation('<?php echo $education['id']; ?>', '<?php echo htmlspecialchars(addslashes($education['degree_name'])); ?>', '<?php echo htmlspecialchars(addslashes($education['institution'] ?? '')); ?>', '<?php echo htmlspecialchars($education['graduation_year'] ?? ''); ?>')">
                                                                <i class="bx bx-edit"></i>
                                                            </button>
                                                            <button type="button" class="btn btn-danger btn-sm <?php echo ($education['employee_count'] > 0) ? 'disabled' : ''; ?>" 
                                                                    <?php if ($education['employee_count'] == 0): ?>
                                                                    onclick="confirmDelete('<?php echo $education['id']; ?>', '<?php echo htmlspecialchars(addslashes($education['degree_name'])); ?>')"
                                                                    <?php endif; ?>
                                                                    data-bs-toggle="tooltip" title="<?php echo ($education['employee_count'] > 0) ? __('cannot_delete_linked_education_level') : __('delete_education_level'); ?>">
                                                                <i class="bx bx-trash"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="6" class="text-center"><?php echo __('no_education_levels_found'); ?></td>
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

<!-- Add/Edit Education Level Modal -->
<div class="modal fade" id="addEducationModal" tabindex="-1" aria-labelledby="addEducationModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="educationForm" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                <input type="hidden" name="education_id" id="education_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle"><?php echo __('add_new_education_level'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="degree_name" class="form-label"><?php echo __('degree_name'); ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="degree_name" name="degree_name" required>
                    </div>
                    <div class="mb-3">
                        <label for="institution" class="form-label"><?php echo __('institution'); ?></label>
                        <input type="text" class="form-control" id="institution" name="institution">
                    </div>
                    <div class="mb-3">
                        <label for="graduation_year" class="form-label"><?php echo __('graduation_year'); ?></label>
                        <input type="number" class="form-control" id="graduation_year" name="graduation_year" min="1900" max="<?php echo date('Y'); ?>">
                        <small class="text-muted"><?php echo __('enter_year_between'); ?> 1900 <?php echo __('and'); ?> <?php echo date('Y'); ?></small>
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
                <?php echo __('delete_education_level_confirm'); ?> <span id="educationName"></span>?
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
    // Function to show edit modal with education level data
    function editEducation(id, degreeName, institution, graduationYear) {
        document.getElementById('education_id').value = id;
        document.getElementById('degree_name').value = degreeName;
        document.getElementById('institution').value = institution;
        document.getElementById('graduation_year').value = graduationYear;
        document.getElementById('modalTitle').textContent = '<?php echo __('edit_education_level'); ?>';
        
        var modal = new bootstrap.Modal(document.getElementById('addEducationModal'));
        modal.show();
    }
    
    // Reset form when modal is closed
    document.getElementById('addEducationModal').addEventListener('hidden.bs.modal', function () {
        document.getElementById('educationForm').reset();
        document.getElementById('education_id').value = '';
        document.getElementById('modalTitle').textContent = '<?php echo __('add_new_education_level'); ?>';
    });
    
    // Function to show delete confirmation modal
    function confirmDelete(id, name) {
        document.getElementById('educationName').textContent = name;
        document.getElementById('confirmDeleteBtn').href = 'education-levels.php?action=delete&id=' + id;
        
        var deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
        deleteModal.show();
    }
    
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    });
    
    <?php if (isset($_SESSION['open_education_modal']) && $_SESSION['open_education_modal']): ?>
    // Auto-open the modal when redirected from new-education.php
    document.addEventListener('DOMContentLoaded', function() {
        var modal = new bootstrap.Modal(document.getElementById('addEducationModal'));
        modal.show();
        <?php unset($_SESSION['open_education_modal']); ?>
    });
    <?php endif; ?>
</script>

</body>
</html>
<?php ob_end_flush(); ?> 
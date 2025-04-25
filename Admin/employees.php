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
    $employeeId = $_GET["id"];
    
    try {
        // Begin transaction
        $pdo->beginTransaction();
        
        // Delete the employee record
        $stmt = $pdo->prepare("DELETE FROM employees WHERE id = ?");
        $stmt->execute([$employeeId]);
        
        // Commit transaction
        $pdo->commit();
        
        $_SESSION["success_message"] = __("employees_deleted_success");
        header("location: employees.php");
        exit;
    } catch (PDOException $e) {
        // Rollback transaction
        $pdo->rollBack();
        
        $_SESSION["error_message"] = __("employees_error_delete") . ": " . $e->getMessage();
        header("location: employees.php");
        exit;
    }
}

// Get filter parameters
$searchTerm = isset($_GET["search"]) ? trim($_GET["search"]) : "";
$postFilter = isset($_GET["post"]) ? trim($_GET["post"]) : "";

// Build the SQL query to get all employees
// We no longer need manual pagination since DataTables will handle it
$sql = "SELECT e.*, p.title as post_title
        FROM employees e
        LEFT JOIN posts p ON e.post_id = p.id
        WHERE 1=1";

$params = [];

if (!empty($searchTerm)) {
    $sql .= " AND (e.full_name LIKE ? OR e.phone_number LIKE ?)";
    $params[] = "%{$searchTerm}%";
    $params[] = "%{$searchTerm}%";
}

if (!empty($postFilter)) {
    $sql .= " AND e.post_id = ?";
    $params[] = $postFilter;
}

// Sort by name by default
$sql .= " ORDER BY e.full_name ASC";

// Execute the main query
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all posts for the filter dropdown
$postsStmt = $pdo->query("SELECT id, title FROM posts ORDER BY title");
$posts = $postsStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php include 'layouts/head-main.php'; ?>

<head>
    <title><?php echo __('employees_management'); ?> | <?php echo __('common_rms'); ?></title>
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
                            <h4 class="mb-sm-0 font-size-18"><?php echo __('employees_management'); ?></h4>

                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="javascript: void(0);"><?php echo __('common_rms'); ?></a></li>
                                    <li class="breadcrumb-item active"><?php echo __('employees_list'); ?></li>
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
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?php echo __('common_close'); ?>"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h4 class="card-title"><?php echo __('employees_list'); ?></h4>
                                    <a href="new-employee.php" class="btn btn-primary">
                                        <i class="bx bx-plus me-1"></i> <?php echo __('employees_add_new'); ?>
                                    </a>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row mb-4">
                                    <div class="col-md-8">
                                        <form method="GET" action="employees.php" class="row gy-2 gx-3 align-items-center">
                                            <div class="col-sm-5">
                                                <div class="input-group">
                                                    <input type="text" class="form-control" id="search" name="search" placeholder="<?php echo __('employees_search_placeholder'); ?>" value="<?php echo htmlspecialchars($searchTerm); ?>">
                                                    <button class="btn btn-outline-secondary" type="submit">
                                                        <i class="bx bx-search-alt"></i>
                                                    </button>
                                                </div>
                                            </div>
                                            <div class="col-sm-5">
                                                <select class="form-select" id="post" name="post">
                                                    <option value=""><?php echo __('employees_all_positions'); ?></option>
                                                    <?php foreach ($posts as $post): ?>
                                                    <option value="<?php echo $post['id']; ?>" <?php echo ($postFilter == $post['id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($post['title']); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-sm-2">
                                                <button type="submit" class="btn btn-primary w-100"><?php echo __('common_filter'); ?></button>
                                            </div>
                                        </form>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table id="employees-table" class="table table-bordered table-hover">
                                        <thead>
                                            <tr>
                                                <th><?php echo __('employees_full_name'); ?></th>
                                                <th><?php echo __('employees_position'); ?></th>
                                                <th>Reference</th>
                                                <th><?php echo __('employees_phone'); ?></th>
                                                <th><?php echo __('employees_base_salary'); ?></th>
                                                <th><?php echo __('employees_recruitment_date'); ?></th>
                                                <th><?php echo __('common_actions'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($employees as $employee): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($employee['full_name']); ?></td>
                                                <td><?php echo htmlspecialchars($employee['post_title'] ?? __('not_available')); ?></td>
                                                <td><?php echo htmlspecialchars($employee['reference'] ?? __('not_available')); ?></td>
                                                <td><?php echo htmlspecialchars($employee['phone_number'] ?? __('not_available')); ?></td>
                                                <td>
                                                    <?php 
                                                    if ($employee['base_salary']) {
                                                        echo number_format($employee['base_salary'], 2);
                                                    } else {
                                                        echo __('not_available');
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    if ($employee['recruitment_date']) {
                                                        echo date('Y-m-d', strtotime($employee['recruitment_date']));
                                                    } else {
                                                        echo __('not_available');
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <a href="view-employee.php?id=<?php echo $employee['id']; ?>" class="btn btn-sm btn-info">
                                                        <i class="bx bx-show"></i>
                                                    </a>
                                                    <a href="edit-employee.php?id=<?php echo $employee['id']; ?>" class="btn btn-sm btn-primary">
                                                        <i class="bx bx-edit"></i>
                                                    </a>
                                                    <button class="btn btn-sm btn-danger delete-employee" 
                                                            data-id="<?php echo $employee['id']; ?>"
                                                            data-name="<?php echo htmlspecialchars($employee['full_name']); ?>">
                                                        <i class="bx bx-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- DataTables will handle pagination automatically -->
                                
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

<!-- Delete confirmation modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteModalLabel"><?php echo __('employees_confirm_delete'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php echo __('employees_delete_confirm_text'); ?> <span id="employeeName"></span>?
                <p class="text-danger mt-2 mb-0"><?php echo __('employees_delete_warning'); ?></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('common_cancel'); ?></button>
                <a href="#" id="confirmDeleteBtn" class="btn btn-danger"><?php echo __('common_delete'); ?></a>
            </div>
        </div>
    </div>
</div>

<?php include 'layouts/vendor-scripts.php'; ?>

<!-- DataTables scripts -->
<script src="assets/libs/datatables.net/js/jquery.dataTables.min.js"></script>
<script src="assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js"></script>
<script src="assets/libs/datatables.net-responsive/js/dataTables.responsive.min.js"></script>
<script src="assets/libs/datatables.net-responsive-bs4/js/responsive.bootstrap4.min.js"></script>

<script>
    // Initialize DataTables with translation
    $(document).ready(function() {
        // Initialize DataTable
        var table = $('#employees-table').DataTable({
            "language": {
                "paginate": {
                    "first": "<?php echo __('First'); ?>",
                    "last": "<?php echo __('Last'); ?>",
                    "next": "<?php echo __('Next'); ?>",
                    "previous": "<?php echo __('Previous'); ?>"
                },
                "search": "<?php echo __('Search'); ?>:",
                "lengthMenu": "<?php echo __('Show'); ?> _MENU_ <?php echo __('entries'); ?>",
                "info": "<?php echo __('Showing'); ?> _START_ <?php echo __('to'); ?> _END_ <?php echo __('of'); ?> _TOTAL_ <?php echo __('entries'); ?>",
                "infoEmpty": "<?php echo __('No entries to show'); ?>",
                "infoFiltered": "(<?php echo __('filtered from'); ?> _MAX_ <?php echo __('total entries'); ?>)",
                "emptyTable": "<?php echo __('No data available in table'); ?>",
                "zeroRecords": "<?php echo __('No matching records found'); ?>"
            },
            "drawCallback": function() {
                initEventHandlers();
            }
        });
        
        // Initialize event handlers
        initEventHandlers();
        
        function initEventHandlers() {
            // Delete employee button click
            $('.delete-employee').off('click').on('click', function() {
                var employeeId = $(this).data('id');
                var employeeName = $(this).data('name');
                
                $('#employeeName').text(employeeName);
                $('#confirmDeleteBtn').attr('href', 'employees.php?action=delete&id=' + employeeId);
                
                var deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
                deleteModal.show();
            });
        }
        
        // Auto-submit form when post select changes
        $('#post').on('change', function() {
            $(this).closest('form').submit();
        });
        
        // Display success and error messages using Toastr if available
        <?php if (isset($_SESSION['success_message'])): ?>
            if(typeof toastr !== 'undefined') {
                toastr.success('<?php echo $_SESSION['success_message']; ?>');
            }
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error_message'])): ?>
            if(typeof toastr !== 'undefined') {
                toastr.error('<?php echo $_SESSION['error_message']; ?>');
            }
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>
    });
</script>

</body>
</html>
<?php ob_end_flush(); ?>
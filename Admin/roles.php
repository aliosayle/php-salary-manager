<?php
// Set up error logging
ini_set('display_errors', 1);
ini_set('error_log', __DIR__ . '/layouts/logs/error.log');
error_log("Roles.php - Page load started");

// Include required files
require_once "layouts/config.php";
require_once "layouts/helpers.php";
require_once "layouts/translations.php"; // Include translations before using the __() function
require_once "layouts/session.php"; // Include session validation

// Now session.php has already validated the session, continue with the page

// Check if the user has permission to access this page
requirePermission('view_roles');

// Only admins can manage roles
$is_admin = hasPermission('manage_permissions');

if (!$is_admin) {
    // Not an admin, redirect to dashboard with error message
    $_SESSION['error_message'] = __('roles_error_no_permission');
    header("location: index.php");
    exit;
}

// Process role actions (create, edit, delete, permissions)
$message = '';
$message_type = '';

// Handle new role creation
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'create') {
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    
    // Validate inputs
    $errors = [];
    
    if (empty($name)) {
        $errors[] = __('roles_error_name_required');
    }
    
    // Check if role already exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE name = ?");
    $stmt->execute([$name]);
    if ($stmt->fetchColumn() > 0) {
        $errors[] = __('roles_error_name_exists');
    }
    
    if (empty($errors)) {
        try {
            // Insert the new role
            $stmt = $pdo->prepare("INSERT INTO roles (name, description) VALUES (?, ?)");
            $result = $stmt->execute([$name, $description]);
            
            if ($result) {
                $message = __('roles_created_success');
                $message_type = "success";
            } else {
                $message = __('roles_error_generic');
                $message_type = "danger";
            }
        } catch (PDOException $e) {
            error_log("Error creating role: " . $e->getMessage());
            $message = __('roles_error_database') . ": " . $e->getMessage();
            $message_type = "danger";
        }
    } else {
        $message = __('roles_error_fix') . ": " . implode(", ", $errors);
        $message_type = "danger";
    }
}

// Handle role edit
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'edit') {
    $role_id = $_POST['role_id'];
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    
    // Validate inputs
    $errors = [];
    
    if (empty($name)) {
        $errors[] = __('roles_error_name_required');
    }
    
    // Check if role name already exists for another role
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE name = ? AND id != ?");
    $stmt->execute([$name, $role_id]);
    if ($stmt->fetchColumn() > 0) {
        $errors[] = __('roles_error_name_exists');
    }
    
    if (empty($errors)) {
        try {
            // Update the role
            $stmt = $pdo->prepare("UPDATE roles SET name = ?, description = ? WHERE id = ?");
            $result = $stmt->execute([$name, $description, $role_id]);
            
            if ($result) {
                $message = __('roles_updated_success');
                $message_type = "success";
            } else {
                $message = __('roles_error_generic');
                $message_type = "danger";
            }
        } catch (PDOException $e) {
            error_log("Error updating role: " . $e->getMessage());
            $message = __('roles_error_database') . ": " . $e->getMessage();
            $message_type = "danger";
        }
    } else {
        $message = __('roles_error_fix') . ": " . implode(", ", $errors);
        $message_type = "danger";
    }
}

// Handle role deletion
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'delete') {
    $role_id = $_POST['role_id'];
    
    // Check if the role is assigned to any users
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role_id = ?");
    $stmt->execute([$role_id]);
    $userCount = $stmt->fetchColumn();
    
    if ($userCount > 0) {
        $message = __('roles_error_cannot_delete_assigned');
        $message_type = "danger";
    } else {
        try {
            // Start transaction
            $pdo->beginTransaction();
            
            // Delete role's permissions
            $stmt = $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?");
            $stmt->execute([$role_id]);
            
            // Delete the role
            $stmt = $pdo->prepare("DELETE FROM roles WHERE id = ?");
            $result = $stmt->execute([$role_id]);
            
            if ($result) {
                $pdo->commit();
                $message = __('roles_deleted_success');
                $message_type = "success";
            } else {
                $pdo->rollBack();
                $message = __('roles_error_generic');
                $message_type = "danger";
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Error deleting role: " . $e->getMessage());
            $message = __('roles_error_database') . ": " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// Handle permission update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'permissions') {
    $role_id = $_POST['role_id'];
    $permissions = isset($_POST['permissions']) ? $_POST['permissions'] : [];
    
    try {
        // Start transaction
        $pdo->beginTransaction();
        
        // Remove all existing permissions for this role
        $stmt = $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?");
        $stmt->execute([$role_id]);
        
        // Add new permissions
        if (!empty($permissions)) {
            $stmt = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
            foreach ($permissions as $permission_id) {
                $stmt->execute([$role_id, $permission_id]);
            }
        }
        
        $pdo->commit();
        $message = __('roles_permissions_updated_success');
        $message_type = "success";
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Error updating permissions: " . $e->getMessage());
        $message = __('roles_error_database') . ": " . $e->getMessage();
        $message_type = "danger";
    }
}

// Get the list of roles
try {
    $stmt = $pdo->query("
        SELECT r.*, 
               (SELECT COUNT(*) FROM users WHERE role_id = r.id) as user_count,
               (SELECT COUNT(*) FROM role_permissions WHERE role_id = r.id) as permission_count
        FROM roles r
        ORDER BY r.name
    ");
    $roles = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching roles: " . $e->getMessage());
    $roles = [];
    $message = "Failed to fetch roles: " . $e->getMessage();
    $message_type = "danger";
}

// Get the list of all permissions
try {
    // Use the columns that actually exist in the permissions table
    $stmt = $pdo->query("SELECT id, action, description FROM permissions ORDER BY action");
    error_log("Permissions query executed successfully");
    $all_permissions = $stmt->fetchAll();
    error_log("Found " . count($all_permissions) . " permissions");
    
    if (count($all_permissions) === 0) {
        error_log("No permissions found in database. This could be an issue.");
    }
} catch (PDOException $e) {
    error_log("Error fetching permissions: " . $e->getMessage());
    $all_permissions = [];
}

// Group permissions by category (derived from action)
$permission_categories = [];
foreach ($all_permissions as $permission) {
    // Extract category from action field
    $category = strtolower(preg_replace('/^([^_\s]+).*$/', '$1', $permission['action']));
    
    if (!isset($permission_categories[$category])) {
        $permission_categories[$category] = [];
    }
    
    // Add a 'name' field for backwards compatibility
    $permission['name'] = $permission['description'] ?: $permission['action'];
    $permission_categories[$category][] = $permission;
}

// Function to get permissions for a specific role
function getRolePermissions($pdo, $role_id) {
    $stmt = $pdo->prepare("
        SELECT permission_id 
        FROM role_permissions 
        WHERE role_id = ?
    ");
    $stmt->execute([$role_id]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

?>

<?php include 'layouts/head-main.php'; ?>

<head>
    <title><?php echo __('roles_management'); ?> | Rental Management System</title>
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
                            <h4 class="mb-sm-0 font-size-18"><?php echo __('roles_management'); ?></h4>

                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php"><?php echo __('dashboard'); ?></a></li>
                                    <li class="breadcrumb-item active"><?php echo __('roles_management'); ?></li>
                                </ol>
                            </div>

                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <div class="row">
                    <div class="col-12">
                        <?php if (!empty($message)): ?>
                        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                            <?php echo $message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php endif; ?>
                        
                        <div class="card">
                            <div class="card-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h4 class="card-title mb-0"><?php echo __('roles_list'); ?></h4>
                                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createRoleModal">
                                        <i class="bx bx-plus me-1"></i> <?php echo __('roles_add_new'); ?>
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table id="roles-table" class="table table-striped table-bordered dt-responsive nowrap w-100" style="border-collapse: collapse; border-spacing: 0;">
                                        <thead>
                                            <tr>
                                                <th><?php echo __('roles_name'); ?></th>
                                                <th><?php echo __('roles_description'); ?></th>
                                                <th><?php echo __('roles_users'); ?></th>
                                                <th><?php echo __('roles_permissions'); ?></th>
                                                <th><?php echo __('roles_created_at'); ?></th>
                                                <th><?php echo __('roles_actions'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($roles as $role): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($role['name']); ?></td>
                                                <td><?php echo htmlspecialchars($role['description'] ?? ''); ?></td>
                                                <td><?php echo $role['user_count']; ?></td>
                                                <td><?php echo $role['permission_count']; ?></td>
                                                <td><?php echo !empty($role['created_at']) ? date('Y-m-d H:i', strtotime($role['created_at'])) : __('not_available'); ?></td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-sm btn-info edit-role-btn" 
                                                            data-id="<?php echo $role['id']; ?>"
                                                            data-name="<?php echo htmlspecialchars($role['name']); ?>"
                                                            data-description="<?php echo htmlspecialchars($role['description'] ?? ''); ?>"
                                                            data-bs-toggle="modal" data-bs-target="#editRoleModal">
                                                        <i class="bx bx-edit-alt"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-primary manage-permissions-btn"
                                                            data-id="<?php echo $role['id']; ?>"
                                                            data-name="<?php echo htmlspecialchars($role['name']); ?>"
                                                            data-bs-toggle="modal" data-bs-target="#managePermissionsModal">
                                                        <i class="bx bx-key"></i>
                                                    </button>
                                                    <?php if ($role['user_count'] == 0): ?>
                                                    <button type="button" class="btn btn-sm btn-danger delete-role-btn"
                                                            data-id="<?php echo $role['id']; ?>"
                                                            data-name="<?php echo htmlspecialchars($role['name']); ?>"
                                                            data-bs-toggle="modal" data-bs-target="#deleteRoleModal">
                                                        <i class="bx bx-trash"></i>
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

            </div> <!-- container-fluid -->
        </div>
        <!-- End Page-content -->

        <?php include 'layouts/footer.php'; ?>
    </div>
    <!-- end main content-->

</div>
<!-- END layout-wrapper -->

<!-- Create Role Modal -->
<div class="modal fade" id="createRoleModal" tabindex="-1" aria-labelledby="createRoleModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="createRoleModalLabel"><?php echo __('roles_create_title'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="" method="POST">
                <input type="hidden" name="action" value="create">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="create-name" class="form-label"><?php echo __('roles_name'); ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="create-name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="create-description" class="form-label"><?php echo __('roles_description'); ?></label>
                        <textarea class="form-control" id="create-description" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo __('create'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Role Modal -->
<div class="modal fade" id="editRoleModal" tabindex="-1" aria-labelledby="editRoleModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editRoleModalLabel"><?php echo __('roles_edit_title'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="" method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="role_id" id="edit-role-id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit-name" class="form-label"><?php echo __('roles_name'); ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit-name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit-description" class="form-label"><?php echo __('roles_description'); ?></label>
                        <textarea class="form-control" id="edit-description" name="description" rows="3"></textarea>
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

<!-- Delete Role Modal -->
<div class="modal fade" id="deleteRoleModal" tabindex="-1" aria-labelledby="deleteRoleModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteRoleModalLabel"><?php echo __('roles_delete_title'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="" method="POST">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="role_id" id="delete-role-id">
                <div class="modal-body">
                    <p><?php echo __('roles_delete_confirm'); ?> <span id="delete-role-name" class="fw-bold"></span>?</p>
                    <p class="text-danger"><?php echo __('roles_delete_warning'); ?></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-danger"><?php echo __('delete'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Manage Permissions Modal -->
<div class="modal fade" id="managePermissionsModal" tabindex="-1" aria-labelledby="managePermissionsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="managePermissionsModalLabel"><?php echo __('roles_manage_permissions_for'); ?> <span id="permissions-role-name"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="" method="POST">
                <input type="hidden" name="action" value="permissions">
                <input type="hidden" name="role_id" id="permissions-role-id">
                <div class="modal-body">
                    <div id="permissions-loading" class="text-center mb-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden"><?php echo __('loading'); ?></span>
                        </div>
                        <p class="mt-2"><?php echo __('roles_loading_permissions'); ?></p>
                    </div>
                    <div id="permissions-content" style="display: none;">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bx bx-search"></i></span>
                                    <input type="text" class="form-control" id="permission-search" placeholder="<?php echo __('roles_search_permissions'); ?>">
                                    <button type="button" class="btn btn-outline-secondary clear-search"><?php echo __('clear'); ?></button>
                                </div>
                            </div>
                            <div class="col-md-6 text-md-end mt-2 mt-md-0">
                                <button type="button" class="btn btn-sm btn-outline-primary select-all-permissions"><?php echo __('select_all'); ?></button>
                                <button type="button" class="btn btn-sm btn-outline-secondary deselect-all-permissions"><?php echo __('deselect_all'); ?></button>
                            </div>
                        </div>
                        
                        <div class="permissions-scroll-container" style="max-height: 60vh; overflow-y: auto; overflow-x: hidden;">
                            <div class="accordion" id="accordionPermissions">
                                <?php foreach ($permission_categories as $category => $permissions): ?>
                                <div class="accordion-item permission-category">
                                    <h2 class="accordion-header" id="heading<?php echo str_replace(' ', '', ucfirst($category)); ?>">
                                        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?php echo str_replace(' ', '', ucfirst($category)); ?>" aria-expanded="true" aria-controls="collapse<?php echo str_replace(' ', '', ucfirst($category)); ?>">
                                            <?php echo ucfirst($category); ?> <?php echo __('roles_permissions'); ?>
                                            <span class="ms-2 badge bg-primary permission-count"><?php echo count($permissions); ?></span>
                                        </button>
                                    </h2>
                                    <div id="collapse<?php echo str_replace(' ', '', ucfirst($category)); ?>" class="accordion-collapse collapse show" aria-labelledby="heading<?php echo str_replace(' ', '', ucfirst($category)); ?>" data-bs-parent="#accordionPermissions">
                                        <div class="accordion-body">
                                            <div class="row">
                                                <?php foreach ($permissions as $permission): ?>
                                                <div class="col-md-6 mb-2 permission-item">
                                                    <div class="form-check">
                                                        <input class="form-check-input permission-checkbox" type="checkbox" value="<?php echo $permission['id']; ?>" name="permissions[]" id="permission-<?php echo $permission['id']; ?>" data-description="<?php echo htmlspecialchars(strtolower($permission['description'] ?? $permission['action'])); ?>">
                                                        <label class="form-check-label" for="permission-<?php echo $permission['id']; ?>">
                                                            <?php echo htmlspecialchars($permission['name']); ?>
                                                        </label>
                                                    </div>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div id="no-permissions-found" class="alert alert-info mt-3" style="display: none;">
                            <?php echo __('roles_no_permissions_found'); ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo __('save_permissions'); ?></button>
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
<script src="assets/libs/datatables.net-responsive/js/dataTables.responsive.min.js"></script>
<script src="assets/libs/datatables.net-responsive-bs4/js/responsive.bootstrap4.min.js"></script>


<script>
    $(document).ready(function() {
        // Most basic DataTable initialization
        $('#roles-table').DataTable({
            paging: true,
            ordering: true,
            searching: true,
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
            }
        });
        
        // Handle Edit Role Modal
        $('.edit-role-btn').on('click', function() {
            var roleId = $(this).data('id');
            var name = $(this).data('name');
            var description = $(this).data('description');
            
            $('#edit-role-id').val(roleId);
            $('#edit-name').val(name);
            $('#edit-description').val(description);
        });
        
        // Handle Delete Role Modal
        $('.delete-role-btn').on('click', function() {
            var roleId = $(this).data('id');
            var name = $(this).data('name');
            
            $('#delete-role-id').val(roleId);
            $('#delete-role-name').text(name);
        });
        
        // Handle Manage Permissions Modal
        $('.manage-permissions-btn').on('click', function() {
            var roleId = $(this).data('id');
            var name = $(this).data('name');
            
            $('#permissions-role-id').val(roleId);
            $('#permissions-role-name').text(name);
            
            // Clear any previous search
            $('#permission-search').val('');
            $('.permission-item').show();
            $('.permission-category').show();
            $('#no-permissions-found').hide();
            
            // Show loading, hide content
            $('#permissions-loading').show();
            $('#permissions-content').hide();
            
            // Uncheck all permissions first
            $('.permission-checkbox').prop('checked', false);
            
            // Load the permissions for this role
            $.ajax({
                url: 'ajax-get-role-permissions.php',
                type: 'GET',
                data: {
                    role_id: roleId
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Check the permissions that are granted to this role
                        $.each(response.permissions, function(index, permissionId) {
                            $('#permission-' + permissionId).prop('checked', true);
                        });
                    } else {
                        // Show error message
                        console.error('Failed to load permissions:', response.message);
                        alert('<?php echo __('roles_error_loading_permissions'); ?>: ' + (response.message || '<?php echo __('unknown_error'); ?>'));
                    }
                    
                    // Hide loading, show content
                    $('#permissions-loading').hide();
                    $('#permissions-content').show();
                    
                    // Focus on the search input after content is shown
                    setTimeout(function() {
                        $('#permission-search').focus();
                    }, 500);
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    alert('<?php echo __('roles_error_loading_permissions'); ?>. <?php echo __('please_try_again'); ?>');
                    
                    // Hide loading, show content (even on error, to allow retry)
                    $('#permissions-loading').hide();
                    $('#permissions-content').show();
                }
            });
        });
        
        // Handle Select All Permissions
        $('.select-all-permissions').on('click', function() {
            $('.permission-checkbox:visible').prop('checked', true);
        });
        
        // Handle Deselect All Permissions
        $('.deselect-all-permissions').on('click', function() {
            $('.permission-checkbox:visible').prop('checked', false);
        });
        
        // Handle Permission Search
        $('#permission-search').on('input', function() {
            var searchTerm = $(this).val().toLowerCase().trim();
            var visibleItems = 0;
            
            // If search is empty, show all
            if (searchTerm === '') {
                $('.permission-item').show();
                $('.permission-category').show();
                $('#no-permissions-found').hide();
                
                // Update the permission counts
                $('.permission-category').each(function() {
                    var categoryItems = $(this).find('.permission-item').length;
                    $(this).find('.permission-count').text(categoryItems);
                });
                
                return;
            }
            
            // Hide all categories first
            $('.permission-category').hide();
            
            // Show items that match search and their parent categories
            $('.permission-item').each(function() {
                var permissionText = $(this).find('label').text().toLowerCase();
                var permissionDesc = $(this).find('input').data('description');
                
                if (permissionText.includes(searchTerm) || (permissionDesc && permissionDesc.includes(searchTerm))) {
                    $(this).show();
                    $(this).closest('.permission-category').show();
                    visibleItems++;
                } else {
                    $(this).hide();
                }
            });
            
            // Update the permission counts for visible categories
            $('.permission-category:visible').each(function() {
                var visibleCategoryItems = $(this).find('.permission-item:visible').length;
                $(this).find('.permission-count').text(visibleCategoryItems);
            });
            
            // Show "no results" message if needed
            if (visibleItems === 0) {
                $('#no-permissions-found').show();
            } else {
                $('#no-permissions-found').hide();
            }
        });
        
        // Handle Clear Search button
        $('.clear-search').on('click', function() {
            $('#permission-search').val('').focus().trigger('input');
        });

        // Add keyboard shortcuts for the permissions modal
        $('#managePermissionsModal').on('keydown', function(e) {
            // If modal is not visible, do nothing
            if (!$('#managePermissionsModal').is(':visible')) {
                return;
            }
            
            // Ctrl+F or Cmd+F (Mac) for search
            if ((e.ctrlKey || e.metaKey) && e.keyCode === 70) {
                e.preventDefault(); // Prevent browser's default search
                $('#permission-search').focus();
            }
            
            // Escape key to clear search if search is focused and not empty
            if (e.keyCode === 27 && document.activeElement === $('#permission-search')[0]) {
                if ($('#permission-search').val() !== '') {
                    e.preventDefault(); // Prevent modal from closing
                    e.stopPropagation(); // Prevent event bubbling
                    $('#permission-search').val('').trigger('input');
                }
            }
        });

        // Make sure the modal event handlers don't interfere with each other
        $('#managePermissionsModal').on('hidden.bs.modal', function() {
            // Clear search and reset view when modal is closed
            $('#permission-search').val('');
            $('.permission-item').show();
            $('.permission-category').show();
            $('#no-permissions-found').hide();
        });
        
        // Display success and error messages using Toastr
        <?php if (isset($_SESSION['success_message'])): ?>
            toastr.success('<?php echo $_SESSION['success_message']; ?>');
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error_message'])): ?>
            toastr.error('<?php echo $_SESSION['error_message']; ?>');
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>
    });
</script>

<!-- App js -->
<script src="assets/js/app.js"></script>

</body>
</html> 
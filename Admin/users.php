<?php
// Set up error logging
ini_set('display_errors', 1);
ini_set('error_log', __DIR__ . '/layouts/logs/error.log');
error_log("Users.php - Page load started");

// Include required files first
require_once "layouts/config.php";
require_once "layouts/helpers.php";
require_once "layouts/translations.php"; // Include translations before using the __() function
require_once "layouts/session.php"; // This includes session validation

// After session.php has validated the session, we can continue with the page

// Double-check if user is logged in
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    error_log("Users.php - User not logged in, redirecting to login page");
    // Store current URL for redirect after login
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    header("location: auth-login.php");
    exit;
}

// Check if the user has permission to access this page
requirePermission('view_users');

// For admin-only actions, use a separate check
$is_admin = hasPermission('manage_permissions');

if (!$is_admin) {
    // Not an admin, redirect to dashboard with error message
    $_SESSION['error_message'] = __('users_error_no_permission');
    header("location: index.php");
    exit;
}

// Handle error and success messaging based on translations
function getErrorTranslation($errorMessage) {
    switch ($errorMessage) {
        case "Name is required":
            return __('users_error_name_required');
        case "Email is required":
            return __('users_error_email_required');
        case "Invalid email format":
            return __('users_error_email_invalid');
        case "Email already exists":
            return __('users_error_email_exists');
        case "Password is required":
            return __('users_error_password_required');
        case "Password must be at least 8 characters":
            return __('users_password_validation');
        case "Selected role does not exist":
            return __('users_error_role_not_exists');
        case "Role selection is required":
            return __('users_role_required');
        case "You cannot delete yourself":
            return __('users_error_delete_self');
        default:
            return $errorMessage;
    }
}

// Process user actions (create, edit, delete)
$message = '';
$message_type = '';

// Handle new user creation
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'create') {
    $username = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);
    $role_id = trim($_POST['role_id']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Debug role_id
    error_log("Attempting to create user with role_id: " . $role_id);
    
    // Validate inputs
    $errors = [];
    
    if (empty($username)) {
        $errors[] = "Name is required";
    }
    
    if (empty($email)) {
        $errors[] = "Email is required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }
    
    if (empty($password)) {
        $errors[] = "Password is required";
    } elseif (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters";
    }
    
    // Check if email already exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetchColumn() > 0) {
        $errors[] = "Email already exists";
    }
    
    // Verify that the role exists
    if ($role_id) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE id = ?");
        $stmt->execute([$role_id]);
        if ($stmt->fetchColumn() == 0) {
            $errors[] = "Selected role does not exist";
            error_log("Invalid role_id selected: " . $role_id . " - role doesn't exist in database");
            
            // Log available roles for debugging
            $available_roles = $pdo->query("SELECT id, name FROM roles")->fetchAll(PDO::FETCH_ASSOC);
            error_log("Available roles: " . print_r($available_roles, true));
        }
    } else {
        $errors[] = "Role selection is required";
        error_log("No role_id provided");
    }
    
    if (empty($errors)) {
        try {
            // Hash the password
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            
            // Debug
            error_log("Creating user with data: " . json_encode([
                'username' => $username, 
                'email' => $email, 
                'role_id' => $role_id, 
                'is_active' => $is_active
            ]));
            
            // Insert the new user
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, role_id, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
            $result = $stmt->execute([$username, $email, $password_hash, $role_id, $is_active]);
            
            if ($result) {
                $message = __('users_created_success');
                $message_type = "success";
                error_log("User created successfully with role_id: " . $role_id);
            } else {
                $message = __('users_error_generic');
                $message_type = "danger";
                error_log("Failed to create user: " . json_encode($pdo->errorInfo()));
            }
        } catch (PDOException $e) {
            error_log("Error creating user: " . $e->getMessage());
            $message = "Database error: " . $e->getMessage();
            $message_type = "danger";
        }
    } else {
        $message = "Please fix the following errors: " . implode(", ", array_map('getErrorTranslation', $errors));
        $message_type = "danger";
    }
}

// Handle user edit
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'edit') {
    $user_id = $_POST['user_id'];
    $username = trim($_POST['name']);
    $email = trim($_POST['email']);
    $role_id = trim($_POST['role_id']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $password = trim($_POST['password']);
    
    // Debug role_id
    error_log("Attempting to edit user with role_id: " . $role_id);
    
    // Validate inputs
    $errors = [];
    
    if (empty($username)) {
        $errors[] = "Name is required";
    }
    
    if (empty($email)) {
        $errors[] = "Email is required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }
    
    // Check if email already exists for other users
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
    $stmt->execute([$email, $user_id]);
    if ($stmt->fetchColumn() > 0) {
        $errors[] = "Email already exists";
    }
    
    // Verify that the role exists
    if ($role_id) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE id = ?");
        $stmt->execute([$role_id]);
        if ($stmt->fetchColumn() == 0) {
            $errors[] = "Selected role does not exist";
            error_log("Invalid role_id selected: " . $role_id . " - role doesn't exist in database");
            
            // Log available roles for debugging
            $available_roles = $pdo->query("SELECT id, name FROM roles")->fetchAll(PDO::FETCH_ASSOC);
            error_log("Available roles: " . print_r($available_roles, true));
        }
    }
    
    if (empty($errors)) {
        try {
            // Update the user
            if (!empty($password)) {
                // Hash the new password
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET username = ?, email = ?, password_hash = ?, role_id = ?, is_active = ?, updated_at = NOW() WHERE id = ?");
                $result = $stmt->execute([$username, $email, $password_hash, $role_id, $is_active, $user_id]);
            } else {
                // Don't update the password
                $stmt = $pdo->prepare("UPDATE users SET username = ?, email = ?, role_id = ?, is_active = ?, updated_at = NOW() WHERE id = ?");
                $result = $stmt->execute([$username, $email, $role_id, $is_active, $user_id]);
            }
            
            if ($result) {
                $message = __('users_updated_success');
                $message_type = "success";
            } else {
                $message = __('users_error_generic');
                $message_type = "danger";
            }
        } catch (PDOException $e) {
            error_log("Error updating user: " . $e->getMessage());
            $message = "Database error: " . $e->getMessage();
            $message_type = "danger";
        }
    } else {
        $message = "Please fix the following errors: " . implode(", ", array_map('getErrorTranslation', $errors));
        $message_type = "danger";
    }
}

// Handle user deletion
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'delete') {
    $user_id = $_POST['user_id'];
    
    // Prevent deleting the current user
    if ($user_id == $_SESSION['user_id']) {
        $message = __('users_error_delete_self');
        $message_type = "danger";
    } else {
        try {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $result = $stmt->execute([$user_id]);
            
            if ($result) {
                $message = __('users_deleted_success');
                $message_type = "success";
            } else {
                $message = __('users_error_generic');
                $message_type = "danger";
            }
        } catch (PDOException $e) {
            error_log("Error deleting user: " . $e->getMessage());
            $message = "Database error: " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// Get the list of users
try {
    $stmt = $pdo->query("
        SELECT u.*, r.name as role_name 
        FROM users u 
        LEFT JOIN roles r ON u.role_id = r.id 
        ORDER BY u.username
    ");
    $users = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching users: " . $e->getMessage());
    $users = [];
    $message = "Failed to fetch users: " . $e->getMessage();
    $message_type = "danger";
}

// Get the list of roles for the dropdown
try {
    $stmt = $pdo->query("SELECT id, name FROM roles ORDER BY name");
    $roles = $stmt->fetchAll();
    error_log("Roles fetched for dropdown: " . count($roles) . " roles found");
    
    // Debug the roles
    foreach ($roles as $role) {
        error_log("Role available: ID={$role['id']}, Name={$role['name']}");
    }
    
    if (empty($roles)) {
        error_log("WARNING: No roles found in the database. Users cannot be created without roles.");
        $message = "No roles exist in the system. Please create at least one role before creating users.";
        $message_type = "warning";
    }
} catch (PDOException $e) {
    error_log("Error fetching roles: " . $e->getMessage());
    $roles = [];
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle user deletion
    if (isset($_POST['delete_user'])) {
        $user_id = $_POST['user_id'];
        
        // Prevent deleting the current user
        if ($user_id == $_SESSION['user_id']) {
            $_SESSION['error_message'] = __('users_error_delete_self');
            header("Location: users.php");
            exit;
        }
        
        try {
            $pdo->beginTransaction();
            
            // Check if user exists
            $stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
            
            if (!$user) {
                throw new Exception("User not found.");
            }
            
            // Delete the user
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            
            $pdo->commit();
            
            // Log the action
            logAction('delete', 'users', $user_id, ['name' => $user['name']], null);
            
            $_SESSION['success_message'] = __('users_deleted_success');
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error_message'] = "Error deleting user: " . $e->getMessage();
            error_log("User deletion error: " . $e->getMessage());
        }
        
        header("Location: users.php");
        exit;
    }
}
?>

<?php include 'layouts/head-main.php'; ?>

<head>
    <title><?php echo __('users_management'); ?> | Rental Management System</title>
    
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
                            <h4 class="mb-sm-0 font-size-18"><?php echo __('users_management'); ?></h4>

                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item active"><?php echo __('users_management'); ?></li>
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
                                    <h4 class="card-title mb-0"><?php echo __('users_list'); ?></h4>
                                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                                        <i class="bx bx-plus me-1"></i> <?php echo __('users_add_new'); ?>
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table id="users-table" class="table table-bordered table-hover">
                                        <thead>
                                            <tr>
                                                <th><?php echo __('users_name'); ?></th>
                                                <th><?php echo __('users_email'); ?></th>
                                                <th><?php echo __('users_role'); ?></th>
                                                <th><?php echo __('users_created'); ?></th>
                                                <th><?php echo __('users_actions'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($users as $user): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($user['username']); ?></td>
                                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                                <td>
                                                    <?php 
                                                    $role_name = 'No Role';
                                                    foreach ($roles as $role) {
                                                        if ($role['id'] == $user['role_id']) {
                                                            $role_name = htmlspecialchars($role['name']);
                                                            break;
                                                        }
                                                    }
                                                    echo $role_name;
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php echo isset($user['created_at']) ? date('Y-m-d', strtotime($user['created_at'])) : 'N/A'; ?>
                                                </td>
                                                <td>
                                                    <button class="btn btn-sm btn-primary edit-user" 
                                                            data-id="<?php echo $user['id']; ?>"
                                                            data-name="<?php echo htmlspecialchars($user['username']); ?>"
                                                            data-email="<?php echo htmlspecialchars($user['email']); ?>"
                                                            data-role="<?php echo $user['role_id']; ?>">
                                                        <i class="bx bx-edit"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-danger delete-user" 
                                                            data-id="<?php echo $user['id']; ?>"
                                                            data-name="<?php echo htmlspecialchars($user['username']); ?>">
                                                        <i class="bx bx-trash"></i>
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

        <?php include 'layouts/footer.php'; ?>
    </div>
    <!-- end main content-->

</div>
<!-- END layout-wrapper -->

<!-- User modals -->

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1" role="dialog" aria-labelledby="addUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addUserModalLabel"><?php echo __('users_add_title'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="addUserForm" method="post" action="users.php">
                <input type="hidden" name="action" value="create">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="user-name" class="form-label"><?php echo __('users_name'); ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="user-name" name="name" required>
                        <div id="user-name-feedback" class="invalid-feedback"></div>
                    </div>
                    <div class="mb-3">
                        <label for="user-email" class="form-label"><?php echo __('users_email'); ?> <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" id="user-email" name="email" required>
                        <div id="user-email-feedback" class="invalid-feedback"></div>
                    </div>
                    <div class="mb-3">
                        <label for="user-password" class="form-label">Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="user-password" name="password" required>
                        <div id="user-password-feedback" class="invalid-feedback"></div>
                    </div>
                    <div class="mb-3">
                        <label for="user-role" class="form-label"><?php echo __('users_role'); ?> <span class="text-danger">*</span></label>
                        <select class="form-control" id="user-role" name="role_id" required>
                            <?php foreach ($roles as $role): ?>
                            <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div id="user-role-feedback" class="invalid-feedback"></div>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="user-active" name="is_active" checked>
                        <label class="form-check-label" for="user-active">
                            <?php echo __('users_active'); ?>
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1" role="dialog" aria-labelledby="editUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editUserModalLabel">Edit User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editUserForm" method="post" action="users.php">
                <input type="hidden" name="action" value="edit">
                <div class="modal-body">
                    <input type="hidden" id="edit-user-id" name="user_id">
                    <div class="mb-3">
                        <label for="edit-user-name" class="form-label"><?php echo __('users_name'); ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit-user-name" name="name" required>
                        <div id="edit-user-name-feedback" class="invalid-feedback"></div>
                    </div>
                    <div class="mb-3">
                        <label for="edit-user-email" class="form-label"><?php echo __('users_email'); ?> <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" id="edit-user-email" name="email" required>
                        <div id="edit-user-email-feedback" class="invalid-feedback"></div>
                    </div>
                    <div class="mb-3">
                        <label for="edit-user-password" class="form-label">Password <small class="text-muted">(Leave blank to keep current password)</small></label>
                        <input type="password" class="form-control" id="edit-user-password" name="password">
                        <div id="edit-user-password-feedback" class="invalid-feedback"></div>
                    </div>
                    <div class="mb-3">
                        <label for="edit-user-role" class="form-label"><?php echo __('users_role'); ?> <span class="text-danger">*</span></label>
                        <select class="form-control" id="edit-user-role" name="role_id" required>
                            <?php foreach ($roles as $role): ?>
                            <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div id="edit-user-role-feedback" class="invalid-feedback"></div>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="edit-user-active" name="is_active">
                        <label class="form-check-label" for="edit-user-active">
                            <?php echo __('users_active'); ?>
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete User Modal -->
<div class="modal fade" id="deleteUserModal" tabindex="-1" role="dialog" aria-labelledby="deleteUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteUserModalLabel"><?php echo __('users_delete_title'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="users.php">
                <div class="modal-body">
                    <input type="hidden" id="delete-user-id" name="user_id">
                    <p><?php echo __('users_delete_confirm'); ?> <strong id="delete-user-name"></strong>?</p>
                    <p class="text-danger"><?php echo __('users_delete_warning'); ?></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="delete_user" class="btn btn-danger">Delete</button>
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

<!-- Responsive datatable examples -->
<script src="assets/libs/datatables.net-responsive/js/dataTables.responsive.min.js"></script>
<script src="assets/libs/datatables.net-responsive-bs4/js/responsive.bootstrap4.min.js"></script>

<script>
    $(document).ready(function() {
        // Initialize DataTable with translation
        var table = $('#users-table').DataTable({
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
            // Edit user button click
            $('.edit-user').off('click').on('click', function() {
                var userId = $(this).data('id');
                var userName = $(this).data('name');
                var userEmail = $(this).data('email');
                var userLanguage = $(this).data('language');
                var userActive = $(this).data('active');
                var userRole = $(this).data('role');
                
                $('#edit-user-id').val(userId);
                $('#edit-user-name').val(userName);
                $('#edit-user-email').val(userEmail);
                $('#edit-user-language').val(userLanguage);
                $('#edit-user-role').val(userRole);
                
                if (userActive == 1) {
                    $('#edit-user-active').prop('checked', true);
                } else {
                    $('#edit-user-active').prop('checked', false);
                }
                
                var editModal = new bootstrap.Modal(document.getElementById('editUserModal'));
                editModal.show();
            });
            
            // Delete user button click
            $('.delete-user').off('click').on('click', function() {
                var userId = $(this).data('id');
                var userName = $(this).data('name');
                
                $('#delete-user-id').val(userId);
                $('#delete-user-name').text(userName);
                
                var deleteModal = new bootstrap.Modal(document.getElementById('deleteUserModal'));
                deleteModal.show();
            });
        }
        
        // Form validation for adding a user
        $('#addUserForm').on('submit', function(e) {
            var isValid = true;
            
            // Validate name
            if ($('#user-name').val().trim() === '') {
                $('#user-name').addClass('is-invalid');
                $('#user-name-feedback').text('<?php echo __("Please enter a name"); ?>');
                isValid = false;
            } else {
                $('#user-name').removeClass('is-invalid');
            }
            
            // Validate email
            var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if ($('#user-email').val().trim() === '') {
                $('#user-email').addClass('is-invalid');
                $('#user-email-feedback').text('<?php echo __("Please enter an email address"); ?>');
                isValid = false;
            } else if (!emailRegex.test($('#user-email').val().trim())) {
                $('#user-email').addClass('is-invalid');
                $('#user-email-feedback').text('<?php echo __("Please enter a valid email address"); ?>');
                isValid = false;
            } else {
                $('#user-email').removeClass('is-invalid');
            }
            
            // Validate password
            if ($('#user-password').val().trim() === '') {
                $('#user-password').addClass('is-invalid');
                $('#user-password-feedback').text('<?php echo __("Please enter a password"); ?>');
                isValid = false;
            } else if ($('#user-password').val().trim().length < 8) {
                $('#user-password').addClass('is-invalid');
                $('#user-password-feedback').text('<?php echo __("Password must be at least 8 characters"); ?>');
                isValid = false;
            } else {
                $('#user-password').removeClass('is-invalid');
            }
            
            if (!isValid) {
                e.preventDefault();
            }
        });
        
        // Form validation for editing a user
        $('#editUserForm').on('submit', function(e) {
            var isValid = true;
            
            // Validate name
            if ($('#edit-user-name').val().trim() === '') {
                $('#edit-user-name').addClass('is-invalid');
                $('#edit-user-name-feedback').text('<?php echo __("Please enter a name"); ?>');
                isValid = false;
            } else {
                $('#edit-user-name').removeClass('is-invalid');
            }
            
            // Validate email
            var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if ($('#edit-user-email').val().trim() === '') {
                $('#edit-user-email').addClass('is-invalid');
                $('#edit-user-email-feedback').text('<?php echo __("Please enter an email address"); ?>');
                isValid = false;
            } else if (!emailRegex.test($('#edit-user-email').val().trim())) {
                $('#edit-user-email').addClass('is-invalid');
                $('#edit-user-email-feedback').text('<?php echo __("Please enter a valid email address"); ?>');
                isValid = false;
            } else {
                $('#edit-user-email').removeClass('is-invalid');
            }
            
            // Validate password only if entered
            if ($('#edit-user-password').val().trim() !== '' && $('#edit-user-password').val().trim().length < 8) {
                $('#edit-user-password').addClass('is-invalid');
                $('#edit-user-password-feedback').text('<?php echo __("Password must be at least 8 characters"); ?>');
                isValid = false;
            } else {
                $('#edit-user-password').removeClass('is-invalid');
            }
            
            if (!isValid) {
                e.preventDefault();
            }
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
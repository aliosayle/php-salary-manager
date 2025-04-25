<?php
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
include "layouts/helpers.php";

// Check permission
requirePermission('manage_permissions');

// Define all available permissions
$all_permissions = [
    'view_dashboard' => 'Access to view dashboard',
    'view_employees' => 'Access to view employees list',
    'manage_employees' => 'Create, edit and delete employees',
    'view_posts' => 'Access to view positions/posts',
    'manage_posts' => 'Create, edit and delete positions/posts',
    'view_education_levels' => 'Access to view education levels',
    'manage_education_levels' => 'Create, edit and delete education levels',
    'view_recommenders' => 'Access to view recommenders',
    'manage_recommenders' => 'Create, edit and delete recommenders',
    'view_shops' => 'Access to view shops',
    'manage_shops' => 'Create, edit and delete shops',
    'view_reports' => 'Access to view reports',
    'generate_reports' => 'Generate and export reports',
    'view_settings' => 'Access to view system settings',
    'manage_settings' => 'Modify system settings',
    'view_users' => 'Access to view users list',
    'manage_users' => 'Create, edit and delete users',
    'view_roles' => 'Access to view roles list',
    'manage_roles' => 'Create, edit and delete roles',
    'view_permissions' => 'Access to view permissions list',
    'manage_permissions' => 'Assign permissions to roles',
    'view_admin_section' => 'Access to view administration section',
];

// Get all roles
try {
    $roles_stmt = $pdo->query("SELECT * FROM roles ORDER BY name");
    $roles = $roles_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_SESSION["error_message"] = "Error fetching roles: " . $e->getMessage();
    $roles = [];
}

// Handle permission updates
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["action"]) && $_POST["action"] == "update_permissions") {
    $role_id = $_POST["role_id"];
    $permissions = isset($_POST["permissions"]) ? $_POST["permissions"] : [];
    
    try {
        // Begin transaction
        $pdo->beginTransaction();
        
        // Delete existing permissions for the role
        $delete_stmt = $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?");
        $delete_stmt->execute([$role_id]);
        
        // Insert new permissions
        if (!empty($permissions)) {
            $insert_stmt = $pdo->prepare("
                INSERT INTO role_permissions (role_id, permission_id) 
                SELECT ?, id FROM permissions WHERE action = ?
            ");
            
            foreach ($permissions as $permission) {
                $insert_stmt->execute([$role_id, $permission]);
            }
        }
        
        // Commit transaction
        $pdo->commit();
        
        $_SESSION["success_message"] = "Permissions updated successfully.";
        header("location: permissions.php");
        exit;
    } catch (PDOException $e) {
        // Roll back transaction
        $pdo->rollBack();
        $_SESSION["error_message"] = "Error updating permissions: " . $e->getMessage();
    }
}

// Include layout files
include 'layouts/head-main.php';
?>

<head>
    <title>Permissions | Admin Panel</title>
    <?php include 'layouts/head.php'; ?>
    <?php include 'layouts/head-style.php'; ?>
    
    <style>
        .permission-group {
            margin-bottom: 20px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        .permission-group h5 {
            margin-bottom: 15px;
        }
        .role-select {
            max-width: 300px;
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
                            <h4 class="mb-sm-0 font-size-18">Permissions</h4>

                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item active">Permissions</li>
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
                            <div class="card-body">
                                <h4 class="card-title mb-4">Manage Role Permissions</h4>
                                
                                <div class="role-select mb-4">
                                    <label for="role-select" class="form-label">Select a role to manage permissions:</label>
                                    <select class="form-select" id="role-select">
                                        <option value="">-- Select Role --</option>
                                        <?php foreach ($roles as $role): ?>
                                            <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div id="permissions-container" class="d-none">
                                    <form id="permissions-form" method="POST" action="permissions.php">
                                        <input type="hidden" name="action" value="update_permissions">
                                        <input type="hidden" name="role_id" id="form-role-id">
                                        
                                        <div class="row">
                                            <div class="col-md-12">
                                                <div class="d-flex justify-content-between align-items-center mb-4">
                                                    <h5 class="mb-0">
                                                        Permissions for: <span id="role-name-display" class="text-primary"></span>
                                                    </h5>
                                                    <div>
                                                        <button type="button" class="btn btn-sm btn-primary" id="select-all-btn">Select All</button>
                                                        <button type="button" class="btn btn-sm btn-secondary" id="deselect-all-btn">Deselect All</button>
                                                    </div>
                                                </div>
                                                
                                                <div class="permission-list">
                                                    <!-- Dashboard permissions -->
                                                    <div class="permission-group">
                                                        <h5>Dashboard</h5>
                                                        <div class="row">
                                                            <div class="col-md-4">
                                                                <div class="form-check mb-3">
                                                                    <input class="form-check-input permission-checkbox" type="checkbox" id="view_dashboard" name="permissions[]" value="view_dashboard">
                                                                    <label class="form-check-label" for="view_dashboard">
                                                                        View Dashboard
                                                                    </label>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-4">
                                                                <div class="form-check mb-3">
                                                                    <input class="form-check-input permission-checkbox" type="checkbox" id="view_admin_section" name="permissions[]" value="view_admin_section">
                                                                    <label class="form-check-label" for="view_admin_section">
                                                                        Access Admin Section
                                                                    </label>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    
                                                    <!-- Employee permissions -->
                                                    <div class="permission-group">
                                                        <h5>Employees</h5>
                                                        <div class="row">
                                                            <div class="col-md-4">
                                                                <div class="form-check mb-3">
                                                                    <input class="form-check-input permission-checkbox" type="checkbox" id="view_employees" name="permissions[]" value="view_employees">
                                                                    <label class="form-check-label" for="view_employees">
                                                                        View Employees
                                                                    </label>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-4">
                                                                <div class="form-check mb-3">
                                                                    <input class="form-check-input permission-checkbox" type="checkbox" id="manage_employees" name="permissions[]" value="manage_employees">
                                                                    <label class="form-check-label" for="manage_employees">
                                                                        Manage Employees
                                                                    </label>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    
                                                    <!-- Posts permissions -->
                                                    <div class="permission-group">
                                                        <h5>Positions</h5>
                                                        <div class="row">
                                                            <div class="col-md-4">
                                                                <div class="form-check mb-3">
                                                                    <input class="form-check-input permission-checkbox" type="checkbox" id="view_posts" name="permissions[]" value="view_posts">
                                                                    <label class="form-check-label" for="view_posts">
                                                                        View Positions
                                                                    </label>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-4">
                                                                <div class="form-check mb-3">
                                                                    <input class="form-check-input permission-checkbox" type="checkbox" id="manage_posts" name="permissions[]" value="manage_posts">
                                                                    <label class="form-check-label" for="manage_posts">
                                                                        Manage Positions
                                                                    </label>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    
                                                    <!-- Education Levels permissions -->
                                                    <div class="permission-group">
                                                        <h5>Education Levels</h5>
                                                        <div class="row">
                                                            <div class="col-md-4">
                                                                <div class="form-check mb-3">
                                                                    <input class="form-check-input permission-checkbox" type="checkbox" id="view_education_levels" name="permissions[]" value="view_education_levels">
                                                                    <label class="form-check-label" for="view_education_levels">
                                                                        View Education Levels
                                                                    </label>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-4">
                                                                <div class="form-check mb-3">
                                                                    <input class="form-check-input permission-checkbox" type="checkbox" id="manage_education_levels" name="permissions[]" value="manage_education_levels">
                                                                    <label class="form-check-label" for="manage_education_levels">
                                                                        Manage Education Levels
                                                                    </label>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    
                                                    <!-- Recommenders permissions -->
                                                    <div class="permission-group">
                                                        <h5>Recommenders</h5>
                                                        <div class="row">
                                                            <div class="col-md-4">
                                                                <div class="form-check mb-3">
                                                                    <input class="form-check-input permission-checkbox" type="checkbox" id="view_recommenders" name="permissions[]" value="view_recommenders">
                                                                    <label class="form-check-label" for="view_recommenders">
                                                                        View Recommenders
                                                                    </label>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-4">
                                                                <div class="form-check mb-3">
                                                                    <input class="form-check-input permission-checkbox" type="checkbox" id="manage_recommenders" name="permissions[]" value="manage_recommenders">
                                                                    <label class="form-check-label" for="manage_recommenders">
                                                                        Manage Recommenders
                                                                    </label>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    
                                                    <!-- Shops permissions -->
                                                    <div class="permission-group">
                                                        <h5>Shops</h5>
                                                        <div class="row">
                                                            <div class="col-md-4">
                                                                <div class="form-check mb-3">
                                                                    <input class="form-check-input permission-checkbox" type="checkbox" id="view_shops" name="permissions[]" value="view_shops">
                                                                    <label class="form-check-label" for="view_shops">
                                                                        View Shops
                                                                    </label>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-4">
                                                                <div class="form-check mb-3">
                                                                    <input class="form-check-input permission-checkbox" type="checkbox" id="manage_shops" name="permissions[]" value="manage_shops">
                                                                    <label class="form-check-label" for="manage_shops">
                                                                        Manage Shops
                                                                    </label>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    
                                                    <!-- Reports permissions -->
                                                    <div class="permission-group">
                                                        <h5>Reports</h5>
                                                        <div class="row">
                                                            <div class="col-md-4">
                                                                <div class="form-check mb-3">
                                                                    <input class="form-check-input permission-checkbox" type="checkbox" id="view_reports" name="permissions[]" value="view_reports">
                                                                    <label class="form-check-label" for="view_reports">
                                                                        View Reports
                                                                    </label>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-4">
                                                                <div class="form-check mb-3">
                                                                    <input class="form-check-input permission-checkbox" type="checkbox" id="generate_reports" name="permissions[]" value="generate_reports">
                                                                    <label class="form-check-label" for="generate_reports">
                                                                        Generate Reports
                                                                    </label>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    
                                                    <!-- Settings permissions -->
                                                    <div class="permission-group">
                                                        <h5>Settings</h5>
                                                        <div class="row">
                                                            <div class="col-md-4">
                                                                <div class="form-check mb-3">
                                                                    <input class="form-check-input permission-checkbox" type="checkbox" id="view_settings" name="permissions[]" value="view_settings">
                                                                    <label class="form-check-label" for="view_settings">
                                                                        View Settings
                                                                    </label>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-4">
                                                                <div class="form-check mb-3">
                                                                    <input class="form-check-input permission-checkbox" type="checkbox" id="manage_settings" name="permissions[]" value="manage_settings">
                                                                    <label class="form-check-label" for="manage_settings">
                                                                        Manage Settings
                                                                    </label>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    
                                                    <!-- Users permissions -->
                                                    <div class="permission-group">
                                                        <h5>Users & Roles</h5>
                                                        <div class="row">
                                                            <div class="col-md-4">
                                                                <div class="form-check mb-3">
                                                                    <input class="form-check-input permission-checkbox" type="checkbox" id="view_users" name="permissions[]" value="view_users">
                                                                    <label class="form-check-label" for="view_users">
                                                                        View Users
                                                                    </label>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-4">
                                                                <div class="form-check mb-3">
                                                                    <input class="form-check-input permission-checkbox" type="checkbox" id="manage_users" name="permissions[]" value="manage_users">
                                                                    <label class="form-check-label" for="manage_users">
                                                                        Manage Users
                                                                    </label>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-4">
                                                                <div class="form-check mb-3">
                                                                    <input class="form-check-input permission-checkbox" type="checkbox" id="view_roles" name="permissions[]" value="view_roles">
                                                                    <label class="form-check-label" for="view_roles">
                                                                        View Roles
                                                                    </label>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-4">
                                                                <div class="form-check mb-3">
                                                                    <input class="form-check-input permission-checkbox" type="checkbox" id="manage_roles" name="permissions[]" value="manage_roles">
                                                                    <label class="form-check-label" for="manage_roles">
                                                                        Manage Roles
                                                                    </label>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-4">
                                                                <div class="form-check mb-3">
                                                                    <input class="form-check-input permission-checkbox" type="checkbox" id="view_permissions" name="permissions[]" value="view_permissions">
                                                                    <label class="form-check-label" for="view_permissions">
                                                                        View Permissions
                                                                    </label>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-4">
                                                                <div class="form-check mb-3">
                                                                    <input class="form-check-input permission-checkbox" type="checkbox" id="manage_permissions" name="permissions[]" value="manage_permissions">
                                                                    <label class="form-check-label" for="manage_permissions">
                                                                        Manage Permissions
                                                                    </label>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="mt-4">
                                                    <button type="submit" class="btn btn-primary">Save Permissions</button>
                                                </div>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                                
                                <div id="no-role-selected" class="alert alert-info">
                                    Please select a role to manage its permissions.
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

<?php include 'layouts/right-sidebar.php'; ?>
<?php include 'layouts/vendor-scripts.php'; ?>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const roleSelect = document.getElementById('role-select');
        const permissionsContainer = document.getElementById('permissions-container');
        const noRoleSelected = document.getElementById('no-role-selected');
        const roleNameDisplay = document.getElementById('role-name-display');
        const formRoleId = document.getElementById('form-role-id');
        const selectAllBtn = document.getElementById('select-all-btn');
        const deselectAllBtn = document.getElementById('deselect-all-btn');
        const permissionCheckboxes = document.querySelectorAll('.permission-checkbox');
        
        // Role data
        const roles = <?php echo json_encode($roles); ?>;
        
        // Load permissions when a role is selected
        roleSelect.addEventListener('change', function() {
            const roleId = this.value;
            
            if (roleId) {
                loadRolePermissions(roleId);
                permissionsContainer.classList.remove('d-none');
                noRoleSelected.classList.add('d-none');
                
                // Set the role ID in the form
                formRoleId.value = roleId;
                
                // Display the role name
                const role = roles.find(r => r.id === roleId);
                if (role) {
                    roleNameDisplay.textContent = role.name;
                }
            } else {
                permissionsContainer.classList.add('d-none');
                noRoleSelected.classList.remove('d-none');
            }
        });
        
        // Select all permissions
        selectAllBtn.addEventListener('click', function() {
            permissionCheckboxes.forEach(checkbox => {
                checkbox.checked = true;
            });
        });
        
        // Deselect all permissions
        deselectAllBtn.addEventListener('click', function() {
            permissionCheckboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
        });
        
        // Function to load role permissions from the server
        function loadRolePermissions(roleId) {
            // First, uncheck all permissions
            permissionCheckboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            
            // Fetch the role's permissions
            fetch(`ajax-handlers/get-role-permissions.php?role_id=${roleId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.permissions) {
                        // Check the permissions that the role has
                        data.permissions.forEach(permission => {
                            const checkbox = document.getElementById(permission);
                            if (checkbox) {
                                checkbox.checked = true;
                            }
                        });
                    }
                })
                .catch(error => {
                    console.error('Error fetching permissions:', error);
                });
        }
    });
</script>

</body>
</html> 
<?php
// Set up error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include config file
require_once "layouts/config.php";

echo "<h2>Setting up Employee Manager System</h2>";

// Default permissions
$default_permissions = [
    ['action' => 'view_dashboard', 'description' => 'Access to view the dashboard'],
    ['action' => 'manage_users', 'description' => 'Ability to create, edit, and delete users'],
    ['action' => 'manage_roles', 'description' => 'Ability to create, edit, and delete roles and permissions'],
    ['action' => 'view_employees', 'description' => 'Access to view employee records'],
    ['action' => 'manage_employees', 'description' => 'Ability to create, edit, and delete employee records'],
    ['action' => 'view_education', 'description' => 'Access to view education records'],
    ['action' => 'manage_education', 'description' => 'Ability to create, edit, and delete education records'],
    ['action' => 'view_posts', 'description' => 'Access to view job posts'],
    ['action' => 'manage_posts', 'description' => 'Ability to create, edit, and delete job posts'],
    ['action' => 'generate_reports', 'description' => 'Ability to generate and export reports']
];

try {
    // Start transaction
    $pdo->beginTransaction();
    
    echo "<h3>Creating permissions...</h3>";
    
    // Insert default permissions
    $permissionIds = [];
    $stmt = $pdo->prepare("INSERT INTO permissions (action, description) VALUES (?, ?)");
    
    foreach ($default_permissions as $permission) {
        // Check if permission already exists
        $checkStmt = $pdo->prepare("SELECT id FROM permissions WHERE action = ?");
        $checkStmt->execute([$permission['action']]);
        $existingPermission = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingPermission) {
            echo "Permission '{$permission['action']}' already exists.<br>";
            $permissionIds[] = $existingPermission['id'];
        } else {
            $stmt->execute([$permission['action'], $permission['description']]);
            $permissionId = $pdo->lastInsertId();
            $permissionIds[] = $permissionId;
            echo "Created permission: {$permission['action']}<br>";
        }
    }
    
    echo "<h3>Creating Administrator role...</h3>";
    
    // Create admin role if it doesn't exist
    $checkRoleStmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'Administrator'");
    $checkRoleStmt->execute();
    $adminRole = $checkRoleStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($adminRole) {
        echo "Administrator role already exists.<br>";
        $roleId = $adminRole['id'];
    } else {
        $roleStmt = $pdo->prepare("INSERT INTO roles (name, description) VALUES (?, ?)");
        $roleStmt->execute(['Administrator', 'Full system access']);
        $roleId = $pdo->lastInsertId();
        echo "Created Administrator role.<br>";
    }
    
    echo "<h3>Assigning permissions to the Administrator role...</h3>";
    
    // Assign all permissions to admin role
    foreach ($permissionIds as $permissionId) {
        // Check if permission is already assigned
        $checkAssignmentStmt = $pdo->prepare("SELECT * FROM role_permissions WHERE role_id = ? AND permission_id = ?");
        $checkAssignmentStmt->execute([$roleId, $permissionId]);
        
        if ($checkAssignmentStmt->rowCount() == 0) {
            $assignStmt = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
            $assignStmt->execute([$roleId, $permissionId]);
            echo "Assigned a permission to Administrator role.<br>";
        } else {
            echo "Permission already assigned to Administrator role.<br>";
        }
    }
    
    echo "<h3>Creating admin user (if none exists)...</h3>";
    
    // Create default admin user if none exists
    $checkUserStmt = $pdo->prepare("SELECT COUNT(*) FROM users");
    $checkUserStmt->execute();
    $userCount = $checkUserStmt->fetchColumn();
    
    if ($userCount == 0) {
        // Create default admin user
        $defaultPassword = 'admin123';
        $passwordHash = password_hash($defaultPassword, PASSWORD_DEFAULT);
        
        $userStmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, role_id, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
        $userStmt->execute(['admin', 'admin@example.com', $passwordHash, $roleId, 1]);
        
        echo "<div style='background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin-top: 20px;'>";
        echo "<h4>Default admin user created:</h4>";
        echo "<ul>";
        echo "<li><strong>Username:</strong> admin</li>";
        echo "<li><strong>Email:</strong> admin@example.com</li>";
        echo "<li><strong>Password:</strong> {$defaultPassword}</li>";
        echo "</ul>";
        echo "<p style='color: red; font-weight: bold;'>IMPORTANT: Please change this password immediately after login!</p>";
        echo "</div>";
    } else {
        echo "Users already exist in the system. Default admin user not created.<br>";
    }
    
    // Commit transaction
    $pdo->commit();
    echo "<h3 style='color: green;'>Setup completed successfully!</h3>";
    
    echo "<p><a href='auth-login.php' style='display: inline-block; background-color: #007bff; color: white; padding: 10px 15px; text-decoration: none; border-radius: 5px;'>Go to Login Page</a></p>";
    
} catch (PDOException $e) {
    // Rollback transaction on error
    $pdo->rollBack();
    echo "<h3 style='color: red;'>Error during setup!</h3>";
    echo "<p>Error message: " . $e->getMessage() . "</p>";
}
?> 
<?php
// Set up error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include config file
require_once "layouts/config.php";

echo "<h2>Adding Shop Management Permissions</h2>";

// Shop permissions
$shop_permissions = [
    ['action' => 'view_shops', 'description' => 'Access to view shops'],
    ['action' => 'manage_shops', 'description' => 'Ability to create, edit, and delete shops'],
    ['action' => 'assign_employees', 'description' => 'Ability to assign employees to shops']
];

try {
    // Start transaction
    $pdo->beginTransaction();
    
    echo "<h3>Creating shop permissions...</h3>";
    
    // Insert shop permissions
    $permissionIds = [];
    $stmt = $pdo->prepare("INSERT INTO permissions (id, action, description) VALUES (UUID(), ?, ?)");
    
    foreach ($shop_permissions as $permission) {
        // Check if permission already exists
        $checkStmt = $pdo->prepare("SELECT id FROM permissions WHERE action = ?");
        $checkStmt->execute([$permission['action']]);
        $existingPermission = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingPermission) {
            echo "Permission '{$permission['action']}' already exists.<br>";
            $permissionIds[] = $existingPermission['id'];
        } else {
            $stmt->execute([$permission['action'], $permission['description']]);
            echo "Created permission: {$permission['action']}<br>";
            
            // Get the last inserted permission ID
            $getIdStmt = $pdo->prepare("SELECT id FROM permissions WHERE action = ?");
            $getIdStmt->execute([$permission['action']]);
            $newPermission = $getIdStmt->fetch(PDO::FETCH_ASSOC);
            $permissionIds[] = $newPermission['id'];
        }
    }
    
    echo "<h3>Assigning permissions to Administrator role...</h3>";
    
    // Get administrator role ID
    $roleStmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'Administrator'");
    $roleStmt->execute();
    $adminRole = $roleStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($adminRole) {
        $roleId = $adminRole['id'];
        
        // Assign all shop permissions to admin role
        foreach ($permissionIds as $permissionId) {
            // Check if permission is already assigned
            $checkAssignmentStmt = $pdo->prepare("SELECT * FROM role_permissions WHERE role_id = ? AND permission_id = ?");
            $checkAssignmentStmt->execute([$roleId, $permissionId]);
            
            if ($checkAssignmentStmt->rowCount() == 0) {
                $assignStmt = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
                $assignStmt->execute([$roleId, $permissionId]);
                echo "Assigned permission to Administrator role.<br>";
            } else {
                echo "Permission already assigned to Administrator role.<br>";
            }
        }
    } else {
        echo "Administrator role not found!<br>";
    }
    
    // Commit transaction
    $pdo->commit();
    echo "<h3 style='color: green;'>Shop permissions added successfully!</h3>";
    
    echo "<p><a href='index.php' style='display: inline-block; background-color: #007bff; color: white; padding: 10px 15px; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a></p>";
    
} catch (PDOException $e) {
    // Rollback transaction on error
    $pdo->rollBack();
    echo "<h3 style='color: red;'>Error adding shop permissions!</h3>";
    echo "<p>Error message: " . $e->getMessage() . "</p>";
}
?> 
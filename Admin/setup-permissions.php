<?php
/**
 * Setup Permissions Script
 * Creates default permissions if none exist in the database
 */

// Include required files
require_once "layouts/config.php";

echo "<h1>Setting up permissions</h1>";

// Default permissions to create
$default_permissions = [
    // User management
    ['action' => 'view_users', 'description' => 'View user list'],
    ['action' => 'add_users', 'description' => 'Add new users'],
    ['action' => 'edit_users', 'description' => 'Edit existing users'],
    ['action' => 'delete_users', 'description' => 'Delete users'],
    
    // Role management
    ['action' => 'view_roles', 'description' => 'View roles list'],
    ['action' => 'add_roles', 'description' => 'Add new roles'],
    ['action' => 'edit_roles', 'description' => 'Edit existing roles'],
    ['action' => 'delete_roles', 'description' => 'Delete roles'],
    
    // Permission management
    ['action' => 'manage_permissions', 'description' => 'Manage role permissions'],
    
    // Employee management
    ['action' => 'view_employees', 'description' => 'View employee list'],
    ['action' => 'add_employees', 'description' => 'Add new employees'],
    ['action' => 'edit_employees', 'description' => 'Edit existing employees'],
    ['action' => 'delete_employees', 'description' => 'Delete employees'],
    
    // Reports
    ['action' => 'view_reports', 'description' => 'View reports'],
    ['action' => 'export_reports', 'description' => 'Export reports'],
    
    // Settings
    ['action' => 'manage_settings', 'description' => 'Manage system settings']
];

// Check if permissions already exist
$stmt = $pdo->query("SELECT COUNT(*) FROM permissions");
$permission_count = $stmt->fetchColumn();

if ($permission_count == 0) {
    echo "<p>No permissions found. Creating default permissions...</p>";
    
    // Start transaction
    $pdo->beginTransaction();
    
    try {
        // Prepare statement for permission insertion
        $insert_stmt = $pdo->prepare("INSERT INTO permissions (id, action, description) VALUES (UUID(), ?, ?)");
        
        // Insert each permission
        foreach ($default_permissions as $permission) {
            $insert_stmt->execute([$permission['action'], $permission['description']]);
            echo "<p>Created permission: {$permission['action']}</p>";
        }
        
        // Commit transaction
        $pdo->commit();
        echo "<p><strong>Successfully created " . count($default_permissions) . " permissions</strong></p>";
        
        // Now check for Administrator role and assign all permissions
        $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'Administrator'");
        $stmt->execute();
        $admin_role = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($admin_role) {
            echo "<p>Found Administrator role. Assigning all permissions...</p>";
            
            // Get all permission IDs
            $stmt = $pdo->query("SELECT id FROM permissions");
            $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Start transaction
            $pdo->beginTransaction();
            
            try {
                // Prepare statement for role permission assignment
                $assign_stmt = $pdo->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
                
                // Assign each permission to admin role
                foreach ($permissions as $permission_id) {
                    $assign_stmt->execute([$admin_role['id'], $permission_id]);
                }
                
                // Commit transaction
                $pdo->commit();
                echo "<p><strong>Successfully assigned all permissions to Administrator role</strong></p>";
            } catch (Exception $e) {
                // Rollback transaction on error
                $pdo->rollBack();
                echo "<p>Error assigning permissions to Administrator role: " . $e->getMessage() . "</p>";
            }
        } else {
            echo "<p>Administrator role not found. Please create it and assign permissions manually.</p>";
        }
    } catch (Exception $e) {
        // Rollback transaction on error
        $pdo->rollBack();
        echo "<p>Error creating permissions: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p>Found $permission_count permissions in the database. No need to create default permissions.</p>";
}

echo "<p><a href='index.php'>Return to Dashboard</a></p>";
?> 
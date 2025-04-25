<?php
// Set up error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include configuration
require_once 'layouts/config.php';

// Start HTML output
echo "<!DOCTYPE html>
<html>
<head>
    <title>Database Schema Fix</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 20px auto; padding: 20px; line-height: 1.6; }
        h1, h2 { color: #333; }
        .success { color: green; }
        .error { color: red; }
        .warning { color: orange; }
        .info { color: blue; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 5px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>Database Schema Fix</h1>";

try {
    echo "<h2>Checking and Fixing Tables...</h2>";

    // Begin transaction
    $pdo->beginTransaction();

    // 1. Check if the role_permissions table exists
    echo "<p>Checking role_permissions table...</p>";
    $tableExists = $pdo->query("SHOW TABLES LIKE 'role_permissions'")->rowCount() > 0;
    
    if (!$tableExists) {
        echo "<p class='info'>Creating role_permissions table...</p>";
        
        $pdo->exec("
        CREATE TABLE IF NOT EXISTS role_permissions (
            id VARCHAR(36) PRIMARY KEY,
            role_id VARCHAR(36) NOT NULL,
            permission_id VARCHAR(36) NOT NULL,
            FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
            FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
            UNIQUE KEY (role_id, permission_id)
        )
        ");
        
        // Create UUID trigger for role_permissions
        $pdo->exec("
        CREATE TRIGGER IF NOT EXISTS before_insert_role_permissions
        BEFORE INSERT ON role_permissions
        FOR EACH ROW
        BEGIN
            IF NEW.id IS NULL THEN
                SET NEW.id = UUID();
            END IF;
        END
        ");
        
        echo "<p class='success'>role_permissions table created successfully.</p>";
    } else {
        echo "<p class='success'>role_permissions table already exists.</p>";
    }
    
    // 2. Check if permissions_per_role table exists and migrate data if needed
    echo "<p>Checking permissions_per_role table...</p>";
    $oldTableExists = $pdo->query("SHOW TABLES LIKE 'permissions_per_role'")->rowCount() > 0;
    
    if ($oldTableExists) {
        echo "<p class='info'>permissions_per_role table exists. Migrating data to role_permissions...</p>";
        
        // Check if role_permissions table exists to migrate data
        if ($tableExists) {
            try {
                // Count records in the old table
                $stmt = $pdo->query("SELECT COUNT(*) FROM permissions_per_role");
                $count = $stmt->fetchColumn();
                
                echo "<p>Found $count records to migrate.</p>";
                
                if ($count > 0) {
                    // Migrate data from old table to new table
                    $pdo->exec("
                    INSERT IGNORE INTO role_permissions (role_id, permission_id)
                    SELECT role_id, permission_id FROM permissions_per_role
                    ");
                    
                    $rowsInserted = $pdo->query("SELECT ROW_COUNT()")->fetchColumn();
                    echo "<p class='success'>Migrated $rowsInserted permission records to role_permissions table.</p>";
                }
                
                // Drop the old table if migration successful
                $pdo->exec("DROP TABLE permissions_per_role");
                echo "<p class='success'>Dropped old permissions_per_role table.</p>";
            } catch (PDOException $e) {
                echo "<p class='error'>Error migrating data: " . $e->getMessage() . "</p>";
                throw $e;
            }
        }
    } else {
        echo "<p class='info'>permissions_per_role table doesn't exist. No migration needed.</p>";
    }
    
    // 3. Check permissions table structure
    echo "<p>Checking permissions table structure...</p>";
    $permissionsTableExists = $pdo->query("SHOW TABLES LIKE 'permissions'")->rowCount() > 0;
    
    if ($permissionsTableExists) {
        // Check columns 
        $stmt = $pdo->query("SHOW COLUMNS FROM permissions LIKE 'description_eng'");
        $hasDescriptionEng = $stmt->rowCount() > 0;
        
        if (!$hasDescriptionEng) {
            echo "<p class='info'>Adding description_eng column to permissions table...</p>";
            
            try {
                // Add description_eng column
                $pdo->exec("ALTER TABLE permissions ADD COLUMN description_eng TEXT");
                
                // Update existing records to set description_eng from description
                $pdo->exec("UPDATE permissions SET description_eng = description WHERE description_eng IS NULL");
                
                echo "<p class='success'>Added description_eng column and updated data.</p>";
            } catch (PDOException $e) {
                echo "<p class='error'>Error updating permissions table: " . $e->getMessage() . "</p>";
                throw $e;
            }
        } else {
            echo "<p class='success'>permissions table has the correct structure.</p>";
        }
        
        // Check if category column exists
        $stmt = $pdo->query("SHOW COLUMNS FROM permissions LIKE 'category'");
        $hasCategoryColumn = $stmt->rowCount() > 0;
        
        if (!$hasCategoryColumn) {
            echo "<p class='info'>Adding category column to permissions table...</p>";
            
            try {
                // Add category column
                $pdo->exec("ALTER TABLE permissions ADD COLUMN category VARCHAR(50)");
                
                // Update categories based on permission name prefixes
                $pdo->exec("
                UPDATE permissions 
                SET category = 
                    CASE 
                        WHEN name LIKE 'user.%' THEN 'user'
                        WHEN name LIKE 'role.%' THEN 'role'
                        WHEN name LIKE 'property.%' THEN 'property'
                        WHEN name LIKE 'tenant.%' THEN 'tenant'
                        WHEN name LIKE 'lease.%' THEN 'lease'
                        WHEN name LIKE 'payment.%' THEN 'payment'
                        WHEN name LIKE 'report.%' THEN 'report'
                        WHEN name LIKE 'system.%' THEN 'system'
                        WHEN name LIKE 'dataset.%' THEN 'dataset'
                        ELSE 'other'
                    END
                ");
                
                echo "<p class='success'>Added category column and categorized permissions.</p>";
            } catch (PDOException $e) {
                echo "<p class='error'>Error adding category column: " . $e->getMessage() . "</p>";
                throw $e;
            }
        } else {
            echo "<p class='success'>permissions table already has category column.</p>";
        }
    } else {
        echo "<p class='warning'>permissions table doesn't exist! This is a critical issue.</p>";
    }
    
    // 4. Check for required permissions
    echo "<p>Checking for required permissions...</p>";
    
    $permissionCount = $pdo->query("SELECT COUNT(*) FROM permissions")->fetchColumn();
    
    if ($permissionCount == 0) {
        echo "<p class='warning'>No permissions found. Adding default permissions...</p>";
        
        $permissionsData = [
            // User permissions
            ['name' => 'user.view', 'description_eng' => 'View users', 'category' => 'user'],
            ['name' => 'user.create', 'description_eng' => 'Create users', 'category' => 'user'],
            ['name' => 'user.edit', 'description_eng' => 'Edit users', 'category' => 'user'],
            ['name' => 'user.delete', 'description_eng' => 'Delete users', 'category' => 'user'],
            
            // Role permissions
            ['name' => 'role.view', 'description_eng' => 'View roles', 'category' => 'role'],
            ['name' => 'role.create', 'description_eng' => 'Create roles', 'category' => 'role'],
            ['name' => 'role.edit', 'description_eng' => 'Edit roles', 'category' => 'role'],
            ['name' => 'role.delete', 'description_eng' => 'Delete roles', 'category' => 'role'],
            ['name' => 'role.manage_permissions', 'description_eng' => 'Manage role permissions', 'category' => 'role'],
            
            // Employee permissions
            ['name' => 'employee.view', 'description_eng' => 'View employees', 'category' => 'employee'],
            ['name' => 'employee.create', 'description_eng' => 'Create employees', 'category' => 'employee'],
            ['name' => 'employee.edit', 'description_eng' => 'Edit employees', 'category' => 'employee'],
            ['name' => 'employee.delete', 'description_eng' => 'Delete employees', 'category' => 'employee'],
            
            // System permissions
            ['name' => 'system.settings', 'description_eng' => 'Manage system settings', 'category' => 'system'],
            ['name' => 'system.audit_logs', 'description_eng' => 'View audit logs', 'category' => 'system'],
        ];
        
        $stmt = $pdo->prepare("INSERT INTO permissions (name, description_eng, category) VALUES (?, ?, ?)");
        
        foreach ($permissionsData as $permission) {
            $stmt->execute([
                $permission['name'],
                $permission['description_eng'],
                $permission['category']
            ]);
        }
        
        echo "<p class='success'>Added " . count($permissionsData) . " default permissions.</p>";
        
        // Assign all permissions to admin role
        echo "<p>Assigning permissions to Administrator role...</p>";
        
        // Get admin role ID
        $adminRoleStmt = $pdo->query("SELECT id FROM roles WHERE name = 'Administrator' OR id = 1 LIMIT 1");
        if ($adminRole = $adminRoleStmt->fetch()) {
            $adminRoleId = $adminRole['id'];
            
            // Get all permission IDs
            $permStmt = $pdo->query("SELECT id FROM permissions");
            $permissionIds = $permStmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Assign each permission to admin role
            $assignStmt = $pdo->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
            
            foreach ($permissionIds as $permissionId) {
                $assignStmt->execute([$adminRoleId, $permissionId]);
            }
            
            echo "<p class='success'>Assigned " . count($permissionIds) . " permissions to Administrator role.</p>";
        } else {
            echo "<p class='error'>Administrator role not found!</p>";
        }
    } else {
        echo "<p class='success'>Found $permissionCount permissions in the database.</p>";
    }
    
    // Commit transaction
    $pdo->commit();
    
    echo "<h2 class='success'>Database Schema Fixed Successfully!</h2>";
    echo "<p><a href='index.php'>Return to Dashboard</a></p>";
    
} catch (PDOException $e) {
    // Rollback on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    echo "<h2 class='error'>Error During Database Fix</h2>";
    echo "<p class='error'>" . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
} finally {
    echo "</body></html>";
}
?> 
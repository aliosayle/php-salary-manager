<?php
/**
 * Database Initialization Script for Rental Management System
 * 
 * This script creates all required tables and initial data for the application
 */

// Include configuration
require_once 'layouts/config.php';

// Enable error display for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Set admin credentials
$adminEmail = 'admin@rentalms.com';
$adminName = 'System Administrator';
$adminPassword = 'Admin@2023';

echo "<h1>RMS Database Initialization</h1>";

try {
    // Begin transaction
    $pdo->beginTransaction();
    
    echo "<h2>Creating Database Tables</h2>";
    
    // Create roles table
    echo "<p>Creating roles table... ";
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS roles (
        id VARCHAR(36) PRIMARY KEY,
        name VARCHAR(255) NOT NULL UNIQUE,
        description TEXT
    )
    ");
    echo "Done</p>";
    
    // Create roles trigger
    echo "<p>Creating roles trigger... ";
    $pdo->exec("
    DROP TRIGGER IF EXISTS before_insert_roles;
    ");
    
    $pdo->exec("
    CREATE TRIGGER before_insert_roles
    BEFORE INSERT ON roles
    FOR EACH ROW
    BEGIN
        IF NEW.id IS NULL THEN
            SET NEW.id = UUID();
        END IF;
    END;
    ");
    echo "Done</p>";
    
    // Create users table
    echo "<p>Creating users table... ";
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
        id VARCHAR(36) PRIMARY KEY,
        email VARCHAR(255) UNIQUE NOT NULL,
        name VARCHAR(255) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        is_active BOOLEAN DEFAULT TRUE,
        role_id VARCHAR(36),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE SET NULL
    )
    ");
    echo "Done</p>";
    
    // Create users trigger
    echo "<p>Creating users trigger... ";
    $pdo->exec("
    DROP TRIGGER IF EXISTS before_insert_users;
    ");
    
    $pdo->exec("
    CREATE TRIGGER before_insert_users
    BEFORE INSERT ON users
    FOR EACH ROW
    BEGIN
        IF NEW.id IS NULL THEN
            SET NEW.id = UUID();
        END IF;
    END;
    ");
    echo "Done</p>";
    
    // Create sessions table
    echo "<p>Creating sessions table... ";
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS sessions (
        id VARCHAR(36) PRIMARY KEY,
        user_id VARCHAR(36) NOT NULL,
        session_token VARCHAR(255) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        expires_at TIMESTAMP NOT NULL,
        is_active BOOLEAN DEFAULT TRUE,
        public_ip VARCHAR(45),
        local_ip VARCHAR(45),
        payload JSON,
        browser_info TEXT,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )
    ");
    echo "Done</p>";
    
    // Create sessions trigger
    echo "<p>Creating sessions trigger... ";
    $pdo->exec("
    DROP TRIGGER IF EXISTS before_insert_sessions;
    ");
    
    $pdo->exec("
    CREATE TRIGGER before_insert_sessions
    BEFORE INSERT ON sessions
    FOR EACH ROW
    BEGIN
        IF NEW.id IS NULL THEN
            SET NEW.id = UUID();
        END IF;
    END;
    ");
    echo "Done</p>";
    
    // Create permissions table
    echo "<p>Creating permissions table... ";
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS permissions (
        id VARCHAR(36) PRIMARY KEY,
        name VARCHAR(255) NOT NULL UNIQUE,
        description_eng TEXT NOT NULL,
        description_french TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
    ");
    echo "Done</p>";
    
    // Create permissions trigger
    echo "<p>Creating permissions trigger... ";
    $pdo->exec("
    DROP TRIGGER IF EXISTS before_insert_permissions;
    ");
    
    $pdo->exec("
    CREATE TRIGGER before_insert_permissions
    BEFORE INSERT ON permissions
    FOR EACH ROW
    BEGIN
        IF NEW.id IS NULL THEN
            SET NEW.id = UUID();
        END IF;
    END;
    ");
    echo "Done</p>";
    
    // Create permissions_per_role table
    echo "<p>Creating permissions_per_role table... ";
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS permissions_per_role (
        id VARCHAR(36) PRIMARY KEY,
        role_id VARCHAR(36) NOT NULL,
        permission_id VARCHAR(36) NOT NULL,
        is_granted BOOLEAN DEFAULT TRUE,
        FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
        FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
    )
    ");
    echo "Done</p>";
    
    // Create permissions_per_role trigger
    echo "<p>Creating permissions_per_role trigger... ";
    $pdo->exec("
    DROP TRIGGER IF EXISTS before_insert_permissions_per_role;
    ");
    
    $pdo->exec("
    CREATE TRIGGER before_insert_permissions_per_role
    BEFORE INSERT ON permissions_per_role
    FOR EACH ROW
    BEGIN
        IF NEW.id IS NULL THEN
            SET NEW.id = UUID();
        END IF;
    END;
    ");
    echo "Done</p>";
    
    // Create audit_logs table
    echo "<p>Creating audit_logs table... ";
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS audit_logs (
        id VARCHAR(36) PRIMARY KEY,
        user_id VARCHAR(36),
        action VARCHAR(255) NOT NULL,
        table_name VARCHAR(255) NOT NULL,
        record_id VARCHAR(36),
        old_values JSON,
        new_values JSON,
        ip_address VARCHAR(45),
        user_agent TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    )
    ");
    echo "Done</p>";
    
    // Create audit_logs trigger
    echo "<p>Creating audit_logs trigger... ";
    $pdo->exec("
    DROP TRIGGER IF EXISTS before_insert_audit_logs;
    ");
    
    $pdo->exec("
    CREATE TRIGGER before_insert_audit_logs
    BEFORE INSERT ON audit_logs
    FOR EACH ROW
    BEGIN
        IF NEW.id IS NULL THEN
            SET NEW.id = UUID();
        END IF;
    END;
    ");
    echo "Done</p>";
    
    // Create datasets table
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS datasets (
        id VARCHAR(36) PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )
    ");

    // Create UUID trigger for datasets
    $pdo->exec("
    CREATE TRIGGER IF NOT EXISTS before_insert_datasets
    BEFORE INSERT ON datasets
    FOR EACH ROW
    BEGIN
        IF NEW.id IS NULL THEN
            SET NEW.id = UUID();
        END IF;
    END
    ");

    // Create user_datasets table (many-to-many relationship)
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS user_datasets (
        id VARCHAR(36) PRIMARY KEY,
        user_id VARCHAR(36) NOT NULL,
        dataset_id VARCHAR(36) NOT NULL,
        is_default BOOLEAN DEFAULT FALSE,
        can_edit BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (dataset_id) REFERENCES datasets(id) ON DELETE CASCADE,
        UNIQUE KEY (user_id, dataset_id)
    )
    ");

    // Create UUID trigger for user_datasets
    $pdo->exec("
    CREATE TRIGGER IF NOT EXISTS before_insert_user_datasets
    BEFORE INSERT ON user_datasets
    FOR EACH ROW
    BEGIN
        IF NEW.id IS NULL THEN
            SET NEW.id = UUID();
        END IF;
    END
    ");

    // Create Initial Data
    echo "<h2>Creating Initial Data</h2>";
    
    // Create default roles
    $roles = [
        ['id' => 1, 'name' => 'Administrator', 'description' => 'System administrator with full access to all features'],
        ['id' => 2, 'name' => 'Property Manager', 'description' => 'Can manage properties, tenants, and leases'],
        ['id' => 3, 'name' => 'Tenant', 'description' => 'Can view their own lease and payment information']
    ];

    echo "<p>Creating default roles...</p>";
    foreach ($roles as $role) {
        // Check if role already exists by ID
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE id = ?");
        $stmt->execute([$role['id']]);
        $exists = $stmt->fetchColumn() > 0;
        
        if (!$exists) {
            try {
                // Insert new role with specific ID
                $stmt = $pdo->prepare("INSERT INTO roles (id, name, description) VALUES (?, ?, ?)");
                $stmt->execute([$role['id'], $role['name'], $role['description']]);
                echo "<p>Created role: " . htmlspecialchars($role['name']) . " with ID " . $role['id'] . "</p>";
            } catch (PDOException $e) {
                echo "<p style='color: red;'>Error creating role " . htmlspecialchars($role['name']) . ": " . $e->getMessage() . "</p>";
            }
        } else {
            echo "<p>Role already exists: " . htmlspecialchars($role['name']) . " (ID: " . $role['id'] . ")</p>";
        }
    }
    
    // Add default permissions
    $permissionsData = [
        // User permissions
        ['name' => 'user.view', 'description_eng' => 'View users'],
        ['name' => 'user.create', 'description_eng' => 'Create users'],
        ['name' => 'user.edit', 'description_eng' => 'Edit users'],
        ['name' => 'user.delete', 'description_eng' => 'Delete users'],
        
        // Role permissions
        ['name' => 'role.view', 'description_eng' => 'View roles'],
        ['name' => 'role.create', 'description_eng' => 'Create roles'],
        ['name' => 'role.edit', 'description_eng' => 'Edit roles'],
        ['name' => 'role.delete', 'description_eng' => 'Delete roles'],
        ['name' => 'role.manage_permissions', 'description_eng' => 'Manage role permissions'],
        
        // Property permissions
        ['name' => 'property.view', 'description_eng' => 'View properties'],
        ['name' => 'property.create', 'description_eng' => 'Create properties'],
        ['name' => 'property.edit', 'description_eng' => 'Edit properties'],
        ['name' => 'property.delete', 'description_eng' => 'Delete properties'],
        
        // Tenant permissions
        ['name' => 'tenant.view', 'description_eng' => 'View tenants'],
        ['name' => 'tenant.create', 'description_eng' => 'Create tenants'],
        ['name' => 'tenant.edit', 'description_eng' => 'Edit tenants'],
        ['name' => 'tenant.delete', 'description_eng' => 'Delete tenants'],
        
        // Lease permissions
        ['name' => 'lease.view', 'description_eng' => 'View leases'],
        ['name' => 'lease.create', 'description_eng' => 'Create leases'],
        ['name' => 'lease.edit', 'description_eng' => 'Edit leases'],
        ['name' => 'lease.delete', 'description_eng' => 'Delete leases'],
        
        // Payment permissions
        ['name' => 'payment.view', 'description_eng' => 'View payments'],
        ['name' => 'payment.create', 'description_eng' => 'Create payments'],
        ['name' => 'payment.edit', 'description_eng' => 'Edit payments'],
        ['name' => 'payment.delete', 'description_eng' => 'Delete payments'],
        
        // Report permissions
        ['name' => 'report.view', 'description_eng' => 'View reports'],
        ['name' => 'report.generate', 'description_eng' => 'Generate reports'],
        
        // System permissions
        ['name' => 'system.settings', 'description_eng' => 'Manage system settings'],
        ['name' => 'system.audit_logs', 'description_eng' => 'View audit logs'],
        
        // Dataset permissions
        ['name' => 'dataset.view', 'description_eng' => 'View datasets'],
        ['name' => 'dataset.create', 'description_eng' => 'Create datasets'],
        ['name' => 'dataset.edit', 'description_eng' => 'Edit datasets'],
        ['name' => 'dataset.delete', 'description_eng' => 'Delete datasets'],
        ['name' => 'dataset.assign_users', 'description_eng' => 'Assign users to datasets']
    ];

    echo "<p>Creating permissions... ";
    $permissionsStmt = $pdo->prepare("INSERT INTO permissions (name, description_eng) VALUES (?, ?)");
    foreach ($permissionsData as $permission) {
        try {
            $permissionsStmt->execute([$permission['name'], $permission['description_eng']]);
        } catch (PDOException $e) {
            // If permission already exists, skip
            if ($e->getCode() != 23000) { // 23000 is duplicate entry error
                throw $e;
            }
        }
    }
    echo "Done</p>";
    
    // Get admin role ID
    $roleStmt = $pdo->prepare("SELECT id FROM roles WHERE name = ?");
    $roleStmt->execute(['Administrator']);
    $adminRole = $roleStmt->fetch();
    $adminRoleId = $adminRole['id'];
    
    // Assign all permissions to admin role
    echo "<p>Assigning permissions to admin role... ";
    try {
        // Get all permissions
        $permissionsStmt = $pdo->query("SELECT id FROM permissions");
        $permissionIds = $permissionsStmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Assign each permission to admin role
        $assignStmt = $pdo->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
        foreach ($permissionIds as $permissionId) {
            $assignStmt->execute([$adminRoleId, $permissionId]);
        }
        echo "Done</p>";
    } catch (PDOException $e) {
        echo "Failed (" . $e->getMessage() . ")</p>";
    }
    
    // Create admin user
    echo "<p>Creating admin user... ";
    
    // Check if admin user exists
    $userStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $userStmt->execute([$adminEmail]);
    
    if ($userStmt->rowCount() === 0) {
        // Hash the password
        $passwordHash = hashPassword($adminPassword);
        
        // Insert admin user
        $insertStmt = $pdo->prepare("
            INSERT INTO users (name, email, password_hash, is_active, role_id) 
            VALUES (?, ?, ?, TRUE, ?)
        ");
        $insertStmt->execute([$adminName, $adminEmail, $passwordHash, $adminRoleId]);
        echo "Created new admin user</p>";
    } else {
        // Update admin user
        $adminId = $userStmt->fetch()['id'];
        $passwordHash = hashPassword($adminPassword);
        
        $updateStmt = $pdo->prepare("
            UPDATE users 
            SET name = ?, password_hash = ?, is_active = TRUE, role_id = ? 
            WHERE id = ?
        ");
        $updateStmt->execute([$adminName, $passwordHash, $adminRoleId, $adminId]);
        echo "Updated existing admin user</p>";
    }
    
    // Add default dataset for the admin user
    try {
        // First check if admin user exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE role_id = 1 LIMIT 1");
        $stmt->execute();
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($admin) {
            $admin_id = $admin['id'];
            
            // Check if default dataset exists
            $stmt = $pdo->prepare("SELECT id FROM datasets WHERE name = 'Default Dataset' LIMIT 1");
            $stmt->execute();
            
            if ($stmt->rowCount() == 0) {
                // Create default dataset
                $pdo->exec("INSERT INTO datasets (name, description) VALUES ('Default Dataset', 'Default dataset for Rental Management System')");
                
                // Get the ID of the dataset
                $dataset_id = $pdo->lastInsertId();
                
                if (!$dataset_id) {
                    // If lastInsertId() fails, try to get the ID directly
                    $stmt = $pdo->prepare("SELECT id FROM datasets WHERE name = 'Default Dataset' LIMIT 1");
                    $stmt->execute();
                    $dataset = $stmt->fetch(PDO::FETCH_ASSOC);
                    $dataset_id = $dataset['id'];
                }
                
                // Assign the dataset to the admin user with edit permissions
                $stmt = $pdo->prepare("INSERT INTO user_datasets (user_id, dataset_id, is_default, can_edit) VALUES (?, ?, TRUE, TRUE)");
                $stmt->execute([$admin_id, $dataset_id]);
                echo "<p>Created and assigned default dataset to admin user</p>";
            } else {
                $dataset = $stmt->fetch(PDO::FETCH_ASSOC);
                $dataset_id = $dataset['id'];
                
                // Check if the dataset is already assigned to the admin
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_datasets WHERE user_id = ? AND dataset_id = ?");
                $stmt->execute([$admin_id, $dataset_id]);
                
                if ($stmt->fetchColumn() == 0) {
                    // Assign the existing dataset to the admin user with edit permissions
                    $stmt = $pdo->prepare("INSERT INTO user_datasets (user_id, dataset_id, is_default, can_edit) VALUES (?, ?, TRUE, TRUE)");
                    $stmt->execute([$admin_id, $dataset_id]);
                    echo "<p>Assigned existing default dataset to admin user</p>";
                }
            }
        }
    } catch (PDOException $e) {
        echo "Error setting up default dataset: " . $e->getMessage() . "<br>";
    }
    
    // Commit the transaction
    $pdo->commit();
    
    echo "<div style='background-color: #dff0d8; border: 1px solid #d6e9c6; padding: 15px; margin: 20px 0; border-radius: 4px;'>";
    echo "<h3 style='color: #3c763d; margin-top: 0;'>Database Initialized Successfully!</h3>";
    echo "<p>Admin user credentials:</p>";
    echo "<ul>";
    echo "<li><strong>Email:</strong> " . $adminEmail . "</li>";
    echo "<li><strong>Password:</strong> " . $adminPassword . "</li>";
    echo "</ul>";
    echo "<p><a href='auth-login.php' style='background-color: #5cb85c; color: white; padding: 6px 12px; text-decoration: none; border-radius: 4px;'>Go to Login Page</a></p>";
    echo "</div>";
    
} catch (PDOException $e) {
    // Rollback the transaction on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    echo "<div style='background-color: #f2dede; border: 1px solid #ebccd1; padding: 15px; margin: 20px 0; border-radius: 4px;'>";
    echo "<h3 style='color: #a94442; margin-top: 0;'>Database Initialization Failed</h3>";
    echo "<p>Error: " . $e->getMessage() . "</p>";
    echo "<p>Line: " . $e->getLine() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
    echo "</div>";
}
?> 
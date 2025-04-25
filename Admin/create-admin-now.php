<?php
// Direct admin creation script

// Include configuration
require_once 'layouts/config.php';

// Set admin credentials
$adminEmail = 'admin@rentalms.com';
$adminName = 'System Administrator';
$adminPassword = 'Admin@2023';
$passwordHash = hashPassword($adminPassword);

// Enable error display for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>Creating Admin User</h1>";

try {
    // Begin transaction
    $pdo->beginTransaction();
    
    // Check if roles table exists
    $roleTableExists = false;
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'roles'");
        $roleTableExists = $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        echo "<p>Error checking roles table: " . $e->getMessage() . "</p>";
    }
    
    echo "<p>Roles table exists: " . ($roleTableExists ? "Yes" : "No") . "</p>";
    
    // Create roles table if it doesn't exist
    if (!$roleTableExists) {
        echo "<p>Creating roles table...</p>";
        
        $pdo->exec("
        CREATE TABLE IF NOT EXISTS roles (
            id VARCHAR(36) PRIMARY KEY,
            name VARCHAR(255) NOT NULL UNIQUE,
            description TEXT
        )
        ");
        
        // Create roles trigger
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
    }
    
    // Check if users table exists
    $userTableExists = false;
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
        $userTableExists = $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        echo "<p>Error checking users table: " . $e->getMessage() . "</p>";
    }
    
    echo "<p>Users table exists: " . ($userTableExists ? "Yes" : "No") . "</p>";
    
    // Create users table if it doesn't exist
    if (!$userTableExists) {
        echo "<p>Creating users table...</p>";
        
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
        
        // Create users trigger
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
    }
    
    // Check if admin role exists
    $adminRoleId = null;
    $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = ?");
    $stmt->execute(['Administrator']);
    if ($stmt->rowCount() > 0) {
        $adminRoleId = $stmt->fetch()['id'];
        echo "<p>Admin role exists, ID: " . $adminRoleId . "</p>";
    } else {
        // Create admin role
        echo "<p>Creating admin role...</p>";
        $stmt = $pdo->prepare("INSERT INTO roles (name, description) VALUES (?, ?)");
        $stmt->execute(['Administrator', 'System administrator with full access']);
        $adminRoleId = $pdo->lastInsertId();
        echo "<p>Admin role created, ID: " . $adminRoleId . "</p>";
    }
    
    // Check if admin user exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$adminEmail]);
    
    if ($stmt->rowCount() > 0) {
        $adminId = $stmt->fetch()['id'];
        echo "<p>Admin user already exists, ID: " . $adminId . "</p>";
        
        // Update password
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, name = ?, is_active = TRUE WHERE id = ?");
        $stmt->execute([$passwordHash, $adminName, $adminId]);
        echo "<p>Admin user credentials updated.</p>";
    } else {
        // Create admin user
        echo "<p>Creating admin user...</p>";
        $stmt = $pdo->prepare("INSERT INTO users (email, name, password_hash, is_active, role_id) VALUES (?, ?, ?, TRUE, ?)");
        $stmt->execute([$adminEmail, $adminName, $passwordHash, $adminRoleId]);
        $adminId = $pdo->lastInsertId();
        echo "<p>Admin user created, ID: " . $adminId . "</p>";
    }
    
    // Commit transaction
    $pdo->commit();
    
    echo "<div style='background-color: #dff0d8; border: 1px solid #d6e9c6; padding: 15px; margin: 20px 0; border-radius: 4px;'>";
    echo "<h3 style='color: #3c763d; margin-top: 0;'>Success!</h3>";
    echo "<p>Admin user credentials:</p>";
    echo "<ul>";
    echo "<li><strong>Email:</strong> " . $adminEmail . "</li>";
    echo "<li><strong>Password:</strong> " . $adminPassword . "</li>";
    echo "</ul>";
    echo "<p><a href='auth-login.php' style='background-color: #5cb85c; color: white; padding: 6px 12px; text-decoration: none; border-radius: 4px;'>Go to Login Page</a></p>";
    echo "</div>";
    
} catch (PDOException $e) {
    // Rollback transaction
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    echo "<div style='background-color: #f2dede; border: 1px solid #ebccd1; padding: 15px; margin: 20px 0; border-radius: 4px;'>";
    echo "<h3 style='color: #a94442; margin-top: 0;'>Error!</h3>";
    echo "<p>An error occurred while creating the admin user:</p>";
    echo "<pre>" . $e->getMessage() . "</pre>";
    echo "</div>";
}
?> 
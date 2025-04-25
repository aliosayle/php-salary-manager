<?php
// Set up error reporting
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Include database configuration
require_once "layouts/config.php";

echo "<h1>Roles Table Check</h1>";

try {
    // Check if roles table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'roles'");
    $roleTableExists = $stmt->rowCount() > 0;
    
    echo "<p>Roles table exists: " . ($roleTableExists ? "Yes" : "No") . "</p>";
    
    if ($roleTableExists) {
        // Get table structure
        $stmt = $pdo->query("DESCRIBE roles");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h2>Table Structure:</h2>";
        echo "<table border='1'>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
        foreach ($columns as $column) {
            echo "<tr>";
            foreach ($column as $key => $value) {
                echo "<td>" . htmlspecialchars($value ?? 'NULL') . "</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
        
        // Get all roles
        $stmt = $pdo->query("SELECT * FROM roles");
        $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h2>Roles Data:</h2>";
        if (count($roles) > 0) {
            echo "<table border='1'>";
            echo "<tr>";
            foreach (array_keys($roles[0]) as $key) {
                echo "<th>" . htmlspecialchars($key) . "</th>";
            }
            echo "</tr>";
            
            foreach ($roles as $role) {
                echo "<tr>";
                foreach ($role as $value) {
                    echo "<td>" . htmlspecialchars($value ?? 'NULL') . "</td>";
                }
                echo "</tr>";
            }
            echo "</table>";
            
            echo "<p>Total roles: " . count($roles) . "</p>";
        } else {
            echo "<p>No roles found in the database!</p>";
        }
    }
    
    // Check users table role_id column
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'role_id'");
    $roleIdColumn = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<h2>Users Table role_id Column:</h2>";
    if ($roleIdColumn) {
        echo "<table border='1'>";
        echo "<tr>";
        foreach ($roleIdColumn as $key => $value) {
            echo "<th>" . htmlspecialchars($key) . "</th>";
        }
        echo "</tr>";
        echo "<tr>";
        foreach ($roleIdColumn as $value) {
            echo "<td>" . htmlspecialchars($value ?? 'NULL') . "</td>";
        }
        echo "</tr>";
        echo "</table>";
    } else {
        echo "<p>role_id column not found in users table!</p>";
    }
    
    // Check users with roles
    $stmt = $pdo->query("SELECT u.id, u.name, u.email, u.role_id, r.name as role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id LIMIT 10");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h2>Users with Roles (up to 10):</h2>";
    if (count($users) > 0) {
        echo "<table border='1'>";
        echo "<tr>";
        foreach (array_keys($users[0]) as $key) {
            echo "<th>" . htmlspecialchars($key) . "</th>";
        }
        echo "</tr>";
        
        foreach ($users as $user) {
            echo "<tr>";
            foreach ($user as $value) {
                echo "<td>" . htmlspecialchars($value ?? 'NULL') . "</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No users found in the database!</p>";
    }
} catch (PDOException $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?> 
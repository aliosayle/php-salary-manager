<?php
// Include the database configuration
include "../layouts/config.php";

try {
    // Check if posts table exists
    $check = $pdo->query("SHOW TABLES LIKE 'posts'");
    if ($check->rowCount() == 0) {
        // Create posts table if it doesn't exist
        $pdo->exec("CREATE TABLE IF NOT EXISTS posts (
            id VARCHAR(36) NOT NULL PRIMARY KEY,
            title VARCHAR(255) NOT NULL
        )");
        
        echo "Posts table created successfully.<br>";
        
        // Add some default positions
        $pdo->exec("INSERT INTO posts (id, title) VALUES 
            (UUID(), 'Manager'),
            (UUID(), 'Developer'),
            (UUID(), 'Accountant'),
            (UUID(), 'HR Specialist'),
            (UUID(), 'Administrative Assistant')
        ");
        
        echo "Default positions added successfully.";
    } else {
        echo "Posts table already exists.";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?> 
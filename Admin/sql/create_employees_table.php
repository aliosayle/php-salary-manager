<?php
// Include the database configuration
include "../layouts/config.php";

try {
    // Check if employees table exists
    $check = $pdo->query("SHOW TABLES LIKE 'employees'");
    if ($check->rowCount() == 0) {
        // Create employees table if it doesn't exist
        $pdo->exec("CREATE TABLE IF NOT EXISTS employees (
            id VARCHAR(36) NOT NULL PRIMARY KEY,
            full_name VARCHAR(255) NOT NULL,
            assignment VARCHAR(255) NULL,
            post_id VARCHAR(36) NULL,
            recruitment_date DATE NULL,
            end_of_service_date DATE NULL,
            education_level_id VARCHAR(36) NULL,
            phone_number VARCHAR(255) NULL,
            address TEXT NULL,
            recommended_by_id VARCHAR(36) NULL,
            base_salary DECIMAL(10,2) NULL,
            FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE SET NULL
        )");
        
        echo "Employees table created successfully.<br>";
    } else {
        // Check if the structure needs to be updated
        $alterNeeded = false;
        
        // Check if id column is VARCHAR(36)
        $idColumn = $pdo->query("SHOW COLUMNS FROM employees LIKE 'id'");
        $idInfo = $idColumn->fetch(PDO::FETCH_ASSOC);
        if ($idInfo['Type'] != 'varchar(36)') {
            $alterNeeded = true;
        }
        
        if ($alterNeeded) {
            // Backup existing data
            echo "Updating employees table structure. Creating backup first...<br>";
            $pdo->exec("CREATE TABLE employees_backup AS SELECT * FROM employees");
            echo "Backup created as employees_backup.<br>";
            
            // Drop and recreate employees table
            $pdo->exec("DROP TABLE employees");
            $pdo->exec("CREATE TABLE employees (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                full_name VARCHAR(255) NOT NULL,
                assignment VARCHAR(255) NULL,
                post_id VARCHAR(36) NULL,
                recruitment_date DATE NULL,
                end_of_service_date DATE NULL,
                education_level_id VARCHAR(36) NULL,
                phone_number VARCHAR(255) NULL,
                address TEXT NULL,
                recommended_by_id VARCHAR(36) NULL,
                base_salary DECIMAL(10,2) NULL,
                FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE SET NULL
            )");
            
            echo "Employees table recreated with updated structure.<br>";
        } else {
            echo "Employees table already exists with correct structure.<br>";
        }
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?> 
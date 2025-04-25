<?php
// Include required files
require_once "../layouts/config.php";
require_once "../layouts/helpers.php";
require_once "../layouts/session.php";

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Check permission
if (!hasPermission('manage_employees')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Get shop ID
$shopId = isset($_POST['shop_id']) ? trim($_POST['shop_id']) : null;

// Check if shop ID is provided
if (empty($shopId)) {
    echo json_encode(['success' => false, 'message' => 'Shop ID is required']);
    exit;
}

// Check if shop exists
try {
    $stmt = $pdo->prepare("SELECT id FROM shops WHERE id = ?");
    $stmt->execute([$shopId]);
    if ($stmt->rowCount() == 0) {
        echo json_encode(['success' => false, 'message' => 'Shop not found']);
        exit;
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit;
}

// Get employee IDs
$employeeIds = isset($_POST['employee_ids']) ? $_POST['employee_ids'] : [];

// Validate employee IDs
if (!is_array($employeeIds)) {
    echo json_encode(['success' => false, 'message' => 'Invalid employee data']);
    exit;
}

try {
    // Begin transaction
    $pdo->beginTransaction();
    
    // Validate employee types - ensure each post type is only assigned once
    $errors = [];
    $postTypes = [];
    $hasResponsible = false;

    // Check if any employee is already a responsible for this shop
    $existingResponsibleStmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM shop_responsibles 
        WHERE shop_id = ?
    ");
    $existingResponsibleStmt->execute([$shopId]);
    $existingResponsibleCount = $existingResponsibleStmt->fetchColumn();

    // Get post types of all employees to be assigned
    if (!empty($employeeIds)) {
        $employeesQuery = $pdo->prepare("
            SELECT e.id, e.full_name, e.post_id, p.title as post_title
            FROM employees e
            LEFT JOIN posts p ON e.post_id = p.id
            WHERE e.id IN (" . implode(',', array_fill(0, count($employeeIds), '?')) . ")
        ");
        $employeesQuery->execute($employeeIds);
        $employeesData = $employeesQuery->fetchAll(PDO::FETCH_ASSOC);

        // Check for duplicate post types
        foreach ($employeesData as $employee) {
            $postId = $employee['post_id'];
            
            // Skip employees without a post type
            if (empty($postId)) {
                continue;
            }
            
            // Check if this post type already exists in our assignments
            if (isset($postTypes[$postId])) {
                $errors[] = "Multiple employees of type '" . htmlspecialchars($employee['post_title']) . "' cannot be assigned to the same shop.";
            } else {
                $postTypes[$postId] = [
                    'employee_id' => $employee['id'],
                    'name' => $employee['full_name'],
                    'post_title' => $employee['post_title']
                ];
            }
            
            // Check if employee is a responsible
            $responsibleCheckStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM shop_responsibles
                WHERE employee_id = ?
            ");
            $responsibleCheckStmt->execute([$employee['id']]);
            $isResponsible = ($responsibleCheckStmt->fetchColumn() > 0);
            
            if ($isResponsible) {
                if ($hasResponsible) {
                    $errors[] = "Only one shop responsible can be assigned to a shop.";
                }
                $hasResponsible = true;
            }
        }
    }
    
    // If there are errors, stop and return them
    if (!empty($errors)) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => implode("<br>", $errors)]);
        exit;
    }
    
    // Delete existing assignments for this shop
    $deleteStmt = $pdo->prepare("DELETE FROM employee_shops WHERE shop_id = ?");
    $deleteStmt->execute([$shopId]);
    
    // Handle responsible assignment - remove existing responsibles
    $deleteResponsibleStmt = $pdo->prepare("DELETE FROM shop_responsibles WHERE shop_id = ?");
    $deleteResponsibleStmt->execute([$shopId]);
    
    // Add new assignments
    if (!empty($employeeIds)) {
        $insertStmt = $pdo->prepare("INSERT INTO employee_shops (employee_id, shop_id) VALUES (?, ?)");
        foreach ($employeeIds as $employeeId) {
            $insertStmt->execute([$employeeId, $shopId]);
            
            // Check if this employee is marked as a responsible
            foreach ($employeesData as $employee) {
                if ($employee['id'] === $employeeId) {
                    $isManager = false;
                    
                    // Check if the employee's position is a manager or responsible
                    // This is a simple example, you may need to adjust this based on your specific titles
                    $postTitle = strtolower($employee['post_title'] ?? '');
                    if (strpos($postTitle, 'manager') !== false || 
                        strpos($postTitle, 'responsible') !== false || 
                        strpos($postTitle, 'supervisor') !== false) {
                        $isManager = true;
                    }
                    
                    // If this is a manager, insert into shop_responsibles
                    if ($isManager && !$existingResponsibleCount) {
                        $insertResponsibleStmt = $pdo->prepare("
                            INSERT INTO shop_responsibles (id, employee_id, shop_id) 
                            VALUES (UUID(), ?, ?)
                        ");
                        $insertResponsibleStmt->execute([$employeeId, $shopId]);
                        $existingResponsibleCount = 1; // Ensure we only add one responsible
                    }
                    break;
                }
            }
        }
    }
    
    // Commit transaction
    $pdo->commit();
    
    echo json_encode(['success' => true, 'message' => 'Employee assignments updated successfully']);
} catch (PDOException $e) {
    // Rollback transaction
    $pdo->rollBack();
    
    echo json_encode(['success' => false, 'message' => 'Error updating assignments: ' . $e->getMessage()]);
} 
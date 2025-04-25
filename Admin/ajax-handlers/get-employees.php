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
if (!hasPermission('view_employees')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

// Check if shop_id is provided
if (!isset($_GET['shop_id']) || empty($_GET['shop_id'])) {
    echo json_encode(['success' => false, 'message' => 'Shop ID is required']);
    exit;
}

$shopId = $_GET['shop_id'];

try {
    // Check if shop exists
    $shopStmt = $pdo->prepare("SELECT id FROM shops WHERE id = ?");
    $shopStmt->execute([$shopId]);
    
    if ($shopStmt->rowCount() == 0) {
        echo json_encode(['success' => false, 'message' => 'Shop not found']);
        exit;
    }
    
    // Get all employees that are not assigned to any other shop
    // or are already assigned to this shop
    $employeesStmt = $pdo->prepare("
        SELECT e.id, e.full_name, p.title as post_title
        FROM employees e
        LEFT JOIN posts p ON e.post_id = p.id
        WHERE e.id NOT IN (
            SELECT es.employee_id
            FROM employee_shops es
            WHERE es.shop_id != ?
        ) OR e.id IN (
            SELECT es.employee_id
            FROM employee_shops es
            WHERE es.shop_id = ?
        )
        ORDER BY e.full_name ASC
    ");
    $employeesStmt->execute([$shopId, $shopId]);
    $employees = $employeesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get employees already assigned to this shop
    $assignedStmt = $pdo->prepare("
        SELECT employee_id
        FROM employee_shops
        WHERE shop_id = ?
    ");
    $assignedStmt->execute([$shopId]);
    $assignedEmployees = $assignedStmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo json_encode([
        'success' => true,
        'employees' => $employees,
        'assigned_employees' => $assignedEmployees
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit;
} 
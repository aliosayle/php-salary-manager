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
if (!hasPermission('manage_shops')) {
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
    
    // Get all employees who have the "Responsable" post title
    $employeesStmt = $pdo->prepare("
        SELECT DISTINCT e.id, e.full_name, p.title as post_title
        FROM employees e
        JOIN posts p ON e.post_id = p.id
        WHERE p.title = 'Responsable'
        ORDER BY e.full_name ASC
    ");
    $employeesStmt->execute();
    $employees = $employeesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get current responsible employee for this shop
    $responsibleStmt = $pdo->prepare("
        SELECT employee_id
        FROM shop_responsibles
        WHERE shop_id = ?
    ");
    $responsibleStmt->execute([$shopId]);
    $currentResponsible = $responsibleStmt->fetchColumn();
    
    echo json_encode([
        'success' => true,
        'employees' => $employees,
        'current_responsible' => $currentResponsible
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit;
} 
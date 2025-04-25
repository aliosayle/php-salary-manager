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

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Get shop ID and employee ID
$shopId = isset($_POST['shop_id']) ? trim($_POST['shop_id']) : null;
$employeeId = isset($_POST['employee_id']) ? trim($_POST['employee_id']) : null;

// Check if shop ID is provided
if (empty($shopId)) {
    echo json_encode(['success' => false, 'message' => 'Shop ID is required']);
    exit;
}

// Check if employee ID is provided
if (empty($employeeId)) {
    echo json_encode(['success' => false, 'message' => 'Employee ID is required']);
    exit;
}

try {
    // Check if shop exists
    $shopStmt = $pdo->prepare("SELECT id FROM shops WHERE id = ?");
    $shopStmt->execute([$shopId]);
    
    if ($shopStmt->rowCount() == 0) {
        echo json_encode(['success' => false, 'message' => 'Shop not found']);
        exit;
    }
    
    // Check if employee exists and has the Responsable post
    $employeeStmt = $pdo->prepare("
        SELECT e.id 
        FROM employees e
        JOIN posts p ON e.post_id = p.id
        WHERE e.id = ? AND p.title = 'Responsable'
    ");
    $employeeStmt->execute([$employeeId]);
    
    if ($employeeStmt->rowCount() == 0) {
        echo json_encode(['success' => false, 'message' => 'Employee not found or does not have the Responsable position']);
        exit;
    }
    
    // Check if the employee is already responsible for another shop
    $checkExistingStmt = $pdo->prepare("
        SELECT shop_id 
        FROM shop_responsibles 
        WHERE employee_id = ? AND shop_id != ?
    ");
    $checkExistingStmt->execute([$employeeId, $shopId]);
    
    if ($checkExistingStmt->rowCount() > 0) {
        $otherShopId = $checkExistingStmt->fetchColumn();
        
        // Get the shop name
        $shopNameStmt = $pdo->prepare("SELECT name FROM shops WHERE id = ?");
        $shopNameStmt->execute([$otherShopId]);
        $shopName = $shopNameStmt->fetchColumn();
        
        echo json_encode(['success' => false, 'message' => 'This employee is already responsible for another shop: ' . $shopName]);
        exit;
    }
    
    // Begin transaction
    $pdo->beginTransaction();
    
    // Check if a responsible already exists for this shop
    $existingStmt = $pdo->prepare("SELECT id FROM shop_responsibles WHERE shop_id = ?");
    $existingStmt->execute([$shopId]);
    $exists = $existingStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($exists) {
        // Update existing responsible
        $updateStmt = $pdo->prepare("UPDATE shop_responsibles SET employee_id = ? WHERE shop_id = ?");
        $updateStmt->execute([$employeeId, $shopId]);
    } else {
        // Insert new responsible
        $insertStmt = $pdo->prepare("INSERT INTO shop_responsibles (employee_id, shop_id) VALUES (?, ?)");
        $insertStmt->execute([$employeeId, $shopId]);
    }
    
    // Commit transaction
    $pdo->commit();
    
    echo json_encode(['success' => true, 'message' => 'Shop responsible assigned successfully']);
    
} catch (PDOException $e) {
    // Rollback transaction
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit;
} 
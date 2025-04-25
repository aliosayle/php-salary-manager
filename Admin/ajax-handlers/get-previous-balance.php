<?php
// Start a PHP session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Include required files
include "../layouts/config.php";
include "../layouts/helpers.php";

// Set appropriate content type
header('Content-Type: application/json');

// Get parameters from request
$employeeId = isset($_GET['employee_id']) ? $_GET['employee_id'] : null;
$shopId = isset($_GET['shop_id']) ? $_GET['shop_id'] : null;
$month = isset($_GET['month']) ? intval($_GET['month']) : null;
$year = isset($_GET['year']) ? intval($_GET['year']) : null;

// Validate input
if (!$employeeId) {
    echo json_encode(['success' => false, 'message' => 'Missing employee_id parameter']);
    exit;
}

if (!$shopId) {
    echo json_encode(['success' => false, 'message' => 'Missing shop_id parameter']);
    exit;
}

if (!$month || $month < 1 || $month > 12) {
    echo json_encode(['success' => false, 'message' => 'Invalid month parameter']);
    exit;
}

if (!$year || $year < 2000 || $year > 2100) {
    echo json_encode(['success' => false, 'message' => 'Invalid year parameter']);
    exit;
}

try {
    // Convert month/year to date for comparison
    $currentDate = date('Y-m-d', strtotime("$year-$month-01"));
    
    // First check if the employee exists
    $employeeStmt = $pdo->prepare("SELECT id FROM employees WHERE id = ?");
    $employeeStmt->execute([$employeeId]);
    if ($employeeStmt->rowCount() == 0) {
        throw new Exception("Employee not found with ID: $employeeId");
    }
    
    // Check if the shop exists
    $shopStmt = $pdo->prepare("SELECT id FROM shops WHERE id = ?");
    $shopStmt->execute([$shopId]);
    if ($shopStmt->rowCount() == 0) {
        throw new Exception("Shop not found with ID: $shopId");
    }
    
    // Get the most recent debt record for this employee/shop that is before the current month
    $stmt = $pdo->prepare("
        SELECT evaluation_month, remaining_balance 
        FROM manager_debts 
        WHERE employee_id = ? 
        AND shop_id = ? 
        AND evaluation_month < ?
        ORDER BY evaluation_month DESC
        LIMIT 1
    ");
    $stmt->execute([$employeeId, $shopId, $currentDate]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Return previous month's remaining balance or 0 if no previous records
    $balance = $result ? floatval($result['remaining_balance']) : 0;
    $prevMonth = $result ? date('F Y', strtotime($result['evaluation_month'])) : 'None';
    
    echo json_encode([
        'success' => true, 
        'balance' => $balance,
        'from_month' => $prevMonth,
        'message' => $result ? "Balance carried over from $prevMonth" : "No previous balance found"
    ]);
    
} catch (Exception $e) {
    error_log("Error in get-previous-balance.php: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Error: ' . $e->getMessage()
    ]);
} 
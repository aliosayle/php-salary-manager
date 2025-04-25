<?php
// Start a PHP session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

// Include required files
include "../layouts/config.php";

// Check if the request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Get the parameters
$employee_id = isset($_POST['employee_id']) ? $_POST['employee_id'] : null;
$shop_id = isset($_POST['shop_id']) ? $_POST['shop_id'] : null;
$month_id = isset($_POST['month_id']) ? $_POST['month_id'] : null;

// Validate the parameters
if (!$employee_id || !$shop_id || !$month_id) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

try {
    // Get the month date for the selected month
    $stmt = $pdo->prepare("SELECT * FROM months WHERE id = ?");
    $stmt->execute([$month_id]);
    $selected_month = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$selected_month) {
        echo json_encode(['success' => false, 'message' => 'Invalid month']);
        exit;
    }
    
    // Format as YYYY-MM-01
    $month_date = $selected_month['year'] . '-' . str_pad($selected_month['month'], 2, '0', STR_PAD_LEFT) . '-01';
    
    // Get the previous month
    $previous_date = date('Y-m-d', strtotime($month_date . ' -1 month'));
    
    // Get the previous month's debt balance
    $stmt = $pdo->prepare("
        SELECT remaining_balance 
        FROM manager_debts 
        WHERE employee_id = ? AND shop_id = ? AND evaluation_month = ?
    ");
    $stmt->execute([$employee_id, $shop_id, $previous_date]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $previous_balance = $result ? (float)$result['remaining_balance'] : 0;
    
    echo json_encode([
        'success' => true, 
        'balance' => $previous_balance,
        'month_date' => $month_date,
        'previous_date' => $previous_date
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} 
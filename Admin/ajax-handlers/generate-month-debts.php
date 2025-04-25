<?php
// Start a PHP session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and has administrative permissions
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Check for required permissions
$hasPermission = false;
if (isset($_SESSION['permissions']) && is_array($_SESSION['permissions'])) {
    $hasPermission = in_array('manage_months', $_SESSION['permissions']) || 
                     in_array('admin', $_SESSION['permissions']);
}

if (!$hasPermission) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit;
}

// Include required files
include "../layouts/config.php";
include "../layouts/helpers.php";
include "../manager-debts.php"; // Include the file with the generateDebtRecordsForNewMonth function

// Set appropriate content type
header('Content-Type: application/json');

// Get parameters from request
$month = isset($_POST['month']) ? intval($_POST['month']) : null;
$year = isset($_POST['year']) ? intval($_POST['year']) : null;

// Validate input
if (!$month || $month < 1 || $month > 12) {
    echo json_encode(['success' => false, 'message' => 'Invalid month parameter']);
    exit;
}

if (!$year || $year < 2000 || $year > 2100) {
    echo json_encode(['success' => false, 'message' => 'Invalid year parameter']);
    exit;
}

try {
    // Generate debt records for the month
    $result = generateDebtRecordsForNewMonth($pdo, $month, $year);
    
    if ($result['success']) {
        echo json_encode([
            'success' => true, 
            'message' => 'Debt records generated successfully',
            'records_created' => $result['records_created']
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => 'Error generating debt records: ' . $result['error']
        ]);
    }
} catch (Exception $e) {
    error_log("Error in generate-month-debts.php: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Error: ' . $e->getMessage()
    ]);
} 
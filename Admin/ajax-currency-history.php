<?php
// Set up error logging
ini_set('display_errors', 1);
ini_set('error_log', __DIR__ . '/layouts/logs/error.log');
error_log("ajax-currency-history.php - Request received: " . json_encode($_GET));

// Initialize the session
session_start();

// Prepare response array
$response = [
    'status' => 'error',
    'message' => '',
    'rate' => null,
    'date' => null
];

// Check if user is logged in
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    $response['message'] = 'Not authenticated';
    echo json_encode($response);
    exit;
}

/**
 * Validate request fields against a whitelist
 * 
 * @param array $allowedFields List of allowed field names
 * @param array $requestData The request data to validate
 * @param string $method The request method (POST, GET)
 * @return array Sanitized data containing only whitelisted fields
 */
function validateRequestFields($allowedFields, $requestData, $method = 'GET') {
    $sanitizedData = [];
    
    // Log for debugging
    error_log("Validating " . $method . " request fields: " . json_encode($requestData));
    
    // Check for unexpected fields
    $unexpectedFields = array_diff(array_keys($requestData), array_merge($allowedFields, [$method . '_token']));
    if (!empty($unexpectedFields)) {
        error_log("WARNING: Unexpected " . $method . " fields detected: " . implode(', ', $unexpectedFields));
    }
    
    // Extract and sanitize only allowed fields
    foreach ($allowedFields as $field) {
        if (isset($requestData[$field])) {
            // Basic sanitization - implement more specific sanitization as needed
            $sanitizedData[$field] = is_string($requestData[$field]) ? 
                trim($requestData[$field]) : $requestData[$field];
        }
    }
    
    return $sanitizedData;
}

// Define allowed fields for this endpoint
$allowedFields = ['currency_id', 'dataset_id', 'date'];

// Validate and sanitize request fields
$sanitizedData = validateRequestFields($allowedFields, $_GET, 'GET');

// Check if required parameters are provided
if (!isset($sanitizedData['currency_id']) || !isset($sanitizedData['dataset_id'])) {
    $response['message'] = 'Missing required parameters';
    echo json_encode($response);
    exit;
}

$currency_id = trim($sanitizedData['currency_id']);
$dataset_id = trim($sanitizedData['dataset_id']);
$date = isset($sanitizedData['date']) ? trim($sanitizedData['date']) : date('Y-m-d');

// Check if dataset_id matches the active dataset
if (!isset($_SESSION['active_dataset']) || $_SESSION['active_dataset']['id'] !== $dataset_id) {
    $response['message'] = 'Invalid dataset';
    echo json_encode($response);
    exit;
}

// Include database configuration
include "layouts/config.php";

// Create PDO connection
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";port=" . DB_PORT,
        DB_USER,
        DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    $response['message'] = 'Database connection error';
    echo json_encode($response);
    exit;
}

// Fetch currency rate for the specific date
try {
    // Verify that the currency exists and belongs to this dataset
    $stmt = $pdo->prepare("SELECT id, code FROM currencies WHERE id = ? AND dataset_id = ?");
    $stmt->execute([$currency_id, $dataset_id]);
    $currency = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$currency) {
        $response['message'] = 'Currency not found or access denied';
        echo json_encode($response);
        exit;
    }
    
    // Get rate for the specific date or closest previous date
    $stmt = $pdo->prepare("
        SELECT rate_date, rate
        FROM currency_rates
        WHERE currency_id = ? AND dataset_id = ? AND rate_date <= ?
        ORDER BY rate_date DESC
        LIMIT 1
    ");
    $stmt->execute([$currency_id, $dataset_id, $date]);
    $rate = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Return success response
    $response['status'] = 'success';
    $response['currency_code'] = $currency['code'];
    $response['date'] = $date;
    
    if ($rate) {
        $response['rate'] = $rate['rate'];
        $response['actual_date'] = $rate['rate_date'];
    } else {
        $response['rate'] = null;
        $response['actual_date'] = null;
    }
    
    echo json_encode($response);
    
} catch (PDOException $e) {
    error_log("Error fetching currency rate: " . $e->getMessage());
    $response['message'] = 'Database error occurred while fetching currency rate';
    echo json_encode($response);
    exit;
} 
 
 
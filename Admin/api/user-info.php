<?php
/**
 * User Info API Endpoint
 * 
 * This API endpoint returns information about the authenticated user
 * It requires a valid JWT token for authentication
 */

// Set content type to JSON
header('Content-Type: application/json');

// Enable CORS for API requests (modify as needed for your environment)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// For preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Include required files
require_once "../layouts/config.php";
require_once "../layouts/api-auth.php";

// Initialize API authentication
$auth = new APIAuth();

// Require authentication for this endpoint
$user = $auth->requireAuth();

// Get user details from database
try {
    // Prepare database query to get complete user information
    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.email, u.role_id, r.name as role_name, u.preferred_language, 
        u.is_active, u.created_at, u.last_login
        FROM users u
        LEFT JOIN roles r ON u.role_id = r.id
        WHERE u.id = ?
    ");
    
    $stmt->execute([$user['user_id']]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$userData) {
        // User not found in database
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'User not found',
            'error' => 'The specified user could not be found'
        ]);
        exit;
    }
    
    // Get user permissions
    $permissions_stmt = $pdo->prepare("
        SELECT p.description_eng 
        FROM permissions p 
        JOIN permissions_per_role ppr ON p.id = ppr.permission_id 
        WHERE ppr.role_id = ? 
        AND ppr.is_granted = TRUE
    ");
    $permissions_stmt->execute([$userData['role_id']]);
    $permissions = $permissions_stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Add permissions to user data
    $userData['permissions'] = $permissions;
    
    // Get user datasets
    $datasets_stmt = $pdo->prepare("
        SELECT d.* 
        FROM datasets d
        JOIN user_datasets ud ON d.id = ud.dataset_id
        WHERE ud.user_id = ?
    ");
    $datasets_stmt->execute([$userData['id']]);
    $datasets = $datasets_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Add datasets to user data
    $userData['datasets'] = $datasets;
    
    // Remove sensitive information
    unset($userData['password_hash']);
    
    // Return user data
    echo json_encode([
        'success' => true,
        'user' => $userData
    ]);
    
} catch (PDOException $e) {
    // Database error
    error_log("API error in user-info.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error',
        'error' => 'An internal server error occurred'
    ]);
}
?> 
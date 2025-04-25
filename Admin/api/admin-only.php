<?php
/**
 * Admin-Only API Endpoint
 * 
 * This API endpoint provides data only accessible to administrators
 * It requires a valid JWT token and admin role (role_id = 1)
 */

// Set content type to JSON
header('Content-Type: application/json');

// Enable CORS for API requests
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

// Require authentication with admin role (role_id = 1)
$auth->requireRole(1);

// Simulated sensitive data only admins should see
$adminData = [
    'system_stats' => [
        'total_users' => 125,
        'active_users' => 98,
        'inactive_users' => 27,
        'total_datasets' => 15,
        'total_properties' => 42,
        'total_units' => 356,
        'total_leases' => 289,
        'database_size' => '42.7 MB'
    ],
    'recent_activities' => [
        [
            'action' => 'User created',
            'user_name' => 'John Smith',
            'timestamp' => '2023-05-15 14:32:18',
            'ip_address' => '192.168.1.25'
        ],
        [
            'action' => 'Role modified',
            'user_name' => 'Admin',
            'timestamp' => '2023-05-15 11:18:45',
            'ip_address' => '192.168.1.1'
        ],
        [
            'action' => 'Dataset deleted',
            'user_name' => 'Jane Doe',
            'timestamp' => '2023-05-14 16:05:22',
            'ip_address' => '192.168.1.18'
        ],
        [
            'action' => 'Settings changed',
            'user_name' => 'Admin',
            'timestamp' => '2023-05-13 09:42:11',
            'ip_address' => '192.168.1.1'
        ]
    ],
    'server_info' => [
        'php_version' => PHP_VERSION,
        'server_software' => $_SERVER['SERVER_SOFTWARE'],
        'database_type' => 'MySQL',
        'database_version' => $pdo->query('SELECT VERSION()')->fetchColumn(),
        'environment' => 'Production',
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time')
    ]
];

// Return the admin data
echo json_encode([
    'success' => true,
    'message' => 'Access granted to admin area',
    'data' => $adminData
]);
?> 
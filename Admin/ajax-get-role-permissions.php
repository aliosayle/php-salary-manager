<?php
// Set up error reporting
ini_set('display_errors', 0);
error_reporting(0);
ini_set('error_log', __DIR__ . '/layouts/logs/error.log');

// Start a PHP session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include required files
require_once "layouts/config.php";

// Check if user is logged in
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    error_log("AJAX-get-role-permissions - Not logged in");
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

// Log request for debugging
error_log("AJAX-get-role-permissions - Request from user: " . ($_SESSION['user_id'] ?? 'Unknown'));

// Validate request
if (!isset($_GET['role_id']) || empty($_GET['role_id'])) {
    error_log("AJAX-get-role-permissions - Missing role ID");
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Missing role ID']);
    exit;
}

$role_id = $_GET['role_id'];
error_log("AJAX-get-role-permissions - Getting permissions for role: " . $role_id);

try {
    // Get role permissions from the role_permissions table
    $stmt = $pdo->prepare("SELECT permission_id FROM role_permissions WHERE role_id = ?");
    $stmt->execute([$role_id]);
    $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
    error_log("AJAX-get-role-permissions - Found " . count($permissions) . " permissions");
    
    // If no permissions, check if this is an admin role and create default permissions
    if (empty($permissions)) {
        $stmt = $pdo->prepare("SELECT name FROM roles WHERE id = ?");
        $stmt->execute([$role_id]);
        $role = $stmt->fetch(PDO::FETCH_ASSOC);
    
        // If this is an administrator role, add all permissions
        if ($role && $role['name'] === 'Administrator') {
            error_log("AJAX-get-role-permissions - Admin role detected, getting all permissions");
            $stmt = $pdo->query("SELECT id FROM permissions");
            $allPermissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Add all permissions to the admin role
            if (!empty($allPermissions)) {
                error_log("AJAX-get-role-permissions - Adding " . count($allPermissions) . " permissions to admin role");
                $pdo->beginTransaction();
                
                $stmt = $pdo->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
                foreach ($allPermissions as $permission_id) {
                    $stmt->execute([$role_id, $permission_id]);
                }
                
                $pdo->commit();
                
                // Fetch the permissions again
                $stmt = $pdo->prepare("SELECT permission_id FROM role_permissions WHERE role_id = ?");
                $stmt->execute([$role_id]);
                $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
                error_log("AJAX-get-role-permissions - Now role has " . count($permissions) . " permissions");
    }
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'permissions' => $permissions
    ]);
} catch (Exception $e) {
    error_log("AJAX-get-role-permissions - Error: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching permissions: ' . $e->getMessage()
    ]);
}
?> 
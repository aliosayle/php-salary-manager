<?php
// Start a PHP session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// Include required files
include "../layouts/config.php";

// Check if role_id is provided
if (!isset($_GET['role_id']) || empty($_GET['role_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Role ID is required']);
    exit;
}

$role_id = $_GET['role_id'];

try {
    // Get the permissions for the role
    $stmt = $pdo->prepare("
        SELECT p.action 
        FROM role_permissions rp
        INNER JOIN permissions p ON rp.permission_id = p.id
        WHERE rp.role_id = ?
    ");
    $stmt->execute([$role_id]);
    
    $permissions = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $permissions[] = $row['action'];
    }
    
    // Return the permissions as JSON
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'permissions' => $permissions]);
} catch (PDOException $e) {
    // Return error message
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?> 
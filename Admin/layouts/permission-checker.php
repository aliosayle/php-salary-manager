<?php
/**
 * Permission checker helper functions
 * This file provides utility functions for checking user permissions
 */

// Check if function already exists before declaring it
if (!function_exists('hasPermission')) {
    /**
     * Check if the current user has a specific permission
     * 
     * @param string $permission The permission to check for
     * @return bool Whether the user has the permission
     */
    function hasPermission($permission) {
        if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
            return false;
        }
        
        // Check if permission exists in session cache
        if (isset($_SESSION['permissions']) && is_array($_SESSION['permissions'])) {
            return in_array($permission, $_SESSION['permissions']);
        }
        
        return false;
    }
}

/**
 * Ensure the user has the required permission or redirect to dashboard
 * 
 * @param string $action The permission action required
 * @param string $redirect_url URL to redirect to if permission denied (default: index.php)
 */
function requirePermission($action, $redirect_url = 'index.php') {
    if (!hasPermission($action)) {
        $_SESSION['error_message'] = "You don't have permission to access this page.";
        header("Location: " . $redirect_url);
        exit;
    }
}

/**
 * Load all permissions for a user into session
 *
 * @param int $user_id User ID
 * @param int $role_id Role ID
 * @return array Array of permission actions
 */
function loadUserPermissions($user_id, $role_id) {
    global $pdo;
    $permissions = [];
    
    try {
        $stmt = $pdo->prepare("
            SELECT p.action 
            FROM permissions p
            JOIN role_permissions rp ON p.id = rp.permission_id
            WHERE rp.role_id = ?
        ");
        $stmt->execute([$role_id]);
        $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Store in session for faster access
        $_SESSION['permissions'] = $permissions;
        
    } catch (Exception $e) {
        error_log("Error loading permissions: " . $e->getMessage());
    }
    
    return $permissions;
}
?> 
<?php
// Include required files
require_once "config.php";
require_once "helpers.php";
require_once "permission-checker.php";

// Create logs directory if it doesn't exist
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// Set up custom error logging to file
$logFile = $logDir . '/error.log';
ini_set('error_log', $logFile);

// For debugging
error_log("Session check started. URI: " . $_SERVER['REQUEST_URI']);

// Start PHP session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Log session data for debugging
if (isset($_SESSION) && !empty($_SESSION)) {
    error_log("Session data: " . print_r($_SESSION, true));
}

// Skip session check for login page and assets
$current_page = basename($_SERVER['PHP_SELF']);
$skip_auth_check = [
    'auth-login.php',
    'auth-login-debug.php',
    'auth-recoverpw.php',
    'auth-register.php',
    'create-admin.php',
    'create-admin-now.php',
    'init-database.php',
    'test-db.php',
    'test-db-cli.php',
    'test-init.php',
    'permissions-fix.php'
];

// Skip authentication check for certain pages
if (in_array($current_page, $skip_auth_check)) {
    error_log("Auth check skipped for: " . $current_page);
    return;
}

// Check if this script is included by another script that's already in the skip list
$backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
if (isset($backtrace[0]['file'])) {
    $calling_script = basename($backtrace[0]['file']);
    if (in_array($calling_script, $skip_auth_check)) {
        error_log("Auth check skipped for included file: " . $calling_script);
        return;
    }
}

// Simple session check - if user is not logged in, redirect to login
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    // Store the current URL for redirect after login
    if (!isset($_SESSION['redirect_url']) && !strpos($_SERVER['REQUEST_URI'], 'auth-login.php')) {
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    }
    
    error_log("Session invalid, redirecting to login page. Current page: " . $current_page);
    
    // Clear the session
    session_unset();
    session_destroy();
    
    header("location: " . ADMIN_URL . "/auth-login.php");
    exit;
}

// If we got here, session is valid and user is authenticated
error_log("Session valid for user: " . ($_SESSION['name'] ?? 'Unknown'));

// Remove dataset requirement since this system doesn't use datasets
// require_once __DIR__ . "/check-dataset.php";

/**
 * This function is kept for backward compatibility
 * Uses the improved hasPermission() function from permission-checker.php
 * 
 * @param string $permissionName The name of the permission to check
 * @return bool Whether the user has the permission
 */
function checkPermission($permissionName) {
    return hasPermission($permissionName);
}

/**
 * Log an action in the audit_logs table
 * 
 * @param string $action The action being performed (create, update, delete, etc.)
 * @param string $tableName The table being affected
 * @param int|null $recordId The ID of the record being affected
 * @param array|null $oldValues The old values of the record (for updates)
 * @param array|null $newValues The new values of the record (for creates/updates)
 * @return bool Whether the log was successfully created
 */
function logAction($action, $tableName, $recordId = null, $oldValues = null, $newValues = null) {
    global $pdo;
    
    try {
        $sql = "INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        
        $userId = $_SESSION['user_id'] ?? null;
        $ipAddress = getClientIP();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        // Convert arrays to JSON
        $oldValuesJson = $oldValues ? json_encode($oldValues) : null;
        $newValuesJson = $newValues ? json_encode($newValues) : null;
        
        $stmt->execute([
            $userId,
            $action,
            $tableName,
            $recordId,
            $oldValuesJson,
            $newValuesJson,
            $ipAddress,
            $userAgent
        ]);
        
        return true;
    } catch (PDOException $e) {
        error_log("Audit log error: " . $e->getMessage());
        return false;
    }
}
?>
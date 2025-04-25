<?php
// Include configuration and session manager files
require_once "layouts/config.php";
require_once "layouts/session-manager.php";
require_once "layouts/jwt-helper.php";

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set up error logging
$logDir = __DIR__ . '/layouts/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}
ini_set('error_log', $logDir . '/error.log');

// Debug information
error_log("Logout process started");
error_log("SESSION data: " . print_r($_SESSION, true));
error_log("COOKIE data: " . print_r($_COOKIE, true));

// Initialize session manager with database connection
$sessionManager = new SessionManager($pdo);

// Get the current session token - try multiple sources
$sessionToken = $_COOKIE['session_token'] ?? null;
$sessionTokenFromSession = $_SESSION['session_token'] ?? null;

error_log("Session token from cookie: " . ($sessionToken ?? 'not found'));
error_log("Session token from session: " . ($sessionTokenFromSession ?? 'not found'));

// Use session token from session if cookie token is not available
if (!$sessionToken && $sessionTokenFromSession) {
    $sessionToken = $sessionTokenFromSession;
    error_log("Using session token from SESSION instead of COOKIE");
}

if ($sessionToken) {
    // Get user ID before invalidating session
    $userId = $_SESSION['user_id'] ?? null;
    
    // Check if this token is in the database and active
    try {
        $stmt = $pdo->prepare("SELECT id, is_active FROM sessions WHERE session_token = ?");
        $stmt->execute([$sessionToken]);
        $sessionData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($sessionData) {
            error_log("Found session in database: ID=" . $sessionData['id'] . ", is_active=" . $sessionData['is_active']);
            
            // Invalidate the session by token
            $result = $sessionManager->invalidateSessionByToken($sessionToken);
            error_log("Session invalidation result: " . ($result ? "SUCCESS" : "FAILED"));
            
            // Double-check if invalidation worked
            $stmt = $pdo->prepare("SELECT is_active FROM sessions WHERE session_token = ?");
            $stmt->execute([$sessionToken]);
            $checkData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($checkData) {
                error_log("After invalidation, session is_active=" . $checkData['is_active']);
            } else {
                error_log("Could not find session after invalidation attempt");
            }
        } else {
            error_log("Session token not found in database: " . $sessionToken);
        }
    } catch (Exception $e) {
        error_log("Error checking session before logout: " . $e->getMessage());
    }
} else {
    error_log("No session token found in cookies or session");
}

// Clear all session variables
$_SESSION = array();

// Clear the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Clear the session token cookie
setcookie('session_token', '', time() - 3600, '/', '', true, true);

// Clear the JWT token cookie
$jwtHelper = new JWTHelper();
$jwtHelper->clearTokenCookie();
error_log("JWT token cookie cleared");

// Destroy the PHP session
session_destroy();

error_log("Logout completed");

// Redirect to login page
header("location: auth-login.php");
exit;
?>
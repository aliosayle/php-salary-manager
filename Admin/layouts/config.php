<?php
/**
 * Configuration File for Employee Manager System
 */

// Error reporting settings - Enable for debugging
ini_set('display_errors', 1); 
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Define base paths
define('BASE_PATH', dirname(dirname(__FILE__))); // Base path for the project
define('ADMIN_PATH', BASE_PATH . '/Admin'); // Admin directory path

// Database configuration
define('DB_HOST', 'localhost'); // Database host
define('DB_NAME', 'salarymanager'); // Database name
define('DB_USER', 'root'); // Database username
define('DB_PASS', 'goldfish@2025'); // Database password - Update with your password if required
define('DB_PORT', '3306'); // Database port

// Security settings
define('SESSION_LIFETIME', 3600); // Session lifetime in seconds (1 hour)
define('CSRF_TOKEN_EXPIRY', 3600); // CSRF token expiry in seconds (1 hour)
define('MAX_LOGIN_ATTEMPTS', 5); // Maximum login attempts before account lock
define('PASSWORD_RESET_EXPIRY', 86400); // Password reset token expiry in seconds (24 hours)
define('JWT_SECRET_KEY', 'YVDkmCT7QdRfILkF3jHc9eJlN8SzGn1P6uBXsb0vwtyp4ZUMoW'); // Secret key for JWT tokens - change this to a random strong value in production

// File upload settings
define('MAX_FILE_SIZE', 5242880); // Maximum file size for uploads (5MB)
define('ALLOWED_FILE_TYPES', ['jpg', 'jpeg', 'png', 'pdf', 'docx', 'xlsx']); // Allowed file types for uploads
define('UPLOAD_PATH', BASE_PATH . '/uploads'); // Path for file uploads

// Application settings
define('APP_NAME', 'Employee Manager System'); // Application name
define('APP_URL', 'http://localhost/salarymanager'); // Application URL
define('ADMIN_URL', APP_URL . '/Admin'); // Admin URL
define('DEFAULT_LANGUAGE', 'en'); // Default language
define('DEFAULT_TIMEZONE', 'UTC'); // Default timezone

// Set default timezone
date_default_timezone_set(DEFAULT_TIMEZONE);

// For session security
define('SECURE_SESSION', false);
define('SESSION_HTTP_ONLY', true);
define('SESSION_PATH', '/');
define('SESSION_DOMAIN', '');
define('PASSWORD_COST', 12);

// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    // Configure session settings first
    ini_set('session.use_only_cookies', 1);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', SECURE_SESSION);
    ini_set('session.cookie_path', SESSION_PATH);
    ini_set('session.cookie_domain', SESSION_DOMAIN);
    ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
    
    session_start();
}

// Create a PDO connection to the database
try {
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    // Log and display error for debugging
    error_log("Database connection error: " . $e->getMessage());
    die("Database connection failed: " . $e->getMessage());
}

/**
 * Sanitize user input to prevent XSS attacks
 * 
 * @param string $input The input to sanitize
 * @return string The sanitized input
 */
function sanitizeInput($input) {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

/**
 * Check if user has specific permission
 *
 * @param string $permissionAction The permission action to check
 * @param string $userId The user ID to check permissions for
 * @return bool Whether the user has the permission
 */
if (!function_exists('hasPermission')) {
    function hasPermission($permissionAction, $userId = null) {
        global $pdo;
        
        // If userId is not provided, use the current logged-in user
        if ($userId === null) {
            if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
                return false;
            }
            $userId = $_SESSION['user_id'];
        }
        
        // Check if user is admin - admins have all permissions
        $stmt = $pdo->prepare("SELECT r.name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
        $stmt->execute([$userId]);
        $role = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($role && $role['name'] === 'Administrator') {
            return true;
        }
        
        // If session has permissions array, check it first
        if (isset($_SESSION['permissions']) && is_array($_SESSION['permissions'])) {
            return in_array($permissionAction, $_SESSION['permissions']);
        }
        
        $query = "SELECT COUNT(*) FROM users 
                  JOIN role_permissions ON users.role_id = role_permissions.role_id
                  JOIN permissions ON role_permissions.permission_id = permissions.id
                  WHERE users.id = ? AND permissions.action = ? AND users.is_active = 1";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$userId, $permissionAction]);
        
        return $stmt->fetchColumn() > 0;
    }
}

$gmailid = ''; // YOUR gmail email
$gmailpassword = ''; // YOUR gmail password
$gmailusername = ''; // YOUR gmail User name

?>
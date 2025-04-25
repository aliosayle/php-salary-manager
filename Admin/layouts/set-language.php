<?php
// Initialize the session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Validate language parameter
$allowedLanguages = ['en', 'fr'];
$lang = isset($_GET['lang']) && in_array($_GET['lang'], $allowedLanguages) ? $_GET['lang'] : 'en';

// Set the language in the session
$_SESSION['lang'] = $lang;

// If user is logged in, update their preferred language in the database
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true && isset($_SESSION['user_id'])) {
    // Include database configuration
    require_once __DIR__ . '/config.php';
    
    try {
        // Create PDO connection
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";port=" . DB_PORT,
            DB_USER,
            DB_PASS,
            array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
        );
        
        // Update the user's preferred language
        $stmt = $pdo->prepare("UPDATE users SET preferred_language = ? WHERE id = ?");
        $stmt->execute([$lang, $_SESSION['user_id']]);
        
        // Log the language update
        error_log("Updated preferred language to {$lang} for user ID: {$_SESSION['user_id']}");
    } catch (PDOException $e) {
        // Log the error but continue
        error_log("Error updating preferred language: " . $e->getMessage());
    }
}

// Get the redirect URL (default to index.php)
$redirect = isset($_GET['redirect']) ? $_GET['redirect'] : '../index.php';

// Sanitize the redirect URL to prevent open redirect vulnerability
// Only allow redirects to pages within the Admin directory
if (!preg_match('/^(\/[a-zA-Z0-9\-_\/\.]+\.php(\?.*)?)?$/', $redirect)) {
    $redirect = '../index.php';
}

// Redirect back to the previous page or to the default
header("Location: " . $redirect);
exit; 
 
 
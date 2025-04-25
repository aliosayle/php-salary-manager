<?php
// Initialize session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include translation system with appropriate path handling
$translations_file = __DIR__ . "/translations.php";

// Check if the file exists in expected location, if not try alternate path
if (!file_exists($translations_file)) {
    // Try a relative path for when accessed from different directories
    $translations_file = "layouts/translations.php";
    
    // Handle the case where the file might be in a parent directory
    if (!file_exists($translations_file)) {
        $translations_file = "../layouts/translations.php";
    }
}

require_once $translations_file;

// Set default language to English if not set
if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = "en";
}

// Get current language
$lang = $_SESSION['lang'];
?>
<!DOCTYPE html>
<html lang="<?php echo $lang ?>">
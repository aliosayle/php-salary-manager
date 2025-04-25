<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set a message and redirect
$_SESSION['info_message'] = "The bonus calculation feature has been removed from the system.";
header("Location: index.php");
exit;
?>
<?php
// Start a PHP session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: auth-login.php");
    exit;
}

// Set a session variable to trigger the modal on the recommenders page
$_SESSION['open_recommender_modal'] = true;

// Redirect to recommenders page
header("location: recommenders.php");
exit;
?> 
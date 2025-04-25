<?php
/**
 * Helper Functions for Rental Management System
 * 
 * This file contains helper functions used throughout the application
 */

/**
 * Create a secure password hash
 * 
 * @param string $password The password to hash
 * @return string The hashed password
 */
/*
function hashPassword($password) {
    return password_hash($password, PASSWORD_ARGON2ID, [
        'memory_cost' => 65536, // 64MB
        'time_cost'   => 4,
        'threads'     => 3,
    ]);
}
*/

/**
 * Verify a password against a hash
 * 
 * @param string $password The password to verify
 * @param string $hash The hash to verify against
 * @return bool Whether the password matches the hash
 */
/*
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}
*/

/**
 * Generate a secure random token
 * 
 * @param int $length Length of the token (default: 32)
 * @return string The generated token
 */
// function already defined in config.php
/*
function generateSecureToken($length = 32) {
    return bin2hex(random_bytes($length / 2));
}
*/

/**
 * Sanitize user input to prevent XSS attacks
 * 
 * @param string $input The input to sanitize
 * @return string The sanitized input
 */
// function already defined in config.php 
/*
function sanitizeInput($input) {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}
*/

/**
 * Format a date to a specific format
 * 
 * @param string $date The date to format
 * @param string $format The format to use (default: Y-m-d H:i:s)
 * @return string The formatted date
 */
function formatDate($date, $format = 'Y-m-d') {
    return date($format, strtotime($date));
}

/**
 * Check if a string starts with a specific substring
 * 
 * @param string $haystack The string to search in
 * @param string $needle The substring to search for
 * @return bool Whether the string starts with the substring
 */
function startsWith($haystack, $needle) {
    return substr($haystack, 0, strlen($needle)) === $needle;
}

/**
 * Check if a string ends with a specific substring
 * 
 * @param string $haystack The string to search in
 * @param string $needle The substring to search for
 * @return bool Whether the string ends with the substring
 */
function endsWith($haystack, $needle) {
    return substr($haystack, -strlen($needle)) === $needle;
}

/**
 * Log errors to file
 * 
 * @param string $message The error message
 * @param string $level The error level (default: ERROR)
 * @return void
 */
function logError($message, $level = 'ERROR') {
    $logFile = __DIR__ . '/../logs/error.log';
    $logDir = dirname($logFile);
    
    // Create logs directory if it doesn't exist
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message" . PHP_EOL;
    
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

/**
 * Get the client's IP address
 * 
 * @return string The client's IP address
 */
function getClientIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    
    // Sanitize IP (remove port if present)
    return preg_replace('/:\d+$/', '', $ip);
}

/**
 * Create a URL-friendly slug from a string
 * 
 * @param string $string The string to convert
 * @return string The slug
 */
function createSlug($string) {
    // Replace non-alphanumeric characters with dashes
    $slug = preg_replace('/[^A-Za-z0-9-]+/', '-', $string);
    // Convert to lowercase
    $slug = strtolower($slug);
    // Remove leading/trailing dashes
    $slug = trim($slug, '-');
    // Replace multiple dashes with a single dash
    $slug = preg_replace('/-+/', '-', $slug);
    
    return $slug;
}

/**
 * Format a number as currency
 * 
 * @param float $amount Amount to format
 * @param int $decimals Number of decimal places
 * @return string Formatted currency amount
 */
function formatCurrency($amount, $decimals = 2) {
    return getCurrencySymbol() . ' ' . number_format($amount, $decimals);
}

/**
 * Get the currency symbol from settings or use default
 * 
 * @return string Currency symbol
 */
function getCurrencySymbol() {
    global $pdo;
    
    // Try to get from settings table
    try {
        $stmt = $pdo->prepare("SELECT value FROM settings WHERE name = 'currency_symbol'");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && !empty($result['value'])) {
            return $result['value'];
        }
    } catch (PDOException $e) {
        // Fallback to default if there's an error
    }
    
    // Default currency symbol
    return '$';
}

/**
 * Format a number as percentage
 * 
 * @param float $value The value to format
 * @param int $decimals The number of decimal places (default: 2)
 * @return string The formatted percentage
 */
function formatPercentage($value, $decimals = 2) {
    return number_format($value * 100, $decimals, '.', ',') . '%';
}

/**
 * Convert a string to title case
 * 
 * @param string $string The string to convert
 * @return string The converted string
 */
function toTitleCase($string) {
    return ucwords(strtolower($string));
}

/**
 * Truncate a string to a specific length
 * 
 * @param string $string The string to truncate
 * @param int $length The maximum length (default: 100)
 * @param string $append The string to append if truncated (default: ...)
 * @return string The truncated string
 */
function truncateString($string, $length = 100, $append = '...') {
    if (strlen($string) <= $length) {
        return $string;
    }
    
    return substr($string, 0, $length) . $append;
}

/**
 * Get a list of months (1-12) with their names
 * 
 * @return array Associative array of month numbers and names
 */
function getMonthsList() {
    $months = [];
    for ($i = 1; $i <= 12; $i++) {
        $months[$i] = date('F', mktime(0, 0, 0, $i, 1));
    }
    return $months;
}

/**
 * Get a list of years from current year to a number of years back
 * 
 * @param int $yearsBack Number of years to go back from current year
 * @return array Array of years
 */
function getYearsList($yearsBack = 4) {
    $years = [];
    $currentYear = (int)date('Y');
    for ($i = $currentYear; $i >= ($currentYear - $yearsBack); $i--) {
        $years[] = $i;
    }
    return $years;
}

/**
 * Get month name from month number
 * 
 * @param int $monthNum Month number (1-12)
 * @return string Month name
 */
function getMonthName($monthNum) {
    return date('F', mktime(0, 0, 0, $monthNum, 1));
}

/**
 * Check if a user has a specific permission
 * 
 * @param string $permission Permission to check
 * @return bool True if user has permission, false otherwise
 */
if (!function_exists('hasPermission')) {
    function hasPermission($permission) {
        // If not logged in, no permissions
        if (!isset($_SESSION['user_id'])) {
            return false;
        }
        
        // Admin users have all permissions
        if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1) {
            return true;
        }
        
        // Check if permission exists in user's permissions array
        if (isset($_SESSION['permissions']) && is_array($_SESSION['permissions'])) {
            return in_array($permission, $_SESSION['permissions']);
        }
        
        return false;
    }
}

/**
 * Check if a specific month is open for editing
 * 
 * @param int $month Month number (1-12)
 * @param int $year Year (e.g., 2023)
 * @return bool True if month is open, false otherwise
 */
function isMonthOpen($month, $year) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM open_months WHERE month = :month AND year = :year");
        $stmt->execute([
            ':month' => $month,
            ':year' => $year
        ]);
        
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        // Log error and return false
        error_log("Error checking if month is open: " . $e->getMessage());
        return false;
    }
}

/**
 * Get the grade color class based on rating value
 * 
 * @param float $rating Rating value
 * @return string CSS class name
 */
function getGradeColorClass($rating) {
    if ($rating >= 4.5) {
        return 'bg-success'; // Excellent
    } elseif ($rating >= 3.5) {
        return 'bg-info'; // Good
    } elseif ($rating >= 2.5) {
        return 'bg-warning'; // Average
    } elseif ($rating >= 1.5) {
        return 'bg-warning text-dark'; // Below Average
    } else {
        return 'bg-danger'; // Poor
    }
}

/**
 * Get first day of a month
 * 
 * @param int $month Month number (1-12)
 * @param int $year Year (e.g., 2023)
 * @return string Date in YYYY-MM-DD format
 */
function getFirstDayOfMonth($month, $year) {
    return date('Y-m-d', mktime(0, 0, 0, $month, 1, $year));
}

/**
 * Get last day of a month
 * 
 * @param int $month Month number (1-12)
 * @param int $year Year (e.g., 2023)
 * @return string Date in YYYY-MM-DD format
 */
function getLastDayOfMonth($month, $year) {
    return date('Y-m-t', mktime(0, 0, 0, $month, 1, $year));
} 
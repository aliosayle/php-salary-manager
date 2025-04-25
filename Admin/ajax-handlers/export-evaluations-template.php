<?php
// Start output buffering
ob_start();

// Start a PHP session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("HTTP/1.1 401 Unauthorized");
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Include required files
require_once "../layouts/config.php";
require_once "../layouts/helpers.php";
require_once "../layouts/session.php";
require_once "../layouts/translations.php";

// Check if the user has permission to access this page
requirePermission('manage_employees');

// Get request parameters
$month = isset($_GET['month']) ? intval($_GET['month']) : date('m');
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$shopId = isset($_GET['shop_id']) ? $_GET['shop_id'] : '';

// Create CSV output
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="evaluations-template-' . date('F-Y', mktime(0, 0, 0, $month, 1, $year)) . '.csv"');

// Output directly to the browser
$output = fopen('php://output', 'w');

// Get employee data
try {
    $sql = "
        SELECT e.id, e.full_name, e.reference, p.title as position, s.name as shop_name, 
            ev.attendance, ev.cleanliness, ev.unloading, ev.sales, 
            ev.order_management, ev.stock_sheet, ev.inventory,
            ev.machine_and_team, ev.management, ev.ranking, ev.total_score
        FROM employees e
        INNER JOIN posts p ON e.post_id = p.id
        INNER JOIN employee_shops es ON e.id = es.employee_id
        INNER JOIN shops s ON es.shop_id = s.id
        LEFT JOIN (
            SELECT * FROM employee_evaluations 
            WHERE MONTH(evaluation_month) = ? AND YEAR(evaluation_month) = ?
        ) ev ON e.id = ev.employee_id
        WHERE e.post_id = '04b5ce3e-1aaf-11f0-99a1-cc28aa53b74d'
    ";
    
    $params = [$month, $year];
    
    if (!empty($shopId)) {
        $sql .= " AND es.shop_id = ?";
        $params[] = $shopId;
    }
    
    $sql .= " ORDER BY s.name, e.full_name";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Write headers with instructions in the first row
    fputcsv($output, ['** DO NOT MODIFY THE ID, NAME, REFERENCE, POSITION OR SHOP COLUMNS **']);
    
    // Write headers for the column names
    fputcsv($output, [
        'ID', 'Employee Name', 'Reference', 'Position', 'Shop',
        'Attendance (0-10)', 'Cleanliness (0-10)', 'Unloading (0-10)', 'Sales (0-10)', 
        'Order Management (0-10)', 'Stock Sheet (0-10)', 'Inventory (0-10)',
        'Team Management (0-10)', 'Management (0-10)', 'Ranking (0-10)'
    ]);
    
    // Write instructions for scoring in the third row
    fputcsv($output, ['** SCORING GUIDE: 8-10 Excellent, 6-7 Good, 4-5 Average, 1-3 Needs Improvement **']);
    
    // Add empty row
    fputcsv($output, []);
    
    // Write employee data
    foreach ($employees as $employee) {
        fputcsv($output, [
            $employee['id'], 
            $employee['full_name'], 
            $employee['reference'], 
            $employee['position'], 
            $employee['shop_name'], 
            $employee['attendance'] ?? '', 
            $employee['cleanliness'] ?? '', 
            $employee['unloading'] ?? '', 
            $employee['sales'] ?? '', 
            $employee['order_management'] ?? '', 
            $employee['stock_sheet'] ?? '', 
            $employee['inventory'] ?? '', 
            $employee['machine_and_team'] ?? '', 
            $employee['management'] ?? '', 
            $employee['ranking'] ?? ''
        ]);
    }
    
    fclose($output);
    
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Error exporting data: ' . $e->getMessage()]);
}

exit;
?>
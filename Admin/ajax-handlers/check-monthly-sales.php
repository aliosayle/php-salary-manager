<?php
/**
 * AJAX handler to check if a monthly sales record already exists
 */
require_once '../layouts/session.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

// Check permission
if (!hasPermission('manage_monthly_sales')) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'No permission']);
    exit;
}

// Get parameters
$shopId = isset($_GET['shop_id']) ? $_GET['shop_id'] : '';
$month = isset($_GET['month']) ? $_GET['month'] : '';
$year = isset($_GET['year']) ? $_GET['year'] : '';
$ignoreId = isset($_GET['ignore_id']) ? $_GET['ignore_id'] : '';

if (empty($shopId) || empty($month) || empty($year)) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

// Format the sales month
$salesMonth = $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . '-01';

try {
    // Prepare the query with potential ID exclusion
    $sql = "SELECT id, sales_amount FROM monthly_sales WHERE shop_id = ? AND sales_month = ?";
    $params = [$shopId, $salesMonth];
    
    if (!empty($ignoreId)) {
        $sql .= " AND id != ?";
        $params[] = $ignoreId;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    if ($stmt->rowCount() > 0) {
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        header('Content-Type: application/json');
        echo json_encode([
            'exists' => true,
            'sales_amount' => $result['sales_amount']
        ]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['exists' => false]);
    }
} catch (PDOException $e) {
    error_log("Error checking monthly sales: " . $e->getMessage());
    
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database error']);
} 
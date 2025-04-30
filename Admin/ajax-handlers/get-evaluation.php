<?php
// Include required files
require_once "../layouts/config.php";
require_once "../layouts/helpers.php";
require_once "../layouts/session.php";

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Check permission
if (!hasPermission('manage_employees')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

// Check if evaluation ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Evaluation ID is required']);
    exit;
}

$evaluationId = $_GET['id'];

try {
    // Get evaluation data
    $stmt = $pdo->prepare("
        SELECT 
            e.id,
            e.employee_id,
            e.evaluation_month,
            e.ranking,
            e.attendance,
            e.cleanliness,
            e.unloading,
            e.sales,
            e.order_management,
            e.stock_sheet,
            e.inventory,
            e.machine_and_team,
            e.management,
            e.total_score,
            e.bonus_amount,
            e.created_at,
            emp.full_name as employee_name,
            p.title as position,
            s.name as shop_name,
            emp.base_salary
        FROM employee_evaluations e
        JOIN employees emp ON e.employee_id = emp.id
        LEFT JOIN posts p ON emp.post_id = p.id
        LEFT JOIN employee_shops es ON emp.id = es.employee_id
        LEFT JOIN shops s ON es.shop_id = s.id
        WHERE e.id = ?
    ");
    $stmt->execute([$evaluationId]);
    
    if ($stmt->rowCount() == 0) {
        echo json_encode(['success' => false, 'message' => 'Evaluation not found']);
        exit;
    }
    
    $evaluation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // If bonus_amount is not set in database, calculate it from total_ranges based on total score
    if (empty($evaluation['bonus_amount'])) {
        $bonusStmt = $pdo->prepare("
            SELECT amount 
            FROM total_ranges 
            WHERE ? BETWEEN min_value AND max_value 
            LIMIT 1
        ");
        $bonusStmt->execute([$evaluation['total_score']]);
        $bonusResult = $bonusStmt->fetch(PDO::FETCH_ASSOC);
        
        // Add bonus amount to evaluation data
        $evaluation['bonus_amount'] = $bonusResult ? $bonusResult['amount'] : 0;
    }
    
    echo json_encode([
        'success' => true,
        'evaluation' => $evaluation
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit;
}
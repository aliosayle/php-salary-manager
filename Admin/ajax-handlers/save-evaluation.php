<?php
require_once '../layouts/session.php';
require_once '../layouts/config.php';

if (!canManageEmployees()) {
    echo json_encode(['success' => false, 'message' => 'You do not have permission to manage employee evaluations']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $employeeId = $_POST['employee_id'] ?? '';
    $evaluationMonth = $_POST['evaluation_month'] ?? '';
    $ranking = $_POST['ranking'] ?? 0;
    $attendance = $_POST['attendance'] ?? 0;
    $cleanliness = $_POST['cleanliness'] ?? 0;
    $unloading = $_POST['unloading'] ?? 0;
    $sales = $_POST['sales'] ?? 0;
    $orderManagement = $_POST['order_management'] ?? 0;
    $stockSheet = $_POST['stock_sheet'] ?? 0;
    $inventory = $_POST['inventory'] ?? 0;
    $machineAndTeam = $_POST['machine_and_team'] ?? 0;
    $totalScore = $_POST['total_score'] ?? 0;
    $comments = $_POST['comments'] ?? '';
    
    // Validation
    if (empty($employeeId) || empty($evaluationMonth)) {
        echo json_encode(['success' => false, 'message' => 'Employee ID and evaluation month are required']);
        exit;
    }
    
    try {
        $conn = new PDO("mysql:host=$dbHost;dbname=$dbName", $dbUser, $dbPass);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Calculate bonus amount based on total score
        $bonusAmount = 0;
        $bonusStmt = $conn->prepare("SELECT amount FROM total_ranges WHERE ? BETWEEN min_value AND max_value LIMIT 1");
        $bonusStmt->execute([$totalScore]);
        $bonusResult = $bonusStmt->fetch(PDO::FETCH_ASSOC);
        if ($bonusResult) {
            $bonusAmount = $bonusResult['amount'];
        }
        
        // Check if evaluation already exists for this employee and month
        $checkStmt = $conn->prepare("SELECT id FROM employee_evaluations WHERE employee_id = :employee_id AND evaluation_month = :evaluation_month");
        $checkStmt->bindParam(':employee_id', $employeeId);
        $checkStmt->bindParam(':evaluation_month', $evaluationMonth);
        $checkStmt->execute();
        
        if ($existingEvaluation = $checkStmt->fetch(PDO::FETCH_ASSOC)) {
            // Update existing evaluation
            $updateStmt = $conn->prepare("
                UPDATE employee_evaluations SET 
                ranking = :ranking,
                attendance = :attendance,
                cleanliness = :cleanliness,
                unloading = :unloading,
                sales = :sales,
                order_management = :order_management,
                stock_sheet = :stock_sheet,
                inventory = :inventory,
                machine_and_team = :machine_and_team,
                total_score = :total_score,
                bonus_amount = :bonus_amount
                WHERE id = :id
            ");
            
            $updateStmt->bindParam(':ranking', $ranking);
            $updateStmt->bindParam(':attendance', $attendance);
            $updateStmt->bindParam(':cleanliness', $cleanliness);
            $updateStmt->bindParam(':unloading', $unloading);
            $updateStmt->bindParam(':sales', $sales);
            $updateStmt->bindParam(':order_management', $orderManagement);
            $updateStmt->bindParam(':stock_sheet', $stockSheet);
            $updateStmt->bindParam(':inventory', $inventory);
            $updateStmt->bindParam(':machine_and_team', $machineAndTeam);
            $updateStmt->bindParam(':total_score', $totalScore);
            $updateStmt->bindParam(':bonus_amount', $bonusAmount);
            $updateStmt->bindParam(':id', $existingEvaluation['id']);
            
            if ($updateStmt->execute()) {
                // Log the evaluation update
                logAction("Updated evaluation for employee ID: $employeeId for month: $evaluationMonth");
                echo json_encode(['success' => true, 'message' => 'Evaluation updated successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update evaluation']);
            }
        } else {
            // Generate UUID for new evaluation
            $evaluationId = generateUUID();
            
            // Insert new evaluation
            $insertStmt = $conn->prepare("
                INSERT INTO employee_evaluations (
                    id, 
                    employee_id, 
                    evaluation_month, 
                    ranking,
                    attendance,
                    cleanliness,
                    unloading,
                    sales,
                    order_management,
                    stock_sheet,
                    inventory,
                    machine_and_team,
                    total_score,
                    bonus_amount
                ) VALUES (
                    :id,
                    :employee_id,
                    :evaluation_month,
                    :ranking,
                    :attendance,
                    :cleanliness,
                    :unloading,
                    :sales,
                    :order_management,
                    :stock_sheet,
                    :inventory,
                    :machine_and_team,
                    :total_score,
                    :bonus_amount
                )
            ");
            
            $insertStmt->bindParam(':id', $evaluationId);
            $insertStmt->bindParam(':employee_id', $employeeId);
            $insertStmt->bindParam(':evaluation_month', $evaluationMonth);
            $insertStmt->bindParam(':ranking', $ranking);
            $insertStmt->bindParam(':attendance', $attendance);
            $insertStmt->bindParam(':cleanliness', $cleanliness);
            $insertStmt->bindParam(':unloading', $unloading);
            $insertStmt->bindParam(':sales', $sales);
            $insertStmt->bindParam(':order_management', $orderManagement);
            $insertStmt->bindParam(':stock_sheet', $stockSheet);
            $insertStmt->bindParam(':inventory', $inventory);
            $insertStmt->bindParam(':machine_and_team', $machineAndTeam);
            $insertStmt->bindParam(':total_score', $totalScore);
            $insertStmt->bindParam(':bonus_amount', $bonusAmount);
            
            if ($insertStmt->execute()) {
                // Log the evaluation creation
                logAction("Created new evaluation for employee ID: $employeeId for month: $evaluationMonth");
                echo json_encode(['success' => true, 'message' => 'Evaluation saved successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to save evaluation']);
            }
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
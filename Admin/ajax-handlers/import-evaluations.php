<?php
// Start output buffering
ob_start();

// Start a PHP session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header('Content-Type: application/json');
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

// Check if file is uploaded
if (!isset($_FILES['excelFile']) || $_FILES['excelFile']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error.']);
    exit;
}

// Check file type
$allowedMimeTypes = [
    'application/vnd.ms-excel',
    'text/csv',
    'text/plain',
    'application/csv',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/octet-stream'
];

$fileInfo = pathinfo($_FILES['excelFile']['name']);
if (!in_array($_FILES['excelFile']['type'], $allowedMimeTypes) && 
    !in_array(strtolower($fileInfo['extension']), ['csv'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid file type. Please upload a CSV file.']);
    exit;
}

// Get month and year from request
$month = isset($_POST['month']) ? intval($_POST['month']) : date('m');
$year = isset($_POST['year']) ? intval($_POST['year']) : date('Y');

// Process the uploaded file
try {
    // Begin transaction
    $pdo->beginTransaction();
    
    // Variable to track progress
    $successCount = 0;
    $errorCount = 0;
    
    // Create evaluation date as the first day of the selected month
    $evaluationMonth = date('Y-m-d', strtotime($year . '-' . $month . '-01'));

    // Open the uploaded file
    $handle = fopen($_FILES['excelFile']['tmp_name'], 'r');
    if ($handle === false) {
        throw new Exception("Could not open the uploaded file.");
    }

    // Skip instruction rows (first 4 rows)
    for ($i = 0; $i < 4; $i++) {
        fgetcsv($handle);
    }
    
    // Read data rows
    $rowNumber = 5; // Start at row 5 (after the instruction rows)
    while (($data = fgetcsv($handle)) !== false) {
        // Skip empty rows
        if (empty($data[0])) {
            $rowNumber++;
            continue;
        }
        
        // Extract data from CSV columns
        $employeeId = trim($data[0]);
        
        // Skip if employee ID is empty
        if (empty($employeeId)) {
            $rowNumber++;
            continue;
        }
        
        // Check if the employee exists
        $checkEmployeeStmt = $pdo->prepare("SELECT id FROM employees WHERE id = ?");
        $checkEmployeeStmt->execute([$employeeId]);
        
        if ($checkEmployeeStmt->rowCount() === 0) {
            $errorCount++;
            $rowNumber++;
            continue;
        }
        
        // Get evaluation scores (handle empty cells)
        $attendance = !empty($data[5]) ? intval($data[5]) : 0;
        $cleanliness = !empty($data[6]) ? intval($data[6]) : 0;
        $unloading = !empty($data[7]) ? intval($data[7]) : 0;
        $sales = !empty($data[8]) ? intval($data[8]) : 0;
        $order_management = !empty($data[9]) ? intval($data[9]) : 0;
        $stock_sheet = !empty($data[10]) ? intval($data[10]) : 0;
        $inventory = !empty($data[11]) ? intval($data[11]) : 0;
        $machine_and_team = !empty($data[12]) ? intval($data[12]) : 0;
        $management = !empty($data[13]) ? intval($data[13]) : 0;
        $ranking = !empty($data[14]) ? intval($data[14]) : 0;
        
        // Calculate total score
        $totalScore = $attendance + $cleanliness + $unloading + $sales + 
                     $order_management + $stock_sheet + $inventory + 
                     $machine_and_team + $management;
        
        // Skip if all scores are 0
        if ($totalScore === 0 && $ranking === 0) {
            $rowNumber++;
            continue;
        }
        
        // Check if an evaluation already exists for this employee/month/year
        $checkStmt = $pdo->prepare("
            SELECT id FROM employee_evaluations 
            WHERE employee_id = ? AND MONTH(evaluation_month) = ? AND YEAR(evaluation_month) = ?
        ");
        $checkStmt->execute([$employeeId, $month, $year]);
        
        if ($checkStmt->rowCount() > 0) {
            // Update existing evaluation
            $evaluationId = $checkStmt->fetchColumn();
            $stmt = $pdo->prepare("
                UPDATE employee_evaluations 
                SET attendance = ?, cleanliness = ?, unloading = ?, 
                    sales = ?, order_management = ?, stock_sheet = ?,
                    inventory = ?, machine_and_team = ?, management = ?, ranking = ?,
                    total_score = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $attendance, $cleanliness, $unloading, $sales, 
                $order_management, $stock_sheet, $inventory, $machine_and_team,
                $management, $ranking, $totalScore, $evaluationId
            ]);
        } else {
            // Create new evaluation
            $stmt = $pdo->prepare("
                INSERT INTO employee_evaluations 
                (employee_id, evaluation_month, attendance, cleanliness, 
                unloading, sales, order_management, stock_sheet, inventory,
                machine_and_team, management, ranking, total_score) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $employeeId, $evaluationMonth, $attendance, $cleanliness, 
                $unloading, $sales, $order_management, $stock_sheet,
                $inventory, $machine_and_team, $management, $ranking, $totalScore
            ]);
        }
        
        $successCount++;
        $rowNumber++;
    }
    
    fclose($handle);
    
    // Commit the transaction
    $pdo->commit();
    
    // Prepare response message
    $message = '';
    if ($successCount > 0) {
        $message .= sprintf(__('evaluations_import_success'), $successCount) . ' ';
    } else {
        $message .= __('no_evaluations_imported') . ' ';
    }
    
    if ($errorCount > 0) {
        $message .= sprintf(__('evaluations_import_errors'), $errorCount);
    }
    
    echo json_encode(['success' => true, 'message' => $message]);
    
} catch (Exception $e) {
    // Roll back the transaction if an error occurred
    if (isset($pdo)) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Error processing file: ' . $e->getMessage()]);
}

exit;
?>
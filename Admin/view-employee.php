<?php
// Start output buffering
ob_start();

// Start a PHP session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: auth-login.php");
    exit;
}

// Include required files
include "layouts/config.php";

// Check if ID parameter exists
if (!isset($_GET["id"]) || empty(trim($_GET["id"]))) {
    // Redirect to employees page if no ID provided
    $_SESSION["error_message"] = "No employee ID provided.";
    header("location: employees.php");
    exit;
}

$employee_id = trim($_GET["id"]);

// Get employee data with related information
try {
    $stmt = $pdo->prepare("
        SELECT e.*, p.title as position_title,
               CASE 
                  WHEN e.end_of_service_date IS NULL THEN 'Active' 
                  WHEN e.end_of_service_date <= CURDATE() THEN 'Inactive'
                  ELSE 'Active'
               END as status
        FROM employees e
        LEFT JOIN posts p ON e.post_id = p.id
        WHERE e.id = ?
    ");
    $stmt->execute([$employee_id]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$employee) {
        $_SESSION["error_message"] = "Employee not found.";
        header("location: employees.php");
        exit;
    }
    
} catch (PDOException $e) {
    $_SESSION["error_message"] = "Error fetching employee data: " . $e->getMessage();
    header("location: employees.php");
    exit;
}
?>

<?php include 'layouts/head-main.php'; ?>

<head>
    <title>View Employee | Employee Manager System</title>
    <?php include 'layouts/head.php'; ?>
    <?php include 'layouts/head-style.php'; ?>
</head>

<?php include 'layouts/body.php'; ?>

<!-- Begin page -->
<div id="layout-wrapper">

    <?php include 'layouts/menu.php'; ?>
    <?php include 'layouts/header.php'; ?>

    <!-- ============================================================== -->
    <!-- Start right Content here -->
    <!-- ============================================================== -->
    <div class="main-content">

        <div class="page-content">
            <div class="container-fluid">

                <!-- start page title -->
                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                            <h4 class="mb-sm-0 font-size-18">Employee Details</h4>

                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="employees.php">Employees</a></li>
                                    <li class="breadcrumb-item active">View Employee</li>
                                </ol>
                            </div>

                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <div class="row">
                    <div class="col-xl-4">
                        <div class="card overflow-hidden">
                            <div class="bg-primary bg-soft">
                                <div class="row">
                                    <div class="col-7">
                                        <div class="text-primary p-3">
                                            <h5 class="text-primary">Employee Details</h5>
                                            <p class="mb-0"><?php echo htmlspecialchars($employee['position_title'] ?? 'Employee'); ?></p>
                                        </div>
                                    </div>
                                    <div class="col-5 align-self-end">
                                        <img src="assets/images/profile-img.png" alt="" class="img-fluid">
                                    </div>
                                </div>
                            </div>
                            <div class="card-body pt-0">
                                <div class="row">
                                    <div class="col-sm-12">
                                        <div class="avatar-xl profile-user-wid mx-auto mb-3">
                                            <div class="avatar-title rounded-circle bg-light text-primary profile-user-wid">
                                                <?php echo strtoupper(substr($employee['full_name'], 0, 1)); ?>
                                            </div>
                                        </div>
                                        <h5 class="font-size-16 text-center"><?php echo htmlspecialchars($employee['full_name']); ?></h5>
                                        <div class="mt-3">
                                            <p class="text-muted mb-1">Phone: <?php echo htmlspecialchars($employee['phone_number'] ?? 'Not provided'); ?></p>
                                            <p class="text-muted mb-1">Status: 
                                                <span class="badge <?php echo $employee['status'] === 'Active' ? 'bg-success' : 'bg-danger'; ?>">
                                                    <?php echo $employee['status']; ?>
                                                </span>
                                            </p>
                                            <p class="text-muted mb-0">Base Salary: $<?php echo number_format($employee['base_salary'] ?? 0, 2); ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title mb-3">Actions</h5>
                                <div class="d-flex gap-2">
                                    <a href="edit-employee.php?id=<?php echo $employee['id']; ?>" class="btn btn-primary">
                                        <i class="bx bx-edit me-1"></i> Edit
                                    </a>
                                    <a href="javascript:void(0);" onclick="confirmDelete('<?php echo $employee['id']; ?>', '<?php echo htmlspecialchars(addslashes($employee['full_name'])); ?>')" class="btn btn-danger">
                                        <i class="bx bx-trash me-1"></i> Delete
                                    </a>
                                    <a href="employees.php" class="btn btn-secondary">
                                        <i class="bx bx-arrow-back me-1"></i> Back
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title">Employment Information</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-bordered mb-0">
                                        <tbody>
                                            <tr>
                                                <th width="30%">Position</th>
                                                <td><?php echo htmlspecialchars($employee['position_title'] ?? 'Not assigned'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Reference</th>
                                                <td><?php echo htmlspecialchars($employee['reference'] ?? 'Not provided'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Recruitment Date</th>
                                                <td>
                                                    <?php 
                                                    if ($employee['recruitment_date']) {
                                                        echo date('F d, Y', strtotime($employee['recruitment_date']));
                                                    } else {
                                                        echo 'Not provided';
                                                    }
                                                    ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>End of Service Date</th>
                                                <td>
                                                    <?php 
                                                    if ($employee['end_of_service_date']) {
                                                        echo date('F d, Y', strtotime($employee['end_of_service_date']));
                                                    } else {
                                                        echo 'Still employed';
                                                    }
                                                    ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Employment Duration</th>
                                                <td>
                                                    <?php
                                                    if ($employee['recruitment_date']) {
                                                        $start = new DateTime($employee['recruitment_date']);
                                                        $end = $employee['end_of_service_date'] ? new DateTime($employee['end_of_service_date']) : new DateTime();
                                                        $interval = $start->diff($end);
                                                        
                                                        $duration = [];
                                                        if ($interval->y > 0) {
                                                            $duration[] = $interval->y . ' year' . ($interval->y > 1 ? 's' : '');
                                                        }
                                                        if ($interval->m > 0) {
                                                            $duration[] = $interval->m . ' month' . ($interval->m > 1 ? 's' : '');
                                                        }
                                                        if (count($duration) == 0 && $interval->d > 0) {
                                                            $duration[] = $interval->d . ' day' . ($interval->d > 1 ? 's' : '');
                                                        }
                                                        
                                                        echo implode(', ', $duration);
                                                        if (!$employee['end_of_service_date']) {
                                                            echo ' (ongoing)';
                                                        }
                                                    } else {
                                                        echo 'Unknown';
                                                    }
                                                    ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Assignment</th>
                                                <td><?php echo htmlspecialchars($employee['assignment'] ?? 'Not specified'); ?></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title">Contact Information</h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-4">
                                    <h6 class="text-muted mb-2">Phone Number</h6>
                                    <p class="font-size-15"><?php echo htmlspecialchars($employee['phone_number'] ?? 'Not provided'); ?></p>
                                </div>
                                <div>
                                    <h6 class="text-muted mb-2">Address</h6>
                                    <p class="font-size-15">
                                        <?php 
                                        if (!empty($employee['address'])) {
                                            echo nl2br(htmlspecialchars($employee['address']));
                                        } else {
                                            echo 'No address provided';
                                        }
                                        ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div> <!-- container-fluid -->
        </div>
        <!-- End Page-content -->

        <?php include 'layouts/footer.php'; ?>
    </div>
    <!-- end main content-->

</div>
<!-- END layout-wrapper -->

<!-- Delete confirmation modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteModalLabel">Confirm Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                Are you sure you want to delete employee: <span id="employeeName"></span>?
                <p class="text-danger mt-2 mb-0">This action cannot be undone!</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="#" id="confirmDeleteBtn" class="btn btn-danger">Delete</a>
            </div>
        </div>
    </div>
</div>

<?php include 'layouts/vendor-scripts.php'; ?>

<script>
    function confirmDelete(id, name) {
        document.getElementById('employeeName').textContent = name;
        document.getElementById('confirmDeleteBtn').href = 'employees.php?action=delete&id=' + id;
        
        var deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
        deleteModal.show();
    }
</script>

</body>
</html>
<?php ob_end_flush(); ?>
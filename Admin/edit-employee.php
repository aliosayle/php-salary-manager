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
include "layouts/permission-checker.php"; // Added this line to include permission functions

// Check if user has permission to edit employees
if (!hasPermission("manage_employees")) {
    $_SESSION["error_message"] = "You don't have permission to edit employees.";
    header("location: employees.php");
    exit;
}

// Check if ID parameter exists
if (!isset($_GET["id"]) || empty(trim($_GET["id"]))) {
    // Redirect to employees page if no ID provided
    $_SESSION["error_message"] = __("employee_no_id");
    header("location: employees.php");
    exit;
}

$employee_id = trim($_GET["id"]);
$errors = [];

// Get employee data
try {
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt->execute([$employee_id]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$employee) {
        $_SESSION["error_message"] = "Employee not found.";
        header("location: employees.php");
        exit;
    }
    
    // Set form values
    $full_name = $employee["full_name"];
    $phone_number = $employee["phone_number"] ?? "";
    $address = $employee["address"] ?? "";
    $post_id = $employee["post_id"] ?? "";
    $education_level_id = $employee["education_level_id"] ?? "";
    $recommended_by_id = $employee["recommended_by_id"] ?? "";
    $recruitment_date = $employee["recruitment_date"] ? date('Y-m-d', strtotime($employee["recruitment_date"])) : "";
    $end_of_service_date = $employee["end_of_service_date"] ? date('Y-m-d', strtotime($employee["end_of_service_date"])) : "";
    $base_salary = $employee["base_salary"] ?? "0.00";
    
} catch (PDOException $e) {
    $_SESSION["error_message"] = __("employee_error_fetching") . ": " . $e->getMessage();
    header("location: employees.php");
    exit;
}

// Processing form data when form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Debug information
    error_log("Form submitted - POST data: " . print_r($_POST, true));
    
    // Validate full name
    if (empty(trim($_POST["full_name"]))) {
        $errors["full_name"] = "Please enter employee full name.";
    } else {
        $full_name = trim($_POST["full_name"]);
    }
    
    // Validate phone (not required)
    if (!empty(trim($_POST["phone_number"]))) {
        $phone_number = trim($_POST["phone_number"]);
    } else {
        $phone_number = null;
    }
    
    // Validate address (not required)
    if (!empty(trim($_POST["address"]))) {
        $address = trim($_POST["address"]);
    } else {
        $address = null;
    }
    
    // Validate post/position
    if (empty($_POST["post_id"])) {
        $errors["post_id"] = "Please select a position.";
    } else {
        $post_id = $_POST["post_id"];
    }
    
    // Validate education level (not required)
    if (!empty($_POST["education_level_id"])) {
        $education_level_id = $_POST["education_level_id"];
    } else {
        $education_level_id = null;
    }
    
    // Validate recommender (not required)
    if (!empty($_POST["recommended_by_id"])) {
        $recommended_by_id = $_POST["recommended_by_id"];
    } else {
        $recommended_by_id = null;
    }
    
    // Validate recruitment date
    if (empty($_POST["recruitment_date"])) {
        $errors["recruitment_date"] = "Please enter recruitment date.";
    } else {
        $recruitment_date = $_POST["recruitment_date"];
        
        // Check if date is valid
        $date_obj = DateTime::createFromFormat('Y-m-d', $recruitment_date);
        if (!$date_obj || $date_obj->format('Y-m-d') !== $recruitment_date) {
            $errors["recruitment_date"] = "Please enter a valid date in format YYYY-MM-DD.";
        }
    }
    
    // Validate end of service date (not required)
    if (!empty($_POST["end_of_service_date"])) {
        $end_of_service_date = $_POST["end_of_service_date"];
        
        // Check if date is valid
        $date_obj = DateTime::createFromFormat('Y-m-d', $end_of_service_date);
        if (!$date_obj || $date_obj->format('Y-m-d') !== $end_of_service_date) {
            $errors["end_of_service_date"] = "Please enter a valid date in format YYYY-MM-DD.";
        }
        
        // Check that end date is after recruitment date
        if (!empty($recruitment_date) && strtotime($end_of_service_date) < strtotime($recruitment_date)) {
            $errors["end_of_service_date"] = "End of service date must be after recruitment date.";
        }
    } else {
        $end_of_service_date = null;
    }
    
    // Validate base salary
    if (isset($_POST["base_salary"]) && $_POST["base_salary"] !== "") {
        $base_salary = str_replace(',', '', trim($_POST["base_salary"]));
        if (!is_numeric($base_salary) || $base_salary < 0) {
            $errors["base_salary"] = "Base salary must be a positive number.";
        }
    } else {
        $base_salary = 0;
    }
    
    // Check for errors before proceeding
    if (empty($errors)) {
        try {
            // Begin transaction
            $pdo->beginTransaction();
            
            // Prepare an update statement
            $sql = "UPDATE employees SET 
                    full_name = ?, 
                    phone_number = ?, 
                    address = ?, 
                    post_id = ?, 
                    education_level_id = ?,
                    recommended_by_id = ?,
                    recruitment_date = ?, 
                    end_of_service_date = ?,
                    base_salary = ?
                    WHERE id = ?";
            
            $stmt = $pdo->prepare($sql);
            
            // Execute the prepared statement with parameters
            if ($stmt->execute([
                $full_name, 
                $phone_number, 
                $address, 
                $post_id, 
                $education_level_id,
                $recommended_by_id,
                $recruitment_date, 
                $end_of_service_date,
                $base_salary,
                $employee_id
            ])) {
                // Process shop assignments
                // First, remove all existing shop assignments
                $delete_stmt = $pdo->prepare("DELETE FROM employee_shops WHERE employee_id = ?");
                $delete_stmt->execute([$employee_id]);
                
                // Add new shop assignments if any shops were selected
                if (isset($_POST['shops']) && is_array($_POST['shops']) && !empty($_POST['shops'])) {
                    $insert_stmt = $pdo->prepare("INSERT INTO employee_shops (employee_id, shop_id) VALUES (?, ?)");
                    
                    foreach ($_POST['shops'] as $shop_id) {
                        $insert_stmt->execute([$employee_id, $shop_id]);
                    }
                }
                
                // Commit transaction
                $pdo->commit();
                
                // Set success message and redirect
                $_SESSION["success_message"] = "Employee successfully updated.";
                header("location: employees.php");
                exit;
            } else {
                // Rollback transaction
                $pdo->rollBack();
                
                $_SESSION["error_message"] = "Error: Failed to update employee information.";
            }
        } catch (PDOException $e) {
            // Rollback transaction
            $pdo->rollBack();
            
            // Log the error and show a more detailed message
            error_log("Database error during employee update: " . $e->getMessage());
            $errors["db_error"] = "Database error: " . $e->getMessage();
            // Don't redirect immediately, let the error display
        }
    }
}

// Get all posts/positions for dropdown
try {
    $stmt = $pdo->prepare("SELECT id, title FROM posts ORDER BY title");
    $stmt->execute();
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all education levels for the dropdown
    $stmt = $pdo->prepare("SELECT id, degree_name, institution FROM education_levels ORDER BY degree_name");
    $stmt->execute();
    $education_levels = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all recommenders
    $stmt = $pdo->prepare("SELECT id, name, relation FROM recommenders ORDER BY name");
    $stmt->execute();
    $recommenders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch all shops for employee assignment
    $stmt = $pdo->prepare("SELECT id, name FROM shops ORDER BY name");
    $stmt->execute();
    $shops = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get shops already assigned to this employee
    $stmt = $pdo->prepare("SELECT shop_id FROM employee_shops WHERE employee_id = ?");
    $stmt->execute([$employee_id]);
    $assigned_shops = $stmt->fetchAll(PDO::FETCH_COLUMN);

} catch (PDOException $e) {
    // Log error and display generic message
    error_log("Database error: " . $e->getMessage());
    $_SESSION['error'] = "A database error occurred.";
    header("Location: employees.php");
    exit;
}
?>

<?php include 'layouts/head-main.php'; ?>

<head>
    <title>Edit Employee | Employee Manager System</title>
    <?php include 'layouts/head.php'; ?>
    
    <!-- Flatpickr date picker -->
    <link href="assets/libs/flatpickr/flatpickr.min.css" rel="stylesheet" type="text/css">
    
    <!-- Select2 CSS -->
    <link href="assets/libs/select2/css/select2.min.css" rel="stylesheet" type="text/css">
    
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
                            <h4 class="mb-sm-0 font-size-18">Edit Employee</h4>

                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="employees.php">Employees</a></li>
                                    <li class="breadcrumb-item active">Edit Employee</li>
                                </ol>
                            </div>

                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h4 class="card-title">Edit Employee Information</h4>
                                <p class="card-title-desc">Update the employee details below</p>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($errors)): ?>
                                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                    <ul class="mb-0">
                                        <?php foreach ($errors as $error): ?>
                                        <li><?php echo $error; ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                </div>
                                <?php endif; ?>
                                
                                    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]) . '?id=' . htmlspecialchars($employee_id); ?>" method="post">
                                <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="full_name" class="form-label">Full Name <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control <?php echo (!empty($errors['full_name'])) ? 'is-invalid' : ''; ?>" 
                                                    id="full_name" name="full_name" value="<?php echo htmlspecialchars($full_name); ?>" required>
                                                <?php if (!empty($errors['full_name'])): ?>
                                                <div class="invalid-feedback"><?php echo $errors['full_name']; ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="post_id" class="form-label">Position <span class="text-danger">*</span></label>
                                                <select class="form-select <?php echo (!empty($errors['post_id'])) ? 'is-invalid' : ''; ?>" 
                                                    id="post_id" name="post_id" required>
                                                    <option value="">Select Position</option>
                                                    <?php foreach ($posts as $post): ?>
                                                    <option value="<?php echo $post['id']; ?>" <?php echo ($post_id == $post['id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($post['title']); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <?php if (!empty($errors['post_id'])): ?>
                                                <div class="invalid-feedback"><?php echo $errors['post_id']; ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="education_level_id" class="form-label">Education Level</label>
                                                <select class="form-select <?php echo (!empty($errors['education_level_id'])) ? 'is-invalid' : ''; ?>" 
                                                    id="education_level_id" name="education_level_id">
                                                    <option value="">Select Education Level</option>
                                                    <?php foreach ($education_levels as $education): ?>
                                                    <option value="<?php echo $education['id']; ?>" <?php echo ($education_level_id == $education['id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($education['degree_name']); ?>
                                                        <?php if (!empty($education['institution'])): ?>
                                                            (<?php echo htmlspecialchars($education['institution']); ?>)
                                                        <?php endif; ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <?php if (!empty($errors['education_level_id'])): ?>
                                                <div class="invalid-feedback"><?php echo $errors['education_level_id']; ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="recommended_by_id" class="form-label">Recommended By</label>
                                                <select class="form-select <?php echo (!empty($errors['recommended_by_id'])) ? 'is-invalid' : ''; ?>" 
                                                    id="recommended_by_id" name="recommended_by_id">
                                                    <option value="">Select Recommended By</option>
                                                    <?php foreach ($recommenders as $recommender): ?>
                                                    <option value="<?php echo $recommender['id']; ?>" <?php echo ($recommended_by_id == $recommender['id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($recommender['name']); ?>
                                                        <?php if (!empty($recommender['relation'])): ?>
                                                            (<?php echo htmlspecialchars($recommender['relation']); ?>)
                                                        <?php endif; ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <?php if (!empty($errors['recommended_by_id'])): ?>
                                                <div class="invalid-feedback"><?php echo $errors['recommended_by_id']; ?></div>
                                                <?php endif; ?>
                                                <small class="text-muted">
                                                    <a href="new-recommender.php" target="_blank">Add New Recommender</a>
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="phone_number" class="form-label">Phone Number</label>
                                                <input type="text" class="form-control <?php echo (!empty($errors['phone_number'])) ? 'is-invalid' : ''; ?>" 
                                                    id="phone_number" name="phone_number" value="<?php echo htmlspecialchars($phone_number); ?>">
                                                <?php if (!empty($errors['phone_number'])): ?>
                                                <div class="invalid-feedback"><?php echo $errors['phone_number']; ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="recruitment_date" class="form-label">Recruitment Date <span class="text-danger">*</span></label>
                                                <input type="date" class="form-control flatpickr-input <?php echo (!empty($errors['recruitment_date'])) ? 'is-invalid' : ''; ?>" 
                                                    id="recruitment_date" name="recruitment_date" value="<?php echo htmlspecialchars($recruitment_date); ?>" required>
                                                <?php if (!empty($errors['recruitment_date'])): ?>
                                                <div class="invalid-feedback"><?php echo $errors['recruitment_date']; ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="end_of_service_date" class="form-label">End of Service Date</label>
                                                <input type="date" class="form-control flatpickr-input <?php echo (!empty($errors['end_of_service_date'])) ? 'is-invalid' : ''; ?>" 
                                                    id="end_of_service_date" name="end_of_service_date" value="<?php echo htmlspecialchars($end_of_service_date); ?>">
                                                <?php if (!empty($errors['end_of_service_date'])): ?>
                                                <div class="invalid-feedback"><?php echo $errors['end_of_service_date']; ?></div>
                                                <?php endif; ?>
                                                <small class="text-muted">Leave blank if still employed</small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-12">
                                            <div class="mb-3">
                                                <label for="address" class="form-label">Address</label>
                                                <textarea class="form-control <?php echo (!empty($errors['address'])) ? 'is-invalid' : ''; ?>" 
                                                    id="address" name="address" rows="3"><?php echo htmlspecialchars($address); ?></textarea>
                                                <?php if (!empty($errors['address'])): ?>
                                                <div class="invalid-feedback"><?php echo $errors['address']; ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="base_salary" class="form-label">Base Salary</label>
                                                <div class="input-group">
                                                    <span class="input-group-text">$</span>
                                                    <input type="text" class="form-control <?php echo (!empty($errors['base_salary'])) ? 'is-invalid' : ''; ?>" 
                                                        id="base_salary" name="base_salary" value="<?php echo htmlspecialchars($base_salary); ?>">
                                                </div>
                                                <?php if (!empty($errors['base_salary'])): ?>
                                                <div class="invalid-feedback d-block"><?php echo $errors['base_salary']; ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                                                <!-- Shop Assignment Fields -->
                                    <div class="row mt-4">
                                        <div class="col-12">
                                            <h5 class="border-bottom pb-2">Shop Assignment</h5>
                                            <div class="mb-3">
                                                <label for="shops" class="form-label">Assigned Shops</label>
                                                <select class="select2 form-control select2-multiple" id="shops" name="shops[]" multiple="multiple" data-placeholder="Choose shops...">
                                                    <?php foreach ($shops as $shop): ?>
                                                        <option value="<?= $shop['id'] ?>" <?= in_array($shop['id'], $assigned_shops) ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($shop['name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <small class="text-muted">Select multiple shops if applicable</small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-4">
                                        <button type="submit" class="btn btn-primary">Update Employee</button>
                                        <a href="employees.php" class="btn btn-secondary ms-2">Cancel</a>
                                    </div>
                                </form>
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

<?php include 'layouts/vendor-scripts.php'; ?>

<!-- Flatpickr js -->
<script src="assets/libs/flatpickr/flatpickr.min.js"></script>

<!-- Select2 js -->
<script src="assets/libs/select2/js/select2.min.js"></script>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        // Initialize flatpickr date pickers
        flatpickr("#recruitment_date", {
            dateFormat: "Y-m-d"
        });
        
        flatpickr("#end_of_service_date", {
            dateFormat: "Y-m-d",
            allowInput: true
        });
        
        // Initialize select2 for shop selection
        $(".select2-multiple").select2();
        
        // Format currency input
        document.getElementById('base_salary').addEventListener('blur', function(e) {
            const value = this.value.replace(/,/g, '');
            if (!isNaN(value) && value.trim() !== '') {
                this.value = parseFloat(value).toFixed(2);
            }
        });
    });
</script>

</body>
</html>
<?php ob_end_flush(); ?>
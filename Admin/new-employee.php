<?php
// Start output buffering
ob_start();

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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
include "layouts/translations.php"; // Include translations

// Define variables and initialize with empty values
$full_name = $phone_number = $address = $post_id = $education_level_id = $recommended_by_id = "";
$recruitment_date = date('Y-m-d'); // Default to current date
$base_salary = "0.00";
$errors = [];

// Processing form data when form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Validate full name
    if (empty(trim($_POST["full_name"]))) {
        $errors["full_name"] = __("employee_name_required");
    } else {
        $full_name = trim($_POST["full_name"]);
    }
    
    // Validate phone (not required)
    if (!empty(trim($_POST["phone_number"]))) {
        $phone_number = trim($_POST["phone_number"]);
    }
    
    // Validate address (not required)
    if (!empty(trim($_POST["address"]))) {
        $address = trim($_POST["address"]);
    }
    
    // Validate post/position
    if (empty($_POST["post_id"])) {
        $errors["post_id"] = __("employee_position_required");
    } else {
        $post_id = $_POST["post_id"];
    }
    
    // Get education level (not required)
    if (!empty($_POST["education_level_id"])) {
        $education_level_id = $_POST["education_level_id"];
    } else {
        $education_level_id = null;
    }
    
    // Get recommender (not required)
    if (!empty($_POST["recommended_by_id"])) {
        $recommended_by_id = $_POST["recommended_by_id"];
    } else {
        $recommended_by_id = null;
    }
    
    // Validate recruitment date
    if (empty($_POST["recruitment_date"])) {
        $errors["recruitment_date"] = __("employee_recruitment_date_required");
    } else {
        $recruitment_date = $_POST["recruitment_date"];
        
        // Check if date is valid
        $date_obj = DateTime::createFromFormat('Y-m-d', $recruitment_date);
        if (!$date_obj || $date_obj->format('Y-m-d') !== $recruitment_date) {
            $errors["recruitment_date"] = __("employee_valid_date_format");
        }
    }
    
    // Validate base salary
    if (isset($_POST["base_salary"]) && $_POST["base_salary"] !== "") {
        $base_salary = str_replace(',', '', trim($_POST["base_salary"]));
        if (!is_numeric($base_salary) || $base_salary < 0) {
            $errors["base_salary"] = __("employee_salary_positive");
        }
    } else {
        $base_salary = 0;
    }
    
    // Check for errors before proceeding
    if (empty($errors)) {
        try {
            // Begin transaction
            $pdo->beginTransaction();
            
            // Prepare an insert statement
            $sql = "INSERT INTO employees (id, full_name, phone_number, address, post_id, recruitment_date, base_salary, education_level_id, recommended_by_id) 
                    VALUES (UUID(), ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $pdo->prepare($sql);
            
            // Bind parameters
            $stmt->bindParam(1, $param_full_name, PDO::PARAM_STR);
            $stmt->bindParam(2, $param_phone_number, PDO::PARAM_STR);
            $stmt->bindParam(3, $param_address, PDO::PARAM_STR);
            $stmt->bindParam(4, $param_post_id, PDO::PARAM_STR);
            $stmt->bindParam(5, $param_recruitment_date, PDO::PARAM_STR);
            $stmt->bindParam(6, $param_base_salary, PDO::PARAM_STR);
            $stmt->bindParam(7, $param_education_level_id, PDO::PARAM_STR);
            $stmt->bindParam(8, $param_recommended_by_id, PDO::PARAM_STR);
            
            // Set parameters
            $param_full_name = $full_name;
            $param_phone_number = $phone_number;
            $param_address = $address;
            $param_post_id = $post_id;
            $param_recruitment_date = $recruitment_date;
            $param_base_salary = $base_salary;
            $param_education_level_id = $education_level_id;
            $param_recommended_by_id = $recommended_by_id;
            
            // Execute the prepared statement
            if ($stmt->execute()) {
                // Commit transaction
                $pdo->commit();
                
                // Set success message and redirect
                $_SESSION["success_message"] = __("employee_added_success");
                header("location: employees.php");
                exit;
            } else {
                // Rollback transaction
                $pdo->rollBack();
                
                $_SESSION["error_message"] = __("employee_add_error");
            }
        } catch (PDOException $e) {
            // Rollback transaction
            $pdo->rollBack();
            
            $_SESSION["error_message"] = __("employee_db_error") . ": " . $e->getMessage();
        }
    }
}

// Get all posts/positions for dropdown
try {
    $posts_stmt = $pdo->query("SELECT id, title FROM posts ORDER BY title");
    $posts = $posts_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all education levels for dropdown
    $education_stmt = $pdo->query("SELECT id, degree_name, institution FROM education_levels ORDER BY degree_name");
    $education_levels = $education_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all recommenders for dropdown
    $recommender_stmt = $pdo->query("SELECT id, name, relation FROM recommenders ORDER BY name");
    $recommenders = $recommender_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $posts = [];
    $education_levels = [];
    $recommenders = [];
    $errors["database"] = __("employee_db_load_error") . ": " . $e->getMessage();
}
?>

<?php include 'layouts/head-main.php'; ?>

<head>
    <title><?php echo __('employee_add_title'); ?> | <?php echo __('common_rms'); ?></title>
    <?php include 'layouts/head.php'; ?>
    
    <!-- Flatpickr date picker -->
    <link href="assets/libs/flatpickr/flatpickr.min.css" rel="stylesheet" type="text/css">
    
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
                            <h4 class="mb-sm-0 font-size-18"><?php echo __('employee_add_title'); ?></h4>

                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="employees.php"><?php echo __('employees_management'); ?></a></li>
                                    <li class="breadcrumb-item active"><?php echo __('employee_add_title'); ?></li>
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
                                <h4 class="card-title"><?php echo __('employee_add_info'); ?></h4>
                                <p class="card-title-desc"><?php echo __('employee_add_desc'); ?></p>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($errors)): ?>
                                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                    <ul class="mb-0">
                                        <?php foreach ($errors as $error): ?>
                                        <li><?php echo $error; ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?php echo __('common_close'); ?>"></button>
                                </div>
                                <?php endif; ?>
                                
                                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="full_name" class="form-label"><?php echo __('employees_full_name'); ?> <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control <?php echo (!empty($errors['full_name'])) ? 'is-invalid' : ''; ?>" 
                                                    id="full_name" name="full_name" value="<?php echo htmlspecialchars($full_name); ?>" required>
                                                <?php if (!empty($errors['full_name'])): ?>
                                                <div class="invalid-feedback"><?php echo $errors['full_name']; ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="post_id" class="form-label"><?php echo __('employees_position'); ?> <span class="text-danger">*</span></label>
                                                <select class="form-select <?php echo (!empty($errors['post_id'])) ? 'is-invalid' : ''; ?>" 
                                                    id="post_id" name="post_id" required>
                                                    <option value=""><?php echo __('employee_select_position'); ?></option>
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
                                                <label for="phone_number" class="form-label"><?php echo __('employees_phone'); ?></label>
                                                <input type="text" class="form-control <?php echo (!empty($errors['phone_number'])) ? 'is-invalid' : ''; ?>" 
                                                    id="phone_number" name="phone_number" value="<?php echo htmlspecialchars($phone_number); ?>">
                                                <?php if (!empty($errors['phone_number'])): ?>
                                                <div class="invalid-feedback"><?php echo $errors['phone_number']; ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="recruitment_date" class="form-label"><?php echo __('employees_recruitment_date'); ?> <span class="text-danger">*</span></label>
                                                <input type="date" class="form-control flatpickr-input <?php echo (!empty($errors['recruitment_date'])) ? 'is-invalid' : ''; ?>" 
                                                    id="recruitment_date" name="recruitment_date" value="<?php echo htmlspecialchars($recruitment_date); ?>" required>
                                                <?php if (!empty($errors['recruitment_date'])): ?>
                                                <div class="invalid-feedback"><?php echo $errors['recruitment_date']; ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="base_salary" class="form-label"><?php echo __('employees_base_salary'); ?></label>
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

                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="education_level_id" class="form-label"><?php echo __('employee_education_level'); ?></label>
                                                <select class="form-select <?php echo (!empty($errors['education_level_id'])) ? 'is-invalid' : ''; ?>" 
                                                    id="education_level_id" name="education_level_id">
                                                    <option value=""><?php echo __('employee_select_education'); ?></option>
                                                    <?php foreach ($education_levels as $education): ?>
                                                    <option value="<?php echo $education['id']; ?>" <?php echo ($education_level_id == $education['id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($education['degree_name']); ?>
                                                        <?php if(!empty($education['institution'])): ?> 
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
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="recommended_by_id" class="form-label"><?php echo __('employee_recommended_by'); ?></label>
                                                <select class="form-select <?php echo (!empty($errors['recommended_by_id'])) ? 'is-invalid' : ''; ?>" 
                                                    id="recommended_by_id" name="recommended_by_id">
                                                    <option value=""><?php echo __('employee_select_recommender'); ?></option>
                                                    <?php foreach ($recommenders as $recommender): ?>
                                                    <option value="<?php echo $recommender['id']; ?>" <?php echo ($recommended_by_id == $recommender['id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($recommender['name']); ?>
                                                        <?php if(!empty($recommender['relation'])): ?> 
                                                            (<?php echo htmlspecialchars($recommender['relation']); ?>)
                                                        <?php endif; ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <?php if (!empty($errors['recommended_by_id'])): ?>
                                                <div class="invalid-feedback"><?php echo $errors['recommended_by_id']; ?></div>
                                                <?php endif; ?>
                                                <small class="text-muted">
                                                    <a href="new-recommender.php" target="_blank"><?php echo __('employee_add_new_recommender'); ?></a>
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-12">
                                            <div class="mb-3">
                                                <label for="address" class="form-label"><?php echo __('employee_address'); ?></label>
                                                <textarea class="form-control <?php echo (!empty($errors['address'])) ? 'is-invalid' : ''; ?>" 
                                                    id="address" name="address" rows="3"><?php echo htmlspecialchars($address); ?></textarea>
                                                <?php if (!empty($errors['address'])): ?>
                                                <div class="invalid-feedback"><?php echo $errors['address']; ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-4">
                                        <button type="submit" class="btn btn-primary"><?php echo __('employee_save_button'); ?></button>
                                        <a href="employees.php" class="btn btn-secondary ms-2"><?php echo __('common_cancel'); ?></a>
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

<script>
    document.addEventListener("DOMContentLoaded", function() {
        // Initialize flatpickr date picker
        flatpickr("#recruitment_date", {
            dateFormat: "Y-m-d",
            maxDate: "today"
        });
        
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
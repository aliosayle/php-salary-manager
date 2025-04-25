<?php
// Start session
session_start();

// Include layout files
include_once "layouts/config.php";
include_once "layouts/session.php";
include_once "layouts/helpers.php";

// Check for permission
if (!hasPermission('manage_shops')) {
    $_SESSION['error'] = "You don't have permission to edit shops";
    header("Location: shops.php");
    exit();
}

// Check if shop ID exists
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error'] = "Shop ID is required";
    header("Location: shops.php");
    exit();
}

// Get shop ID
$shop_id = $_GET['id'];

// Initialize error array
$errors = [];

// Get shop details
try {
    $stmt = $pdo->prepare("SELECT * FROM shops WHERE id = ?");
    $stmt->execute([$shop_id]);
    $shop = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$shop) {
        $_SESSION['error'] = "Shop not found";
        header("Location: shops.php");
        exit();
    }
    
    // Initialize variables
    $shop_name = $shop['name'];
    $shop_address = $shop['address'];
    $shop_phone = $shop['phone'];
    $shop_email = $shop['email'];
    
} catch (PDOException $e) {
    $_SESSION['error'] = "Error retrieving shop details: " . $e->getMessage();
    header("Location: shops.php");
    exit();
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate inputs
    $shop_name = trim($_POST['shop_name'] ?? '');
    $shop_address = trim($_POST['shop_address'] ?? '');
    $shop_phone = trim($_POST['shop_phone'] ?? '');
    $shop_email = trim($_POST['shop_email'] ?? '');
    
    // Validation
    if (empty($shop_name)) {
        $errors['shop_name'] = "Shop name is required";
    }
    
    if (empty($shop_address)) {
        $errors['shop_address'] = "Address is required";
    }
    
    if (empty($shop_phone)) {
        $errors['shop_phone'] = "Phone number is required";
    }
    
    if (!empty($shop_email) && !filter_var($shop_email, FILTER_VALIDATE_EMAIL)) {
        $errors['shop_email'] = "Please enter a valid email address";
    }
    
    // If no errors, proceed with updating the shop
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("UPDATE shops SET name = ?, address = ?, phone = ?, email = ? WHERE id = ?");
            $stmt->execute([$shop_name, $shop_address, $shop_phone, $shop_email, $shop_id]);
            
            $_SESSION['success'] = "Shop updated successfully";
            header("Location: shops.php");
            exit();
        } catch (PDOException $e) {
            $_SESSION['error'] = "Error updating shop: " . $e->getMessage();
        }
    }
}

// Page title
$page_title = "Edit Shop";

include "layouts/header.php";
?>

<div class="page-content">
    <div class="container-fluid">

        <!-- Page title -->
        <div class="row">
            <div class="col-12">
                <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                    <h4 class="mb-sm-0 font-size-18">Edit Shop</h4>

                    <div class="page-title-right">
                        <ol class="breadcrumb m-0">
                            <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="shops.php">Shops</a></li>
                            <li class="breadcrumb-item active">Edit Shop</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- Success/Error messages -->
        <?php include "layouts/alert.php"; ?>

        <div class="row">
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Shop Information</h4>
                        
                        <form action="" method="POST" id="edit-shop-form" class="needs-validation" novalidate>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="shop_name" class="form-label">Shop Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control <?= isset($errors['shop_name']) ? 'is-invalid' : '' ?>" 
                                               id="shop_name" name="shop_name" value="<?= htmlspecialchars($shop_name) ?>" required>
                                        <?php if (isset($errors['shop_name'])): ?>
                                            <div class="invalid-feedback">
                                                <?= $errors['shop_name'] ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="shop_phone" class="form-label">Phone Number <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control <?= isset($errors['shop_phone']) ? 'is-invalid' : '' ?>" 
                                               id="shop_phone" name="shop_phone" value="<?= htmlspecialchars($shop_phone) ?>" required>
                                        <?php if (isset($errors['shop_phone'])): ?>
                                            <div class="invalid-feedback">
                                                <?= $errors['shop_phone'] ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="shop_address" class="form-label">Address <span class="text-danger">*</span></label>
                                        <textarea class="form-control <?= isset($errors['shop_address']) ? 'is-invalid' : '' ?>" 
                                                  id="shop_address" name="shop_address" rows="3" required><?= htmlspecialchars($shop_address) ?></textarea>
                                        <?php if (isset($errors['shop_address'])): ?>
                                            <div class="invalid-feedback">
                                                <?= $errors['shop_address'] ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="shop_email" class="form-label">Email Address</label>
                                        <input type="email" class="form-control <?= isset($errors['shop_email']) ? 'is-invalid' : '' ?>" 
                                               id="shop_email" name="shop_email" value="<?= htmlspecialchars($shop_email) ?>">
                                        <?php if (isset($errors['shop_email'])): ?>
                                            <div class="invalid-feedback">
                                                <?= $errors['shop_email'] ?>
                                            </div>
                                        <?php endif; ?>
                                        <small class="text-muted">Optional</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="d-flex justify-content-end mt-4">
                                <a href="shops.php" class="btn btn-secondary me-2">Cancel</a>
                                <button type="submit" class="btn btn-primary">Update Shop</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include "layouts/footer.php"; ?>

<script>
    // Form validation
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('edit-shop-form');
        
        form.addEventListener('submit', function(event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            
            form.classList.add('was-validated');
        }, false);
    });
</script> 
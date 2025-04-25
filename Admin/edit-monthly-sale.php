<?php
require_once 'layouts/session.php';
require_once 'layouts/head-main.php';

// Check if user has permission to manage monthly sales
if (!hasPermission('manage_monthly_sales')) {
    $_SESSION['error_message'] = __('no_permission_manage_monthly_sales');
    header('Location: index.php');
    exit;
}

// Initialize variables
$id = $_GET['id'] ?? null;
$salesData = null;
$errors = [];

// Validate ID
if (!$id) {
    $_SESSION['error_message'] = __('invalid_record_id');
    header('Location: monthly-sales.php');
    exit;
}

// Fetch the sales record
try {
    $sql = "SELECT ms.*, s.name as shop_name 
            FROM monthly_sales ms
            JOIN shops s ON ms.shop_id = s.id
            WHERE ms.id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $id]);
    
    if ($stmt->rowCount() === 0) {
        $_SESSION['error_message'] = __('record_not_found');
        header('Location: monthly-sales.php');
        exit;
    }
    
    $salesData = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_SESSION['error_message'] = __('error_loading_record') . ': ' . $e->getMessage();
    header('Location: monthly-sales.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $sales_amount = $_POST['sales_amount'] ?? '';
    
    // Validate inputs
    if (empty($sales_amount)) {
        $errors['sales_amount'] = __('sales_amount_required');
    } elseif (!is_numeric($sales_amount) || $sales_amount < 0) {
        $errors['sales_amount'] = __('sales_amount_invalid');
    }
    
    // If no errors, proceed with updating
    if (empty($errors)) {
        try {
            // Update record
            $updateSql = "UPDATE monthly_sales SET sales_amount = :sales_amount, updated_at = NOW() WHERE id = :id";
            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->execute([
                ':sales_amount' => $sales_amount,
                ':id' => $id
            ]);
            
            $_SESSION['success_message'] = __('sales_updated_success');
            header('Location: monthly-sales.php');
            exit;
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = __('sales_update_error') . ': ' . $e->getMessage();
        }
    }
}
?>

<!-- ============================================================== -->
<!-- Start right Content here -->
<!-- ============================================================== -->

<?php require_once 'layouts/header.php'; ?>

<!-- Page Content-->
<div class="page-content">
    <div class="container-fluid">

        <!-- Start page title -->
        <div class="row">
            <div class="col-12">
                <div class="page-title-box d-flex align-items-center justify-content-between">
                    <h4 class="mb-0"><?php echo __('edit_monthly_sales'); ?></h4>

                    <div class="page-title-right">
                        <ol class="breadcrumb m-0">
                            <li class="breadcrumb-item"><a href="index.php"><?php echo __('menu'); ?></a></li>
                            <li class="breadcrumb-item"><a href="monthly-sales.php"><?php echo __('monthly_sales'); ?></a></li>
                            <li class="breadcrumb-item active"><?php echo __('edit'); ?></li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
        <!-- End page title -->

        <div class="row">
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4"><?php echo __('edit_sales_record'); ?></h4>
                        
                        <!-- Form -->
                        <form method="post" class="needs-validation" novalidate>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"><?php echo __('shop'); ?></label>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($salesData['shop_name']); ?>" readonly>
                                </div>
                                
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"><?php echo __('period'); ?></label>
                                    <input type="text" class="form-control" value="<?php echo getMonthName($salesData['month']) . ' ' . $salesData['year']; ?>" readonly>
                                </div>
                                
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"><?php echo __('sales_amount'); ?></label>
                                    <div class="input-group">
                                        <span class="input-group-text"><?php echo getCurrencySymbol(); ?></span>
                                        <input type="number" class="form-control" name="sales_amount" 
                                               value="<?php echo isset($_POST['sales_amount']) ? htmlspecialchars($_POST['sales_amount']) : htmlspecialchars($salesData['sales_amount']); ?>" 
                                               step="0.01" min="0" required>
                                    </div>
                                    <?php if (isset($errors['sales_amount'])): ?>
                                        <div class="invalid-feedback d-block"><?php echo $errors['sales_amount']; ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="row mt-3">
                                <div class="col-md-12">
                                    <button type="submit" class="btn btn-primary"><?php echo __('update_sales_data'); ?></button>
                                    <a href="monthly-sales.php" class="btn btn-secondary ms-2"><?php echo __('cancel'); ?></a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

    </div>
    <!-- End Container -->
</div>
<!-- End Page Content -->

<?php require_once 'layouts/footer.php'; ?>

<script>
    $(document).ready(function() {
        // Form validation
        $('form').submit(function(event) {
            var form = $(this);
            if (form[0].checkValidity() === false) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.addClass('was-validated');
        });
    });
</script> 
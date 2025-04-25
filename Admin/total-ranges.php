<?php
include 'layouts/session.php';
include 'layouts/head-main.php';

// Check permission
if (!hasPermission('manage_settings')) {
    $_SESSION["error_message"] = "You don't have permission to access this resource.";
    header("location: index.php");
    exit;
}

// Process form submissions
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Add or update a range
    if (isset($_POST["action"]) && ($_POST["action"] == "add" || $_POST["action"] == "edit")) {
        $id = isset($_POST["id"]) ? $_POST["id"] : null;
        $min_value = $_POST["min_value"];
        $max_value = $_POST["max_value"];
        $amount = $_POST["amount"];
        
        // Validate input
        $errors = [];
        
        if (!is_numeric($min_value) || $min_value < 0) {
            $errors[] = "Minimum value must be a non-negative number.";
        }
        
        if (!is_numeric($max_value) || $max_value < 0) {
            $errors[] = "Maximum value must be a non-negative number.";
        }
        
        if ($min_value > $max_value) {
            $errors[] = "Minimum value cannot be greater than maximum value.";
        }
        
        if (!is_numeric($amount) || $amount < 0) {
            $errors[] = "Amount must be a non-negative number.";
        }
        
        // Check for overlapping ranges
        if (empty($errors)) {
            $params = [];
            $idCondition = "";
            
            if ($id) {
                $idCondition = " AND id != ?";
                $params = [$min_value, $min_value, $max_value, $max_value, $min_value, $max_value, $id];
            } else {
                $params = [$min_value, $min_value, $max_value, $max_value, $min_value, $max_value];
            }
            
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM total_ranges 
                WHERE (
                    (min_value <= ? AND max_value >= ?) OR
                    (min_value <= ? AND max_value >= ?) OR
                    (min_value >= ? AND max_value <= ?)
                ) $idCondition
            ");
            $stmt->execute($params);
            
            if ($stmt->fetchColumn() > 0) {
                $errors[] = "This range overlaps with an existing range.";
            }
        }
        
        if (empty($errors)) {
            try {
                if ($_POST["action"] == "add") {
                    $stmt = $pdo->prepare("
                        INSERT INTO total_ranges (min_value, max_value, amount)
                        VALUES (?, ?, ?)
                    ");
                    $stmt->execute([$min_value, $max_value, $amount]);
                    $_SESSION["success_message"] = "Range added successfully.";
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE total_ranges
                        SET min_value = ?, max_value = ?, amount = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$min_value, $max_value, $amount, $id]);
                    $_SESSION["success_message"] = "Range updated successfully.";
                }
                
                header("location: total-ranges.php");
                exit;
            } catch (PDOException $e) {
                $errors[] = "Database error: " . $e->getMessage();
            }
        }
        
        // If we get here, there were errors
        if (!empty($errors)) {
            $_SESSION["error_message"] = implode("<br>", $errors);
        }
    }
    
    // Delete a range
    if (isset($_POST["action"]) && $_POST["action"] == "delete" && isset($_POST["id"])) {
        try {
            $stmt = $pdo->prepare("DELETE FROM total_ranges WHERE id = ?");
            $stmt->execute([$_POST["id"]]);
            
            $_SESSION["success_message"] = "Range deleted successfully.";
            header("location: total-ranges.php");
            exit;
        } catch (PDOException $e) {
            $_SESSION["error_message"] = "Error deleting range: " . $e->getMessage();
        }
    }
}

// Get all ranges
try {
    $stmt = $pdo->query("SELECT * FROM total_ranges ORDER BY min_value ASC");
    $ranges = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_SESSION["error_message"] = "Error fetching ranges: " . $e->getMessage();
    $ranges = [];
}

// Edit mode
$editMode = false;
$editItem = null;

if (isset($_GET["edit"]) && is_numeric($_GET["edit"])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM total_ranges WHERE id = ?");
        $stmt->execute([$_GET["edit"]]);
        $editItem = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($editItem) {
            $editMode = true;
        }
    } catch (PDOException $e) {
        $_SESSION["error_message"] = "Error fetching range: " . $e->getMessage();
    }
}
?>

<!-- ============================================================== -->
<!-- Start head content here -->
<!-- ============================================================== -->
<head>
    <title>Total Ranges | Salary Manager</title>
    <?php include 'layouts/head.php'; ?>
    
    <!-- DataTables CSS -->
    <link href="assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/libs/datatables.net-buttons-bs4/css/buttons.bootstrap4.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css" rel="stylesheet" type="text/css" />
    
    <?php include 'layouts/head-style.php'; ?>
    
    <style>
        .form-outline {
            position: relative;
        }
        .currency-prefix {
            position: absolute;
            left: 0.75rem;
            top: 0.5rem;
            color: #495057;
        }
        .currency-input {
            padding-left: 1.5rem;
        }
    </style>
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
                            <h4 class="mb-sm-0 font-size-18">Total Ranges</h4>

                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                    <li class="breadcrumb-item active">Total Ranges</li>
                                </ol>
                            </div>

                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <div class="row">
                    <div class="col-12">
                        <!-- Display notifications -->
                        <?php if(isset($_SESSION['error_message'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?php echo $_SESSION['error_message']; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php unset($_SESSION['error_message']); ?>
                        <?php endif; ?>

                        <?php if(isset($_SESSION['success_message'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?php echo $_SESSION['success_message']; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php unset($_SESSION['success_message']); ?>
                        <?php endif; ?>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-body">
                                        <h4 class="card-title mb-4">
                                            <?php echo $editMode ? "Edit Range" : "Add New Range"; ?>
                                        </h4>
                                        
                                        <form method="post" action="total-ranges.php">
                                            <input type="hidden" name="action" value="<?php echo $editMode ? 'edit' : 'add'; ?>">
                                            <?php if ($editMode): ?>
                                                <input type="hidden" name="id" value="<?php echo $editItem['id']; ?>">
                                            <?php endif; ?>
                                            
                                            <div class="mb-3">
                                                <label for="min_value" class="form-label">Minimum Value</label>
                                                <input type="number" class="form-control" id="min_value" name="min_value" required min="0" 
                                                       value="<?php echo $editMode ? $editItem['min_value'] : ''; ?>">
                                                <div class="form-text">Enter the minimum evaluation score (inclusive).</div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label for="max_value" class="form-label">Maximum Value</label>
                                                <input type="number" class="form-control" id="max_value" name="max_value" required min="0"
                                                       value="<?php echo $editMode ? $editItem['max_value'] : ''; ?>">
                                                <div class="form-text">Enter the maximum evaluation score (inclusive).</div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label for="amount" class="form-label">Amount</label>
                                                <div class="form-outline">
                                                    <span class="currency-prefix">$</span>
                                                    <input type="number" class="form-control currency-input" id="amount" name="amount" required min="0" step="0.01"
                                                           value="<?php echo $editMode ? $editItem['amount'] : ''; ?>">
                                                </div>
                                                <div class="form-text">Enter the monetary amount for this score range.</div>
                                            </div>
                                            
                                            <div class="d-flex justify-content-between">
                                                <button type="submit" class="btn btn-primary">
                                                    <?php echo $editMode ? "Update Range" : "Add Range"; ?>
                                                </button>
                                                
                                                <?php if ($editMode): ?>
                                                    <a href="total-ranges.php" class="btn btn-secondary">Cancel</a>
                                                <?php endif; ?>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-8">
                                <div class="card">
                                    <div class="card-body">
                                        <h4 class="card-title mb-4">All Total Ranges</h4>
                                        
                                        <div class="table-responsive">
                                            <table id="ranges-table" class="table table-striped table-bordered w-100">
                                                <thead>
                                                    <tr>
                                                        <th>ID</th>
                                                        <th>Min Value</th>
                                                        <th>Max Value</th>
                                                        <th>Amount</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($ranges as $range): ?>
                                                        <tr>
                                                            <td><?php echo $range['id']; ?></td>
                                                            <td><?php echo $range['min_value']; ?></td>
                                                            <td><?php echo $range['max_value']; ?></td>
                                                            <td>$<?php echo number_format($range['amount'], 2); ?></td>
                                                            <td>
                                                                <a href="total-ranges.php?edit=<?php echo $range['id']; ?>" class="btn btn-sm btn-info">
                                                                    <i class="bx bx-edit"></i>
                                                                </a>
                                                                <button type="button" class="btn btn-sm btn-danger delete-btn" 
                                                                        data-id="<?php echo $range['id']; ?>"
                                                                        data-min="<?php echo $range['min_value']; ?>"
                                                                        data-max="<?php echo $range['max_value']; ?>">
                                                                    <i class="bx bx-trash"></i>
                                                                </button>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Delete Confirmation Modal -->
        <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="deleteModalLabel">Confirm Delete</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        Are you sure you want to delete the range <span id="delete-range-text"></span>?
                    </div>
                    <div class="modal-footer">
                        <form method="post" action="total-ranges.php">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" id="delete-id">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger">Delete</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <?php include 'layouts/footer.php'; ?>
    </div>
    <!-- end main content-->

</div>
<!-- END layout-wrapper -->

<?php include 'layouts/vendor-scripts.php'; ?>

<!-- Required datatable js -->
<script src="assets/libs/datatables.net/js/jquery.dataTables.min.js"></script>
<script src="assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize DataTable
        $('#ranges-table').DataTable({
            responsive: true,
            columnDefs: [
                { orderable: false, targets: -1 }
            ]
        });
        
        // Handle delete button click
        const deleteButtons = document.querySelectorAll('.delete-btn');
        const deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
        
        deleteButtons.forEach(button => {
            button.addEventListener('click', function() {
                const id = this.getAttribute('data-id');
                const min = this.getAttribute('data-min');
                const max = this.getAttribute('data-max');
                
                document.getElementById('delete-id').value = id;
                document.getElementById('delete-range-text').textContent = `[${min} - ${max}]`;
                
                deleteModal.show();
            });
        });
    });
</script>

</body>
</html>
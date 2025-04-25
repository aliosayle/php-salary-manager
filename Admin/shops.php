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
include "layouts/helpers.php";
include "layouts/session.php";

// Check if the user has permission to access this page
requirePermission('view_employees');

// Process delete operation
if (isset($_GET["action"]) && $_GET["action"] == "delete" && isset($_GET["id"])) {
    $shopId = $_GET["id"];
    
    try {
        // Begin transaction
        $pdo->beginTransaction();
        
        // First, check if any employees are assigned to this shop
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM employee_shops WHERE shop_id = ?");
        $checkStmt->execute([$shopId]);
        $employeeCount = $checkStmt->fetchColumn();
        
        if ($employeeCount > 0) {
            // Shop has employees assigned, show error
            $_SESSION["error_message"] = __('shop_has_employees_assigned');
            header("location: shops.php");
            exit;
        }
        
        // Delete the shop record
        $stmt = $pdo->prepare("DELETE FROM shops WHERE id = ?");
        $stmt->execute([$shopId]);
        
        // Commit transaction
        $pdo->commit();
        
        $_SESSION["success_message"] = __('shop_deleted_success');
        header("location: shops.php");
        exit;
    } catch (PDOException $e) {
        // Rollback transaction
        $pdo->rollBack();
        
        $_SESSION["error_message"] = __('shop_delete_error') . ": " . $e->getMessage();
        header("location: shops.php");
        exit;
    }
}

// Process shop creation
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["action"]) && $_POST["action"] == "create") {
    $name = trim($_POST["name"]);
    $location = trim($_POST["location"]);
    
    // Validate inputs
    $errors = [];
    
    if (empty($name)) {
        $errors[] = __('shop_name_required');
    }
    
    if (empty($errors)) {
        try {
            // Insert new shop
            $stmt = $pdo->prepare("INSERT INTO shops (name, location) VALUES (?, ?)");
            $result = $stmt->execute([$name, $location]);
            
            if ($result) {
                $_SESSION["success_message"] = __('shop_created_success');
                header("location: shops.php");
                exit;
            } else {
                $_SESSION["error_message"] = __('shop_create_error');
                header("location: shops.php");
                exit;
            }
        } catch (PDOException $e) {
            $_SESSION["error_message"] = "Error creating shop: " . $e->getMessage();
            header("location: shops.php");
            exit;
        }
    } else {
        $_SESSION["error_message"] = implode("<br>", $errors);
        header("location: shops.php");
        exit;
    }
}

// Process shop update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["action"]) && $_POST["action"] == "update") {
    $shopId = $_POST["shop_id"];
    $name = trim($_POST["name"]);
    $location = trim($_POST["location"]);
    
    // Validate inputs
    $errors = [];
    
    if (empty($name)) {
        $errors[] = "Shop name is required";
    }
    
    if (empty($errors)) {
        try {
            // Update shop
            $stmt = $pdo->prepare("UPDATE shops SET name = ?, location = ? WHERE id = ?");
            $result = $stmt->execute([$name, $location, $shopId]);
            
            if ($result) {
                $_SESSION["success_message"] = __('shop_updated_success');
                header("location: shops.php");
                exit;
            } else {
                $_SESSION["error_message"] = __('shop_update_error');
                header("location: shops.php");
                exit;
            }
        } catch (PDOException $e) {
            $_SESSION["error_message"] = "Error updating shop: " . $e->getMessage();
            header("location: shops.php");
            exit;
        }
    } else {
        $_SESSION["error_message"] = implode("<br>", $errors);
        header("location: shops.php");
        exit;
    }
}

// Get all shops
try {
    $sql = "SELECT s.*, COUNT(DISTINCT es.employee_id) as employee_count 
            FROM shops s
            LEFT JOIN employee_shops es ON s.id = es.shop_id
            GROUP BY s.id
            ORDER BY s.name ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $shops = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_SESSION["error_message"] = "Error fetching shops: " . $e->getMessage();
    $shops = [];
}

// Get shop responsibles
$responsibleStmt = $pdo->prepare("
    SELECT sr.shop_id, e.full_name as responsible_name
    FROM shop_responsibles sr
    JOIN employees e ON sr.employee_id = e.id
");
$responsibleStmt->execute();
$responsibles = [];
while ($row = $responsibleStmt->fetch(PDO::FETCH_ASSOC)) {
    $responsibles[$row['shop_id']] = $row['responsible_name'];
}
?>

<?php include 'layouts/head-main.php'; ?>

<head>
    <title><?php echo __('shops'); ?> | <?php echo __('employee_manager_system'); ?></title>
    <?php include 'layouts/head.php'; ?>
    
    <!-- DataTables CSS -->
    <link href="assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/libs/datatables.net-buttons-bs4/css/buttons.bootstrap4.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css" rel="stylesheet" type="text/css" />
    
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
                            <h4 class="mb-sm-0 font-size-18"><?php echo __('shops'); ?></h4>

                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="javascript: void(0);"><?php echo __('employee_manager'); ?></a></li>
                                    <li class="breadcrumb-item active"><?php echo __('shops'); ?></li>
                                </ol>
                            </div>

                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <?php if (isset($_SESSION['success_message']) || isset($_SESSION['error_message'])): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-<?php echo isset($_SESSION['success_message']) ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
                            <?php 
                            echo isset($_SESSION['success_message']) ? $_SESSION['success_message'] : $_SESSION['error_message']; 
                            // Clear the messages
                            unset($_SESSION['success_message']);
                            unset($_SESSION['error_message']);
                            ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h4 class="card-title"><?php echo __('shop_list'); ?></h4>
                                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addShopModal">
                                        <i class="bx bx-plus me-1"></i> <?php echo __('add_new_shop'); ?>
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover" id="shopsTable">
                                        <thead class="table-light">
                                            <tr>
                                                <th>#</th>
                                                <th><?php echo __('name'); ?></th>
                                                <th><?php echo __('location'); ?></th>
                                                <th><?php echo __('employees_assigned'); ?></th>
                                                <th><?php echo __('responsible'); ?></th>
                                                <th><?php echo __('actions'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $count = 1; foreach ($shops as $shop): ?>
                                            <tr>
                                                <th scope="row"><?php echo $count++; ?></th>
                                                <td><?php echo htmlspecialchars($shop['name']); ?></td>
                                                <td><?php echo htmlspecialchars($shop['location'] ?? __('not_specified')); ?></td>
                                                <td>
                                                    <span class="badge bg-primary"><?php echo $shop['employee_count']; ?></span>
                                                </td>
                                                <td>
                                                    <?php if (isset($responsibles[$shop['id']])): ?>
                                                    <span class="badge bg-success"><?php echo htmlspecialchars($responsibles[$shop['id']]); ?></span>
                                                    <?php else: ?>
                                                    <span class="badge bg-warning"><?php echo __('not_assigned'); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="d-flex gap-2">
                                                        <button type="button" class="btn btn-sm btn-info edit-shop-btn" 
                                                                data-id="<?php echo $shop['id']; ?>"
                                                                data-name="<?php echo htmlspecialchars($shop['name']); ?>"
                                                                data-location="<?php echo htmlspecialchars($shop['location'] ?? ''); ?>"
                                                                data-bs-toggle="modal" data-bs-target="#editShopModal">
                                                            <i class="bx bx-edit-alt"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-sm btn-primary" onclick="openAssignModal('<?php echo $shop['id']; ?>', '<?php echo htmlspecialchars($shop['name']); ?>')">
                                                            <i class="bx bx-user-plus"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-sm btn-success" onclick="openResponsibleModal('<?php echo $shop['id']; ?>', '<?php echo htmlspecialchars($shop['name']); ?>')">
                                                            <i class="bx bx-user-check"></i>
                                                        </button>
                                                        <?php if ($shop['employee_count'] == 0): ?>
                                                        <button type="button" class="btn btn-sm btn-danger delete-shop-btn"
                                                                data-id="<?php echo $shop['id']; ?>"
                                                                data-name="<?php echo htmlspecialchars($shop['name']); ?>"
                                                                data-bs-toggle="modal" data-bs-target="#deleteShopModal">
                                                            <i class="bx bx-trash"></i>
                                                        </button>
                                                        <?php endif; ?>
                                                    </div>
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

            </div> <!-- container-fluid -->
        </div>
        <!-- End Page-content -->

        <?php include 'layouts/footer.php'; ?>
    </div>
    <!-- end main content-->

</div>
<!-- END layout-wrapper -->

<!-- Add Shop Modal -->
<div class="modal fade" id="addShopModal" tabindex="-1" aria-labelledby="addShopModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addShopModalLabel"><?php echo __('add_new_shop'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="shops.php">
                <input type="hidden" name="action" value="create">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="name" class="form-label"><?php echo __('shop_name'); ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="location" class="form-label"><?php echo __('location'); ?></label>
                        <input type="text" class="form-control" id="location" name="location">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo __('create'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Shop Modal -->
<div class="modal fade" id="editShopModal" tabindex="-1" aria-labelledby="editShopModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editShopModalLabel"><?php echo __('edit_shop'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="shops.php">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="shop_id" id="edit_shop_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_name" class="form-label">Shop Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_location" class="form-label">Location</label>
                        <input type="text" class="form-control" id="edit_location" name="location">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo __('update'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Shop Modal -->
<div class="modal fade" id="deleteShopModal" tabindex="-1" aria-labelledby="deleteShopModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteShopModalLabel"><?php echo __('delete_shop'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="GET" action="shops.php">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="delete_shop_id">
                <div class="modal-body">
                    <p><?php echo __('shop_delete_confirm'); ?> <strong id="delete_shop_name"></strong>?</p>
                    <p class="text-danger"><?php echo __('action_cannot_be_undone'); ?></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-danger"><?php echo __('delete'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Assign Employees Modal -->
<div class="modal fade" id="assignEmployeesModal" tabindex="-1" aria-labelledby="assignEmployeesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="assignEmployeesModalLabel"><?php echo __('assign_employees_to_shop'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="bx bx-info-circle me-1"></i>
                    <?php echo __('employee_shop_assignment_info'); ?>
                </div>
                <div id="assignModalError" class="alert alert-danger d-none"></div>
                <div id="assignModalSuccess" class="alert alert-success d-none"></div>
                
                <div class="mb-3">
                    <label class="form-label"><?php echo __('shop'); ?>:</label>
                    <h5 id="assignShopName"></h5>
                    <input type="hidden" id="assignShopId" value="">
                </div>
                
                <div class="mb-3">
                    <label for="employeeSearch" class="form-label"><?php echo __('search_employees'); ?>:</label>
                    <input type="text" class="form-control" id="employeeSearch" placeholder="Type to search...">
                </div>
                
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="form-label mb-0"><?php echo __('select_employees'); ?>:</label>
                        <div>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="selectAllEmployees"><?php echo __('select_all'); ?></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary ms-1" id="deselectAllEmployees"><?php echo __('deselect_all'); ?></button>
                        </div>
                    </div>
                    <div id="employeeList" class="border rounded p-3" style="max-height: 300px; overflow-y: auto;">
                        <div class="text-center py-3">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p class="mt-2"><?php echo __('loading_employees'); ?></p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                <button type="button" class="btn btn-primary" id="saveEmployeeAssignments"><?php echo __('save'); ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Assign Responsible Modal -->
<div class="modal fade" id="assignResponsibleModal" tabindex="-1" aria-labelledby="assignResponsibleModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="assignResponsibleModalLabel"><?php echo __('assign_responsible_for'); ?> <span id="responsibleShopName"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="bx bx-info-circle me-1"></i>
                    <?php echo __('responsible_assignment_info'); ?>
                </div>
                <div class="alert alert-danger d-none" id="responsibleModalError"></div>
                <div class="alert alert-success d-none" id="responsibleModalSuccess"></div>
                
                <input type="hidden" id="responsibleShopId" value="">
                
                <div class="mb-3">
                    <label for="employeeResponsibleSearch" class="form-label"><?php echo __('search_employee'); ?></label>
                    <input type="text" class="form-control" id="employeeResponsibleSearch" placeholder="Type to search...">
                </div>
                
                <div class="mb-3">
                    <label class="form-label"><?php echo __('select_responsible_employee'); ?></label>
                    <div id="employeeResponsibleList" style="max-height: 300px; overflow-y: auto;">
                        <div class="d-flex justify-content-center">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('close'); ?></button>
                <button type="button" class="btn btn-primary" id="saveResponsibleAssignment"><?php echo __('save'); ?></button>
            </div>
        </div>
    </div>
</div>

<?php include 'layouts/right-sidebar.php'; ?>

<?php include 'layouts/vendor-scripts.php'; ?>

<!-- Required datatable js -->
<script src="assets/libs/datatables.net/js/jquery.dataTables.min.js"></script>
<script src="assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js"></script>

<!-- Buttons examples -->
<script src="assets/libs/datatables.net-buttons/js/dataTables.buttons.min.js"></script>
<script src="assets/libs/datatables.net-buttons-bs4/js/buttons.bootstrap4.min.js"></script>
<script src="assets/libs/jszip/jszip.min.js"></script>
<script src="assets/libs/pdfmake/build/pdfmake.min.js"></script>
<script src="assets/libs/pdfmake/build/vfs_fonts.js"></script>
<script src="assets/libs/datatables.net-buttons/js/buttons.html5.min.js"></script>
<script src="assets/libs/datatables.net-buttons/js/buttons.print.min.js"></script>
<script src="assets/libs/datatables.net-buttons/js/buttons.colVis.min.js"></script>

<!-- Responsive examples -->
<script src="assets/libs/datatables.net-responsive/js/dataTables.responsive.min.js"></script>
<script src="assets/libs/datatables.net-responsive-bs4/js/responsive.bootstrap4.min.js"></script>

<script>
    // JavaScript to handle modal data
    document.addEventListener('DOMContentLoaded', function() {
        // Edit Modal
        var editButtons = document.querySelectorAll('.edit-shop-btn');
        editButtons.forEach(function(button) {
            button.addEventListener('click', function() {
                var id = this.getAttribute('data-id');
                var name = this.getAttribute('data-name');
                var location = this.getAttribute('data-location');
                
                document.getElementById('edit_shop_id').value = id;
                document.getElementById('edit_name').value = name;
                document.getElementById('edit_location').value = location;
            });
        });
        
        // Delete Modal
        var deleteButtons = document.querySelectorAll('.delete-shop-btn');
        deleteButtons.forEach(function(button) {
            button.addEventListener('click', function() {
                var id = this.getAttribute('data-id');
                var name = this.getAttribute('data-name');
                
                document.getElementById('delete_shop_id').value = id;
                document.getElementById('delete_shop_name').textContent = name;
            });
        });

        // Employee search functionality
        document.getElementById('employeeSearch').addEventListener('keyup', function() {
            var value = this.value.toLowerCase();
            var employeeItems = document.querySelectorAll('.employee-item');
            
            employeeItems.forEach(function(item) {
                var text = item.textContent.toLowerCase();
                if (text.indexOf(value) > -1) {
                    item.style.display = '';
                } else {
                    item.style.display = 'none';
                }
            });
        });
        
        // Select all employees
        document.getElementById('selectAllEmployees').addEventListener('click', function() {
            var checkboxes = document.querySelectorAll('.employee-checkbox');
            checkboxes.forEach(function(checkbox) {
                checkbox.checked = true;
            });
        });
        
        // Deselect all employees
        document.getElementById('deselectAllEmployees').addEventListener('click', function() {
            var checkboxes = document.querySelectorAll('.employee-checkbox');
            checkboxes.forEach(function(checkbox) {
                checkbox.checked = false;
            });
        });
        
        // Save employee assignments
        document.getElementById('saveEmployeeAssignments').addEventListener('click', function() {
            saveEmployeeAssignments();
        });

        // Employee responsible search functionality
        document.getElementById('employeeResponsibleSearch').addEventListener('keyup', function() {
            var value = this.value.toLowerCase();
            var employeeItems = document.querySelectorAll('.employee-responsible-item');
            
            employeeItems.forEach(function(item) {
                var text = item.textContent.toLowerCase();
                if (text.indexOf(value) > -1) {
                    item.style.display = '';
                } else {
                    item.style.display = 'none';
                }
            });
        });
        
        // Save responsible assignment
        document.getElementById('saveResponsibleAssignment').addEventListener('click', function() {
            saveResponsibleAssignment();
        });
    });
    
    // Function to open the assign employees modal
    function openAssignModal(shopId, shopName) {
        document.getElementById('assignShopId').value = shopId;
        document.getElementById('assignShopName').textContent = shopName;
        document.getElementById('assignModalError').classList.add('d-none');
        document.getElementById('assignModalError').textContent = '';
        document.getElementById('assignModalSuccess').classList.add('d-none');
        document.getElementById('assignModalSuccess').textContent = '';
        
        // Load employees
        loadEmployees(shopId);
        
        // Show modal
        var assignModal = new bootstrap.Modal(document.getElementById('assignEmployeesModal'));
        assignModal.show();
    }
    
    // Function to load employees for assignment
    function loadEmployees(shopId) {
        fetch('ajax-handlers/get-employees.php?shop_id=' + shopId)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    renderEmployeeList(data.employees, data.assigned_employees);
                } else {
                    document.getElementById('employeeList').innerHTML = '<div class="alert alert-danger">' + data.message + '</div>';
                }
            })
            .catch(error => {
                document.getElementById('employeeList').innerHTML = '<div class="alert alert-danger"><?php echo __('error_loading_employees'); ?>: ' + error.message + '</div>';
            });
    }
    
    // Function to render the employee list
    function renderEmployeeList(employees, assignedEmployees) {
        var employeeList = document.getElementById('employeeList');
        
        if (employees.length === 0) {
            employeeList.innerHTML = '<div class="alert alert-info"><?php echo __('no_employees_found'); ?></div>';
            return;
        }
        
        var html = '';
        
        employees.forEach(function(employee) {
            var isChecked = assignedEmployees.includes(employee.id) ? 'checked' : '';
            
            html += '<div class="form-check employee-item mb-2">';
            html += '<input class="form-check-input employee-checkbox" type="checkbox" value="' + employee.id + '" id="employee-' + employee.id + '" ' + isChecked + '>';
            html += '<label class="form-check-label" for="employee-' + employee.id + '">';
            html += employee.full_name;
            if (employee.post_title) {
                html += ' <small class="text-muted">(' + employee.post_title + ')</small>';
            }
            html += '</label>';
            html += '</div>';
        });
        
        employeeList.innerHTML = html;
    }
    
    // Function to save employee assignments
    function saveEmployeeAssignments() {
        var shopId = document.getElementById('assignShopId').value;
        var employeeIds = [];
        
        // Get selected employees
        document.querySelectorAll('.employee-checkbox:checked').forEach(function(checkbox) {
            employeeIds.push(checkbox.value);
        });
        
        // Disable save button during save
        var saveButton = document.getElementById('saveEmployeeAssignments');
        saveButton.disabled = true;
        saveButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> <?php echo __('saving'); ?>...';
        
        // Clear previous messages
        var errorElement = document.getElementById('assignModalError');
        var successElement = document.getElementById('assignModalSuccess');
        errorElement.classList.add('d-none');
        errorElement.textContent = '';
        successElement.classList.add('d-none');
        successElement.textContent = '';
        
        // Create form data
        var formData = new FormData();
        formData.append('shop_id', shopId);
        employeeIds.forEach(function(id) {
            formData.append('employee_ids[]', id);
        });
        
        // Send request
        fetch('ajax-handlers/assign-employees.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                successElement.classList.remove('d-none');
                successElement.textContent = data.message;
                
                // Reload the page after a short delay
                setTimeout(function() {
                    window.location.reload();
                }, 1500);
            } else {
                errorElement.classList.remove('d-none');
                errorElement.textContent = data.message;
                saveButton.disabled = false;
                saveButton.textContent = 'Save';
            }
        })
        .catch(error => {
            errorElement.classList.remove('d-none');
            errorElement.textContent = '<?php echo __('error_saving_assignments'); ?>: ' + error.message;
            saveButton.disabled = false;
            saveButton.textContent = 'Save';
        });
    }

    // Function to open the assign responsible modal
    function openResponsibleModal(shopId, shopName) {
        document.getElementById('responsibleShopId').value = shopId;
        document.getElementById('responsibleShopName').textContent = shopName;
        document.getElementById('responsibleModalError').classList.add('d-none');
        document.getElementById('responsibleModalError').textContent = '';
        document.getElementById('responsibleModalSuccess').classList.add('d-none');
        document.getElementById('responsibleModalSuccess').textContent = '';
        
        // Load employees for this shop
        loadEmployeesForResponsible(shopId);
        
        // Show modal
        var responsibleModal = new bootstrap.Modal(document.getElementById('assignResponsibleModal'));
        responsibleModal.show();
    }
    
    // Function to load employees for responsible assignment
    function loadEmployeesForResponsible(shopId) {
        fetch('ajax-handlers/get-employees-for-responsible.php?shop_id=' + shopId)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    renderResponsibleEmployeeList(data.employees, data.current_responsible);
                } else {
                    document.getElementById('employeeResponsibleList').innerHTML = '<div class="alert alert-danger">' + data.message + '</div>';
                }
            })
            .catch(error => {
                document.getElementById('employeeResponsibleList').innerHTML = '<div class="alert alert-danger"><?php echo __('error_loading_employees'); ?>: ' + error.message + '</div>';
            });
    }
    
    // Function to render the employee list for responsible selection
    function renderResponsibleEmployeeList(employees, currentResponsible) {
        var employeeList = document.getElementById('employeeResponsibleList');
        
        if (employees.length === 0) {
            employeeList.innerHTML = '<div class="alert alert-warning">' +
                '<i class="bx bx-error me-1"></i>' +
                '<?php echo __('no_eligible_employees_found'); ?>' +
                '<ul class="mb-0 mt-1">' +
                '<li><?php echo __('responsable_position_required'); ?></li>' +
                '</ul>' +
                '</div>';
            return;
        }
        
        var html = '';
        
        employees.forEach(function(employee) {
            var isChecked = currentResponsible && employee.id === currentResponsible ? 'checked' : '';
            
            html += '<div class="form-check employee-responsible-item mb-2">';
            html += '<input class="form-check-input employee-responsible-radio" type="radio" name="responsibleEmployee" value="' + employee.id + '" id="responsible-' + employee.id + '" ' + isChecked + '>';
            html += '<label class="form-check-label" for="responsible-' + employee.id + '">';
            html += employee.full_name;
            if (employee.post_title) {
                html += ' <small class="text-muted">(' + employee.post_title + ')</small>';
            }
            html += '</label>';
            html += '</div>';
        });
        
        employeeList.innerHTML = html;
    }
    
    // Function to save responsible assignment
    function saveResponsibleAssignment() {
        var shopId = document.getElementById('responsibleShopId').value;
        var selectedEmployee = document.querySelector('input[name="responsibleEmployee"]:checked');
        
        if (!selectedEmployee) {
            document.getElementById('responsibleModalError').classList.remove('d-none');
            document.getElementById('responsibleModalError').textContent = '<?php echo __('please_select_employee'); ?>';
            return;
        }
        
        var employeeId = selectedEmployee.value;
        
        // Disable save button during save
        var saveButton = document.getElementById('saveResponsibleAssignment');
        saveButton.disabled = true;
        saveButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> <?php echo __('saving'); ?>...';
        
        // Clear previous messages
        var errorElement = document.getElementById('responsibleModalError');
        var successElement = document.getElementById('responsibleModalSuccess');
        errorElement.classList.add('d-none');
        errorElement.textContent = '';
        successElement.classList.add('d-none');
        successElement.textContent = '';
        
        // Create form data
        var formData = new FormData();
        formData.append('shop_id', shopId);
        formData.append('employee_id', employeeId);
        
        // Send request
        fetch('ajax-handlers/assign-responsible.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                successElement.classList.remove('d-none');
                successElement.textContent = data.message;
                
                // Reload the page after a short delay
                setTimeout(function() {
                    window.location.reload();
                }, 1500);
            } else {
                errorElement.classList.remove('d-none');
                errorElement.textContent = data.message;
                saveButton.disabled = false;
                saveButton.textContent = 'Save';
            }
        })
        .catch(error => {
            errorElement.classList.remove('d-none');
            errorElement.textContent = '<?php echo __('error_saving_responsible'); ?>: ' + error.message;
            saveButton.disabled = false;
            saveButton.textContent = 'Save';
        });
    }
</script>

<!-- App js -->
<script src="assets/js/app.js"></script>

<!-- Required libs for the employee assignment functionality -->
<script src="assets/libs/select2/js/select2.min.js"></script>

<script>
    // Initialize DataTables
    $(document).ready(function() {
        $('#shopsTable').DataTable({
            responsive: true,
            language: {
                <?php if ($_SESSION['lang'] == 'fr'): ?>
                search: 'Rechercher:',
                paginate: {
                    first: 'Premier',
                    previous: 'Précédent',
                    next: 'Suivant',
                    last: 'Dernier'
                },
                info: 'Affichage de _START_ à _END_ sur _TOTAL_ entrées',
                emptyTable: 'Aucune donnée disponible dans le tableau',
                infoEmpty: 'Affichage de 0 à 0 sur 0 entrées',
                infoFiltered: '(filtré à partir de _MAX_ entrées au total)',
                lengthMenu: 'Afficher _MENU_ entrées',
                loadingRecords: 'Chargement...',
                processing: 'Traitement...',
                zeroRecords: 'Aucun enregistrement correspondant trouvé'
                <?php endif; ?>
            },
            dom: 'Bfrtip',
            buttons: [
                {
                    extend: 'excel',
                    text: '<i class="bx bx-file me-1"></i> Excel',
                    className: 'btn btn-sm btn-success'
                },
                {
                    extend: 'pdf',
                    text: '<i class="bx bx-file-pdf me-1"></i> PDF',
                    className: 'btn btn-sm btn-danger'
                },
                {
                    extend: 'print',
                    text: '<i class="bx bx-printer me-1"></i> <?php echo __("print"); ?>',
                    className: 'btn btn-sm btn-info'
                }
            ],
            lengthMenu: [
                [10, 25, 50, -1], 
                ['10', '25', '50', '<?php echo __("all"); ?>']
            ],
            pageLength: 10
        });
    });
</script>

</body>
</html>
<?php
// End output buffering and send content to browser
ob_end_flush();
?>
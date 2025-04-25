<?php
// Start session
session_start();

// Include layout files
include_once "layouts/config.php";
include_once "layouts/session.php";
include_once "layouts/helpers.php";

// Check for permission
if (!hasPermission('manage_shops')) {
    $_SESSION['error'] = "You don't have permission to manage shop employees";
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
} catch (PDOException $e) {
    $_SESSION['error'] = "Error retrieving shop details: " . $e->getMessage();
    header("Location: shops.php");
    exit();
}

// Get employees assigned to this shop
try {
    $stmt = $pdo->prepare("
        SELECT e.id, e.full_name, e.employee_number, p.title AS position 
        FROM employees e
        JOIN employee_shops es ON e.id = es.employee_id
        LEFT JOIN posts p ON e.post_id = p.id
        WHERE es.shop_id = ?
        ORDER BY e.full_name
    ");
    $stmt->execute([$shop_id]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_SESSION['error'] = "Error retrieving employees: " . $e->getMessage();
    $employees = [];
}

// Page title
$page_title = "Shop Employees - " . $shop['name'];

include "layouts/header.php";
?>

<div class="page-content">
    <div class="container-fluid">

        <!-- Page title -->
        <div class="row">
            <div class="col-12">
                <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                    <h4 class="mb-sm-0 font-size-18">Employees: <?= htmlspecialchars($shop['name']) ?></h4>

                    <div class="page-title-right">
                        <ol class="breadcrumb m-0">
                            <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="shops.php">Shops</a></li>
                            <li class="breadcrumb-item active">Shop Employees</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- Success/Error messages -->
        <?php include "layouts/alert.php"; ?>

        <div class="row">
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">Shop Information</h4>
                        
                        <div class="table-responsive">
                            <table class="table table-nowrap mb-0">
                                <tbody>
                                    <tr>
                                        <th scope="row">Name:</th>
                                        <td><?= htmlspecialchars($shop['name']) ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Location:</th>
                                        <td><?= htmlspecialchars($shop['location'] ?? 'Not specified') ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Created:</th>
                                        <td><?= date('M d, Y', strtotime($shop['created_at'])) ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Total Employees:</th>
                                        <td><span class="badge bg-primary"><?= count($employees) ?></span></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="mt-3">
                            <a href="shops.php" class="btn btn-secondary">Back to Shops</a>
                            <button type="button" class="btn btn-primary" onclick="openAssignModal('<?= $shop['id'] ?>', '<?= htmlspecialchars($shop['name']) ?>')">
                                Manage Employees
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">Assigned Employees</h4>
                        
                        <?php if (empty($employees)): ?>
                            <div class="alert alert-info">No employees assigned to this shop yet.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover datatable">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>Employee Number</th>
                                            <th>Name</th>
                                            <th>Position</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($employees as $index => $employee): ?>
                                            <tr>
                                                <td><?= $index + 1 ?></td>
                                                <td><?= htmlspecialchars($employee['employee_number'] ?? 'N/A') ?></td>
                                                <td><?= htmlspecialchars($employee['full_name']) ?></td>
                                                <td>
                                                    <?php 
                                                    $position = htmlspecialchars($employee['position'] ?? 'N/A');
                                                    echo $position;
                                                    
                                                    // Add badge if position is a manager/responsible
                                                    $positionLower = strtolower($position);
                                                    if (strpos($positionLower, 'manager') !== false || 
                                                        strpos($positionLower, 'responsible') !== false || 
                                                        strpos($positionLower, 'supervisor') !== false) {
                                                        echo ' <span class="badge bg-primary">Shop Responsible</span>';
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <a href="edit-employee.php?id=<?= $employee['id'] ?>" class="btn btn-sm btn-info">
                                                        <i class="bx bx-edit"></i> Edit
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Assign Employees Modal -->
<div class="modal fade" id="assignEmployeesModal" tabindex="-1" aria-labelledby="assignEmployeesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="assignEmployeesModalLabel">Assign Employees to Shop</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="assignModalError" class="alert alert-danger d-none"></div>
                <div id="assignModalSuccess" class="alert alert-success d-none"></div>
                
                <div class="mb-3">
                    <label class="form-label">Shop:</label>
                    <h5 id="assignShopName"></h5>
                    <input type="hidden" id="assignShopId" value="">
                </div>
                
                <div class="mb-3">
                    <label for="employeeSearch" class="form-label">Search Employees:</label>
                    <input type="text" class="form-control" id="employeeSearch" placeholder="Type to search...">
                </div>
                
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="form-label mb-0">Select Employees:</label>
                        <div>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="selectAllEmployees">Select All</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary ms-1" id="deselectAllEmployees">Deselect All</button>
                        </div>
                    </div>
                    <div id="employeeList" class="border rounded p-3" style="max-height: 300px; overflow-y: auto;">
                        <div class="text-center py-3">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p class="mt-2">Loading employees...</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveEmployeeAssignments">Save</button>
            </div>
        </div>
    </div>
</div>

<?php include "layouts/footer.php"; ?>

<script>
    $(document).ready(function() {
        // Initialize DataTable
        $('.datatable').DataTable({
            responsive: true,
            lengthChange: false,
            pageLength: 10,
            searching: true,
            ordering: true
        });
        
        // Employee search functionality
        $('#employeeSearch').on('keyup', function() {
            var value = $(this).val().toLowerCase();
            $('.employee-item').filter(function() {
                $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
            });
        });
        
        // Select all employees
        $('#selectAllEmployees').on('click', function() {
            $('.employee-checkbox').prop('checked', true);
        });
        
        // Deselect all employees
        $('#deselectAllEmployees').on('click', function() {
            $('.employee-checkbox').prop('checked', false);
        });
        
        // Save employee assignments
        $('#saveEmployeeAssignments').on('click', function() {
            saveEmployeeAssignments();
        });
    });
    
    // Function to open the assign employees modal
    function openAssignModal(shopId, shopName) {
        $('#assignShopId').val(shopId);
        $('#assignShopName').text(shopName);
        $('#assignModalError').addClass('d-none').text('');
        $('#assignModalSuccess').addClass('d-none').text('');
        
        // Load employees
        loadEmployees(shopId);
        
        // Show modal
        var assignModal = new bootstrap.Modal(document.getElementById('assignEmployeesModal'));
        assignModal.show();
    }
    
    // Function to load employees for assignment
    function loadEmployees(shopId) {
        $.ajax({
            url: 'ajax-handlers/get-employees.php',
            type: 'GET',
            data: {
                shop_id: shopId
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    renderEmployeeList(response.employees, response.assigned_employees);
                } else {
                    $('#employeeList').html('<div class="alert alert-danger">' + response.message + '</div>');
                }
            },
            error: function(xhr, status, error) {
                $('#employeeList').html('<div class="alert alert-danger">Error loading employees: ' + error + '</div>');
            }
        });
    }
    
    // Function to render the employee list
    function renderEmployeeList(employees, assignedEmployees) {
        if (employees.length === 0) {
            $('#employeeList').html('<div class="alert alert-info">No employees found.</div>');
            return;
        }
        
        // Group employees by position
        var positionGroups = {};
        var positionsWithLimit = ['manager', 'responsible', 'supervisor']; // Types that are limited to one per shop
        
        employees.forEach(function(employee) {
            var position = employee.post_title || 'Unassigned';
            if (!positionGroups[position]) {
                positionGroups[position] = [];
            }
            positionGroups[position].push(employee);
        });
        
        var html = '';
        
        // For each position group
        Object.keys(positionGroups).sort().forEach(function(position) {
            var isLimitedPosition = positionsWithLimit.some(function(limitedPos) {
                return position.toLowerCase().includes(limitedPos);
            });
            
            html += '<div class="position-group mb-3">';
            html += '<h6 class="mb-2">' + position;
            
            // Add badge if position is limited
            if (isLimitedPosition) {
                html += ' <span class="badge bg-warning text-dark">Max: 1 per shop</span>';
            }
            
            html += '</h6>';
            
            // Add employees in this position
            positionGroups[position].forEach(function(employee) {
                var isChecked = assignedEmployees.includes(employee.id) ? 'checked' : '';
                
                html += '<div class="form-check employee-item ms-3 mb-2">';
                html += '<input class="form-check-input employee-checkbox" type="checkbox" value="' + employee.id + '" ' +
                        'data-position="' + position + '" ' +
                        'data-limited="' + (isLimitedPosition ? 'true' : 'false') + '" ' +
                        'id="employee-' + employee.id + '" ' + isChecked + '>';
                html += '<label class="form-check-label" for="employee-' + employee.id + '">';
                html += employee.full_name;
                html += '</label>';
                html += '</div>';
            });
            
            html += '</div>';
        });
        
        $('#employeeList').html(html);
        
        // Add event listener for limited position checkboxes
        $('.employee-checkbox[data-limited="true"]').change(function() {
            if ($(this).is(':checked')) {
                var position = $(this).data('position');
                // Uncheck other checkboxes with the same position
                $('.employee-checkbox[data-position="' + position + '"]').not(this).prop('checked', false);
            }
        });
    }
    
    // Function to save employee assignments
    function saveEmployeeAssignments() {
        var shopId = $('#assignShopId').val();
        var employeeIds = [];
        
        // Get selected employees
        $('.employee-checkbox:checked').each(function() {
            employeeIds.push($(this).val());
        });
        
        // Disable save button during save
        $('#saveEmployeeAssignments').prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...');
        
        // Clear previous messages
        $('#assignModalError').addClass('d-none').text('');
        $('#assignModalSuccess').addClass('d-none').text('');
        
        $.ajax({
            url: 'ajax-handlers/assign-employees.php',
            type: 'POST',
            data: {
                shop_id: shopId,
                employee_ids: employeeIds
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#assignModalSuccess').removeClass('d-none').text(response.message);
                    
                    // Reload the page after a short delay
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    $('#assignModalError').removeClass('d-none').text(response.message);
                    $('#saveEmployeeAssignments').prop('disabled', false).text('Save');
                }
            },
            error: function(xhr, status, error) {
                $('#assignModalError').removeClass('d-none').text('Error saving assignments: ' + error);
                $('#saveEmployeeAssignments').prop('disabled', false).text('Save');
            }
        });
    }
</script> 
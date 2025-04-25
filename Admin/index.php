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

// Get user info
$username = $_SESSION["username"] ?? "User";
$userRole = "Administrator"; // Default role name

// Try to get the actual role name
if (isset($_SESSION["role_id"])) {
    try {
        $stmt = $pdo->prepare("SELECT name FROM roles WHERE id = ?");
        $stmt->execute([$_SESSION["role_id"]]);
        $role = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($role) {
            $userRole = $role['name'];
        }
    } catch (PDOException $e) {
        // Just use the default role name if there's an error
    }
}

// Get total employees
$stmt = $pdo->query("SELECT COUNT(*) as total FROM employees");
$total_employees = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Get total roles
$stmt = $pdo->query("SELECT COUNT(*) as total FROM roles");
$total_roles = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Get total positions
$stmt = $pdo->query("SELECT COUNT(*) as total FROM posts");
$total_positions = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Get total users
$stmt = $pdo->query("SELECT COUNT(*) as total FROM users");
$total_users = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Get total shops
$stmt = $pdo->query("SELECT COUNT(*) as total FROM shops");
$total_shops = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
?>

<?php include 'layouts/head-main.php'; ?>

<head>
    <title>Dashboard | Employee Manager System</title>
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
                            <h4 class="mb-sm-0 font-size-18">Dashboard</h4>

                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="javascript: void(0);">Employee Manager</a></li>
                                    <li class="breadcrumb-item active">Dashboard</li>
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

                <!-- Welcome message -->
                <div class="row">
                    <div class="col-xl-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h5 class="card-title">Welcome back, <?php echo htmlspecialchars($username); ?>!</h5>
                                        <p class="text-muted mb-0">You are logged in as <?php echo htmlspecialchars($userRole); ?></p>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <div class="avatar-sm rounded-circle bg-primary">
                                            <span class="avatar-title rounded-circle bg-primary">
                                                <?php echo strtoupper(substr($username, 0, 1)); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                                </div>

                <!-- Stats widgets -->
                <div class="row">
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <span class="text-muted mb-3 lh-1 d-block text-truncate">Total Employees</span>
                                        <h4 class="mb-3">
                                            <span class="counter-value" data-target="<?php echo $total_employees; ?>"><?php echo $total_employees; ?></span>
                                        </h4>
                                        <div class="text-nowrap">
                                            <a href="employees.php" class="text-primary">View Details <i class="mdi mdi-arrow-right ms-1"></i></a>
                                        </div>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-soft-primary rounded-circle text-primary">
                                            <i class="bx bx-user-circle font-size-24"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                                </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card card-h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <span class="text-muted mb-3 lh-1 d-block text-truncate">Total Positions</span>
                                        <h4 class="mb-3">
                                            <span class="counter-value" data-target="<?php echo $total_positions; ?>"><?php echo $total_positions; ?></span>
                                        </h4>
                                        <div class="text-nowrap">
                                            <a href="posts.php" class="text-primary">View Details <i class="mdi mdi-arrow-right ms-1"></i></a>
                                        </div>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-soft-success rounded-circle text-success">
                                            <i class="bx bx-briefcase font-size-24"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                                </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card card-h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <span class="text-muted mb-3 lh-1 d-block text-truncate">Total Shops</span>
                                        <h4 class="mb-3">
                                            <span class="counter-value" data-target="<?php echo $total_shops; ?>"><?php echo $total_shops; ?></span>
                                        </h4>
                                        <div class="text-nowrap">
                                            <?php if (hasPermission('manage_shops')): ?>
                                            <a href="shops.php" class="text-primary">View Details <i class="mdi mdi-arrow-right ms-1"></i></a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-soft-warning rounded-circle text-warning">
                                            <i class="bx bx-store font-size-24"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6">
                        <div class="card card-h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <span class="text-muted mb-3 lh-1 d-block text-truncate">Total Users</span>
                                        <h4 class="mb-3">
                                            <span class="counter-value" data-target="<?php echo $total_users; ?>"><?php echo $total_users; ?></span>
                                        </h4>
                                        <div class="text-nowrap">
                                            <?php if (hasPermission('manage_users')): ?>
                                            <a href="users.php" class="text-primary">View Details <i class="mdi mdi-arrow-right ms-1"></i></a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="avatar-sm">
                                        <span class="avatar-title bg-soft-info rounded-circle text-info">
                                            <i class="bx bx-user font-size-24"></i>
                                        </span>
                                </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Quick actions -->
                <div class="row">
                    <div class="col-xl-12">
                        <div class="card">
                            <div class="card-header">
                                <h4 class="card-title">Quick Actions</h4>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-3">
                                        <a href="employees.php" class="btn btn-primary btn-lg w-100 mb-3">
                                            <i class="mdi mdi-account-multiple me-1"></i> Manage Employees
                                        </a>
                                    </div>
                                    <div class="col-md-3">
                                        <a href="posts.php" class="btn btn-success btn-lg w-100 mb-3">
                                            <i class="mdi mdi-briefcase me-1"></i> Manage Positions
                                        </a>
                                    </div>
                                    <div class="col-md-3">
                                        <a href="roles.php" class="btn btn-info btn-lg w-100 mb-3">
                                            <i class="mdi mdi-shield-account me-1"></i> Manage Roles
                                        </a>
                                    </div>
                                    <div class="col-md-3">
                                        <a href="users.php" class="btn btn-warning btn-lg w-100 mb-3">
                                            <i class="mdi mdi-account-cog me-1"></i> Manage Users
                                        </a>
                                    </div>
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

<?php include 'layouts/vendor-scripts.php'; ?>

<!-- App js -->
<script src="assets/js/app.js"></script>

</body>
</html>
<?php ob_end_flush(); ?>
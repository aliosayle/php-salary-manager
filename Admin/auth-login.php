<?php
// Start a PHP session if not already started
if (session_status() === PHP_SESSION_NONE) {
session_start();
}

// Include config file
require_once "layouts/config.php";
require_once "layouts/helpers.php";
require_once "layouts/permission-checker.php"; // Include permission functions

// Check if the user is already logged in, if yes then redirect to index page
if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    header("location: index.php");
    exit;
}

// Define variables and initialize with empty values
$email = $password = "";
$email_err = $password_err = $login_err = "";

// Processing form data when form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
        // Check if email is empty
        if (empty(trim($_POST["email"]))) {
            $email_err = "Please enter email.";
        } else {
            $email = sanitizeInput($_POST["email"]);
            // Validate email format
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $email_err = "Invalid email format.";
            }
        }
        
        // Check if password is empty
        if (empty(trim($_POST["password"]))) {
            $password_err = "Please enter your password.";
        } else {
            $password = trim($_POST["password"]);
        }
        
        // Validate credentials
        if (empty($email_err) && empty($password_err)) {
            try {
                // Prepare a select statement
            $sql = "SELECT id, username, email, password_hash, role_id, is_active FROM users WHERE email = ?";
                $stmt = $pdo->prepare($sql);
                
                // Execute with parameters
                $stmt->execute([$email]);
                
                // Check if email exists
                if ($stmt->rowCount() == 1) {
                    // Fetch user data
                    $row = $stmt->fetch();
                    
                    // Check if user is active
                    if (!$row['is_active']) {
                        $login_err = "Your account is inactive. Please contact an administrator.";
                    } else {
                        // Verify password
                    if (password_verify($password, $row['password_hash'])) {
                        // Start session if needed
                        if (session_status() === PHP_SESSION_NONE) {
                            session_start();
                        }
                        
                        // Store data in session variables
                        $_SESSION["loggedin"] = true;
                        $_SESSION["user_id"] = $row['id'];  // Set user_id for permission checks
                        $_SESSION["id"] = $row['id'];       // Keep id for backward compatibility
                        $_SESSION["name"] = $row['username']; // Use username as name
                        $_SESSION["username"] = $row['username']; 
                        $_SESSION["email"] = $row['email'];
                        $_SESSION["role_id"] = $row['role_id'];
                        $_SESSION["last_activity"] = time();
                                
                        // This system does not use datasets - all dataset checks have been removed
                        
                        // Log successful login
                        error_log("User logged in: {$row['email']} (ID: {$row['id']}, Role: {$row['role_id']})");
                        
                        // Load user permissions into session
                        loadUserPermissions($row['id'], $row['role_id']);
                        error_log("Loaded " . count($_SESSION['permissions'] ?? []) . " permissions for user");
                        
                        // Check if there's a redirect URL stored in session
                        if (isset($_SESSION['redirect_url'])) {
                            $redirect = $_SESSION['redirect_url'];
                                unset($_SESSION['redirect_url']);
                            header("Location: " . $redirect);
                        } else {
                            // Redirect to dashboard
                            header("Location: index.php");
                        }
                        exit;
                    } else {
                        $login_err = "Invalid email or password.";
                        }
                    }
                } else {
                // Email doesn't exist
                $login_err = "Invalid email or password.";
                }
            } catch(PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            $login_err = "Oops! Something went wrong. Please try again later.";
        }
    }
}
?>
<?php include 'layouts/head-main.php'; ?>

<head>
    <title>Login | Employee Manager System</title>
    <?php include 'layouts/head.php'; ?>
    <?php include 'layouts/head-style.php'; ?>
</head>

<?php include 'layouts/body.php'; ?>
<div class="auth-page">
    <div class="container-fluid p-0">
        <div class="row g-0">
            <div class="col-xxl-3 col-lg-4 col-md-5">
                <div class="auth-full-page-content d-flex p-sm-5 p-4">
                    <div class="w-100">
                        <div class="d-flex flex-column h-100">
                            <div class="mb-4 mb-md-5 text-center">
                                <a href="index.php" class="d-block auth-logo">
                                    <img src="assets/images/logo-sm.svg" alt="" height="28"> <span class="logo-txt">Employee Manager</span>
                                </a>
                            </div>
                            <div class="auth-content my-auto">
                                <div class="text-center">
                                    <h5 class="mb-0">Welcome Back!</h5>
                                    <p class="text-muted mt-2">Sign in to continue to Employee Manager System.</p>
                                </div>
                                
                                <?php if(!empty($login_err)){ ?>
                                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                    <?php echo $login_err; ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                </div>
                                <?php } ?>
                                
                                <form class="custom-form mt-4 pt-2" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                                    <div class="mb-3 <?php echo (!empty($email_err)) ? 'has-error' : ''; ?>">
                                        <label class="form-label" for="email">Email</label>
                                        <input type="email" class="form-control" id="email" placeholder="Enter email" name="email" value="<?php echo htmlspecialchars($email); ?>">
                                        <?php if (!empty($email_err)) { ?>
                                            <span class="text-danger"><?php echo $email_err; ?></span>
                                        <?php } ?>
                                    </div>
                                    <div class="mb-3 <?php echo (!empty($password_err)) ? 'has-error' : ''; ?>">
                                        <div class="d-flex align-items-start">
                                            <div class="flex-grow-1">
                                                <label class="form-label" for="password">Password</label>
                                            </div>
                                            <div class="flex-shrink-0">
                                                <div class="">
                                                    <a href="auth-recoverpw.php" class="text-muted">Forgot password?</a>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="input-group auth-pass-inputgroup">
                                            <input type="password" class="form-control" placeholder="Enter password" name="password" aria-label="Password" aria-describedby="password-addon">
                                            <button class="btn btn-light ms-0" type="button" id="password-addon"><i class="mdi mdi-eye-outline"></i></button>
                                        </div>
                                        <?php if (!empty($password_err)) { ?>
                                            <span class="text-danger"><?php echo $password_err; ?></span>
                                        <?php } ?>
                                    </div>
                                    <div class="row mb-4">
                                        <div class="col">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="remember-check">
                                                <label class="form-check-label" for="remember-check">
                                                    Remember me
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <button class="btn btn-primary w-100 waves-effect waves-light" type="submit">Log In</button>
                                    </div>
                                </form>

                                <div class="mt-5 text-center">
                                    <p class="text-muted mb-0">Don't have an account? <a href="auth-register.php" class="text-primary fw-semibold">Register</a></p>
                                </div>
                            </div>
                            <div class="mt-4 mt-md-5 text-center">
                                <p class="mb-0">© <script>document.write(new Date().getFullYear())</script> Employee Manager. All rights reserved.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xxl-9 col-lg-8 col-md-7">
                <div class="auth-bg pt-md-5 p-4 d-flex">
                    <div class="bg-overlay bg-primary"></div>
                    <ul class="bg-bubbles">
                        <li></li>
                        <li></li>
                        <li></li>
                        <li></li>
                        <li></li>
                        <li></li>
                        <li></li>
                        <li></li>
                        <li></li>
                        <li></li>
                    </ul>
                    <div class="row justify-content-center align-items-center">
                        <div class="col-xl-7">
                            <div class="p-0 p-sm-4 px-xl-0">
                                <div class="text-white">
                                    <h5 class="text-white">Employee Management Made Simple</h5>
                                    <p class="mt-3">Streamline your employee information, records, and more with our comprehensive Employee Manager System.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- JAVASCRIPT -->
<?php include 'layouts/vendor-scripts.php'; ?>
<!-- password addon init -->
<script src="assets/js/pages/pass-addon.init.js"></script>

</body>

</html>
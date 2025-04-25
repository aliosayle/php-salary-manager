<?php
/**
 * Admin User Creation Script
 * 
 * This script creates an admin user in the database
 * Run this script once to create the initial admin user
 */

// Include configuration and helper files
require_once "layouts/config.php";
require_once "layouts/helpers.php";

// Check if script is being run from command line
$isCli = (php_sapi_name() === 'cli');

// Default admin user details
$adminName = "Administrator";
$adminEmail = "admin@example.com";
$adminPassword = "Admin@123"; // This will be overridden if provided as an argument

// Get admin details from command line arguments if running from CLI
if ($isCli) {
    echo "Running Admin User Creation Script...\n";
    
    // Check for command line arguments
    if ($argc >= 4) {
        $adminName = $argv[1];
        $adminEmail = $argv[2];
        $adminPassword = $argv[3];
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // If running from web, get details from form submission
    if (isset($_POST['name']) && isset($_POST['email']) && isset($_POST['password'])) {
        $adminName = trim($_POST['name']);
        $adminEmail = trim($_POST['email']);
        $adminPassword = $_POST['password'];
    }
}

// Validate inputs
$errors = [];

if (empty($adminName)) {
    $errors[] = "Admin name is required";
}

if (empty($adminEmail)) {
    $errors[] = "Admin email is required";
} elseif (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    $errors[] = "Invalid email format";
}

if (empty($adminPassword)) {
    $errors[] = "Admin password is required";
} elseif (strlen($adminPassword) < 8) {
    $errors[] = "Password must be at least 8 characters long";
} elseif (!preg_match("/[A-Z]/", $adminPassword) || 
          !preg_match("/[a-z]/", $adminPassword) || 
          !preg_match("/[0-9]/", $adminPassword)) {
    $errors[] = "Password must contain at least one uppercase letter, one lowercase letter, and one number";
}

// Check if there are any errors
if (!empty($errors)) {
    if ($isCli) {
        echo "Errors found:\n";
        foreach ($errors as $error) {
            echo "- " . $error . "\n";
        }
        echo "Usage: php create-admin.php [name] [email] [password]\n";
        exit(1);
    } else {
        // Display errors and show form again
    }
} else {
    try {
        // Begin transaction
        $pdo->beginTransaction();
        
        // Check if the admin role exists, create it if not
        $roleStmt = $pdo->prepare("SELECT id FROM roles WHERE name = ?");
        $roleStmt->execute(["Administrator"]);
        
        if ($roleStmt->rowCount() === 0) {
            // Create admin role
            $createRoleStmt = $pdo->prepare("INSERT INTO roles (name, description) VALUES (?, ?)");
            $createRoleStmt->execute(["Administrator", "System administrator with full access"]);
            $roleId = $pdo->lastInsertId();
            
            echo $isCli ? "Created Administrator role\n" : "<p>Created Administrator role</p>";
        } else {
            $roleId = $roleStmt->fetch()['id'];
        }
        
        // Check if user with this email already exists
        $userStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $userStmt->execute([$adminEmail]);
        
        if ($userStmt->rowCount() > 0) {
            throw new Exception("A user with this email already exists");
        }
        
        // Hash the password
        $passwordHash = hashPassword($adminPassword);
        
        // Insert admin user
        $userStmt = $pdo->prepare("
            INSERT INTO users (name, email, password_hash, role_id, is_active, created_at) 
            VALUES (?, ?, ?, ?, 1, NOW())
        ");
        
        $userStmt->execute([$adminName, $adminEmail, $passwordHash, $roleId]);
        $userId = $pdo->lastInsertId();
        
        // Assign all permissions to admin role
        $permStmt = $pdo->prepare("SELECT id FROM permissions");
        $permStmt->execute();
        $permissions = $permStmt->fetchAll();
        
        foreach ($permissions as $permission) {
            $permRoleStmt = $pdo->prepare("
                INSERT INTO permissions_per_role (role_id, permission_id, is_granted) 
                VALUES (?, ?, 1)
            ");
            $permRoleStmt->execute([$roleId, $permission['id']]);
        }
        
        // Commit the transaction
        $pdo->commit();
        
        $successMessage = "Admin user created successfully!";
        if ($isCli) {
            echo $successMessage . "\n";
            echo "Name: $adminName\n";
            echo "Email: $adminEmail\n";
            exit(0);
        }
    } catch (Exception $e) {
        // Rollback the transaction on error
        $pdo->rollBack();
        
        $errorMessage = "Error creating admin user: " . $e->getMessage();
        if ($isCli) {
            echo $errorMessage . "\n";
            exit(1);
        }
    }
}

// Web interface if not run from CLI
if (!$isCli) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Create Admin User</title>
        <link href="assets/css/bootstrap.min.css" rel="stylesheet" type="text/css" />
        <style>
            body {
                background-color: #f8f9fa;
                padding-top: 50px;
            }
            .container {
                max-width: 500px;
            }
            .card {
                border-radius: 10px;
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            }
            .card-header {
                background-color: #4e73df;
                color: white;
                border-radius: 10px 10px 0 0 !important;
            }
            .btn-primary {
                background-color: #4e73df;
                border-color: #4e73df;
            }
            .btn-primary:hover {
                background-color: #2e59d9;
                border-color: #2e59d9;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="card">
                <div class="card-header text-center">
                    <h4 class="mb-0">Create Admin User</h4>
                </div>
                <div class="card-body">
                    <?php if (isset($successMessage)): ?>
                    <div class="alert alert-success" role="alert">
                        <?php echo $successMessage; ?>
                        <hr>
                        <p class="mb-0">You can now <a href="auth-login.php" class="alert-link">log in</a> with these credentials.</p>
                    </div>
                    <?php elseif (isset($errorMessage)): ?>
                    <div class="alert alert-danger" role="alert">
                        <?php echo $errorMessage; ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!isset($successMessage)): ?>
                    <form method="post">
                        <div class="mb-3">
                            <label for="name" class="form-label">Admin Name</label>
                            <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($adminName ?? ''); ?>" required>
                            <?php if (isset($errors) && in_array("Admin name is required", $errors)): ?>
                            <div class="text-danger"><?php echo "Admin name is required"; ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Admin Email</label>
                            <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($adminEmail ?? ''); ?>" required>
                            <?php foreach ($errors ?? [] as $error): ?>
                                <?php if (strpos($error, "email") !== false): ?>
                                <div class="text-danger"><?php echo $error; ?></div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Admin Password</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                            <?php foreach ($errors ?? [] as $error): ?>
                                <?php if (strpos($error, "Password") !== false): ?>
                                <div class="text-danger"><?php echo $error; ?></div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <div class="form-text">Password must be at least 8 characters long and contain at least one uppercase letter, one lowercase letter, and one number.</div>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">Create Admin User</button>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <script src="assets/libs/jquery/jquery.min.js"></script>
        <script src="assets/libs/bootstrap/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>
    <?php
}
?> 
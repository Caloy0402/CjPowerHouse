<?php
session_start();
require_once 'dbconn.php'; // Database connection

$error = ""; // Error message variable

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);

    if (!empty($email) && !empty($password)) {
        $stmt = $conn->prepare("SELECT id, full_name, email, password, role FROM cjusers WHERE email = ?");
        if ($stmt) {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows == 1) {
                $user = $result->fetch_assoc();
                if (password_verify($password, $user['password'])) {
                    // Set session variables
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['profile_image'] = $user['profile_image']; // Store the profile image path

                    // Redirect based on role
                    if ($user['role'] == 'Admin') {
                        header("Location: Admin-Dashboard.php");
                    } elseif ($user['role'] == 'Cashier') {
                        header("Location: Cashier-Dashboard.php");
                    } else {
                        $error = "Unauthorized access.";
                    }
                    exit();
                } else {
                    $error = "Invalid email or password.";
                }
            } else {
                $error = "User not found.";
            }
            $stmt->close();
        } else {
            $error = "SQL error: " . $conn->error;
        }
    } else {
        $error = "Please enter email and password.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - CJ Powerhouse</title>
    <link rel="icon" type="image/png" href="Image/logo.png">
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">

    <style>
        /* Centered loading spinner */
        #loading-spinner {
            display: none; /* Initially hidden */
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 9999;
        }
    </style>
</head>
<body>
    <!-- Spinner -->
    <div id="loading-spinner">
        <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>

    <div class="container d-flex justify-content-center align-items-center vh-100">
        <div class="bg-light p-5 rounded shadow-sm" style="max-width: 400px; width: 100%;">
            <h3 class="text-center">Sign In</h3>
            
            <?php if (!empty($error)): ?>
                <p class="text-danger text-center"><?= htmlspecialchars($error); ?></p>
            <?php endif; ?>

            <form method="POST" action="login.php" onsubmit="showSpinner()">
                <div class="mb-3">
                    <label class="form-label">Email address</label>
                    <input type="email" name="email" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <button id="login-btn" type="submit" class="btn btn-primary w-100">Login</button>
            </form>
        </div>
    </div>

    <script>
        function showSpinner() {
            document.getElementById("loading-spinner").style.display = "block"; 
            document.getElementById("login-btn").disabled = true; 
        }

        function hideSpinner() {
            document.getElementById("loading-spinner").style.display = "none"; 
            document.getElementById("login-btn").disabled = false; 
        }
        
      
        window.onload = function() {
            hideSpinner();
        };
    </script>
</body>
</html>

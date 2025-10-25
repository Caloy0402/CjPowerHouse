<?php
session_start();
require_once 'dbconn.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: signin.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$success_message = '';
$error_message = '';

// Handle form submission for updating profile
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $first_name = trim($_POST['first_name']);
    $middle_name = trim($_POST['middle_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $home_address = trim($_POST['home_address']);
    $contact_info = trim($_POST['contact_info']);
    
    // Validate required fields
    if (empty($first_name) || empty($last_name) || empty($email)) {
        $error_message = "First name, last name, and email are required.";
    } else {
        // Check if email is already taken by another user
        $check_email = $conn->prepare("SELECT id FROM cjusers WHERE email = ? AND id != ?");
        $check_email->bind_param("si", $email, $user_id);
        $check_email->execute();
        $email_result = $check_email->get_result();
        
        if ($email_result->num_rows > 0) {
            $error_message = "Email address is already taken by another user.";
        } else {
            // Handle profile image upload
            $profile_image = $_SESSION['profile_image']; // Keep current image by default
            
            if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == 0) {
                $image_name = $_FILES['profile_image']['name'];
                $image_tmp_name = $_FILES['profile_image']['tmp_name'];
                $image_ext = strtolower(pathinfo($image_name, PATHINFO_EXTENSION));
                
                $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
                
                if (in_array($image_ext, $allowed_ext)) {
                    $new_image_name = uniqid() . '.' . $image_ext;
                    $upload_dir = 'uploads/';
                    $upload_path = $upload_dir . $new_image_name;
                    
                    if (move_uploaded_file($image_tmp_name, $upload_path)) {
                        $profile_image = $new_image_name;
                    }
                }
            }
            
            // Update user profile
            $update_sql = "UPDATE cjusers SET first_name = ?, middle_name = ?, last_name = ?, email = ?, home_address = ?, contact_info = ?, profile_image = ? WHERE id = ?";
            $stmt = $conn->prepare($update_sql);
            
            if ($stmt) {
                $stmt->bind_param("sssssssi", $first_name, $middle_name, $last_name, $email, $home_address, $contact_info, $profile_image, $user_id);
                
                if ($stmt->execute()) {
                    $success_message = "Profile updated successfully!";
                    // Update session variables
                    $_SESSION['full_name'] = $first_name . ' ' . $last_name;
                    $_SESSION['profile_image'] = $profile_image;
                } else {
                    $error_message = "Error updating profile: " . $stmt->error;
                }
                $stmt->close();
            } else {
                $error_message = "Database error: " . $conn->error;
            }
        }
        $check_email->close();
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error_message = "All password fields are required.";
    } elseif ($new_password !== $confirm_password) {
        $error_message = "New password and confirm password do not match.";
    } elseif (strlen($new_password) < 6) {
        $error_message = "New password must be at least 6 characters long.";
    } else {
        // Verify current password
        $check_password = $conn->prepare("SELECT password FROM cjusers WHERE id = ?");
        $check_password->bind_param("i", $user_id);
        $check_password->execute();
        $password_result = $check_password->get_result();
        
        if ($password_result->num_rows > 0) {
            $user_data = $password_result->fetch_assoc();
            
            if (password_verify($current_password, $user_data['password'])) {
                // Update password
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $update_password = $conn->prepare("UPDATE cjusers SET password = ? WHERE id = ?");
                $update_password->bind_param("si", $hashed_password, $user_id);
                
                if ($update_password->execute()) {
                    $success_message = "Password changed successfully!";
                } else {
                    $error_message = "Error changing password: " . $update_password->error;
                }
                $update_password->close();
            } else {
                $error_message = "Current password is incorrect.";
            }
        } else {
            $error_message = "User not found.";
        }
        $check_password->close();
    }
}

// Fetch current user data
$user_sql = "SELECT * FROM cjusers WHERE id = ?";
$user_stmt = $conn->prepare($user_sql);
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user_data = $user_result->fetch_assoc();
$user_stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Admin Profile - CJ Powerhouse</title>
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <meta content="" name="keywords">
    <meta content="" name="description">

    <!-- Favicons -->
    <link href="Image/logo.png" rel="icon">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600&family=Roboto:wght@500;700&display=swap" rel="stylesheet">

    <!-- icon font Stylesheet -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.10.0/css/all.min.css"> 
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.4.1/font/bootstrap-icons.css">

    <!--libraries stylesheet-->
    <link href="lib/owlcarousel/assets/owl.carousel.min.css" rel="stylesheet">
    <link href="lib/tempusdominus/css/tempusdominus-bootstrap-4.min.css" rel="stylesheet">

    <!--customized Bootstrap Stylesheet-->
    <link href="css/bootstrap.min.css" rel="stylesheet">

    <!--Template Stylesheet-->
    <link href="css/style.css" rel="stylesheet">
    
    <style>
        .form-control.bg-dark {
            background-color: #343a40 !important;
            color: #ffffff !important;
            border-color: #6c757d !important;
        }
        
        .form-control.bg-dark:focus {
            background-color: #343a40 !important;
            color: #ffffff !important;
            border-color: #007bff !important;
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25) !important;
        }
        
        .form-control.bg-dark::placeholder {
            color: #adb5bd !important;
        }
        
        .form-control.bg-dark:disabled {
            background-color: #495057 !important;
            color: #6c757d !important;
        }
        
        .input-group-text.bg-dark {
            background-color: #343a40 !important;
            border-color: #6c757d !important;
            color: #ffffff !important;
        }
        
        .form-select.bg-dark {
            background-color: #343a40 !important;
            color: #ffffff !important;
            border: none !important;
        }
        
        .form-select.bg-dark:focus {
            background-color: #343a40 !important;
            color: #ffffff !important;
            border: none !important;
            box-shadow: none !important;
        }
    </style>
</head>
<body>
    <div class="container-fluid position-relative d-flex p-0">
        <!-- Spinner Start -->
        <div id="spinner" class="show bg-dark position-fixed translate-middle w-100 vh-100 top-50 start-50 d-flex align-items-center justify-content-center">
            <img src="img/Loading.gif" alt="Loading..." style="width: 200px; height: 200px;" />
        </div>
        <!-- Spinner End -->

        <!-- Sidebar Start -->
        <div class="sidebar pe-4 pb-3">
            <nav class="navbar bg-secondary navbar-dark">
                <a href="Admin-Dashboard.php" class="navbar-brand mx-4 mb-3">
                    <h3 class="text-primary"><i class="fa fa-user-edit me-2"></i>Cj P'House</h3>
                </a>
                <div class="d-flex align-items-center ms-4 mb-4">
                    <div class="position-relative">
                        <img src="<?= $user_data['profile_image'] ? (strpos($user_data['profile_image'], 'uploads/') === 0 ? $user_data['profile_image'] : 'uploads/' . $user_data['profile_image']) : 'img/jandi.jpg' ?>" alt="" class="rounded-circle" style="width: 40px; height: 40px;">
                        <div class="bg-success rounded-circle border border-2 border-white position-absolute end-0 bottom-0 p-1"></div>
                    </div>
                    <div class="ms-3">
                        <h6 class="mb-0"><?= htmlspecialchars($user_data['first_name'] . ' ' . $user_data['last_name']) ?></h6>
                        <span>Admin</span>
                    </div>
                </div>
                <div class="navbar-nav w-100">
                    <a href="Admin-Dashboard.php" class="nav-item nav-link"><i class="fa fa-tachometer-alt me-2"></i>Dashboard</a>

                    <div class="nav-item dropdown">
                        <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                            <i class="fa fa-users me-2"></i>Users
                        </a>
                        <div class="dropdown-menu bg-transparent border-0">
                            <a href="Admin-AddUser.php" class="dropdown-item">Add Users</a>
                            <a href="Admin-ManageUser.php" class="dropdown-item">Manage Users</a>
                        </div>
                    </div>

                    <div class="nav-item dropdown">
                        <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                            <i class="fa fa-th me-2"></i>Product
                        </a>
                        <div class="dropdown-menu bg-transparent border-0">
                            <a href="Admin-Stockmanagement.php" class="dropdown-item">Stock Management</a>
                            <a href="Admin-buy-out-item.php" class="dropdown-item">Buy-out Item</a>
                            <a href="Admin-ReturnedItems.php" class="dropdown-item">Returned Item</a>
                        </div>
                    </div>

                    <a href="Admin-OrderLogs.php" class="nav-item nav-link"><i class="fa fa-shopping-cart me-2"></i>Order Logs</a>
                    <a href="Admin-SalesReport.php" class="nav-item nav-link"><i class="fa fa-file-alt me-2"></i>Sales Report</a>
                    <a href="Admin-StaffLogs.php" class="nav-item nav-link"><i class="fa fa-user-clock me-2"></i>Staff Logs</a>
                    <a href="Admin-RescueLogs.php" class="nav-item nav-link"><i class="fa fa-ambulance me-2"></i>Rescue Logs</a>
                    <a href="Admin-Profile.php" class="nav-item nav-link active"><i class="fa fa-user me-2"></i>Profile</a>
                </div>
            </div>
        </nav>
    </div>
    <!-- Sidebar End -->

    <!-- Content Start -->
    <div class="content">
        <!-- Navbar Start -->
        <nav class="navbar navbar-expand bg-secondary navbar-dark sticky-top px-4 py-0">
            <a href="Admin-Dashboard.php" class="navbar-brand d-flex d-lg-none me-4">
                <h2 class="text-primary mb-0"><i class="fa fa-user-edit"></i></h2>
            </a>
            <a href="#" class="sidebar-toggler flex-shrink-0">
                <i class="fa fa-bars"></i>
            </a>

            <div class="navbar-nav align-items-center ms-auto">
                <?php include 'admin_notifications.php'; ?>
                <?php include 'admin_rescue_notifications.php'; ?>
                <?php include 'admin_user_notifications.php'; ?>
                <div class="nav-item dropdown">
                    <a href="" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                        <img src="<?= $user_data['profile_image'] ? (strpos($user_data['profile_image'], 'uploads/') === 0 ? $user_data['profile_image'] : 'uploads/' . $user_data['profile_image']) : 'img/jandi.jpg' ?>" alt="" class="rounded-circle me-lg-2" style="width: 40px; height: 40px;">
                        <span class="d-none d-lg-inline"><?= htmlspecialchars($user_data['first_name']) ?></span>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end bg-dark border-0 rounded-3 shadow-lg m-0" style="min-width: 200px;">
                        <div class="dropdown-header text-light border-bottom border-secondary">
                            <small class="text-muted">Account</small>
                        </div>
                        <a href="Admin-Profile.php" class="dropdown-item text-light d-flex align-items-center py-2">
                            <i class="fas fa-user me-2 text-primary"></i>
                            <span>Profile</span>
                        </a>
                        <a href="logout.php" class="dropdown-item text-light d-flex align-items-center py-2">
                            <i class="fas fa-sign-out-alt me-2 text-danger"></i>
                            <span>Log out</span>
                        </a>
                    </div>
                </div>
            </div>
        </nav>
        <!-- Navbar End -->

        <!-- Profile Content Start -->
        <div class="container-fluid pt-4 px-4">
            <div class="row g-4">
                <div class="col-12">
                    <div class="bg-secondary rounded h-100 p-4">
                        <h6 class="mb-4">Admin Profile</h6>
                        
                        <?php if ($success_message): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <?= htmlspecialchars($success_message) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($error_message): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <?= htmlspecialchars($error_message) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <!-- Profile Information Card -->
                        <div class="row">
                            <div class="col-md-4">
                                <div class="card bg-dark">
                                    <div class="card-body text-center">
                                        <img src="<?= $user_data['profile_image'] ? (strpos($user_data['profile_image'], 'uploads/') === 0 ? $user_data['profile_image'] : 'uploads/' . $user_data['profile_image']) : 'img/jandi.jpg' ?>" 
                                             alt="Profile Picture" class="rounded-circle mb-3" style="width: 150px; height: 150px; object-fit: cover;">
                                        <h5 class="text-white"><?= htmlspecialchars($user_data['first_name'] . ' ' . $user_data['last_name']) ?></h5>
                                        <p class="text-muted"><?= htmlspecialchars($user_data['role']) ?></p>
                                        <p class="text-muted"><?= htmlspecialchars($user_data['email']) ?></p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-8">
                                <!-- Profile Details Form -->
                                <div class="card bg-dark">
                                    <div class="card-header">
                                        <h5 class="text-white mb-0">Profile Information</h5>
                                    </div>
                                    <div class="card-body">
                                        <form method="POST" enctype="multipart/form-data">
                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label for="first_name" class="form-label text-white">First Name</label>
                                                    <input type="text" class="form-control bg-dark text-white border-secondary" id="first_name" name="first_name" 
                                                           value="<?= htmlspecialchars($user_data['first_name']) ?>" required>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label for="last_name" class="form-label text-white">Last Name</label>
                                                    <input type="text" class="form-control bg-dark text-white border-secondary" id="last_name" name="last_name" 
                                                           value="<?= htmlspecialchars($user_data['last_name']) ?>" required>
                                                </div>
                                            </div>
                                            
                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label for="middle_name" class="form-label text-white">Middle Name</label>
                                                    <input type="text" class="form-control bg-dark text-white border-secondary" id="middle_name" name="middle_name" 
                                                           value="<?= htmlspecialchars($user_data['middle_name']) ?>">
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label for="email" class="form-label text-white">Email Address</label>
                                                    <input type="email" class="form-control bg-dark text-white border-secondary" id="email" name="email" 
                                                           value="<?= htmlspecialchars($user_data['email']) ?>" required>
                                                </div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label for="home_address" class="form-label text-white">Home Address</label>
                                                <input type="text" class="form-control bg-dark text-white border-secondary" id="home_address" name="home_address" 
                                                       value="<?= htmlspecialchars($user_data['home_address']) ?>">
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label for="contact_info" class="form-label text-white">Contact Information</label>
                                                <div class="input-group">
                                                    <span class="input-group-text bg-dark border-secondary text-white">
                                                        <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/9/99/Flag_of_the_Philippines.svg/320px-Flag_of_the_Philippines.svg.png" 
                                                             alt="PH Flag" class="img-fluid" style="width: 20px; height: auto; margin-right: 5px;">
                                                        <select class="form-select bg-dark text-white border-0" style="width: 80px;">
                                                            <option selected>+63</option>
                                                        </select>
                                                    </span>
                                                    <input type="tel" class="form-control bg-dark text-white border-secondary" id="contact_info" name="contact_info" 
                                                           value="<?= htmlspecialchars($user_data['contact_info']) ?>" 
                                                           placeholder="Phone Number" maxlength="10" pattern="[0-9]{10}" 
                                                           oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 10); validatePhoneNumber(this);" 
                                                           onblur="validatePhoneNumber(this)">
                                                </div>
                                                <small class="text-muted">Enter 10 digits only (e.g., 9123456789)</small>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label for="profile_image" class="form-label text-white">Profile Picture</label>
                                                <input type="file" class="form-control bg-dark text-white border-secondary" id="profile_image" name="profile_image" 
                                                       accept="image/*" onchange="previewImage(event)">
                                                <small class="text-muted">Leave empty to keep current image</small>
                                                <img id="imagePreview" src="#" alt="Preview" style="display: none; width: 100px; height: 100px; margin-top: 10px; border-radius: 5px;">
                                            </div>
                                            
                                            <button type="submit" name="update_profile" class="btn btn-primary">Update Profile</button>
                                        </form>
                                    </div>
                                </div>
                                
                                <!-- Change Password Card -->
                                <div class="card bg-dark mt-4">
                                    <div class="card-header">
                                        <h5 class="text-white mb-0">Change Password</h5>
                                    </div>
                                    <div class="card-body">
                                        <form method="POST">
                                            <div class="mb-3">
                                                <label for="current_password" class="form-label text-white">Current Password</label>
                                                <input type="password" class="form-control bg-dark text-white border-secondary" id="current_password" name="current_password" required>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label for="new_password" class="form-label text-white">New Password</label>
                                                <input type="password" class="form-control bg-dark text-white border-secondary" id="new_password" name="new_password" required>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label for="confirm_password" class="form-label text-white">Confirm New Password</label>
                                                <input type="password" class="form-control bg-dark text-white border-secondary" id="confirm_password" name="confirm_password" required>
                                            </div>
                                            
                                            <button type="submit" name="change_password" class="btn btn-warning">Change Password</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Profile Content End -->

        <!-- Footer Start -->
        <div class="container-fluid pt-4 px-4">
            <div class="bg-secondary rounded-top p-4">
                <div class="row">
                    <div class="col-12 col-sm-6 text-center text-sm-start">
                        &copy; <a href="#">Cj PowerHouse</a>, All Right Reserved.
                    </div> 
                    <div class="col-12 col-sm-6 text-center text-sm-end">
                        Design By: <a href="">Team Jandi</a>
                    </div>
                </div>
            </div>
        </div>
        <!-- Footer End -->
    </div>
    <!-- Content End -->

    <!-- JavaScript Libraries -->
    <script src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
    <script src="lib/chart/Chart.min.js"></script>
    <script src="lib/easing/easing.min.js"></script>
    <script src="lib/waypoints/waypoints.min.js"></script>
    <script src="lib/owlcarousel/owl.carousel.min.js"></script>
    <script src="lib/tempusdominus/js/moment.min.js"></script>
    <script src="lib/tempusdominus/js/moment-timezone.min.js"></script>
    <script src="lib/tempusdominus/js/tempusdominus-bootstrap-4.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Spinner
        setTimeout(function () {
            let spinner = document.getElementById("spinner");
            if (spinner) {
                spinner.classList.remove("show");
            }
        }, 1);

        // Sidebar Toggler
        let sidebarToggler = document.querySelector(".sidebar-toggler");
        if (sidebarToggler) {
            sidebarToggler.addEventListener("click", function () {
                document.querySelector(".sidebar").classList.toggle("open");
                document.querySelector(".content").classList.toggle("open");
            });
        }

        // Image preview function
        function previewImage(event) {
            const file = event.target.files[0];
            const preview = document.getElementById('imagePreview');
            
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                }
                reader.readAsDataURL(file);
            } else {
                preview.style.display = 'none';
            }
        }

        // Password confirmation validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;
            
            if (newPassword !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });

        // Phone number validation function
        function validatePhoneNumber(input) {
            const phoneNumber = input.value.replace(/[^0-9]/g, '');
            
            if (phoneNumber.length !== 10) {
                input.setCustomValidity('Phone number must be exactly 10 digits');
            } else {
                input.setCustomValidity('');
            }
        }
    </script>
</body>
</html>

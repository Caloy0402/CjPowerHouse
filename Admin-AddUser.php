<?php
session_start();
require_once 'dbconn.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: signin.php");
    exit();
}

// Get user data for profile image
$user_id = $_SESSION['user_id'];
$user_query = $conn->prepare("SELECT first_name, last_name, profile_image FROM cjusers WHERE id = ?");
$user_query->bind_param("i", $user_id);
$user_query->execute();
$user_result = $user_query->get_result();
$user_data = $user_result->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Get form data
    $first_name = $_POST['firstName'];
    $middle_name = $_POST['middleName'];
    $last_name = $_POST['lastName'];
    $email = $_POST['emailAddress'];
    $home_address = $_POST['homeAddress'];
    $contact_info = $_POST['contactNumber'];
    $password = password_hash($_POST['passwordInput'], PASSWORD_DEFAULT);
    $role = $_POST['roleSelect'];

    // Get mechanic-specific fields if role is mechanic
    $motor_type = '';
    $plate_number = '';
    $specialization = '';
    
    if ($role === 'Mechanic') {
        $motor_type = $_POST['motorType'] ?? '';
        $plate_number = $_POST['plateNumber'] ?? '';
        $specialization = $_POST['specialization'] ?? 'General Mechanic';
    }
    
    // Get rider-specific fields if role is rider
    if ($role === 'rider') {
        $motor_type = $_POST['riderMotorType'] ?? '';
        $plate_number = $_POST['riderPlateNumber'] ?? '';
        $barangay_id = $_POST['barangaySelect'] ?? '';
        $purok = $_POST['purokInput'] ?? '';
    }

    // Handle image upload - Profile picture is required
    $profile_image = NULL;
    if (!isset($_FILES['profileImage']) || $_FILES['profileImage']['error'] != 0) {
        echo "Profile picture is required. Please upload an image.";
        exit;
    }
    
    if (isset($_FILES['profileImage']) && $_FILES['profileImage']['error'] == 0) {
        $image_name = $_FILES['profileImage']['name'];
        $image_tmp_name = $_FILES['profileImage']['tmp_name'];
        $image_ext = strtolower(pathinfo($image_name, PATHINFO_EXTENSION));

        // Set allowed extensions
        $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];

        if (in_array($image_ext, $allowed_ext)) {
            // Generate unique image name
            $new_image_name = uniqid() . '.' . $image_ext;
            $upload_dir = 'uploads/'; // Ensure this folder exists
            $upload_path = $upload_dir . $new_image_name;

            // Move the uploaded image to the desired directory
            if (move_uploaded_file($image_tmp_name, $upload_path)) {
                $profile_image = $new_image_name; // Save image name to DB
            } else {
                echo "Image upload failed.";
                exit;
            }
        } else {
            echo "Invalid image format. Only JPG, JPEG, PNG, and GIF allowed.";
            exit;
        }
    }

    // Insert user data into the database based on role
    if ($role === 'Mechanic') {
        // Insert into mechanics table
        $sql = "INSERT INTO mechanics (password, email, first_name, middle_name, last_name, phone_number, home_address, ImagePath, MotorType, PlateNumber, specialization) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("sssssssssss", $password, $email, $first_name, $middle_name, $last_name, $contact_info, $home_address, $profile_image, $motor_type, $plate_number, $specialization);
            
            if ($stmt->execute()) {
                echo "New mechanic added successfully";
            } else {
                echo $stmt->error;
            }
            $stmt->close();
        } else {
            echo "Database error: " . $conn->error;
        }
    } elseif ($role === 'rider') {
        // Insert into riders table
        $sql = "INSERT INTO riders (password, email, first_name, middle_name, last_name, phone_number, barangay_id, purok, ImagePath, MotorType, PlateNumber) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("sssssssssss", $password, $email, $first_name, $middle_name, $last_name, $contact_info, $barangay_id, $purok, $profile_image, $motor_type, $plate_number);
            
            if ($stmt->execute()) {
                echo "New rider added successfully";
            } else {
                echo $stmt->error;
            }
            $stmt->close();
        } else {
            echo "Database error: " . $conn->error;
        }
    } else {
        // Insert into CJusers table for admin and cashier roles
        $sql = "INSERT INTO CJusers (first_name, middle_name, last_name, email, home_address, contact_info, password, role, profile_image) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("sssssssss", $first_name, $middle_name, $last_name, $email, $home_address, $contact_info, $password, $role, $profile_image);

            if ($stmt->execute()) {
                echo "New user added successfully";
            } else {
                echo $stmt->error;
            }
            $stmt->close();
        } else {
            echo "Database error: " . $conn->error;
        }
    }

    // Close the connection
    $conn->close();
}
?>




<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Jandi - Admin Dashboard</title>
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <meta content="" name="keywords">
    <meta content="" name="description">

    <!-- Favicons -->
    <link href="image/logo.png" rel="icon">

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

</head>
<body>
    <div class="container-fluid position-relative d-flex p-0">
         <!-- Spinner Start -->
    <div id="spinner" class="show bg-dark position-fixed translate-middle w-100 vh-100 top-50 start-50 d-flex align-items-center justify-content-center">
    <img src="img/Loading.gif" alt="Loading..." style="width: 200px; height: 200px;" />
    </div>
    <!-- Spinner End -->
        <!-- Sidebar Start -->
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
                <h6 class="mb-0"><?= htmlspecialchars($user_data['first_name']) ?></h6>
                <span>Admin</span>
            </div>
        </div>
        <div class="navbar-nav w-100">
            <a href="Admin-Dashboard.php" class="nav-item nav-link active"><i class="fa fa-tachometer-alt me-2"></i>Dashboard</a>

            <div class="nav-item dropdown">
                <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="fa fa-users me-2"></i>Users
                </a>
                <div class="dropdown-menu bg-transparent border-0">
                    <a href="Admin-AddUser.php" class="dropdown-item">Add Users</a>
                    <a href="Admin-ManageUser.php" class="dropdown-item">Manage Users</a>
                </div>
            </div>

            <!-- Updated Product Dropdown -->
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
            </div>
        </div>
    </nav>
</div>
<!-- Sidebar End -->

        <!--Content Start-->
            <div class="content">
                <!--Navbar Start-->
                   <nav class="navbar navbar-expand bg-secondary navbar-dark sticky-top 
                   px-4 py-0">
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
                <a href="" class="nav-link dropdown-toggle" 
                data-bs-toggle="dropdown">
                <img src="<?= $user_data['profile_image'] ? (strpos($user_data['profile_image'], 'uploads/') === 0 ? $user_data['profile_image'] : 'uploads/' . $user_data['profile_image']) : 'img/jandi.jpg' ?>" alt="" class="rounded-circle me-lg-2" 
                    alt="" style="width: 40px; height: 40px;">
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
         <!--Navbar End-->
     
<!-- Add User -->
<div class="container-fluid pt-4 px-4" style="min-height: 100vh; display: flex; align-items: center; justify-content: center;">
    <div class="row g-4 w-100 justify-content-center">
        <div class="col-sm-12 col-xl-6">
            <div class="bg-secondary rounded h-100 p-4">
                <h6 class="mb-4">Add User</h6>
                <form id="addUserForm" enctype="multipart/form-data">
                <div class="mb-3">
                        <label for="roleSelect" class="form-label">Role</label>
                        <select id="roleSelect" name="roleSelect" class="form-control" required onchange="toggleMechanicFields()">
                            <option value="">Select Role</option>
                            <option value="admin">Admin</option>
                            <option value="cashier">Cashier</option>
                            <option value="rider">Rider</option>
                            <option value="Mechanic">Mechanic</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="firstName" class="form-label">First Name</label>
                        <input type="text" id="firstName" name="firstName" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label for="middleName" class="form-label">Middle Name</label>
                        <input type="text" id="middleName" name="middleName" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label for="lastName" class="form-label">Last Name</label>
                        <input type="text" id="lastName" name="lastName" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label for="emailAddress" class="form-label">Email Address</label>
                        <input type="email" id="emailAddress" name="emailAddress" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label for="homeAddress" class="form-label">Home Address</label>
                        <input type="text" id="homeAddress" name="homeAddress" class="form-control" required>
                    </div>
                    
                    <!-- Rider-specific address fields (initially hidden) -->
                    <div id="riderAddressFields" style="display: none;">
                        <div class="mb-3">
                            <label for="barangaySelect" class="form-label">Barangay</label>
                            <select id="barangaySelect" name="barangaySelect" class="form-control">
                                <option value="">Select Barangay</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="purokInput" class="form-label">Purok</label>
                            <input type="text" id="purokInput" name="purokInput" class="form-control" placeholder="e.g., 2">
                        </div>
                    </div>
                    <div class="mb-3">
                    <div class="mb-3">
                        <label for="contactinfo" class="form-label">Contact Info</label>
                        <div class="input-group">
                            <span class="input-group-text bg-dark border-0 text-white">
                                <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/9/99/Flag_of_the_Philippines.svg/320px-Flag_of_the_Philippines.svg.png" 
                                alt="PH Flag" class="img-fluid" style="width: 20px; height: auto; margin-right: 5px;">
                                <select class="form-select bg-dark text-white border-0" style="width: 80px;">
                                    <option selected>+63</option>
                                </select>
                            </span>
                            <input type="tel" id="contactinfo" name="contactNumber" class="form-control custom-bg text-white border-0" placeholder="Phone Number" maxlength="10" pattern="[0-9]{10}" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 10); validatePhoneNumber(this);" onblur="validatePhoneNumber(this)" required>
                        </div>
                        <small class="form-text text-muted">Enter 10 digits only (e.g., 9123456789)</small>
                        <div class="mb-3">
                        <label for="passwordInput" class="form-label">Password</label>
                        <input type="password" id="passwordInput" name="passwordInput" class="form-control" required>
                    </div>
                 
                    
                    <!-- Mechanic-specific fields (initially hidden) -->
                    <div id="mechanicFields" style="display: none;">
                        <div class="mb-3">
                            <label for="motorType" class="form-label">Motor Type</label>
                            <input type="text" id="motorType" name="motorType" class="form-control" placeholder="e.g., XRM 125, Click 150">
                        </div>
                        <div class="mb-3">
                            <label for="plateNumber" class="form-label">Plate Number</label>
                            <input type="text" id="plateNumber" name="plateNumber" class="form-control" placeholder="e.g., ABC-123">
                        </div>
                        <div class="mb-3">
                            <label for="specialization" class="form-label">Specialization</label>
                            <select id="specialization" name="specialization" class="form-control">
                                <option value="General Mechanic">General Mechanic</option>
                                <option value="Engine Specialist">Engine Specialist</option>
                                <option value="Electrical Specialist">Electrical Specialist</option>
                                <option value="Brake Specialist">Brake Specialist</option>
                                <option value="Tire Specialist">Tire Specialist</option>
                                <option value="Transmission Specialist">Transmission Specialist</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Rider-specific fields (initially hidden) -->
                    <div id="riderFields" style="display: none;">
                        <div class="mb-3">
                            <label for="riderMotorType" class="form-label">Motor Type</label>
                            <input type="text" id="riderMotorType" name="riderMotorType" class="form-control" placeholder="e.g., XRM 125, Click 150">
                        </div>
                        <div class="mb-3">
                            <label for="riderPlateNumber" class="form-label">Plate Number</label>
                            <input type="text" id="riderPlateNumber" name="riderPlateNumber" class="form-control" placeholder="e.g., ABC-123">
                        </div>
                    </div>
                    
                    <!-- Image Upload -->
                    <div class="mb-3">
                        <label for="profileImage" class="form-label">Profile Picture <span class="text-danger">*</span></label>
                        <input type="file" id="profileImage" name="profileImage" class="form-control" accept="image/*" onchange="previewImage(event)" required>
                        <img id="imagePreview" src="#" alt="Preview Image" style="display: none; width: 100px; height: 100px; margin-top: 10px; border-radius: 5px;">
                        <div id="imageError" class="text-danger" style="display: none;">Please select a profile picture.</div>
                    </div>

                    <button type="submit" class="btn btn-primary">Register User</button>
                </form>
            </div>
        </div>
    </div>
</div>


<!-- Success Modal -->
<div class="modal fade" id="successModal" tabindex="-1" aria-labelledby="successModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="successModalLabel">User Creation Status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="modalMessage">
                User added successfully!
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>


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
    <!--content end-->
 </div>


















<!--javascript Libraries-->
<script src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
<script src="lib/chart/Chart.min.js"></script>
<script src="lib/easing/easing.min.js"></script>
<script src="lib/waypoints/waypoints.min.js"></script>
<script src="lib/owlcarousel/owl.carousel.min.js"></script>
<script src="lib/tempusdominus/js/moment.min.js"></script>
<script src="lib/tempusdominus/js/moment-timezone.min.js"></script>
<script src="lib/tempusdominus/js/tempusdominus-bootstrap-4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.0/dist/js/bootstrap.bundle.min.js"></script>



 <!-- Template Javascript -->
 <script src="js/A-user.js">
 </script>
 
 <script>
 // Function to toggle mechanic-specific fields
 function toggleMechanicFields() {
     const roleSelect = document.getElementById('roleSelect');
     const mechanicFields = document.getElementById('mechanicFields');
     const riderFields = document.getElementById('riderFields');
     const homeAddressField = document.getElementById('homeAddress').parentElement;
     const riderAddressFields = document.getElementById('riderAddressFields');
     
     // Hide all role-specific fields first
     mechanicFields.style.display = 'none';
     riderFields.style.display = 'none';
     riderAddressFields.style.display = 'none';
     
     // Show home address field by default
     homeAddressField.style.display = 'block';
     
     // Remove required attributes from all role-specific fields
     document.getElementById('motorType').required = false;
     document.getElementById('plateNumber').required = false;
     document.getElementById('specialization').required = false;
     document.getElementById('riderMotorType').required = false;
     document.getElementById('riderPlateNumber').required = false;
     document.getElementById('barangaySelect').required = false;
     document.getElementById('purokInput').required = false;
     document.getElementById('homeAddress').required = true;
     
     if (roleSelect.value === 'Mechanic') {
         mechanicFields.style.display = 'block';
         // Make mechanic fields required
         document.getElementById('motorType').required = true;
         document.getElementById('plateNumber').required = true;
         document.getElementById('specialization').required = true;
     } else if (roleSelect.value === 'rider') {
         riderFields.style.display = 'block';
         riderAddressFields.style.display = 'block';
         homeAddressField.style.display = 'none';
         
         // Make rider fields required
         document.getElementById('riderMotorType').required = true;
         document.getElementById('riderPlateNumber').required = true;
         document.getElementById('barangaySelect').required = true;
         document.getElementById('purokInput').required = true;
         document.getElementById('homeAddress').required = false;
         
         // Load barangays if not already loaded
         loadBarangays();
     }
 }
 
 // Function to load barangays from database
 function loadBarangays() {
     const barangaySelect = document.getElementById('barangaySelect');
     
     // Only load if not already populated
     if (barangaySelect.options.length > 1) {
         return;
     }
     
     fetch('get_barangays.php')
         .then(response => response.json())
         .then(data => {
             barangaySelect.innerHTML = '<option value="">Select Barangay</option>';
             data.forEach(barangay => {
                 const option = document.createElement('option');
                 option.value = barangay.id;
                 option.textContent = barangay.barangay_name;
                 barangaySelect.appendChild(option);
             });
         })
         .catch(error => {
             console.error('Error loading barangays:', error);
         });
 }
 
 // Function to preview image
 function previewImage(event) {
     const file = event.target.files[0];
     const preview = document.getElementById('imagePreview');
     const imageError = document.getElementById('imageError');
     
     if (file) {
         // Validate file type
         const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
         if (!allowedTypes.includes(file.type)) {
             imageError.textContent = 'Please select a valid image file (JPG, PNG, or GIF).';
             imageError.style.display = 'block';
             event.target.value = '';
             preview.style.display = 'none';
             return;
         }
         
         // Validate file size (max 5MB)
         if (file.size > 5 * 1024 * 1024) {
             imageError.textContent = 'Image size must be less than 5MB.';
             imageError.style.display = 'block';
             event.target.value = '';
             preview.style.display = 'none';
             return;
         }
         
         // Clear any previous errors
         imageError.style.display = 'none';
         
         const reader = new FileReader();
         reader.onload = function(e) {
             preview.src = e.target.result;
             preview.style.display = 'block';
         }
         reader.readAsDataURL(file);
     } else {
         preview.style.display = 'none';
         imageError.style.display = 'block';
     }
 }
 
 // Function to validate phone number
 function validatePhoneNumber(input) {
     const phoneNumber = input.value.replace(/[^0-9]/g, '');
     
     if (phoneNumber.length !== 10) {
         input.setCustomValidity('Phone number must be exactly 10 digits');
     } else {
         input.setCustomValidity('');
     }
 }
 
 // Function to validate form before submission
 function validateForm() {
     const profileImage = document.getElementById('profileImage');
     const imageError = document.getElementById('imageError');
     
     if (!profileImage.files || profileImage.files.length === 0) {
         imageError.textContent = 'Please select a profile picture.';
         imageError.style.display = 'block';
         profileImage.focus();
         return false;
     }
     
     // Clear any previous errors
     imageError.style.display = 'none';
     return true;
 }
 
 // Add form validation on submit
 document.addEventListener('DOMContentLoaded', function() {
     const form = document.getElementById('addUserForm');
     if (form) {
         form.addEventListener('submit', function(event) {
             if (!validateForm()) {
                 event.preventDefault();
                 return false;
             }
         });
     }
 });
 </script>
</body>
</html> 
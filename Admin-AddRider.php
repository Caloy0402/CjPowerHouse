<?php
require_once 'dbconn.php';

// Fetch barangays from the database
$query = "SELECT id, barangay_name FROM barangays";
$barangayResult = $conn->query($query);
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
    <link href="assets/img/favicon.png" rel="icon">

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
        <div class="sidebar pe-4 pb-3">
            <nav class="navbar bg-secondary navbar-dark">
                <a href="Admin-Dashboard.php" class="navbar-brand mx-4 mb-3">
                    <h3 class="text-primary"><i class="fa fa-user-edit me-2"></i>Cj P'House</h3>
                </a>
                <div class="d-flex align-items-center ms-4 mb-4">
                    <div class="position-relative">
                        <img src="img/jandi.jpg" alt="" class="rounded-circle" style="width: 40px; height: 40px;">
                        <div class="bg-success rounded-circle border border-2 border-white position-absolute end-0 bottom-0 p-1"></div>
                    </div>
                    <div class="ms-3">
                        <h6 class="mb-0">Jandi</h6>
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
                    <a href="Admin-Payment.php" class="nav-item nav-link"><i class="fa fa-file-alt me-2"></i>Payment</a>
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
                        <div class="dropdown-menu dropdown-menu-end bg-secondary border-0 rounded-0 rounded-bottom m-0">
                            <a href="#" class="dropdown-item">
                                <div class="d-flex aligns-items-center">
                                    <img src="img/johanns.jpg" alt="User Profile" class="rounded-circle" style="width: 40px; height: 40px;">
                                    <div class="ms-2">
                                        <h6 class="fw-normal mb-0">Johanns send you a message</h6>
                                        <small>5 minutes ago</small>
                                    </div>
                                </div>
                            </a>
                            <hr class="dropdown-divider">
                            <a href="#" class="dropdown-item">
                                <div class="d-flex aligns-items-center">
                                    <img src="img/carlo.jpg" alt="" class="rounded-circle" style="width: 40px; height: 40px;">
                                    <div class="ms-2">
                                        <h6 class="fw-normal mb-0">Carlo send you a message</h6>
                                        <small>10 minutes ago</small>
                                    </div>
                                </div>
                            </a>
                            <hr class="dropdown-divider">
                            <a href="#" class="dropdown-item">
                                <div class="d-flex aligns-items-center">
                                    <img src="img/alquin.jpg" alt="" class="rounded-circle" style="width: 40px; height: 40px;">
                                    <div class="ms-2">
                                        <h6 class="fw-normal mb-0">Alquin send you a message</h6>
                                        <small>15 minutes ago</small>
                                    </div>
                                </div>
                            </a>
                            <hr class="dropdown-divider">
                            <a href="#" class="dropdown-item text-center">See all Messages</a>
                        </div>
                    </div>
                    <div class="nav-item dropdown">
                        <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                            <i class="fa fa-bell me-lg-2"></i>
                            <span class="d-none d-lg-inline">Notifications</span>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end bg-secondary border-0 rounded-0 rounded-bottom m-0">
                            <a href="#" class="dropdown-item">
                                <h6 class="fw-normal mb-0">profile updated</h6>

                                <small>10 minutes ago</small>
                            </a>
                            <hr class="dropdown-divider">
                            <a href="#" class="dropdown-item">
                                <h6 class="fw-normal mb-0">Password Changed</h6>
                                <small>15 minutes ago</small>
                            </a>
                            <a hr class="dropdown-divider">
                                <a href="#" class="dropdown-item">
                                    <h6 class="fw-normal mb-0">User Added</h6>
                                    <small>20 minutes ago</small>
                                </a>
                                <a hr class="dropdown-divider">
                                    <a href="#" class="dropdown-item text-center">See all Notification</a>
                        </div>
                    </div>
                    <div class="nav-item dropdown">
                        <a href="" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                            <img src="img/jandi.jpg" alt="" class="rounded-circle me-lg-2" alt="" style="width: 40px; height: 40px;">
                            <span class="d-none d-lg-inline">Jandi</span>
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

            <div class="container d-flex justify-content-center align-items-center" style="min-height: 100vh;">
                <div class="col-12 col-sm-8 col-md-6 col-lg-5">
                    <div class="bg-secondary rounded p-4">
                        <h3 class="text-center mb-3">Sign Up</h3>

                        <form id="signupForm" action="signup_rider.php" method="POST" enctype="multipart/form-data">
                            <!-- Profile Picture Upload Box -->
                            <div class="mb-3 text-center">
                                <label for="profilePicture" class="form-label">Add Profile Image</label>
                                <input type="file" id="profilePicture" name="profilePicture" accept="image/*" class="form-control mt-2">
                            </div>

                            <!-- First Name -->
                            <div class="form-floating mb-3">
                                <input type="text" name="first_name" id="first_name" class="form-control" placeholder="First Name" required>
                                <label>First Name</label>
                            </div>

                            <!-- Middle Name -->
                            <div class="form-floating mb-3">
                                <input type="text" name="middle_name" id="middle_name" class="form-control" placeholder="Middle Name">
                                <label>Middle Name</label>
                            </div>

                            <!-- Last Name -->
                            <div class="form-floating mb-3">
                                <input type="text" name="last_name" id="last_name" class="form-control" placeholder="Last Name" required>
                                <label>Last Name</label>
                            </div>

                            <!-- Email -->
                            <div class="form-floating mb-3">
                                <input type="email" name="email" id="email" class="form-control" placeholder="Email" required>
                                <label>Email</label>
                            </div>

                            <!-- Phone Number -->
                            <div class="input-group mb-3">
                                <div class="input-group-text bg-dark border-0 text-white">
                                    <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/9/99/Flag_of_the_Philippines.svg/320px-Flag_of_the_Philippines.svg.png" alt="PH Flag" class="img-fluid" style="width: 20px; height: auto; margin-right: 5px;">
                                    <select class="form-select bg-dark text-white border-0" style="width: 80px;">
                                        <option selected>+63</option>
                                    </select>
                                </div>
                                <input type="tel" name="contactinfo" id="contactinfo" class="form-control bg-dark text-white border-0 shadow-sm" placeholder="Phone Number" required>
                            </div>

                            <!-- Password -->
                            <div class="form-floating mb-3">
                                <input type="password" name="password" id="password" class="form-control" placeholder="Password" required>
                                <label>Password</label>
                                <span class="eye-icon" id="eye-icon" style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); cursor: pointer;">
                                    <i class="fas fa-eye"></i>
                                </span>
                            </div>

                            <!-- Barangay Dropdown -->
                            <div class="form-floating mb-3">
                                <select id="barangay" name="barangay" class="form-control" required>
                                    <option value="">Select Barangay</option>
                                    <?php
                                    if ($barangayResult->num_rows > 0) {
                                        while ($row = $barangayResult->fetch_assoc()) {
                                            echo "<option value='" . $row['id'] . "'>" . $row['barangay_name'] . "</option>";
                                        }
                                    }
                                    ?>
                                </select>
                                <label for="barangay">Barangay</label>
                            </div>

                            <!-- Purok Textbox -->
                            <div class="form-floating mb-3">
                                <input type="text" id="purok" name="purok" class="form-control" placeholder="Enter Purok" required>
                                <label for="purok">Purok</label>
                            </div>

                            <!-- Motor Type -->
                            <div class="form-floating mb-3">
                                <input type="text" name="MotorType" id="MotorType" class="form-control" placeholder="Motor Type" required>
                                <label>Motor Type</label>
                            </div>

                            <!-- Plate Number -->
                            <div class="form-floating mb-3">
                                <input type="text" name="PlateNumber" id="PlateNumber" class="form-control" placeholder="Plate Number" required>
                                <label>Plate Number</label>
                            </div>

                            <button type="submit" class="btn btn-primary py-3 w-100">Sign Up</button>
                            <p class="text-center mt-3">Already have an account? <a href="signin.php">Login</a></p>
                        </form>
                        <!-- Form End -->

                        <!-- Modal for Incomplete Form Message -->
                        <div class="modal fade" id="incompleteFormModal" tabindex="-1" aria-labelledby="incompleteFormModalLabel" aria-hidden="true">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="incompleteFormModalLabel">Incomplete Form</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <p>Unable to create user due to incomplete form. Please make sure all fields are filled out before submitting.</p>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" id="continueButton" class="btn btn-secondary" data-bs-dismiss="modal" aria-label="Close" disabled>Continue</button>
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

                    </div>
                </div>
            </div>

            <!-- Footer Start -->
            <div class="container-fluid pt-4 px-4">
                <div class="bg-secondary rounded-top p-4">
                    <div class="row">
                        <div class="col-12 col-sm-6 text-center text-sm-start">
                            © <a href="#">Cj PowerHouse</a>, All Right Reserved.
                        </div>
                        <div class="col-12 col-sm-6 text-center text-sm-end">
                            Design By: <a href="">Team Jandi</a>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Footer End -->

        </div>
        <!-- content end -->
    </div>

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

    <!-- Template Javascript -->
    <script src="js/A-user.js"></script>
</body>
</html>
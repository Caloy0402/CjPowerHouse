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
            <a href="Admin-Payment.php" class="nav-item nav-link"><i class="fa fa-file-alt me-2"></i>Payment</a>
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
                        <div class="dropdown-menu dropdown-menu-end bg-secondary 
                        border-0 rounded-0 rounded-bottom m-0">
                        <a href="#" class="dropdown-item">
                            <div class="d-flex aligns-items-center">
                                <img src="img/johanns.jpg" alt="User Profile"
                                class="rounded-circle" style="width: 40px; height: 
                                40px;">
                                <div class="ms-2">
                                    <h6 class="fw-normal mb-0">Johanns send you a 
                                    message</h6>
                                    <small>5 minutes ago</small>
                            </div>
                        </div>
                        </a>
                         <hr class="dropdown-divider">
                         <a href="#" class="dropdown-item">
                            <div class="d-flex aligns-items-center">
                                <img src="img/carlo.jpg" alt=""
                                class="rounded-circle" style="width: 40px; height: 
                                40px;">
                                <div class="ms-2">
                                    <h6 class="fw-normal mb-0">Carlo send you a 
                                    message</h6>
                                    <small>10 minutes ago</small>
                            </div>
                        </div>
                        </a>
                        <hr class="dropdown-divider">
                        <a href="#" class="dropdown-item">
                            <div class="d-flex aligns-items-center">
                                <img src="img/alquin.jpg" alt=""
                                class="rounded-circle" style="width: 40px; height: 
                                40px;">
                                <div class="ms-2">
                                    <h6 class="fw-normal mb-0">Alquin send you a 
                                    message</h6>
                                    <small>15 minutes ago</small>
                            </div>
                        </div>
                        </a>
                        <hr class="dropdown-divider">
                        <a href="#" class="dropdown-item text-center">See all 
                        Messages</a>
                    </div>
                </div>
                <div class="nav-item dropdown">
                    <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="fa fa-bell me-lg-2"></i>
                        <span class="d-none d-lg-inline">Notifications</span>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end bg-secondary 
                    border-0 rounded-0 rounded-bottom m-0">
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
                        <a href="#" class="dropdown-item text-center">See all 
                        Notification</a>
                 </div>        
            </div>
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
     
    <!-- Orders Table -->
<div class="container-fluid pt-4 px-4">
    <div class="bg-secondary text-center rounded p-4">
        <div class="d-flex align-items-center justify-content-between mb-4">
            <h6 class="mb-0">Our Orders</h6>
            <a href="#">Show all</a>
        </div>
        <div class="table-responsive">
            <table class="table text-start align-middle table-bordered table-hover m-0">
                <thead>
                    <tr class="text-white">
                        <th scope="col"><input class="form-check-input" type="checkbox"></th>
                        <th scope="col">Order ID</th>
                        <th scope="col">TRN#</th>
                        <th scope="col">Time Stamp</th>
                        <th scope="col">Payment Method</th>
                        <th scope="col">Status</th>
                        <th scope="col">More Info.</th>
                        <th scope="col" class="d-none">Customer Image</th> <!-- Hidden column -->
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><input class="form-check-input" type="checkbox"></td>
                        <td>0192313</td>
                        <td>3258137234</td>
                        <td>2025/01/11-02:00 PM</td>
                        <td>GCASH</td>
                        <td><span class="status success">success</span></td>
                        <td><button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#orderDetailsModal"
                            data-name="John Doe"
                            data-email="john@example.com" data-contact="09123456789" data-address="123 Main St, Valencia"
                            data-product="Rubber Handle Grip" data-image="customer1.jpg">Details</button></td>
                        <td class="d-none"><img src="img/Alquin HAHA.jpg" alt="Customer Image" class="img-fluid"></td> <!-- Customer image in hidden column -->
                    </tr>
                    <tr>
                        <td><input class="form-check-input" type="checkbox"></td>
                        <td>-----</td>
                        <td>YY/MM/DD-00:00 AM/PM</td>
                        <td>-----</td>
                        <td>-----</td>
                        <td><span class="">-----</span></td>
                        <td><button class="btn btn-sm btn-primary" data-bs-toggle="" data-bs-target="#orderDetailsModal"
                            data-name="John Doe"
                            data-email="john@example.com" data-contact="09123456789" data-address="123 Main St, Valencia"
                            data-product="Rubber Handle Grip" data-image="customer1.jpg">Details</button></td>
                        <td class="d-none"><img src="customer1.jpg" alt="Customer Image" class="img-fluid"></td> <!-- Customer image in hidden column -->
                    </tr>
                    <tr>
                        <td><input class="form-check-input" type="checkbox"></td>
                        <td>-----</td>
                        <td>YY/MM/DD-00:00 AM/PM</td>
                        <td>-----</td>
                        <td>-----</td>
                        <td><span class="">-----</span></td>
                        <td><button class="btn btn-sm btn-primary" data-bs-toggle="" data-bs-target="#orderDetailsModal"
                            data-name="John Doe"
                            data-email="john@example.com" data-contact="09123456789" data-address="123 Main St, Valencia"
                            data-product="Rubber Handle Grip" data-image="customer1.jpg">Details</button></td>
                        <td class="d-none"><img src="customer1.jpg" alt="Customer Image" class="img-fluid"></td> <!-- Customer image in hidden column -->
                    </tr>
                    <tr>
                        <td><input class="form-check-input" type="checkbox"></td>
                        <td>-----</td>
                        <td>YY/MM/DD-00:00 AM/PM</td>
                        <td>-----</td>
                        <td>-----</td>
                        <td><span class="">-----</span></td>
                        <td><button class="btn btn-sm btn-primary" data-bs-toggle="" data-bs-target="#orderDetailsModal"
                            data-name="John Doe"
                            data-email="john@example.com" data-contact="09123456789" data-address="123 Main St, Valencia"
                            data-product="Rubber Handle Grip" data-image="customer1.jpg">Details</button></td>
                        <td class="d-none"><img src="customer1.jpg" alt="Customer Image" class="img-fluid"></td> <!-- Customer image in hidden column -->
                    </tr>
                    <tr>
                        <td><input class="form-check-input" type="checkbox"></td>
                        <td>-----</td>
                        <td>YY/MM/DD-00:00 AM/PM</td>
                        <td>-----</td>
                        <td>-----</td>
                        <td><span class="">-----</span></td>
                        <td><button class="btn btn-sm btn-primary" data-bs-toggle="" data-bs-target="#orderDetailsModal"
                            data-name="John Doe"
                            data-email="john@example.com" data-contact="09123456789" data-address="123 Main St, Valencia"
                            data-product="Rubber Handle Grip" data-image="customer1.jpg">Details</button></td>
                        <td class="d-none"><img src="customer1.jpg" alt="Customer Image" class="img-fluid"></td> <!-- Customer image in hidden column -->
                    </tr>
                    <tr>
                        <td><input class="form-check-input" type="checkbox"></td>
                        <td>-----</td>
                        <td>YY/MM/DD-00:00 AM/PM</td>
                        <td>-----</td>
                        <td>-----</td>
                        <td><span class="">-----</span></td>
                        <td><button class="btn btn-sm btn-primary" data-bs-toggle="" data-bs-target="#orderDetailsModal"
                            data-name="John Doe"
                            data-email="john@example.com" data-contact="09123456789" data-address="123 Main St, Valencia"
                            data-product="Rubber Handle Grip" data-image="customer1.jpg">Details</button></td>
                        <td class="d-none"><img src="customer1.jpg" alt="Customer Image" class="img-fluid"></td> <!-- Customer image in hidden column -->
                    </tr>
                </tbody>
            </table>
        </div>        
        </div>
      </div>
    <!--Orders End-->
    <!-- Modal -->
<div class="modal fade" id="orderDetailsModal" tabindex="-1" aria-labelledby="orderDetailsLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content bg-white text-dark">
            <div class="modal-header">
                <h5 class="modal-title" id="orderDetailsLabel">Order Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <h6 class="text-center text-dark">CUSTOMER INFO</h6>
                <!-- Customer Image -->
                <div class="text-center">
                    <img id="modalCustomerImage" src="img/Alquin HAHA.jpg" alt="Customer Image" class="img-fluid mb-4" style="max-width: 250px; height: 250;">
                </div>
                <!-- Customer Info -->
                <p><strong>Name:</strong> <span id="modalName">Alquin</span></p>
                <p><strong>Email:</strong> <span id="modalEmail">AlquinSarren@example.com</span></p>
                <p><strong>Contact Info:</strong> <span id="modalContact">(+63)-956-5678-696</span></p>
                <p><strong>Address:</strong> <span id="modalAddress">Brgy Sugod P-2, Valencia City, Sarren Compound Fronting Barangay Hall</span></p>

                <hr>

                <h6 class="text-center text-dark">ORDER PRODUCTS</h6>
                <p><strong>Product Name:</strong> <span id="modalItem">THAI SHIFTER</span> <span id="modalPrice">₱1250</span></p>
                <p><strong>Purchase Quantity:</strong> <span id="modalProductQuantity">2</span></p>
                <p><strong>Purchase Total:</strong> <span id="modalPurchasetotal">₱2500</span></p>
                
                <img id="modalImage" src="img/shifter.png" alt="Item Image" class="img-fluid ">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

    <!--Footer Start-->
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
<!--Footer End-->




     </div> 
<!--Content End-->
 </div>



<!--javascript Libraries-->
<script src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="lib/chart/Chart.min.js"></script>
<script src="lib/easing/easing.min.js"></script>
<script src="lib/waypoints/waypoints.min.js"></script>
<script src="lib/owlcarousel/owl.carousel.min.js"></script>
<script src="lib/tempusdominus/js/moment.min.js"></script>
<script src="lib/tempusdominus/js/moment-timezone.min.js"></script>
<script src="lib/tempusdominus/js/tempusdominus-bootstrap-4.min.js"></script>

 <!-- Template Javascript -->
 <script src="js/main.js">
 </script>
</body>
</html> 
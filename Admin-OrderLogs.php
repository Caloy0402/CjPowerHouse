<?php
// Start session and check admin access
session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: signin.php");
    exit();
}

// Include database connection
require_once 'dbconn.php';

// Get user data for profile image
$user_id = $_SESSION['user_id'];
$user_query = $conn->prepare("SELECT first_name, last_name, profile_image FROM cjusers WHERE id = ?");
$user_query->bind_param("i", $user_id);
$user_query->execute();
$user_result = $user_query->get_result();
$user_data = $user_result->fetch_assoc();

// Fetch comprehensive order data with all related information
$recent_orders_query = "SELECT 
    o.*,
    u.first_name, 
    u.last_name, 
    u.ImagePath,
    u.phone_number,
    u.email,
    t.transaction_number,
    gt.reference_number as gcash_reference,
    gt.transaction_date as gcash_transaction_date,
    gt.amount_paid as gcash_amount,
    gt.client_transaction_date_str
FROM orders o 
LEFT JOIN users u ON o.user_id = u.id 
LEFT JOIN transactions t ON o.id = t.order_id
LEFT JOIN gcash_transactions gt ON o.id = gt.order_id
ORDER BY o.order_date DESC 
LIMIT 10";

$recent_orders_result = $conn->query($recent_orders_query);

// Check for query errors
if (!$recent_orders_result) {
    error_log("Error fetching recent orders: " . $conn->error);
    $recent_orders_result = null;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Order Logs</title>
    <link rel="icon" type="image/png" href="image/logo.png">
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

    <style>
        /* Hide customer image column on smaller screens */
        @media (max-width: 768px) {
            .hidden-column {
                display: none;
            }
        }
        
        /* Ensure proper formatting for currency values */
        .currency-value {
            font-weight: bold;
            color: #00ff00;
        }

        /* Status badge colors */
        .status-pending { background-color: #ffc107; color: #000; }
        .status-ready { background-color: #17a2b8; color: #fff; }
        .status-pickup { background-color: #fd7e14; color: #fff; }
        .status-onship { background-color: #6f42c1; color: #fff; }
        .status-completed { background-color: #28a745; color: #fff; }
        .status-return { background-color: #dc3545; color: #fff; }

        /* Modal enhancements */
        .modal-xl {
            max-width: 1200px;
        }
        
        .info-card {
            transition: all 0.3s ease;
            border: 1px solid #e9ecef;
        }
        
        .info-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        
        .customer-profile-section {
            padding: 20px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 15px;
            border: 2px solid #dee2e6;
        }
        
        .modal-header {
            border-bottom: 3px solid #007bff;
        }
        
        .badge {
            font-size: 0.85em;
            padding: 0.5em 0.75em;
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

            <a href="Admin-OrderLogs.php" class="nav-item nav-link active"><i class="fa fa-shopping-cart me-2"></i>Order Logs</a>
            <a href="Admin-SalesReport.php" class="nav-item nav-link"><i class="fa fa-file-alt me-2"></i>Sales Report</a>
            <a href="Admin-StaffLogs.php" class="nav-item nav-link"><i class="fa fa-user-clock me-2"></i>Staff Logs</a>
            <a href="Admin-RescueLogs.php" class="nav-item nav-link"><i class="fa fa-tools me-2"></i>Rescue Logs</a>

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
                <!-- Removed duplicate static notifications dropdown -->
                <div class="nav-item dropdown">
                    <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                        <img class="rounded-circle me-lg-2" src="<?= $user_data['profile_image'] ? (strpos($user_data['profile_image'], 'uploads/') === 0 ? $user_data['profile_image'] : 'uploads/' . $user_data['profile_image']) : 'img/jandi.jpg' ?>" alt="" style="width: 40px; height: 40px;">
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

<!--Order Logs Start-->  
<div class="container-fluid pt-4 px-4">
    <div class="bg-secondary text-center rounded p-4">
        <div class="d-flex align-items-center justify-content-between mb-4">
            <h6 class="mb-0">Order Logs</h6>
            <a href="Admin-Orders.php">Show All Orders</a>
        </div>
        <div class="table-responsive">
                         <table class="table text-center align-middle table-bordered table-hover mb-0">
                <thead>
                    <tr class="text-white">
                        <th scope="col">
                            <input type="checkbox" class="form-check-input">
                        </th>
                        <th class="hidden-column" scope="col">Customer Image</th>
                        <th scope="col">Date</th>
                        <th scope="col">Transaction #</th>
                        <th scope="col">Customer</th>
                        <th scope="col">Amount</th>
                        <th scope="col">Status</th>
                        <th scope="col">Payment Method</th>
                        <th scope="col">Rider</th>
                        <th scope="col">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($recent_orders_result && $recent_orders_result->num_rows > 0) {
                        while ($row = $recent_orders_result->fetch_assoc()) {
                            // Determine status badge class
                            $status_class = 'status-pending';
                            switch(strtolower($row['order_status'])) {
                                case 'ready to ship':
                                    $status_class = 'status-ready';
                                    break;
                                case 'pick up item':
                                    $status_class = 'status-pickup';
                                    break;
                                case 'on-ship':
                                    $status_class = 'status-onship';
                                    break;
                                case 'completed':
                                    $status_class = 'status-completed';
                                    break;
                                case 'return':
                                    $status_class = 'status-return';
                                    break;
                            }

                            echo '<tr>';
                            echo '<td><input class="form-check-input" type="checkbox"></td>';
                            echo '<td class="hidden-column">';
                            echo '<img src="' . $row['ImagePath'] . '" alt="Customer Image" class="img-fluid rounded-circle" style="width: 40px; height: 40px;">';
                            echo '</td>';
                            echo '<td>' . date('M d, Y', strtotime($row['order_date'])) . '</td>';
                            echo '<td>' . ($row['transaction_number'] ?? 'N/A') . '</td>';
                            echo '<td>' . $row['first_name'] . ' ' . $row['last_name'] . '</td>';
                            echo '<td>₱' . number_format($row['total_price'], 2) . '</td>';
                            echo '<td><span class="badge ' . $status_class . '">' . ucfirst($row['order_status']) . '</span></td>';
                            echo '<td>' . ucfirst($row['payment_method']) . '</td>';
                            echo '<td>' . ($row['rider_name'] ?? 'N/A') . '</td>';
                            echo '<td><a href="#" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#orderDetailsModal" 
                                       data-customer="' . $row['first_name'] . ' ' . $row['last_name'] . '" 
                                       data-customer-phone="' . ($row['phone_number'] ?? 'N/A') . '"
                                       data-customer-email="' . ($row['email'] ?? 'N/A') . '"
                                       data-trn="' . ($row['transaction_number'] ?? 'N/A') . '" 
                                       data-amount="₱' . number_format($row['total_price'], 2) . '" 
                                       data-status="' . ucfirst($row['order_status']) . '" 
                                       data-img="' . $row['ImagePath'] . '"
                                       data-date="' . date('M d, Y', strtotime($row['order_date'])) . '"
                                       data-payment="' . $row['payment_method'] . '"
                                       data-delivery="' . $row['delivery_method'] . '"
                                       data-address="' . htmlspecialchars($row['shipping_address']) . '"
                                       data-home-desc="' . htmlspecialchars($row['home_description']) . '"
                                       data-rider-name="' . ($row['rider_name'] ?? 'N/A') . '"
                                       data-rider-contact="' . ($row['rider_contact'] ?? 'N/A') . '"
                                       data-rider-motor="' . ($row['rider_motor_type'] ?? 'N/A') . '"
                                       data-rider-plate="' . ($row['rider_plate_number'] ?? 'N/A') . '"
                                       data-delivery-fee="₱' . number_format($row['delivery_fee'], 2) . '"
                                       data-total-with-delivery="₱' . number_format($row['total_amount_with_delivery'], 2) . '"
                                       data-weight="' . $row['total_weight'] . ' kg"
                                       data-gcash-ref="' . ($row['gcash_reference'] ?? 'N/A') . '"
                                       data-gcash-date="' . ($row['gcash_transaction_date'] ? date('M d, Y H:i', strtotime($row['gcash_transaction_date'])) : 'N/A') . '"
                                       data-gcash-amount="₱' . ($row['gcash_amount'] ? number_format($row['gcash_amount'], 2) : 'N/A') . '"
                                       data-order-id="' . $row['id'] . '">Details</a></td>';
                            echo '</tr>';
                        }
                    } else {
                        echo '<tr><td colspan="10" class="text-center">No orders found</td></tr>';
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal for Order Details -->
<div class="modal fade" id="orderDetailsModal" tabindex="-1" aria-labelledby="orderDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="orderDetailsModalLabel">
                    <i class="fa fa-shopping-cart me-2"></i>Order Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Customer Profile Section -->
                <div class="row mb-4">
                    <div class="col-md-4 text-center">
                        <img id="modalOrderImage" src="" alt="Customer Image" class="img-fluid rounded-circle border border-3 border-primary" style="width: 100px; height: 100px; object-fit: cover;">
                        <h6 id="modalOrderCustomer" class="mt-2 mb-0 fw-bold text-primary"></h6>
                        <small class="text-muted">Customer</small>
                        <div class="mt-2">
                            <small class="text-muted d-block">
                                <i class="fa fa-phone me-1"></i><span id="modalOrderCustomerPhone">N/A</span>
                            </small>
                            <small class="text-muted d-block">
                                <i class="fa fa-envelope me-1"></i><span id="modalOrderCustomerEmail">N/A</span>
                            </small>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <div class="table-responsive">
                                                         <table class="table table-bordered table-striped text-center">
                                 <tbody>
                                     <tr>
                                         <td width="40%" class="fw-bold">Order ID:</td>
                                         <td><span id="modalOrderId"></span></td>
                                     </tr>
                                     <tr>
                                         <td class="fw-bold">Transaction #:</td>
                                         <td><span id="modalOrderTrn"></span></td>
                                     </tr>
                                     <tr>
                                         <td class="fw-bold">Order Date:</td>
                                         <td><span id="modalOrderDate"></span></td>
                                     </tr>
                                     <tr>
                                         <td class="fw-bold">Status:</td>
                                         <td><span id="modalOrderStatus" class="badge"></span></td>
                                     </tr>
                                     <tr>
                                         <td class="fw-bold">Amount:</td>
                                         <td class="text-success fw-bold"><span id="modalOrderAmount"></span></td>
                                     </tr>
                                     <tr>
                                         <td class="fw-bold">Payment Method:</td>
                                         <td><span id="modalOrderPaymentMethod"></span></td>
                                     </tr>
                                     <tr>
                                         <td class="fw-bold">Delivery Fee:</td>
                                         <td><span id="modalOrderDeliveryFee"></span></td>
                                     </tr>
                                     <tr>
                                         <td class="fw-bold">Total with Delivery:</td>
                                         <td class="text-success fw-bold"><span id="modalOrderTotalWithDelivery"></span></td>
                                     </tr>
                                     <tr>
                                         <td class="fw-bold">Total Weight:</td>
                                         <td><span id="modalOrderWeight"></span></td>
                                     </tr>
                                 </tbody>
                             </table>
                        </div>
                    </div>
                </div>

                <hr>

                <!-- Delivery Information -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <h6 class="text-primary mb-3 text-center"><i class="fa fa-map-marker-alt me-2"></i>Delivery Information</h6>
                        <div class="table-responsive">
                                                         <table class="table table-bordered table-striped text-center">
                                 <tbody>
                                     <tr>
                                         <td width="40%" class="fw-bold">Delivery Method:</td>
                                         <td><span id="modalOrderDelivery"></span></td>
                                     </tr>
                                     <tr>
                                         <td class="fw-bold">Purok:</td>
                                         <td><span id="modalOrderPurok"></span></td>
                                     </tr>
                                     <tr>
                                         <td class="fw-bold">Barangay:</td>
                                         <td><span id="modalOrderBarangay"></span></td>
                                     </tr>
                                     <tr>
                                         <td class="fw-bold">Home Description:</td>
                                         <td><span id="modalOrderHomeDesc"></span></td>
                                     </tr>
                                 </tbody>
                             </table>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-primary mb-3 text-center"><i class="fa fa-motorcycle me-2"></i>Rider Information</h6>
                        <div class="table-responsive">
                                                         <table class="table table-bordered table-striped text-center">
                                 <tbody>
                                     <tr>
                                         <td width="40%" class="fw-bold">Rider Name:</td>
                                         <td><span id="modalOrderRiderName"></span></td>
                                     </tr>
                                     <tr>
                                         <td class="fw-bold">Contact:</td>
                                         <td><span id="modalOrderRiderContact"></span></td>
                                     </tr>
                                     <tr>
                                         <td class="fw-bold">Motor Type:</td>
                                         <td><span id="modalOrderRiderMotor"></span></td>
                                     </tr>
                                     <tr>
                                         <td class="fw-bold">Plate Number:</td>
                                         <td><span id="modalOrderRiderPlate"></span></td>
                                     </tr>
                                 </tbody>
                             </table>
                        </div>
                    </div>
                </div>

                <!-- GCASH Payment Details Section -->
                <div id="gcashSection" style="display: none;">
                    <hr>
                    <h6 class="text-success mb-3 text-center"><i class="fa fa-credit-card me-2"></i>GCASH Payment Details</h6>
                    <div class="table-responsive">
                                                 <table class="table table-bordered table-striped text-center">
                             <tbody>
                                 <tr>
                                     <td width="25%" class="fw-bold">Reference Number:</td>
                                     <td width="25%"><span id="modalOrderGcashRef" class="text-success fw-bold"></span></td>
                                     <td width="25%" class="fw-bold">Transaction Date:</td>
                                     <td width="25%"><span id="modalOrderGcashDate"></span></td>
                                 </tr>
                                 <tr>
                                     <td class="fw-bold">Amount Paid:</td>
                                     <td><span id="modalOrderGcashAmount" class="text-success fw-bold"></span></td>
                                     <td></td>
                                     <td></td>
                                 </tr>
                             </tbody>
                         </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fa fa-times me-2"></i>Close
                </button>
            </div>
        </div>
    </div>
</div>
<!--Order Logs End-->

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

    <!-- Custom JavaScript for Order Logs -->
    <script>
          // Hide spinner when page loads
          window.onload = function() {
            const spinner = document.getElementById('spinner');
            if (spinner) {
                spinner.classList.remove('show');
            }
        };

        // Modal handling for order details
        document.addEventListener('DOMContentLoaded', function() {
            const orderDetailsModal = document.getElementById('orderDetailsModal');
            if (orderDetailsModal) {
                orderDetailsModal.addEventListener('show.bs.modal', function(event) {
                    const button = event.relatedTarget;
                    
                                         // Get all data attributes
                     const customer = button.getAttribute('data-customer');
                     const customerPhone = button.getAttribute('data-customer-phone');
                     const customerEmail = button.getAttribute('data-customer-email');
                     const trn = button.getAttribute('data-trn');
                     const amount = button.getAttribute('data-amount');
                     const status = button.getAttribute('data-status');
                     const img = button.getAttribute('data-img');
                     const date = button.getAttribute('data-date');
                     const payment = button.getAttribute('data-payment');
                     const delivery = button.getAttribute('data-delivery');
                     const address = button.getAttribute('data-address');
                     const homeDesc = button.getAttribute('data-home-desc');
                     const riderName = button.getAttribute('data-rider-name');
                     const riderContact = button.getAttribute('data-rider-contact');
                     const riderMotor = button.getAttribute('data-rider-motor');
                     const riderPlate = button.getAttribute('data-rider-plate');
                     const deliveryFee = button.getAttribute('data-delivery-fee');
                     const totalWithDelivery = button.getAttribute('data-total-with-delivery');
                     const weight = button.getAttribute('data-weight');
                     const gcashRef = button.getAttribute('data-gcash-ref');
                     const gcashDate = button.getAttribute('data-gcash-date');
                     const gcashAmount = button.getAttribute('data-gcash-amount');
                     const orderId = button.getAttribute('data-order-id');

                                         // Update modal content
                     document.getElementById('modalOrderCustomer').textContent = customer;
                     document.getElementById('modalOrderCustomerPhone').textContent = customerPhone;
                     document.getElementById('modalOrderCustomerEmail').textContent = customerEmail;
                     document.getElementById('modalOrderTrn').textContent = trn;
                    document.getElementById('modalOrderAmount').textContent = amount;
                    
                    // Update status with proper badge styling
                    const statusElement = document.getElementById('modalOrderStatus');
                    statusElement.textContent = status;
                    statusElement.className = 'badge'; // Reset classes
                    
                    // Add appropriate status badge class
                    const statusLower = status.toLowerCase();
                    if (statusLower.includes('ready to ship')) {
                        statusElement.classList.add('bg-info');
                    } else if (statusLower.includes('pick up') || statusLower.includes('processing')) {
                        statusElement.classList.add('bg-warning');
                    } else if (statusLower.includes('on-ship')) {
                        statusElement.classList.add('bg-primary');
                    } else if (statusLower.includes('completed')) {
                        statusElement.classList.add('bg-success');
                    } else if (statusLower.includes('return')) {
                        statusElement.classList.add('bg-danger');
                    } else if (statusLower.includes('pending')) {
                        statusElement.classList.add('bg-secondary');
                    } else {
                        statusElement.classList.add('bg-secondary');
                    }
                    
                    document.getElementById('modalOrderImage').src = img;
                    document.getElementById('modalOrderDate').textContent = date;
                    document.getElementById('modalOrderPaymentMethod').textContent = payment;
                    document.getElementById('modalOrderDelivery').textContent = delivery;
                    
                    // Parse shipping address to extract Purok and Barangay
                    const addressElement = document.getElementById('modalOrderAddress');
                    const purokElement = document.getElementById('modalOrderPurok');
                    const barangayElement = document.getElementById('modalOrderBarangay');
                    
                    if (address && address !== 'N/A') {
                        // Try to parse address like "2, Bagontaas" or "Purok: 2, Brgy: Bagontaas"
                        let purok = 'N/A';
                        let barangay = 'N/A';
                        
                        if (address.includes('Purok:') && address.includes('Brgy:')) {
                            // Format: "Purok: 2, Brgy: Bagontaas"
                            const purokMatch = address.match(/Purok:\s*([^,]+)/);
                            const barangayMatch = address.match(/Brgy:\s*([^,]+)/);
                            if (purokMatch) purok = purokMatch[1].trim();
                            if (barangayMatch) barangay = barangayMatch[1].trim();
                        } else if (address.includes(',')) {
                            // Format: "2, Bagontaas"
                            const parts = address.split(',');
                            if (parts.length >= 2) {
                                purok = parts[0].trim();
                                barangay = parts[1].trim();
                            }
                        } else {
                            // Single value, assume it's barangay
                            barangay = address.trim();
                        }
                        
                        purokElement.textContent = purok;
                        barangayElement.textContent = barangay;
                    } else {
                        purokElement.textContent = 'N/A';
                        barangayElement.textContent = 'N/A';
                    }
                    
                    document.getElementById('modalOrderHomeDesc').textContent = homeDesc;
                    document.getElementById('modalOrderRiderName').textContent = riderName;
                    document.getElementById('modalOrderRiderContact').textContent = riderContact;
                    document.getElementById('modalOrderRiderMotor').textContent = riderMotor;
                    document.getElementById('modalOrderRiderPlate').textContent = riderPlate;
                    
                    // Handle delivery fee display - show "FREE" if ₱0.00
                    const deliveryFeeElement = document.getElementById('modalOrderDeliveryFee');
                    if (deliveryFee === '₱0.00') {
                        deliveryFeeElement.textContent = 'FREE';
                        deliveryFeeElement.className = 'text-success fw-bold';
                    } else {
                        deliveryFeeElement.textContent = deliveryFee;
                        deliveryFeeElement.className = 'text-muted';
                    }
                    
                    document.getElementById('modalOrderTotalWithDelivery').textContent = totalWithDelivery;
                    document.getElementById('modalOrderWeight').textContent = weight;
                    document.getElementById('modalOrderId').textContent = orderId;
                    document.getElementById('modalOrderGcashRef').textContent = gcashRef;
                    document.getElementById('modalOrderGcashDate').textContent = gcashDate;
                    document.getElementById('modalOrderGcashAmount').textContent = gcashAmount;

                    // Show/hide GCASH section based on payment method
                    const gcashSection = document.getElementById('gcashSection');
                    if (payment.toLowerCase() === 'gcash' && gcashRef !== 'N/A') {
                        gcashSection.style.display = 'block';
                    } else {
                        gcashSection.style.display = 'none';
                    }
                });
            }
        });
    </script>
</body>
</html>

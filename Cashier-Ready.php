<?php
require 'dbconn.php'; // 

// Start the session to access session variables
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    // Redirect to login if user is not logged in
    header("Location: signin.php");
    exit;
}

// Prepare and execute the query to fetch the user profile
$stmt = $conn->prepare("SELECT role, profile_image, first_name, last_name FROM cjusers WHERE id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();

// Fetch the result
if ($user = $result->fetch_assoc()) {
    // Set profile image and role
    $role = $user['role'];
    $profile_image = $user['profile_image'] ? (strpos($user['profile_image'], 'uploads/') === 0 ? $user['profile_image'] : 'uploads/' . $user['profile_image']) : 'img/default.jpg';
    $user_name = $user['first_name'] . ' ' . $user['last_name'];
} else {
    // Default fallback
    $role = 'Guest';
    $profile_image = 'uploads/carlo.jpg';
    $user_name = 'Cashier';
}

$stmt->close();

function getPickupOrderCount($conn, $status, $date = null) {
    // Base query and params
    $params = [$status];
    $types = "s";

    // Count pickup orders with specific status (exclude cancelled and completed orders from active counts)
    if ($date !== null && strtolower($status) === 'completed') {
        // For completed count, we still want to show today's completed orders in dashboard stats
        $sql = "SELECT COUNT(*)
                FROM orders o
                JOIN transactions t ON o.id = t.order_id
                WHERE o.delivery_method = 'pickup' AND o.order_status = ?
                      AND DATE(t.completed_date_transaction) = ?";
        $params[] = $date;
        $types .= 's';
    } else {
        // For active pickup orders, exclude both cancelled and completed
        $sql = "SELECT COUNT(*) FROM orders o WHERE o.delivery_method = 'pickup' AND o.order_status = ? AND o.order_status != 'Cancelled' AND o.order_status != 'Completed'";
        if ($date !== null) {
            $sql .= " AND DATE(o.order_date) = ?";
            $params[] = $date;
            $types .= 's';
        }
    }

    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        error_log("Prepare failed: " . $conn->error);
        return "Error";
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result_count = 0;
    $stmt->bind_result($result_count);
    $stmt->fetch();
    $stmt->close();
    return $result_count;
}

// Get counts for pickup orders
$pendingPickupCount = getPickupOrderCount($conn, 'Pending');
$readyPickupCount = getPickupOrderCount($conn, 'Ready to Ship');

// Get today's counts
$today = date("Y-m-d");
$todayPendingPickupCount = getPickupOrderCount($conn, 'Pending', $today);
$todayReadyPickupCount = getPickupOrderCount($conn, 'Ready to Ship', $today);
$todayCompletedPickupCount = getPickupOrderCount($conn, 'Completed', $today);

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Jandi - Ready for Pickup Orders</title>
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <meta content="" name="keywords">
    <meta content="" name="description">

    <!-- Favicons -->
    <link rel="icon" type="image/png" href="Image/logo.png">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600&family=Roboto:wght@500;700&display=swap"
        rel="stylesheet">

    <!-- icon font Stylesheet -->
    <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.10.0/css/all.min.css">
    <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.4.1/font/bootstrap-icons.css">

    <!--libraries stylesheet-->
    <link href="lib/owlcarousel/assets/owl.carousel.min.css" rel="stylesheet">
    <link href="lib/tempusdominus/css/tempusdominus-bootstrap-4.min.css"
        rel="stylesheet">

    <!--customized Bootstrap Stylesheet-->
    <link href="css/bootstrap.min.css" rel="stylesheet">

    <!--Template Stylesheet-->
    <link href="css/style.css" rel="stylesheet">

    <style>
    /* Custom styles for status tracker */
    .status-tracker {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 20px;
        position: relative;
        margin-bottom: 10px;
    }

    .status-button {
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 10px 20px;
        border-radius: 25px;
        background-color: #343a40;
        color: white;
        text-decoration: none;
        border: 1px solid #6c757d;
        transition: transform 0.2s ease-in-out;
        position: relative;
        z-index: 1;
    }

    .status-button:hover {
        transform: scale(1.05);
        color: white;
        background-color: #495057;
    }

    .status-button .fa {
        margin-right: 8px;
    }

    .badge {
        position: absolute;
        top: -8px;
        right: -8px;
        padding: 3px 6px;
        border-radius: 50%;
        background-color: red;
        color: white;
        font-size: 12px;
    }

    /* The green line connecting the buttons */
    .status-tracker::before {
        content: "";
        position: absolute;
        top: 50%;
        left: 0;
        width: 100%;
        height: 3px;
        background-color: #28a745;
        z-index: 0;
    }

    .table-responsive {
        margin-top: 10px;
    }

    /* Custom styles for modal inputs */
    #pickupOrderModal .form-control {
        background-color: white;
        color: black;
    }

    /* Readonly fields styling */
    #pickupOrderModal .form-control[readonly] {
        background-color: #f8f9fa;
        color: #6c757d;
        cursor: not-allowed;
    }

    .pickup-ready {
        background-color: #d4edda !important;
        border-left: 4px solid #28a745;
    }

    .pickup-pending {
        background-color: #fff3cd !important;
        border-left: 4px solid #ffc107;
    }
    
    /* Pagination styling - show only 5 rows with scroll */
    .table-pagination-container {
        max-height: 300px !important; /* Approximately 5 rows height */
        overflow-y: auto !important;
        border: 1px solid #dee2e6 !important;
        border-radius: 8px !important;
    }
    
    .table-pagination-container::-webkit-scrollbar {
        width: 8px !important;
    }
    
    .table-pagination-container::-webkit-scrollbar-track {
        background: #f1f1f1 !important;
        border-radius: 4px !important;
    }
    
    .table-pagination-container::-webkit-scrollbar-thumb {
        background: #888 !important;
        border-radius: 4px !important;
    }
    
    .table-pagination-container::-webkit-scrollbar-thumb:hover {
        background: #555 !important;
    }
    
    /* Ensure table header stays fixed */
    .table-pagination-container .table thead th {
        position: sticky !important;
        top: 0 !important;
        z-index: 10 !important;
        background-color: #212529 !important;
    }
    
    /* Light theme for selects inside the pickup modal */
    #pickupOrderModal .form-select {
        background-color: #ffffff !important;
        color: #212529 !important;
        border: 1px solid #ced4da !important;
    }
    #pickupOrderModal .form-select:focus {
        background-color: #ffffff !important;
        color: #212529 !important;
        border-color: #80bdff !important;
        box-shadow: 0 0 0 0.2rem rgba(13,110,253,.25) !important;
    }
    #pickupOrderModal .form-select option {
        background-color: #ffffff !important;
        color: #212529 !important;
    }
    </style>
</head>

<body>
    <div class="container-fluid position-relative d-flex p-0">
        <!-- Spinner Start -->
        <div id="spinner"
            class="show bg-dark position-fixed translate-middle w-100 vh-100 top-50 start-50 d-flex align-items-center justify-content-center">
            <img src="img/Loading.gif" alt="Loading..."
                style="width: 200px; height: 200px;" />
        </div>
        <!-- Spinner End -->
        
        <!-- Sidebar Start -->
        <div class="sidebar pe-4 pb-3">
            <nav class="navbar bg-secondary navbar-dark">
                <div class="navbar-brand mx-4 mb-3">
                    <h3 class="text-primary"><i class="fa fa-user-edit me-2"></i>Cj P'House</h3>
                </div>
                <div class="d-flex align-items-center ms-4 mb-4">
                    <div class="position-relative">
                        <img src="<?php echo $profile_image; ?>" alt="" class="rounded-circle"
                            style="width: 40px; height: 40px;">
                        <div
                            class="bg-success rounded-circle border border-2 border-white position-absolute end-0 bottom-0 p-1">
                        </div>
                    </div>
                    <div class="ms-3">
                        <h6 class="mb-0"><?php echo htmlspecialchars($user_name); ?></h6>
                        <span id="role">Cashier</span>
                    </div>
                </div>
                <div class="navbar-nav w-100">
                    <a href="Cashier-Dashboard.php" class="nav-item nav-link"><i
                            class="fa fa-tachometer-alt me-2"></i>Dashboard</a>
                    <div class="nav-item dropdown">
                        <a href="#" class="nav-link dropdown-toggle"
                            data-bs-toggle="dropdown">
                            <i class="fa fa-shopping-cart me-2"></i>Pending Orders
                        </a>
                        <div class="dropdown-menu bg-transparent border-0">
                            <a href="Cashier-COD-Delivery.php"
                                class="dropdown-item">Pending COD orders</a>
                            <a href="Cashier-GCASH-Delivery.php"
                                class="dropdown-item">Pending GCASH orders</a>
                        </div>
                    </div>
                    <a href="Cashier-Pickup-Orders.php" class="nav-item nav-link active"><i
                            class="fa fa-store me-2"></i>Pickup Orders</a>
                    <a href="Cashier-Transactions.php" class="nav-item nav-link"><i
                            class="fa fa-list-alt me-2"></i>Transactions</a>
                    <a href="Cashier-Returns.php" class="nav-item nav-link"><i
                            class="fa fa-undo me-2"></i>Return Product</a>
                </div>
            </nav>
        </div>
        <!-- Sidebar End -->
        
        <!--Content Start-->
        <div class="content">
            <!--Navbar Start-->
            <nav class="navbar navbar-expand bg-secondary navbar-dark sticky-top px-4 py-0">
                <a href="index.php" class="navbar-brand d-flex d-lg-none me-4">
                    <h2 class="text-primary mb-0"><i class="fa fa-user-edit"></i></h2>
                </a>
                <a href="#" class="sidebar-toggler flex-shrink-0">
                    <i class="fa fa-bars"></i>
                </a>
                <div class="navbar-nav align-items-center ms-auto">
                    <?php include 'cashier_notifications.php'; ?>
                    <div class="nav-item dropdown">
                        <a href="" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                            <img src="<?php echo $profile_image; ?>" alt="" class="rounded-circle me-lg-2" alt=""
                                style="width: 40px; height: 40px;">
                            <span class="d-none d-lg-inline"><?php echo htmlspecialchars($user_name); ?></span>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end bg-dark border-0 rounded-3 shadow-lg m-0" style="min-width: 200px;">
                            <div class="dropdown-header text-light border-bottom border-secondary">
                                <small class="text-muted">Account</small>
                            </div>
                            <a href="logout.php" class="dropdown-item text-light d-flex align-items-center py-2">
                                <i class="fas fa-sign-out-alt me-2 text-danger"></i>
                                <span>Log out</span>
                            </a>
                        </div>
                    </div>
                </div>
            </nav>
            <!--Navbar End-->
            
            <!-- Sales & Revenue Start -->
            <div class="container-fluid pt-4 px-4">
                <div class="row g-3">
                    <div class="col-md-4 col-sm-6">
                        <div class="bg-secondary rounded d-flex align-items-center justify-content-between p-3">
                            <i class="fa fa-clock fa-3x text-danger"></i>
                            <div class="ms-2">
                                <p class="mb-1 text-white">Today's Pending Pickups</p>
                                <h6 class="mb-0 text-white"><?php echo $todayPendingPickupCount !== "Error" ? htmlspecialchars($todayPendingPickupCount) : "Error"; ?></h6>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 col-sm-6">
                        <div class="bg-secondary rounded d-flex align-items-center justify-content-between p-3">
                            <i class="fa fa-check-circle fa-3x text-danger"></i>
                            <div class="ms-2">
                                <p class="mb-1 text-white">Today's Ready for Pickup</p>
                                <h6 class="mb-0 text-white"><?php echo $todayReadyPickupCount !== "Error" ? htmlspecialchars($todayReadyPickupCount) : "Error"; ?></h6>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 col-sm-6">
                        <div class="bg-secondary rounded d-flex align-items-center justify-content-between p-3">
                            <i class="fa fa-handshake fa-3x text-danger"></i>
                            <div class="ms-2">
                                <p class="mb-1 text-white">Today's Completed Pickups</p>
                                <h6 class="mb-0 text-white"><?php echo $todayCompletedPickupCount !== "Error" ? htmlspecialchars($todayCompletedPickupCount) : "Error"; ?></h6>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Sales & Revenue End -->
            
            <!-- Status Buttons Start -->
            <div class="container-fluid pt-4 px-4">
                <div class="status-tracker">
                    <a href="Cashier-Pickup-Orders.php" class="status-button">
                        <i class="fa fa-exclamation-triangle"></i> Pending Pickup
                        <?php if ($pendingPickupCount > 0): ?>
                        <span class="badge"><?php echo $pendingPickupCount; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="Cashier-Ready.php" class="status-button" style="background-color: #28a745;">
                        <i class="fa fa-check-circle"></i> Ready for Pickup
                        <?php if ($readyPickupCount > 0): ?>
                        <span class="badge"><?php echo $readyPickupCount; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="Cashier-Completed.php" class="status-button">
                        <i class="fa fa-history"></i> Completed Orders
                    </a>
                </div>
            </div>
            <!-- Status Buttons End -->
            
            <!-- Pickup Orders Start -->
            <div class="container-fluid pt-4 px-4">
                <div class="bg-secondary rounded p-4">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4 class="mb-0">Ready for Pickup Orders</h4>
                        <div class="input-group" style="width: 300px;">
                        </div>
                    </div>
                    <div class="table-responsive table-pagination-container">
                        <table class="table table-hover">
                            <thead>
                                <tr class="text-white text-center">
                                    <th scope="col" class="text-center">Order ID</th>
                                    <th scope="col" class="text-center">TRN #</th>
                                    <th scope="col" class="text-center">Order Date</th>
                                    <th scope="col" class="text-center">Customer Name</th>
                                    <th scope="col" class="text-center">Payment Method</th>
                                    <th scope="col" class="text-center">Total Amount</th>
                                    <th scope="col" class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody id="pickup-orders">
                                <!-- Pickup orders will be dynamically populated here -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <!-- Pickup Orders End -->
            
            <!-- Modal for Pickup Order Details -->
            <div class="modal fade" id="pickupOrderModal" tabindex="-1" aria-labelledby="pickupOrderModalLabel"
                aria-hidden="true">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title text-dark w-100 text-center" id="pickupOrderModalLabel">Pickup Order Details</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"
                                aria-label="Close"></button>
                        </div>
                        <div class="modal-body" style="max-height: 75vh; overflow-y: auto;">
                            <form id="updatePickupOrderForm">
                                <input type="hidden" id="modalOrderId" name="order_id">
                                
                                <div class="customer-details mb-4">
                                    <h6>Customer Details</h6>
                                    <div class="d-flex flex-row align-items-start">
                                        <!-- Customer Image on the Left -->
                                        <div class="customer-image me-3">
                                            <img id="modalCustomerImage" src="" alt="Customer Image"
                                                class="soft-edge-square" width="200" height="200">
                                        </div>
                                        <!-- Customer Details Table on the Right -->
                                        <div class="customer-info flex-fill">
                                            <table class="table table-bordered table-sm">
                                                <tbody>
                                                    <tr>
                                                        <td class="fw-bold bg-secondary text-white text-center" style="width: 35%;">Name:</td>
                                                        <td id="modalCustomerName" class="text-dark"></td>
                                                    </tr>
                                                    <tr>
                                                        <td class="fw-bold bg-secondary text-white text-center">Phone:</td>
                                                        <td id="modalCustomerPhone" class="text-dark"></td>
                                                    </tr>
                                                    <tr>
                                                        <td class="fw-bold bg-secondary text-white text-center">Email:</td>
                                                        <td id="modalCustomerEmail" class="text-dark"></td>
                                                    </tr>
                                                    <tr>
                                                        <td class="fw-bold bg-secondary text-white text-center">Transaction Number:</td>
                                                        <td id="modalTransactionNumber" class="text-dark"></td>
                                                    </tr>
                                                    <tr>
                                                        <td class="fw-bold bg-secondary text-white text-center">Order Date:</td>
                                                        <td id="modalOrderDate" class="text-dark"></td>
                                                    </tr>
                                                    <tr>
                                                        <td class="fw-bold bg-secondary text-white text-center">Payment Method:</td>
                                                        <td class="text-dark">Pick up only</td>
                                                    </tr>
                                                    <tr>
                                                        <td class="fw-bold bg-secondary text-white text-center">Purchase Date:</td>
                                                        <td id="modalPurchaseDate" class="text-dark"></td>
                                                    </tr>
                                                    <tr>
                                                        <td class="fw-bold bg-secondary text-white text-center">Total Amount:</td>
                                                        <td id="modalTotalAmount" class="text-dark"></td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="order-items mb-4">
                                    <h6>Order Items</h6>
                                    <div id="modalOrderItems" class="table-responsive">
                                        <!-- Order items will be populated here -->
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="orderStatus" class="form-label">Update Order Status:</label>
                                    <select class="form-select" id="orderStatus" name="order_status">
                                        <option value="Cancelled">Cancel Order</option>
                                        <option value="Completed">Mark as Completed (Customer Collected)</option>
                                    </select>
                                </div>
                                
                                
                                <button type="button" class="btn btn-success" id="modalUpdateBtn">Update Order</button>
                            </form> 
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary"
                                data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>
            <!---end of modal-->
            
            <!--Footer Start-->
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
        <!--Footer End-->
        </div>
        <!--Content End-->
    </div>
    
    <!--javascript Libraries-->
    <script src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="lib/chart/Chart.min.js"></script>
    <script src="js/notification-sound.js"></script>
    <script src="lib/easing/easing.min.js"></script>
    <script src="lib/waypoints/waypoints.min.js"></script>
    <script src="lib/owlcarousel/owl.carousel.min.js"></script>
    <script src="lib/tempusdominus/js/moment.min.js"></script>
    <script src="lib/tempusdominus/js/moment-timezone.min.js"></script>
    <script src="lib/tempusdominus/js/tempusdominus-bootstrap-4.min.js"></script>

    <!-- Template Javascript -->
    <script src="js/main.js"></script>
    <script>
    let currentFilter = 'ready';
    
    $(document).ready(function() {
        // Load pickup orders when page loads (only ready for pickup for this page)
        loadPickupOrders('ready');
        
        // Handle order status change
        $('#orderStatus').on('change', function() {
            var selectedStatus = $(this).val();
            // Completion notes section removed
        });
        
        // Handle modal population
        $('#pickupOrderModal').on('show.bs.modal', function(event) {
            var button = $(event.relatedTarget);
            
            // Only load order details if triggered by a button click (not programmatically)
            // event.relatedTarget will be null when modal is shown programmatically
            if (button && button.data('order-id')) {
                var orderId = button.data('order-id');
                // Load order details via AJAX
                loadOrderDetails(orderId);
            }
        });
        
        // Reset z-index when pickup modal is hidden
        $('#pickupOrderModal').on('hidden.bs.modal', function() {
            $(this).css('z-index', '');
        });
        
        // Handle update button click
        $('#modalUpdateBtn').on('click', function() {
            updatePickupOrder();
        });
        
        // Search functionality
        $('#searchInput').on('keyup', function(e) {
            if (e.key === 'Enter') {
                searchOrders();
            }
        });
    });
    
    function loadPickupOrders(filter) {
        currentFilter = filter;
        $.ajax({
            url: 'fetch_pickup_order_data.php',
            method: 'GET',
            data: { filter: filter },
            success: function(response) {
                $('#pickup-orders').html(response);
            },
            error: function() {
                $('#pickup-orders').html('<tr><td colspan="7">Error loading pickup orders.</td></tr>');
            }
        });
    }
    
    function filterOrders(filter) {
        loadPickupOrders(filter);
    }
    
    function searchOrders() {
        var searchTerm = $('#searchInput').val();
        $.ajax({
            url: 'fetch_pickup_order_data.php',
            method: 'GET',
            data: { 
                filter: currentFilter,
                search: searchTerm 
            },
            success: function(response) {
                $('#pickup-orders').html(response);
            },
            error: function() {
                $('#pickup-orders').html('<tr><td colspan="7">Error searching orders.</td></tr>');
            }
        });
    }
    
    function loadOrderDetails(orderId) {
        $.ajax({
            url: 'get_pickup_order_details.php',
            method: 'GET',
            data: { order_id: orderId },
            dataType: 'json',
            success: function(data) {
                if (data.success) {
                    populateModal(data.order);
                } else {
                    alert('Error loading order details: ' + data.message);
                }
            },
            error: function() {
                alert('Error loading order details.');
            }
        });
    }
    
    function populateModal(order) {
        $('#modalOrderId').val(order.id);
        $('#modalCustomerName').text(order.customer_name);
        
        // Format phone number with +63
        var phone = order.customer_phone || 'N/A';
        if (phone !== 'N/A' && phone.length >= 10) {
            // Remove any existing +63 or leading zeros
            phone = phone.replace(/^\+63|^63|^0/, '');
            phone = '+63 ' + phone;
        }
        $('#modalCustomerPhone').text(phone);
        
        $('#modalCustomerEmail').text(order.customer_email || 'N/A');
        $('#modalTransactionNumber').text(order.transaction_number);
        $('#modalOrderDate').text(order.order_date);
        $('#modalPurchaseDate').text(order.order_date);
        $('#modalTotalAmount').text('₱' + parseFloat(order.total_price).toFixed(2));
        $('#modalCustomerImage').attr('src', order.customer_image || 'img/default.jpg');
        
        // Populate order items
        var itemsHtml = '<table class="table table-sm"><thead><tr><th>Item</th><th>Quantity</th><th>Price</th></tr></thead><tbody>';
        if (order.items && order.items.length > 0) {
            order.items.forEach(function(item) {
                itemsHtml += '<tr><td>' + item.name + '</td><td>' + item.quantity + '</td><td>₱' + parseFloat(item.price).toFixed(2) + '</td></tr>';
            });
        }
        itemsHtml += '</tbody></table>';
        $('#modalOrderItems').html(itemsHtml);
        
        // Show status update section for active orders
        $('#orderStatus').closest('.mb-3').show();
        $('#modalUpdateBtn').show();
        $('#pickupOrderModalLabel').text('Pickup Order Details');
        
        // Set appropriate status options based on current order status
        var statusSelect = $('#orderStatus');
        statusSelect.empty();
        
        if (order.order_status === 'Pending') {
            // From Pending: can mark as Ready for Pickup or Cancel
            statusSelect.append('<option value="Ready to Ship">Mark as Ready for Pickup</option>');
            statusSelect.append('<option value="Cancelled">Cancel Order</option>');
        } else if (order.order_status === 'Ready to Ship') {
            // From Ready to Ship: can mark as Completed (customer collected) or Cancel
            statusSelect.append('<option value="Completed">Mark as Completed (Customer Collected)</option>');
            statusSelect.append('<option value="Cancelled">Cancel Order</option>');
        } else {
            // Fallback - show cancel and completed
            statusSelect.append('<option value="Cancelled">Cancel Order</option>');
            statusSelect.append('<option value="Completed">Mark as Completed (Customer Collected)</option>');
        }
    }
    
    function updatePickupOrder() {
        var formData = {
            order_id: $('#modalOrderId').val(),
            order_status: $('#orderStatus').val(),
            completion_notes: $('#completionNotes').val()
        };
        
        $.ajax({
            url: 'update_pickup_order.php',
            method: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    alert(response.message);
                    $('#pickupOrderModal').modal('hide');
                    loadPickupOrders(currentFilter); // Reload the orders
                    
                    // If order was completed, show a message about where to find it
                    if (response.new_status === 'Completed') {
                        setTimeout(function() {
                            alert('Order completed successfully! You can now find this transaction in the Transactions page.');
                        }, 500);
                    }
                } else {
                    alert('Error: ' + response.message);
                }
            },
            error: function() {
                alert('Error updating order.');
            }
        });
    }
    </script>
</body>
</html>
<?php $conn->close(); ?>

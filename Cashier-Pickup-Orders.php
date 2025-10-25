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
$profile_image = $user['profile_image'] ? (strpos($user['profile_image'], 'uploads/') === 0 ? $user['profile_image'] : 'uploads/' . $user['profile_image']) : 'img/user.jpg';
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
    <title>Jandi - Pending Pickup Orders</title>
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

    /* Scope badge positioning to status buttons only to avoid affecting table badges */
    .status-button .badge {
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

    /* Custom styles for date inputs in completed orders modal */
    #completedOrdersModal .form-control[type="date"] {
        background-color: white !important;
        color: black !important;
        border: 1px solid #ced4da;
        position: relative;
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='%23007bff' d='M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h1a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2h1V.5a.5.5 0 0 1 .5-.5zM2 2a1 1 0 0 0-1 1v1h14V3a1 1 0 0 0-1-1H2zm13 3H1v9a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V5z'/%3e%3c/svg%3e");
        background-repeat: no-repeat;
        background-position: right 10px center;
        background-size: 20px 20px;
        padding-right: 40px;
    }

    /* Make the native calendar icon visible and styled */
    #completedOrdersModal .form-control[type="date"]::-webkit-calendar-picker-indicator {
        background: transparent;
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='%23007bff' d='M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h1a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2h1V.5a.5.5 0 0 1 .5-.5zM2 2a1 1 0 0 0-1 1v1h14V3a1 1 0 0 0-1-1H2zm13 3H1v9a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V5z'/%3e%3c/svg%3e");
        background-repeat: no-repeat;
        background-position: center;
        background-size: 18px 18px;
        width: 20px;
        height: 20px;
        cursor: pointer;
        opacity: 1;
        position: absolute;
        right: 10px;
        top: 50%;
        transform: translateY(-50%);
        border: none;
        outline: none;
    }

    #completedOrdersModal .form-control[type="date"]::-webkit-calendar-picker-indicator:hover {
        background-color: rgba(0, 123, 255, 0.1);
        border-radius: 3px;
    }

    /* For Firefox - show custom icon */
    #completedOrdersModal .form-control[type="date"] {
        -moz-appearance: textfield;
    }

    /* Make sure the date input text is visible */
    #completedOrdersModal input[type="date"]:focus {
        background-color: white !important;
        color: black !important;
        border-color: #80bdff;
        box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
    }

    /* Custom styles for Quick Select dropdown */
    #completedOrdersModal #timeRange {
        background-color: white !important;
        color: black !important;
        border: 1px solid #ced4da;
    }

    #completedOrdersModal #timeRange:focus {
        background-color: white !important;
        color: black !important;
        border-color: #80bdff;
        box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
    }

    /* Style the dropdown options */
    #completedOrdersModal #timeRange option {
        background-color: white !important;
        color: black !important;
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

    /* Modal polish */
    #pickupOrderModal .customer-image img {
        border-radius: 10px !important;
        object-fit: cover !important;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25) !important;
    }
    #pickupOrderModal .customer-info h5 {
        font-weight: 600 !important;
        margin-top: 6px !important;
    }
    #pickupOrderModal .order-summary-card {
        background: #ffffff !important;
        border: 1px solid #e5e7eb !important;
        border-radius: 10px !important;
    }
    #pickupOrderModal .order-summary-card .card-title {
        font-size: 0.9rem !important;
        color: #6c757d !important;
        margin-bottom: .5rem !important;
    }
    #pickupOrderModal #modalTotalAmount {
        color: #28a745 !important;
        font-weight: 700 !important;
    }
    #pickupOrderModal .items-table thead th {
        background-color: #343a40 !important;
        color: #ffffff !important;
        border: none !important;
    }
    #pickupOrderModal .items-table tbody td {
        vertical-align: middle !important;
        background-color: #ffffff !important;
    }
    #pickupOrderModal .items-table img {
        width: 56px !important;
        height: 56px !important;
        object-fit: cover !important;
        border-radius: 8px !important;
        box-shadow: 0 2px 6px rgba(0,0,0,.15) !important;
    }

    /* New order badge (shows in table ID cell when order is <=10 minutes old) */
    .new-order-badge {
        width: 22px; height: 22px; object-fit: contain; margin-right: 6px; vertical-align: middle;
    }

    /* Light theme for selects in pickup modal */
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

    /* Corner NEW gif badge on Order ID cell */
    td.order-id-cell {
        position: relative;
        overflow: visible;
    }
    td.order-id-cell .new-corner-badge {
        position: absolute;
        top: -12px;
        left: -12px;
        width: 40px;
        height: 40px;
        pointer-events: none;
        z-index: 3;
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
                    <a href="Cashier-Pickup-Orders.php" class="status-button" style="background-color: #28a745;">
                        <i class="fa fa-exclamation-triangle"></i> Pending Pickup
                        <?php if ($pendingPickupCount > 0): ?>
                        <span class="badge"><?php echo $pendingPickupCount; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="Cashier-Ready.php" class="status-button">
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
                        <h4 class="mb-0">Pending Pickup Orders</h4>
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
                                                class="soft-edge-square" width="150" height="150">
                                        </div>
                                        <!-- Customer Name on the Right -->
                                        <div class="customer-info flex-fill">
									<h5 id="modalCustomerName" class="text-dark mb-1"></h5>
									<div class="text-muted small mb-1"><strong>Phone:</strong> <span id="modalCustomerPhone">N/A</span></div>
									<div class="text-muted small"><strong>Email:</strong> <span id="modalCustomerEmail">N/A</span></div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="order-items mb-4">
                                    <h6>Order Items & Details</h6>
                                    <div id="modalOrderItems" class="table-responsive">
                                        <!-- Order items will be populated here -->
                                    </div>
                                    
                                    <!-- Order Summary -->
                                    <div class="row mt-3">
                                        <div class="col-md-6">
                                            <div class="card order-summary-card">
                                                <div class="card-body">
                                                    <h6 class="card-title">Order Information</h6>
                                                    <p class="mb-1"><strong>Order ID:</strong> <span id="modalOrderIdDisplay"></span></p>
                                                    <p class="mb-1"><strong>Transaction #:</strong> <span id="modalTransactionNumber"></span></p>
                                                    <p class="mb-1"><strong>Order Date:</strong> <span id="modalOrderDate"></span></p>
                                                    <p class="mb-0"><strong>Payment Method:</strong> Pick up only</p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="card order-summary-card">
                                                <div class="card-body">
                                                    <h6 class="card-title">Total Amount</h6>
                                                    <h4 class="text-success mb-0" id="modalTotalAmount"></h4>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="orderStatus" class="form-label">Update Order Status:</label>
                                    <select class="form-select" id="orderStatus" name="order_status">
                                        <option value="Cancelled">Cancel Order</option>
                                        <option value="Completed">Ready for Pickup</option>
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
            
            <!-- Modal for Completed Orders History -->
            <div class="modal fade" id="completedOrdersModal" tabindex="-1" aria-labelledby="completedOrdersModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-xl">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title text-dark w-100 text-center" id="completedOrdersModalLabel">Completed Pickup Orders History</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <!-- Time Range Filter -->
                            <div class="row mb-3">
                                <div class="col-md-3">
                                    <label for="dateFrom" class="form-label">From Date:</label>
                                    <input type="date" class="form-control" id="dateFrom" name="date_from">
                                </div>
                                <div class="col-md-3">
                                    <label for="dateTo" class="form-label">To Date:</label>
                                    <input type="date" class="form-control" id="dateTo" name="date_to">
                                </div>
                                <div class="col-md-3">
                                    <label for="timeRange" class="form-label">Quick Select:</label>
                                    <select class="form-select" id="timeRange" onchange="setQuickDateRange()">
                                        <option value="">Select Range</option>
                                        <option value="today">Today</option>
                                        <option value="yesterday">Yesterday</option>
                                        <option value="last7days">Last 7 Days</option>
                                        <option value="last30days">Last 30 Days</option>
                                        <option value="thismonth">This Month</option>
                                        <option value="lastmonth">Last Month</option>
                                    </select>
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <button type="button" class="btn btn-primary me-2" onclick="filterCompletedOrders()">
                                        <i class="fa fa-search"></i> Filter
                                    </button>
                                    <button type="button" class="btn btn-secondary" onclick="clearDateFilters()">
                                        <i class="fa fa-refresh"></i> Clear
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Completed Orders Table -->
                            <div class="table-responsive table-pagination-container">
                                <table class="table table-hover">
                                    <thead>
                                        <tr class="text-white">
                                            <th scope="col">Order ID</th>
                                            <th scope="col">TRN #</th>
                                            <th scope="col">Customer Name</th>
                                            <th scope="col">Total Amount</th>
                                            <th scope="col">Order Date</th>
                                            <th scope="col">Completed Date</th>
                                            <th scope="col">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="completed-orders-table">
                                        <!-- Completed orders will be loaded here -->
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Summary Section -->
                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <div class="card bg-secondary">
                                        <div class="card-body text-center">
                                            <h5 class="card-title text-white">Total Orders</h5>
                                            <h3 class="card-text text-primary" id="totalOrdersCount">0</h3>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card bg-secondary">
                                        <div class="card-body text-center">
                                            <h5 class="card-title text-white">Total Revenue</h5>
                                            <h3 class="card-text text-success" id="totalRevenue">₱0.00</h3>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>
            <!---end of completed orders modal-->
            
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
    let currentFilter = 'pending';
    let eventSource = null;
    let lastNotificationCount = 0;
    let lastOrderCount = 0;
    
    $(document).ready(function() {
        // Initialize notification sound system (will be enabled after first user interaction)
        initializeNotificationSound();
        
        // Load pickup orders when page loads (only pending for this page)
        loadPickupOrders('pending');
        
        // Initialize real-time notifications
        initializeRealTimeNotifications();
        
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
            // Hide back button when modal closes
            $('#modalBackBtn').hide();
        });
        
        // Handle back button click
        $(document).on('click', '#modalBackBtn', function() {
            $('#pickupOrderModal').modal('hide');
            setTimeout(function() {
                $('#completedOrdersModal').modal('show');
            }, 300);
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
        
        // Add date validation event listeners
        $('#dateFrom, #dateTo').on('change', function() {
            var selectedDate = $(this).val();
            var today = formatDate(new Date());
            
            if (selectedDate > today) {
                alert('Cannot select future dates. Setting to today.');
                $(this).val(today);
            }
            
            // Update min/max constraints when dates change
            updateDateConstraints();
        });
        
        // Update date constraints when from date changes
        $('#dateFrom').on('change', function() {
            updateDateConstraints();
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
        console.log('=== LOADING ORDER DETAILS ===');
        console.log('Order ID:', orderId);
        console.log('API URL:', 'get_pickup_order_details.php');
        
        $.ajax({
            url: 'get_pickup_order_details.php',
            method: 'GET',
            data: { 
                order_id: orderId,
                _t: Date.now() // Cache busting
            },
            dataType: 'json',
            timeout: 15000, // allow more time for DB query
            cache: false, // Disable caching
            beforeSend: function() {
                console.log('AJAX request starting...');
            },
            success: function(data) {
                console.log('=== AJAX SUCCESS ===');
                console.log('Raw response:', data);
                console.log('Response type:', typeof data);
                console.log('Success property:', data.success);
                
                if (data && data.success) {
                    console.log('Order data:', data.order);
                    populateModal(data.order);
                } else {
                    console.error('API returned success=false:', data.message);
                    alert('Error loading order details: ' + (data.message || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                console.error('=== AJAX ERROR ===');
                console.error('Status:', status);
                console.error('Error:', error);
                console.error('Response text:', xhr.responseText);
                console.error('Status code:', xhr.status);
                alert('Error loading order details: ' + error);
            },
            complete: function() {
                console.log('AJAX request completed');
            }
        });
    }
    
    function populateModal(order) {
        console.log('=== POPULATING MODAL ===');
        console.log('Order object:', order);
        console.log('Order ID:', order.id);
        console.log('Customer name:', order.customer_name);
        console.log('Customer image:', order.customer_image);
        console.log('Transaction number:', order.transaction_number);
        console.log('Order date:', order.order_date);
        console.log('Total price:', order.total_price);
        console.log('Items:', order.items);
        
        $('#modalOrderId').val(order.id);
        $('#modalOrderIdDisplay').text(order.id);
        $('#modalCustomerName').text(order.customer_name);
        // Format phone as +63-XXXXXXXXXX
        var phone = order.customer_phone || 'N/A';
        if (phone !== 'N/A') {
            var digits = String(phone).replace(/[^0-9]/g, '');
            if (digits.indexOf('63') === 0) digits = digits.substring(2);
            if (digits.indexOf('0') === 0) digits = digits.substring(1);
            if (digits.length >= 9) {
                phone = '+63-' + digits;
            }
        }
        $('#modalCustomerPhone').text(phone);
        $('#modalCustomerEmail').text(order.customer_email || 'N/A');
        $('#modalCustomerImage').attr('src', order.customer_image || 'img/user.jpg');
        
        // Populate order summary
        $('#modalTransactionNumber').text(order.transaction_number);
        $('#modalOrderDate').text(order.order_date);
        $('#modalTotalAmount').text('₱' + parseFloat(order.total_price).toFixed(2));
        
        console.log('Modal elements populated:');
        console.log('Order ID Display:', $('#modalOrderIdDisplay').text());
        console.log('Customer Name:', $('#modalCustomerName').text());
        console.log('Transaction Number:', $('#modalTransactionNumber').text());
        console.log('Order Date:', $('#modalOrderDate').text());
        console.log('Total Amount:', $('#modalTotalAmount').text());
        
        // Check if elements exist
        console.log('Element existence check:');
        console.log('modalOrderIdDisplay exists:', $('#modalOrderIdDisplay').length > 0);
        console.log('modalCustomerName exists:', $('#modalCustomerName').length > 0);
        console.log('modalTransactionNumber exists:', $('#modalTransactionNumber').length > 0);
        console.log('modalOrderDate exists:', $('#modalOrderDate').length > 0);
        console.log('modalTotalAmount exists:', $('#modalTotalAmount').length > 0);
        
        // Populate order items with enhanced display
        var itemsHtml = '<table class="table table-hover items-table">';
        itemsHtml += '<thead>';
        itemsHtml += '<tr><th class="text-center">Image</th><th>Product Name</th><th class="text-center" style="width:110px;">Quantity</th><th class="text-end" style="width:130px;">Unit Price</th><th class="text-end" style="width:140px;">Total</th></tr>';
        itemsHtml += '</thead><tbody>';
        
        if (order.items && order.items.length > 0) {
            order.items.forEach(function(item) {
                var itemTotal = parseFloat(item.price) * parseInt(item.quantity);
                itemsHtml += '<tr>';
                itemsHtml += '<td class="text-center"><img src="' + (item.product_image || 'img/shifter.png') + '" alt="' + item.name + '"></td>';
                itemsHtml += '<td><strong>' + item.name + '</strong></td>';
                itemsHtml += '<td class="text-center"><span class="badge bg-primary">' + item.quantity + '</span></td>';
                itemsHtml += '<td class="text-end">₱' + parseFloat(item.price).toFixed(2) + '</td>';
                itemsHtml += '<td class="text-end"><strong>₱' + itemTotal.toFixed(2) + '</strong></td>';
                itemsHtml += '</tr>';
            });
        } else {
            itemsHtml += '<tr><td colspan="5" class="text-center text-muted">No items found</td></tr>';
        }
        
        itemsHtml += '</tbody></table>';
        $('#modalOrderItems').html(itemsHtml);
        
        // Show status update section for active orders
        $('#orderStatus').closest('.mb-3').show();
        $('#modalUpdateBtn').show();
        $('#pickupOrderModalLabel').text('Pickup Order Details');
        
        // Hide back button for active orders
        $('#modalBackBtn').hide();
        
        // Set appropriate status options based on current order status
        var statusSelect = $('#orderStatus');
        statusSelect.empty();
        
        if (order.order_status === 'Pending') {
            // From Pending: can mark as Ready for Pickup or Cancel
            statusSelect.append('<option value="Ready to Ship">Mark as Ready for Pickup</option>');
            statusSelect.append('<option value="Cancelled">Cancel Order</option>');
        } else if (order.order_status === 'Ready to Ship') {
            // From Ready to Ship: can mark as Completed (customer collected) or Cancel
            statusSelect.append('<option value="Completed">Ready for Pickup</option>');
            statusSelect.append('<option value="Cancelled">Cancel Order</option>');
        } else {
            // Fallback - show cancel and completed
            statusSelect.append('<option value="Cancelled">Cancel Order</option>');
            statusSelect.append('<option value="Completed">Ready for Pickup</option>');
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
                            alert('Order marked as Ready for Pickup! Customer can now collect their order.');
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
    
    function showCompletedOrders() {
        // Set max date to today for both date inputs
        var today = new Date();
        var todayString = formatDate(today);
        $('#dateFrom').attr('max', todayString);
        $('#dateTo').attr('max', todayString);
        
        // Set default date range to last 30 days
        var thirtyDaysAgo = new Date(today.getTime() - (30 * 24 * 60 * 60 * 1000));
        
        $('#dateTo').val(todayString);
        $('#dateFrom').val(formatDate(thirtyDaysAgo));
        $('#timeRange').val('last30days');
        
        // Update date constraints after setting values
        updateDateConstraints();
        
        // Show the modal
        $('#completedOrdersModal').modal('show');
        
        // Load completed orders
        filterCompletedOrders();
    }
    
    function updateDateConstraints() {
        var fromDate = $('#dateFrom').val();
        var toDate = $('#dateTo').val();
        var today = formatDate(new Date());
        
        // Set max date to today for both inputs
        $('#dateFrom').attr('max', today);
        $('#dateTo').attr('max', today);
        
        if (fromDate) {
            // Set minimum date for "To Date" to be the same as "From Date"
            $('#dateTo').attr('min', fromDate);
            
            // If current "To Date" is before "From Date", update it
            if (toDate && toDate < fromDate) {
                $('#dateTo').val(fromDate);
                alert('To Date cannot be earlier than From Date. Setting To Date to match From Date.');
            }
        } else {
            // If no from date, remove min constraint from to date
            $('#dateTo').removeAttr('min');
        }
    }
    
    function formatDate(date) {
        var year = date.getFullYear();
        var month = String(date.getMonth() + 1).padStart(2, '0');
        var day = String(date.getDate()).padStart(2, '0');
        return year + '-' + month + '-' + day;
    }
    
    function setQuickDateRange() {
        var range = $('#timeRange').val();
        var today = new Date();
        var fromDate, toDate;
        
        switch(range) {
            case 'today':
                fromDate = toDate = today;
                break;
            case 'yesterday':
                fromDate = toDate = new Date(today.getTime() - (24 * 60 * 60 * 1000));
                break;
            case 'last7days':
                fromDate = new Date(today.getTime() - (7 * 24 * 60 * 60 * 1000));
                toDate = today;
                break;
            case 'last30days':
                fromDate = new Date(today.getTime() - (30 * 24 * 60 * 60 * 1000));
                toDate = today;
                break;
            case 'thismonth':
                fromDate = new Date(today.getFullYear(), today.getMonth(), 1);
                toDate = today;
                break;
            case 'lastmonth':
                fromDate = new Date(today.getFullYear(), today.getMonth() - 1, 1);
                toDate = new Date(today.getFullYear(), today.getMonth(), 0);
                break;
            default:
                return;
        }
        
        $('#dateFrom').val(formatDate(fromDate));
        $('#dateTo').val(formatDate(toDate));
        
        // Update constraints after setting new dates
        updateDateConstraints();
    }
    
    function clearDateFilters() {
        $('#dateFrom').val('');
        $('#dateTo').val('');
        $('#timeRange').val('');
        
        // Reset date constraints
        var today = formatDate(new Date());
        $('#dateFrom').attr('max', today);
        $('#dateTo').attr('max', today).removeAttr('min');
        
        $('#completed-orders-table').html('<tr><td colspan="7" class="text-center">Please select a date range to view completed orders.</td></tr>');
        $('#totalOrdersCount').text('0');
        $('#totalRevenue').text('₱0.00');
    }
    
    function filterCompletedOrders() {
        var dateFrom = $('#dateFrom').val();
        var dateTo = $('#dateTo').val();
        
        if (!dateFrom || !dateTo) {
            alert('Please select both from and to dates.');
            return;
        }
        
        // Validate that dates are not in the future
        var today = new Date();
        var todayString = formatDate(today);
        
        if (dateFrom > todayString) {
            alert('From date cannot be in the future.');
            $('#dateFrom').val(todayString);
            return;
        }
        
        if (dateTo > todayString) {
            alert('To date cannot be in the future.');
            $('#dateTo').val(todayString);
            return;
        }
        
        // Validate that from date is not after to date
        if (dateFrom > dateTo) {
            alert('From date cannot be after To date.');
            return;
        }
        
        $.ajax({
            url: 'fetch_completed_pickup_orders.php',
            method: 'GET',
            data: {
                date_from: dateFrom,
                date_to: dateTo
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#completed-orders-table').html(response.html);
                    $('#totalOrdersCount').text(response.total_orders);
                    $('#totalRevenue').text('₱' + parseFloat(response.total_revenue).toLocaleString('en-US', {minimumFractionDigits: 2}));
                } else {
                    $('#completed-orders-table').html('<tr><td colspan="7" class="text-center">No completed orders found for the selected date range.</td></tr>');
                    $('#totalOrdersCount').text('0');
                    $('#totalRevenue').text('₱0.00');
                }
            },
            error: function() {
                alert('Error loading completed orders.');
            }
        });
    }
    
    function viewCompletedOrderDetails(orderId) {
        // Validate order ID
        if (!orderId || orderId === '' || orderId === 'undefined') {
            alert('Error: Invalid order ID - received: ' + orderId);
            return;
        }
        
        console.log('Loading completed order details for ID:', orderId, 'Type:', typeof orderId); // Debug log
        
        // Load order details for completed orders (read-only view)
        $.ajax({
            url: 'get_pickup_order_details_fast.php',
            method: 'GET',
            data: { 
                order_id: orderId,
                _t: Date.now()
            },
            dataType: 'json',
            timeout: 30000,
            cache: false,
            success: function(data) {
                if (data && data.success === true) {
                    populateModalReadOnly(data.order);
                    
                    // Hide the completed orders modal first
                    $('#completedOrdersModal').modal('hide');
                    
                    // Small delay to ensure the first modal is hidden before showing the second
                    setTimeout(function() {
                        $('#pickupOrderModal').modal('show');
                    }, 300);
                } else {
                    alert('Error loading order details: ' + (data.message || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                alert('Error loading order details: ' + error);
            }
        });
    }
    
    function populateModalReadOnly(order) {
        // Populate modal with read-only data for completed orders
        $('#modalOrderId').val(order.id);
        $('#modalOrderIdDisplay').text(order.id);
        $('#modalCustomerName').text(order.customer_name);
        $('#modalCustomerImage').attr('src', order.customer_image || 'img/user.jpg');
        var phone = order.customer_phone || 'N/A';
        if (phone !== 'N/A') {
            var digits = String(phone).replace(/[^0-9]/g, '');
            if (digits.indexOf('63') === 0) digits = digits.substring(2);
            if (digits.indexOf('0') === 0) digits = digits.substring(1);
            if (digits.length >= 9) {
                phone = '+63-' + digits;
            }
        }
        $('#modalCustomerPhone').text(phone);
        $('#modalCustomerEmail').text(order.customer_email || 'N/A');
        $('#modalCustomerName').text(order.customer_name);
        $('#modalCustomerImage').attr('src', order.customer_image || 'img/user.jpg');
        var phone = order.customer_phone || 'N/A';
        if (phone !== 'N/A' && String(phone).length >= 10) {
            phone = String(phone).replace(/^\+63|^63|^0/, '');
            phone = '+63 ' + phone;
        }
        $('#modalCustomerPhone').text(phone);
        $('#modalCustomerEmail').text(order.customer_email || 'N/A');
        
        // Populate order summary
        $('#modalTransactionNumber').text(order.transaction_number);
        $('#modalOrderDate').text(order.order_date);
        $('#modalTotalAmount').text('₱' + parseFloat(order.total_price).toFixed(2));
        
        // Populate order items with enhanced display
        var itemsHtml = '<table class="table table-hover">';
        itemsHtml += '<thead class="table-dark">';
        itemsHtml += '<tr><th>Image</th><th>Product Name</th><th>Quantity</th><th>Unit Price</th><th>Total</th></tr>';
        itemsHtml += '</thead><tbody>';
        
        if (order.items && order.items.length > 0) {
            order.items.forEach(function(item) {
                var itemTotal = parseFloat(item.price) * parseInt(item.quantity);
                itemsHtml += '<tr>';
                itemsHtml += '<td><img src="' + (item.product_image || 'img/shifter.png') + '" alt="' + item.name + '" style="width: 50px; height: 50px; object-fit: cover;" class="rounded"></td>';
                itemsHtml += '<td><strong>' + item.name + '</strong></td>';
                itemsHtml += '<td class="text-center"><span class="badge bg-primary">' + item.quantity + '</span></td>';
                itemsHtml += '<td class="text-end">₱' + parseFloat(item.price).toFixed(2) + '</td>';
                itemsHtml += '<td class="text-end"><strong>₱' + itemTotal.toFixed(2) + '</strong></td>';
                itemsHtml += '</tr>';
            });
        } else {
            itemsHtml += '<tr><td colspan="5" class="text-center text-muted">No items found</td></tr>';
        }
        
        itemsHtml += '</tbody></table>';
        $('#modalOrderItems').html(itemsHtml);
        
        // Hide status update section for completed orders
        $('#orderStatus').closest('.mb-3').hide();
        $('#completionSection').hide();
        $('#modalUpdateBtn').hide();
        
        // Show back button for completed orders
        if ($('#modalBackBtn').length === 0) {
            $('#pickupOrderModal .modal-footer').prepend('<button type="button" class="btn btn-secondary" id="modalBackBtn">← Back to History</button>');
        }
        $('#modalBackBtn').show();
        
        // Change modal title
        $('#pickupOrderModalLabel').text('Completed Order Details (Read Only)');
    }
    
    // Real-time notifications functionality
    function initializeRealTimeNotifications() {
        console.log('Initializing real-time notifications for pending pickup orders...');
        connectNotificationSSE();
        
        // Get initial notification count
        getCurrentNotificationCount();
        
        // Get initial order count
        getCurrentOrderCount();
    }
    
    function connectNotificationSSE() {
        if (eventSource && eventSource.readyState !== EventSource.CLOSED) {
            eventSource.close();
        }
        
        eventSource = new EventSource('cashier_notifications_realtime.php');
        
        eventSource.onopen = function(e) {
            console.log('Real-time notification connection established');
        };
        
        eventSource.addEventListener('notification_update', function(e) {
            try {
                const data = JSON.parse(e.data);
                handleNotificationUpdate(data);
            } catch (error) {
                console.error('Error parsing notification data:', error);
            }
        });
        
        eventSource.addEventListener('pickup_order_update', function(e) {
            try {
                const data = JSON.parse(e.data);
                handlePickupOrderUpdate(data);
            } catch (error) {
                console.error('Error parsing pickup order data:', error);
            }
        });
        
        eventSource.onerror = function(e) {
            console.error('SSE connection error, attempting to reconnect...');
            setTimeout(connectNotificationSSE, 5000);
        };
    }
    
    function getCurrentNotificationCount() {
        $.ajax({
            url: 'get_notification_count.php',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    lastNotificationCount = parseInt(response.count) || 0;
                    console.log('Initial notification count:', lastNotificationCount);
                }
            }
        });
    }
    
    function getCurrentOrderCount() {
        $.ajax({
            url: 'get_pickup_counts.php',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    lastOrderCount = parseInt(response.pending_count) || 0;
                    console.log('Initial pending order count:', lastOrderCount);
                }
            }
        });
    }
    
    function handleNotificationUpdate(data) {
        const newCount = parseInt(data.count) || 0;
        
        if (newCount > lastNotificationCount) {
            // New notification arrived
            console.log('New notification detected!');
            
            // Play notification sound
            playNotificationSound();
            
            // Show notification alert
            showNotificationAlert(data.message || 'New notification received');
        }
        
        lastNotificationCount = newCount;
    }
    
    function handlePickupOrderUpdate(data) {
        const newPendingCount = parseInt(data.pending_count) || 0;
        
        if (newPendingCount > lastOrderCount) {
            // New pending pickup order arrived
            console.log('New pending pickup order detected!');
            
            // Play notification sound
            playNotificationSound();
            
            // Show notification alert
            showNotificationAlert('New pending pickup order received!');
            
            // Update dashboard counts
            updateDashboardCounts(data);
            
            // Refresh the orders table
            setTimeout(function() {
                loadPickupOrders('pending');
            }, 1000);
            
        } else if (newPendingCount < lastOrderCount) {
            // Order was processed/moved
            console.log('Pending pickup order was processed');
            
            // Update dashboard counts
            updateDashboardCounts(data);
            
            // Refresh the orders table
            loadPickupOrders('pending');
        }
        
        lastOrderCount = newPendingCount;
    }
    
    function updateDashboardCounts(data) {
        // Update today's counts in the dashboard cards
        if (data.today_pending !== undefined) {
            $('.text-white:contains("Today\'s Pending Pickups")').next().text(data.today_pending);
        }
        if (data.today_ready !== undefined) {
            $('.text-white:contains("Today\'s Ready for Pickup")').next().text(data.today_ready);
        }
        if (data.today_completed !== undefined) {
            $('.text-white:contains("Today\'s Completed Pickups")').next().text(data.today_completed);
        }
        
        // Update badge counts on navigation buttons
        if (data.pending_count !== undefined) {
            updateBadgeCount('.status-button:contains("Pending Pickup")', data.pending_count);
        }
        if (data.ready_count !== undefined) {
            updateBadgeCount('.status-button:contains("Ready for Pickup")', data.ready_count);
        }
    }
    
    function updateBadgeCount(selector, count) {
        const button = $(selector);
        const badge = button.find('.badge');
        
        if (count > 0) {
            if (badge.length === 0) {
                button.append('<span class="badge">' + count + '</span>');
            } else {
                badge.text(count);
            }
        } else {
            badge.remove();
        }
    }
    
    function initializeNotificationSound() {
        // Create a simple audio element for notifications
        window.notificationAudio = new Audio('uploads/NofiticationCash.mp3');
        window.notificationAudio.volume = 0.7;
        window.notificationAudio.preload = 'none'; // Don't preload to avoid 404 errors
        
        // Handle audio loading errors gracefully
        window.notificationAudio.onerror = function() {
            console.log('Notification sound file not found, notifications will be visual only');
            window.notificationAudio = null;
        };
        
        // Enable audio after first user interaction
        let audioEnabled = false;
        
        function enableAudio() {
            if (!audioEnabled) {
                window.notificationAudio.play().then(() => {
                    window.notificationAudio.pause();
                    window.notificationAudio.currentTime = 0;
                    audioEnabled = true;
                    console.log('Notification audio enabled');
                }).catch(e => {
                    console.log('Audio initialization failed:', e);
                });
            }
        }
        
        // Listen for first user interaction
        document.addEventListener('click', enableAudio, { once: true });
        document.addEventListener('keydown', enableAudio, { once: true });
        document.addEventListener('touchstart', enableAudio, { once: true });
    }
    
    function playNotificationSound() {
        // Play notification sound
        if (window.notificationAudio) {
            window.notificationAudio.currentTime = 0;
            window.notificationAudio.play().catch(e => {
                console.log('Could not play notification sound:', e);
            });
        } else {
            console.log('Notification audio not initialized');
        }
    }
    
    function showNotificationAlert(message) {
        // Create a nice notification popup with animation
        const notification = $(`
            <div class="notification-popup" style="
                position: fixed;
                top: 20px;
                right: 20px;
                background: linear-gradient(135deg, #28a745, #20c997);
                color: white;
                padding: 15px 20px;
                border-radius: 10px;
                box-shadow: 0 8px 25px rgba(0,0,0,0.3);
                z-index: 9999;
                max-width: 350px;
                animation: slideInBounce 0.6s cubic-bezier(0.68, -0.55, 0.265, 1.55);
                border-left: 4px solid #fff;
            ">
                <div style="display: flex; align-items: center;">
                    <i class="fa fa-bell me-3" style="font-size: 20px; color: #fff;"></i>
                    <div>
                        <strong style="display: block; margin-bottom: 5px;">New Pickup Order!</strong>
                        <span style="font-size: 14px; opacity: 0.9;">${message}</span>
                    </div>
                </div>
            </div>
        `);
        
        // Add CSS animation if not exists
        if (!$('#notification-styles').length) {
            $('head').append(`
                <style id="notification-styles">
                    @keyframes slideInBounce {
                        0% { 
                            transform: translateX(100%) scale(0.8); 
                            opacity: 0; 
                        }
                        50% { 
                            transform: translateX(-10%) scale(1.05); 
                            opacity: 0.8; 
                        }
                        100% { 
                            transform: translateX(0) scale(1); 
                            opacity: 1; 
                        }
                    }
                    @keyframes slideOut {
                        from { 
                            transform: translateX(0) scale(1); 
                            opacity: 1; 
                        }
                        to { 
                            transform: translateX(100%) scale(0.8); 
                            opacity: 0; 
                        }
                    }
                    .notification-popup:hover {
                        transform: scale(1.02);
                        transition: transform 0.2s ease;
                        cursor: pointer;
                    }
                </style>
            `);
        }
        
        $('body').append(notification);
        
        // Remove notification after 5 seconds with slide out animation
        setTimeout(function() {
            notification.css('animation', 'slideOut 0.5s ease-in-out');
            setTimeout(function() {
                notification.remove();
            }, 500);
        }, 5000);
        
        // Allow manual close on click
        notification.on('click', function() {
            $(this).css('animation', 'slideOut 0.3s ease-in-out');
            setTimeout(function() {
                notification.remove();
            }, 300);
        });
    }
    
    // Enhanced loading function with real-time updates
    function loadPickupOrders(filter) {
        currentFilter = filter;
        
        // Show loading state with animation
        const loadingHtml = `
            <tr>
                <td colspan="7" class="text-center py-4">
                    <div class="d-flex align-items-center justify-content-center">
                        <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                        <span>Loading pickup orders...</span>
                    </div>
                </td>
            </tr>
        `;
        $('#pickup-orders').html(loadingHtml);
        
        $.ajax({
            url: 'fetch_pickup_order_data.php',
            method: 'GET',
            data: { 
                filter: filter,
                timestamp: Date.now() // Prevent caching for real-time data
            },
            cache: false, // Disable caching for real-time data
            success: function(response) {
                // Add fade-in animation
                $('#pickup-orders').fadeOut(200, function() {
                    $(this).html(response).fadeIn(300);
                });
            },
            error: function() {
                $('#pickup-orders').html('<tr><td colspan="7" class="text-center text-danger">Error loading pickup orders. Please refresh the page.</td></tr>');
            }
        });
    }
    
    // Clean up SSE connection when page unloads
    $(window).on('beforeunload', function() {
        if (eventSource) {
            eventSource.close();
        }
    });
    
    // Handle visibility change (when user switches tabs)
    $(document).on('visibilitychange', function() {
        if (document.hidden) {
            // Page is hidden, maintain connection but reduce activity
            console.log('Page hidden, maintaining real-time connection');
        } else {
            // Page is visible, ensure connection is active
            console.log('Page visible, ensuring real-time connection');
            if (!eventSource || eventSource.readyState === EventSource.CLOSED) {
                connectNotificationSSE();
            }
        }
    });
    
    // Periodic fallback refresh every 30 seconds
    setInterval(function() {
        if (document.visibilityState === 'visible') {
            getCurrentOrderCount();
        }
    }, 30000);
    </script>
    <script src="js/script.js"></script>
</body>
</html>
<?php $conn->close(); ?>

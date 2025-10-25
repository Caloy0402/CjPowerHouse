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
    <title>Jandi - Completed Pickup Orders</title>
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
    /* Custom styles for centering table data */
    #completed-orders-table td {
        text-align: center !important;
        vertical-align: middle !important;
    }
    
    #completed-orders-table th {
        text-align: center !important;
        vertical-align: middle !important;
    }
    
    /* Dark theme table styling to match Pending Pickup Orders */
    .table {
        background-color: #212529 !important;
        border-collapse: separate !important;
        border-spacing: 0 !important;
    }
    
    .table thead th {
        background-color: #212529 !important;
        color: #ffffff !important;
        border-bottom: 2px solid #495057 !important;
        border-right: 1px solid #495057 !important;
        font-weight: 600 !important;
        padding: 12px 8px !important;
    }
    
    .table thead th:last-child {
        border-right: none !important;
    }
    
    .table tbody tr {
        background-color: #fcf8e3 !important;
        border-bottom: 1px solid #dee2e6 !important;
    }
    
    .table tbody tr:nth-of-type(odd) {
        background-color: #fcf8e3 !important;
    }
    
    .table tbody tr:nth-of-type(even) {
        background-color: #fcf8e3 !important;
    }
    
    .table tbody tr:hover {
        background-color: #f8f9fa !important;
    }
    
    .table tbody td {
        color: #212529 !important;
        border-right: 1px solid #dee2e6 !important;
        padding: 10px 8px !important;
        font-weight: 500 !important;
    }
    
    .table tbody td:last-child {
        border-right: none !important;
    }
    
    /* Action button styling to match the red theme */
    .table tbody .btn-info {
        background-color: #dc3545 !important;
        border-color: #dc3545 !important;
        color: #ffffff !important;
        font-weight: 500 !important;
        padding: 6px 12px !important;
        border-radius: 4px !important;
        transition: all 0.3s ease !important;
    }
    
    .table tbody .btn-info:hover {
        background-color: #c82333 !important;
        border-color: #bd2130 !important;
        transform: translateY(-1px) !important;
        box-shadow: 0 2px 4px rgba(0,0,0,0.2) !important;
    }
    
    .table tbody .btn-info:focus {
        box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25) !important;
    }
    
    /* Table container styling */
    .table-responsive {
        border-radius: 8px !important;
        overflow: hidden !important;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1) !important;
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
    
    /* Modal table styling - center labels and enhance row titles */
    #pickupOrderModal .table thead th {
        text-align: center !important;
        font-weight: 600 !important;
        background-color: #343a40 !important;
        color: #ffffff !important;
        padding: 10px 8px !important;
    }
    
    #pickupOrderModal .table tbody td {
        text-align: center !important;
        vertical-align: middle !important;
    }
    
    /* Enhanced row title visibility in modal */
    #pickupOrderModal .table tbody td.fw-bold {
        font-weight: 700 !important;
        font-size: 14px !important;
        background-color: #495057 !important;
        color: #ffffff !important;
        text-align: center !important;
        vertical-align: middle !important;
        padding: 12px 8px !important;
        border: 1px solid #6c757d !important;
    }
    
    /* Modal order items table styling */
    #modalOrderItems .table {
        margin-bottom: 0 !important;
    }
    
    #modalOrderItems .table thead th {
        background-color: #343a40 !important;
        color: #ffffff !important;
        text-align: center !important;
        font-weight: 600 !important;
        padding: 10px 8px !important;
        border: 1px solid #495057 !important;
    }
    
    #modalOrderItems .table tbody td {
        text-align: center !important;
        vertical-align: middle !important;
        padding: 8px !important;
        border: 1px solid #dee2e6 !important;
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
    
    /* Refresh button styling */
    #refreshBtn:hover {
        transform: scale(1.05);
        transition: all 0.3s ease;
    }
    
    #refreshBtn:disabled {
        opacity: 0.7;
        cursor: not-allowed;
    }
    
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

    /* Custom styles for date inputs in completed orders modal */
    .form-control[type="date"] {
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
    .form-control[type="date"]::-webkit-calendar-picker-indicator {
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

    .form-control[type="date"]::-webkit-calendar-picker-indicator:hover {
        background-color: rgba(0, 123, 255, 0.1);
        border-radius: 3px;
    }

    /* For Firefox - show custom icon */
    .form-control[type="date"] {
        -moz-appearance: textfield;
    }

    /* Make sure the date input text is visible */
    input[type="date"]:focus {
        background-color: white !important;
        color: black !important;
        border-color: #80bdff;
        box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
    }

    /* Custom styles for Quick Select dropdown */
    #timeRange {
        background-color: white !important;
        color: black !important;
        border: 1px solid #ced4da;
    }

    #timeRange:focus {
        background-color: white !important;
        color: black !important;
        border-color: #80bdff;
        box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
    }

    /* Style the dropdown options */
    #timeRange option {
        background-color: white !important;
        color: black !important;
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
                    <a href="Cashier-Ready.php" class="status-button">
                        <i class="fa fa-check-circle"></i> Ready for Pickup
                        <?php if ($readyPickupCount > 0): ?>
                        <span class="badge"><?php echo $readyPickupCount; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="Cashier-Completed.php" class="status-button" style="background-color: #28a745;">
                        <i class="fa fa-history"></i> Completed Orders
                    </a>
                </div>
            </div>
            <!-- Status Buttons End -->
            
            <!-- Completed Orders Start -->
            <div class="container-fluid pt-4 px-4">
                <div class="bg-secondary rounded p-4">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4 class="mb-0">Completed Pickup Orders History</h4>
                        <button type="button" class="btn btn-outline-primary" onclick="refreshCompletedOrders()" id="refreshBtn" title="Refresh Data">
                            <i class="fa fa-refresh"></i> Refresh
                        </button>
                    </div>
                    
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
                             <label for="transactionSearch" class="form-label">Search Transaction Number:</label>
                             <input type="text" class="form-control" id="transactionSearch" 
                                    placeholder="Type to search..." 
                                    oninput="handleRealTimeSearch()">
                         </div>
                         <div class="col-md-3 d-flex align-items-end">
                             <button type="button" class="btn btn-outline-light" onclick="clearAllFilters()">
                                 <i class="fa fa-times"></i> Clear
                             </button>
                         </div>
                     </div>
                    
                    <!-- Completed Orders Table -->
                    <div class="table-responsive table-pagination-container">
                        <table class="table table-hover">
                                    <thead>
                                        <tr class="text-white text-center">
                                            <th scope="col" class="text-center">Order ID</th>
                                            <th scope="col" class="text-center">TRN #</th>
                                            <th scope="col" class="text-center">Customer Name</th>
                                            <th scope="col" class="text-center">Total Amount</th>
                                            <th scope="col" class="text-center">Order Date</th>
                                            <th scope="col" class="text-center">Completed Date</th>
                                            <th scope="col" class="text-center">Action</th>
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
            </div>
            <!-- Completed Orders End -->
            
            <!-- Modal for Pickup Order Details -->
            <div class="modal fade" id="pickupOrderModal" tabindex="-1" aria-labelledby="pickupOrderModalLabel"
                aria-hidden="true">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title text-dark w-100 text-center" id="pickupOrderModalLabel">Completed Order Details (Read Only)</h5>
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
     $(document).ready(function() {
         // Set default date range to last 30 days on page load
         var today = new Date();
         var thirtyDaysAgo = new Date(today.getTime() - (30 * 24 * 60 * 60 * 1000));
         
         $('#dateTo').val(formatDate(today));
         $('#dateFrom').val(formatDate(thirtyDaysAgo));
         
         // Set max date to today for both date inputs
         var todayString = formatDate(today);
         $('#dateFrom').attr('max', todayString);
         $('#dateTo').attr('max', todayString);
         
         // Update date constraints after setting values
         updateDateConstraints();
         
         // Load completed orders
         filterCompletedOrders();
         
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
         
         // Handle modal population
         $('#pickupOrderModal').on('show.bs.modal', function(event) {
             var button = $(event.relatedTarget);
             
             // Only load order details if triggered by a button click (not programmatically)
             if (button && button.data('order-id')) {
                 var orderId = button.data('order-id');
                 // Load order details via AJAX
                 loadOrderDetails(orderId);
             }
         });
     });
    
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
    
     // Real-time search with debouncing
     let searchTimeout;
     
     function handleRealTimeSearch() {
         // Clear previous timeout
         clearTimeout(searchTimeout);
         
         var transactionNumber = $('#transactionSearch').val().trim();
         
         // If search is empty, show default message
         if (!transactionNumber) {
             $('#completed-orders-table').html('<tr><td colspan="7" class="text-center">Please select a date range or search by transaction number to view orders.</td></tr>');
             $('#totalOrdersCount').text('0');
             $('#totalRevenue').text('₱0.00');
             return;
         }
         
         // Debounce the search - wait 300ms after user stops typing
         searchTimeout = setTimeout(function() {
             searchByTransaction(transactionNumber);
         }, 300);
     }
     
     function searchByTransaction(transactionNumber) {
         if (!transactionNumber) {
             transactionNumber = $('#transactionSearch').val().trim();
         }
         
         if (!transactionNumber) {
             return;
         }
         
         // Show loading state
         $('#completed-orders-table').html('<tr><td colspan="7" class="text-center"><i class="fa fa-spinner fa-spin"></i> Searching...</td></tr>');
         
         $.ajax({
             url: 'search_transaction.php',
             method: 'GET',
             data: {
                 transaction_number: transactionNumber
             },
             dataType: 'json',
             success: function(response) {
                 if (response.success) {
                     $('#completed-orders-table').html(response.html);
                     $('#totalOrdersCount').text(response.total_orders);
                     $('#totalRevenue').text('₱' + parseFloat(response.total_revenue).toLocaleString('en-US', {minimumFractionDigits: 2}));
                 } else {
                     $('#completed-orders-table').html('<tr><td colspan="7" class="text-center text-muted">' + response.message + '</td></tr>');
                     $('#totalOrdersCount').text('0');
                     $('#totalRevenue').text('₱0.00');
                 }
             },
             error: function() {
                 $('#completed-orders-table').html('<tr><td colspan="7" class="text-center text-danger">Error searching transaction. Please try again.</td></tr>');
                 $('#totalOrdersCount').text('0');
                 $('#totalRevenue').text('₱0.00');
             }
         });
     }
     
     function clearAllFilters() {
        // Clear any pending search timeout
        clearTimeout(searchTimeout);
        
        // Clear date filters
        $('#dateFrom').val('');
        $('#dateTo').val('');
        
        // Clear transaction search
        $('#transactionSearch').val('');
        
        // Reset date constraints
        var today = formatDate(new Date());
        $('#dateFrom').attr('max', today);
        $('#dateTo').attr('max', today).removeAttr('min');
        
        // Clear table and stats
        $('#completed-orders-table').html('<tr><td colspan="7" class="text-center">Please select a date range or search by transaction number to view orders.</td></tr>');
        $('#totalOrdersCount').text('0');
        $('#totalRevenue').text('₱0.00');
    }
    
    function refreshCompletedOrders() {
        var dateFrom = $('#dateFrom').val();
        var dateTo = $('#dateTo').val();
        
        // Show loading state on refresh button
        var refreshBtn = $('#refreshBtn');
        var originalHtml = refreshBtn.html();
        refreshBtn.html('<i class="fa fa-spinner fa-spin"></i> Refreshing...');
        refreshBtn.prop('disabled', true);
        
        // Check if we have dates selected for refresh
        if (!dateFrom || !dateTo) {
            alert('Please select both from and to dates to refresh the data.');
            refreshBtn.html(originalHtml);
            refreshBtn.prop('disabled', false);
            return;
        }
        
        // Call the same function as filter to refresh data
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
                alert('Error refreshing completed orders.');
            },
            complete: function() {
                // Reset button state
                refreshBtn.html(originalHtml);
                refreshBtn.prop('disabled', false);
            }
        });
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
            url: 'get_pickup_order_details.php',
            method: 'GET',
            data: { order_id: orderId },
            dataType: 'json',
            beforeSend: function() {
                console.log('Sending AJAX request with data:', { order_id: orderId });
            },
            success: function(data) {
                if (data && data.success === true) {
                    populateModalReadOnly(data.order);
                    $('#pickupOrderModal').modal('show');
                } else {
                    alert('Error loading order details: ' + (data.message || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                alert('Error loading order details: ' + error);
            }
        });
    }
    
    function loadOrderDetails(orderId) {
        viewCompletedOrderDetails(orderId);
    }
    
    function populateModalReadOnly(order) {
        // Populate modal with read-only data for completed orders
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
        
        // Change modal title
        $('#pickupOrderModalLabel').text('Completed Order Details (Read Only)');
    }
    </script>
</body>
</html>
<?php $conn->close(); ?>

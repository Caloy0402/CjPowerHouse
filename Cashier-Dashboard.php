<?php
// Start the session to access session variables
session_start();

// Include the database connection
require_once 'dbconn.php';

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    // Redirect to login if user is not logged in
    header("Location: signin.php");
    exit;
}

// Security check: Ensure only staff members can access this page
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['Admin', 'Cashier', 'Rider', 'Mechanic'])) {
    // If user is a customer, redirect to customer area
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'Customer') {
        header("Location: Mobile-Dashboard.php");
        exit();
    }
    // If no valid role, redirect to appropriate login
    header("Location: signin.php");
    exit();
}

// Check if there's a pending session that needs to be resumed
$showSessionModal = false;
$sessionData = null;
$userData = null;

if (isset($_SESSION['pending_session_data']) && isset($_SESSION['pending_user_data'])) {
    $showSessionModal = true;
    $sessionData = $_SESSION['pending_session_data'];
    $userData = $_SESSION['pending_user_data'];
    
    // Clear the pending session data to avoid showing modal again
    unset($_SESSION['pending_session_data']);
    unset($_SESSION['pending_user_data']);
}

$conn = new mysqli($servername, $username, $password, $dbname);

// Check if the connection is successful
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
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

// --- Fetch data for cards and chart ---

$today = date("Y-m-d");

// **1. Total Sales Today**
// Use total_amount_with_delivery when available; fallback to total_price
$sql = "SELECT SUM(
            CASE 
                WHEN NULLIF(o.total_amount_with_delivery, 0) IS NOT NULL THEN o.total_amount_with_delivery
                ELSE o.total_price
            END
        ) AS total_sales
        FROM orders o
        JOIN transactions t ON o.id = t.order_id
        WHERE o.order_status = 'completed' AND DATE(t.completed_date_transaction) = '$today'";
$result = $conn->query($sql);
$row = $result->fetch_assoc();
$totalSalesToday = ($row && $row['total_sales'] !== null) ? number_format($row['total_sales'], 2) : '0.00';

// **2. Total Orders Today**
$sql = "SELECT COUNT(*) AS total_orders
        FROM orders o
        JOIN transactions t ON o.id = t.order_id
        WHERE o.order_status = 'completed' AND DATE(t.completed_date_transaction) = '$today'";
$result = $conn->query($sql);
$row = $result->fetch_assoc();
$totalOrdersToday = ($row && $row['total_orders'] !== null) ? $row['total_orders'] : 0;

// **3. Average Order Value**
if ($totalOrdersToday > 0) {
    $averageOrderValue = number_format((float)str_replace(',', '', $totalSalesToday) / $totalOrdersToday, 2);
} else {
    $averageOrderValue = '0.00';
}

// **4. Total Weight Sold Today**
$sql = "SELECT SUM(o.total_weight) AS total_weight
        FROM orders o
        JOIN transactions t ON o.id = t.order_id
        WHERE o.order_status = 'completed' AND DATE(t.completed_date_transaction) = '$today'";
$result = $conn->query($sql);
$row = $result->fetch_assoc();
$totalWeightSold = ($row && $row['total_weight'] !== null) ? number_format($row['total_weight'], 2) : '0.00';

// **5. Pending Orders Count**
$sql = "SELECT COUNT(*) AS pending_orders
        FROM orders
        WHERE order_status IN ('pending', 'processing', 'confirmed')";
$result = $conn->query($sql);
$row = $result->fetch_assoc();
$pendingOrdersCount = ($row && $row['pending_orders'] !== null) ? $row['pending_orders'] : 0;


// **5. Sales Data for Chart (Last 7 Days)**
$salesData = [];
$revenueData = [];
$labels = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date("Y-m-d", strtotime("-$i days"));
    $labels[] = date("M d", strtotime($date)); // Format: "Jan 01"

    // Query for sales
    $sql = "SELECT SUM(total_price) AS daily_sales
            FROM orders o
            JOIN transactions t ON t.order_id = o.id
            WHERE DATE(o.order_date) = '$date'
              AND o.order_status = 'completed'
              AND t.user_id = ".$_SESSION['user_id'];

    $result = $conn->query($sql);
    $dailySales = ($result && $result->num_rows > 0) ? (float)$result->fetch_assoc()['daily_sales'] : 0;
    $salesData[] = $dailySales;

    //For test
    $revenueData[] = $dailySales * 0.2;

}

// **6. Recent Transaction Record Table with Date Range Filter and Pagination**

// Get date range from form or default to last 7 days
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-d', strtotime('-7 days'));
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');

// Validate date range - ensure from_date is not after to_date
if ($from_date > $to_date) {
    // Swap dates if from_date is after to_date
    $temp_date = $from_date;
    $from_date = $to_date;
    $to_date = $temp_date;
}

// Get all transaction data for the date range (no pagination)
$sql = "SELECT t.id, t.transaction_number, u.first_name AS user_name, t.order_id, t.created_at
        FROM transactions t
        JOIN users u ON t.user_id = u.id
        WHERE DATE(t.created_at) BETWEEN '$from_date' AND '$to_date'
        ORDER BY t.created_at DESC";
$transactionResult = $conn->query($sql);
if (!$transactionResult) {
    error_log("SQL Error (Recent Transactions): " . $conn->error);
    $transactionData = [];
} else {
    $transactionData = [];
    while ($row = $transactionResult->fetch_assoc()) {
        $transactionData[] = $row;
    }
}


$conn->close();
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cashier Dashboard</title>
    <link rel="icon" type="image/png" href="<?= $baseURL ?>image/logo.png">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <meta content="" name="keywords">
    <meta content="" name="description">

    <!-- Favicons -->
    <link rel="icon" type="image/png" href="Image/logo.png">

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
    <script src="https://code.jquery.com/jquery-3.4.1.min.js"></script>

    <style>
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background-color: #ff4444;
            color: white;
            border-radius: 50%;
            padding: 2px 6px;
            font-size: 12px;
        }

        .notification-item {
            padding: 10px;
            background: #f9f9f9;
            border-radius: 8px;
            margin-bottom: 5px;
        }

        .notification-item h6 {
            color: #333;
            margin-bottom: 5px;
        }

        .notification-item p {
            margin: 5px 0;
            font-size: 14px;
            color: #666;
        }

        .notification-item small {
            color: #999;
            font-size: 12px;
        }

        /* Banner notification styles */
        .alert.position-fixed {
            animation: slideInRight 0.3s ease-out;
            border-left: 4px solid #28a745;
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        .alert .btn-close {
            padding: 0.5rem 0.5rem;
            margin: -0.5rem -0.5rem -0.5rem auto;
        }

        /* Badge pulse animation for real-time updates */
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.2); background-color: #dc3545; }
            100% { transform: scale(1); }
        }

        .dropdown-menu {
            min-width: 300px;
            max-height: 400px;
            overflow-y: auto;
        }

        .notification-icon {
            position: relative;
            cursor: pointer;
        }

        .calendar-icon {
            cursor: pointer !important;
            pointer-events: auto !important;
            z-index: 10;
        }

        .calendar-icon:hover {
            color: #007bff !important;
            transform: scale(1.1);
            transition: all 0.2s ease;
        }

        /* Fancy Reset button */
        .btn-reset {
            background: linear-gradient(135deg, #6c757d, #4b5258);
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 10px 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 6px rgba(0,0,0,0.2);
            transition: transform .15s ease, box-shadow .15s ease, opacity .15s ease;
            text-decoration: none;
        }
        .btn-reset:hover { transform: translateY(-1px); box-shadow: 0 4px 10px rgba(0,0,0,0.25); opacity: .95; }
        .btn-reset:active { transform: translateY(0); box-shadow: 0 2px 6px rgba(0,0,0,0.2); }

        /* Fix date range filter layout */
        .date-filter-form .col-md-3 {
            margin-bottom: 0.5rem;
        }
        
        .date-filter-form .form-label {
            margin-bottom: 0.25rem;
            font-size: 0.875rem;
            font-weight: 600;
        }
        
        .date-filter-form .form-control {
            margin-bottom: 0;
            border: 2px solid #495057;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .date-filter-form .form-control:focus {
            border-color: #007bff;
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
        }
        
        .date-filter-form .form-control:invalid {
            border-color: #dc3545;
        }
        
        .date-filter-form .btn {
            margin-top: 0;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .date-filter-form .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        
        .date-filter-form .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        /* Date input styling improvements */
        .date-input-container {
            position: relative;
        }
        
        .date-input-container .form-control {
            padding-right: 40px;
        }
        
        .calendar-icon {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.2s ease;
            z-index: 10;
        }
        
        .calendar-icon:hover {
            color: #007bff;
            transform: translateY(-50%) scale(1.1);
        }
        
        /* Success message styling */
        .alert-success {
            border-radius: 8px;
            border: none;
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: #155724;
        }
        
        /* Scrollable table styling */
        .table-responsive {
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .table-responsive::-webkit-scrollbar {
            width: 8px;
        }
        
        .table-responsive::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .table-responsive::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 4px;
        }
        
        .table-responsive::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
        
        /* Sticky header for scrollable table */
        .table thead th {
            position: sticky;
            top: 0;
            background-color: #6c757d;
            z-index: 10;
        }
        
        /* Real-time metrics animation */
        .metrics-card {
            transition: all 0.3s ease;
        }
        
        .metrics-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .metrics-value {
            transition: all 0.3s ease;
        }
        
        .metrics-value.updating {
            animation: pulse 0.6s ease-in-out;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        /* Sidebar Toggle Fixes */
        .sidebar-toggler {
            cursor: pointer;
            padding: 8px 12px;
            border: none;
            background: transparent;
            color: #fff;
            font-size: 18px;
            transition: all 0.3s ease;
        }
        
        .sidebar-toggler:hover {
            color: #007bff;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
        }
        
        .sidebar-toggler:focus {
            outline: none;
            box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.25);
        }
        
        /* Ensure sidebar works properly */
        .sidebar {
            transition: margin-left 0.3s ease;
        }
        
        .content {
            transition: margin-left 0.3s ease, width 0.3s ease;
        }
        
        /* Mobile responsiveness */
        @media (max-width: 991.98px) {
            .sidebar {
                margin-left: -250px;
            }
            
            .sidebar.open {
                margin-left: 0;
            }
            
            .content {
                width: 100%;
                margin-left: 0;
            }
        }
        
        @media (min-width: 992px) {
            .sidebar {
                margin-left: 0;
            }
            
            .sidebar.open {
                margin-left: -250px;
            }
            
            .content {
                margin-left: 250px;
            }
            
            .content.open {
                margin-left: 0;
            }
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
        <span class="navbar-brand mx-4 mb-3" style="pointer-events: none; cursor: default;">
            <h3 class="text-primary"><i class="fa fa-user-edit me-2"></i>Cj P'House</h3>
        </span>
        <div class="d-flex align-items-center ms-4 mb-4">
        <div class="position-relative">
    <img src="<?php echo $profile_image; ?>" alt="" class="rounded-circle" style="width: 40px; height: 40px;">
    <div class="bg-success rounded-circle border border-2 border-white position-absolute end-0 bottom-0 p-1"></div>
</div>

            <div class="ms-3">
                <h6 class="mb-0"><?php echo htmlspecialchars($user_name); ?></h6>
                <span>Cashier</span>
            </div>
        </div>
        <div class="navbar-nav w-100">
            <a href="Cashier-Dashboard.php" class="nav-item nav-link active"><i class="fa fa-tachometer-alt me-2"></i>Dashboard</a>
            <div class="nav-item dropdown">
                <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="fa fa-shopping-cart me-2"></i>Pending Orders
                </a>
                <div class="dropdown-menu bg-transparent border-0">
                    <a href="Cashier-COD-Delivery.php" class="dropdown-item">Pending COD orders</a>
                    <a href="Cashier-GCASH-Delivery.php" class="dropdown-item">Pending GCASH orders</a>
                </div>
            </div>
            <a href="Cashier-Transactions.php" class="nav-item nav-link"><i class="fa fa-list-alt me-2"></i>Transactions</a>
            <a href="Cashier-Returns.php" class="nav-item nav-link"><i class="fa fa-undo me-2"></i>Return Product</a>
        </div>
    </nav>
</div>


<!-- Sidebar End -->
  <!--Content Start-->
  <div class="content">
    <!--Navbar Start-->
       <nav class="navbar navbar-expand bg-secondary navbar-dark sticky-top
       px-4 py-0">
            <span class="navbar-brand d-flex d-lg-none me-4" style="pointer-events: none; cursor: default;">
                <h2 class="text-primary mb-0"><i class="fa fa-user-edit"></i></h2>
            </span>
            <a href="#" class="sidebar-toggler flex-shrink-0">
                <i class="fa fa-bars"></i>
            </a>

            <div class="navbar-nav align-items-center ms-auto">
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
            <span class="notification-badge" id="notificationCount" style="display: none;">0</span>
        </a>
        <div class="dropdown-menu dropdown-menu-end bg-secondary border-0 rounded-0 rounded-bottom m-0" id="notificationDropdown">
            <!-- Notification Sound Controls -->
            <div class="dropdown-header d-flex justify-content-between align-items-center">
                <span>Order Notifications</span>
                <div class="btn-group btn-group-sm" role="group">
                    <button type="button" class="btn btn-outline-light btn-sm" id="muteToggleBtn" title="Toggle notification sound">
                        <i class="fas fa-volume-up" id="muteIcon"></i>
                    </button>
                    <button type="button" class="btn btn-outline-light btn-sm" id="testSoundBtn" title="Test notification sound">
                        <i class="fas fa-play"></i>
                    </button>
                </div>
            </div>
            <hr class="dropdown-divider">
            <div class="notification-items" id="notificationItems">
                <!-- Notifications will be added here dynamically -->
            </div>
        </div>
    </div>
    <div class="nav-item dropdown">
        <a href="" class="nav-link dropdown-toggle"
        data-bs-toggle="dropdown">
            <img src="<?php echo $profile_image; ?>" alt="" class="rounded-circle me-lg-2"
            alt="" style="width: 40px; height: 40px;">
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

     <!-- Cards Start -->
     <div class="container-fluid pt-4 px-4">
        <div class="row g-4 justify-content-center">

            <div class="col-sm-6 col-xl-3">
                <div class="bg-secondary rounded d-flex align-items-center justify-content-between p-4 metrics-card">
                    <i class="fa fa-chart-line fa-3x text-primary"></i>
                    <div class="ms-3">
                        <p class="mb-2">Total Sales Today</p>
                        <h6 class="mb-0 metrics-value">₱<?php echo $totalSalesToday; ?></h6>
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-xl-3">
                <div class="bg-secondary rounded d-flex align-items-center justify-content-between p-4 metrics-card">
                    <i class="fa fa-shopping-cart fa-3x text-primary"></i>
                    <div class="ms-3">
                        <p class="mb-2">Total Completed Orders Today</p>
                        <h6 class="mb-0 metrics-value"><?php echo $totalOrdersToday; ?></h6>
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-xl-3">
                <div class="bg-secondary rounded d-flex align-items-center justify-content-between p-4 metrics-card">
                    <i class="fa fa-clock fa-3x text-primary"></i>
                    <div class="ms-3">
                        <p class="mb-2">Pending Orders</p>
                        <h6 class="mb-0 metrics-value"><?php echo $pendingOrdersCount; ?></h6>
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-xl-3">
                <div class="bg-secondary rounded d-flex align-items-center justify-content-between p-4 metrics-card">
                    <i class="fa fa-weight fa-3x text-primary"></i>
                    <div class="ms-3">
                        <p class="mb-2">Total Weight Sold</p>
                        <h6 class="mb-0 metrics-value"><?php echo $totalWeightSold; ?> kg</h6>
                    </div>
                </div>
            </div>

        </div>
    </div>
    <!-- Cards End -->

    <!-- Date Range Filter Start -->
    <div class="container-fluid pt-4 px-4">
        <div class="bg-secondary rounded p-4">
            <div class="row align-items-center">
                <div class="col-md-3">
                    <h6 class="mb-0 text-white">Filter by Date Range</h6>
                </div>
                <div class="col-md-9">
                    <form method="GET" class="row g-3 align-items-center date-filter-form">
                        <div class="col-md-3">
                            <label for="from_date" class="form-label text-white">FROM:</label>
                            <div class="date-input-container">
                                <input type="date" class="form-control" id="from_date" name="from_date" value="<?php echo $from_date; ?>" max="<?php echo date('Y-m-d'); ?>" required>
                                <i class="fa fa-calendar calendar-icon"></i>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label for="to_date" class="form-label text-white">TO:</label>
                            <div class="date-input-container">
                                <input type="date" class="form-control" id="to_date" name="to_date" value="<?php echo $to_date; ?>" max="<?php echo date('Y-m-d'); ?>" required>
                                <i class="fa fa-calendar calendar-icon"></i>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-primary d-block">
                                <i class="fa fa-search me-1"></i>Filter Records
                            </button>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">&nbsp;</label>
                            <button type="button" class="btn btn-reset d-block" id="resetDatesBtn" title="Reset to default date range (last 7 days)">
                                <i class="fa fa-undo me-2"></i>Reset
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <!-- Date Range Filter End -->

    <!-- Sales Summary Table Start -->
    <div class="container-fluid pt-4 px-4">
        <div class="bg-secondary text-center rounded p-4">
            <div class="d-flex align-items-center justify-content-between mb-4">
                <h6 class="mb-0">Recent Transaction Record</h6>
                <div class="d-flex align-items-center">
                    <span class="text-muted me-3">Total: <?php echo count($transactionData); ?> records</span>
                </div>
            </div>
            <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                <table class="table text-center align-middle table-bordered table-hover mb-0" id="transactionTable">
                    <thead>
                        <tr class="text-white">
                            <th scope="col">ID</th>
                            <th scope="col">Transaction #</th>
                            <th scope="col">User Name</th>
                            <th scope="col">Order ID</th>
                            <th scope="col">Order At</th>
                        </tr>
                    </thead>
                    <tbody id="transactionTableBody">
                        <?php if (empty($transactionData)): ?>
                            <tr><td colspan="5">No transaction data available.</td></tr>
                        <?php else: ?>
                            <?php foreach ($transactionData as $txn): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($txn['id']); ?></td>
                                    <td><?php echo htmlspecialchars($txn['transaction_number']); ?></td>
                                    <td><?php echo htmlspecialchars($txn['user_name']); ?></td>
                                    <td><?php echo htmlspecialchars($txn['order_id']); ?></td>
                                    <td><?php echo date('M d, Y g:i A', strtotime($txn['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <!-- Sales Summary Table End -->

<!--Footer Start-->
<div class="container-fluid pt-4 px-4">
    <div class="bg-secondary rounded-top p-4">
        <div class="row">
            <div class="col-12 col-sm-6 text-center text-sm-start">
                © <a href="#">Cj PowerHouse</a>, All Right Reserved.
            </div>
            <div class="col-12 col-sm-6 text-center text-sm-end">
                Designed By <a href="">Jandi</a>
            </div>
        </div>
    </div>
</div>
<!--Footer End-->
    </div>
    <!--Content End-->
</div>



   <!--javascript Libraries-->
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
    <!-- <script src="js/Cashier.js"></script> --> <!-- Disabled due to conflicting sidebar toggle code -->
    
    <!-- Calendar Icon Click Handler and Date Validation -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Function to open date picker
            function openDatePicker(inputId) {
                const input = document.getElementById(inputId);
                if (input) {
                    // Try modern showPicker() method first
                    if (typeof input.showPicker === 'function') {
                        input.showPicker();
                    } else {
                        // Fallback: focus the input to trigger native date picker
                        input.focus();
                        input.click();
                    }
                }
            }

            // Date validation function - simplified since HTML5 handles most validation
            function validateDateRange() {
                const fromDate = document.getElementById('from_date');
                const toDate = document.getElementById('to_date');
                
                if (fromDate && toDate) {
                    // Update TO date minimum when FROM date changes
                    if (fromDate.value) {
                        toDate.min = fromDate.value;
                    }
                    
                    // Update FROM date maximum when TO date changes
                    if (toDate.value) {
                        fromDate.max = toDate.value;
                    }
                }
                return true;
            }

            // Add event listeners to date inputs
            const fromDateInput = document.getElementById('from_date');
            const toDateInput = document.getElementById('to_date');
            
            if (fromDateInput) {
                fromDateInput.addEventListener('change', function() {
                    validateDateRange();
                    // When FROM date changes, update TO date minimum
                    if (this.value && toDateInput) {
                        toDateInput.min = this.value;
                        // If TO date is now invalid, clear it
                        if (toDateInput.value && toDateInput.value < this.value) {
                            toDateInput.value = this.value;
                        }
                    }
                });
            }
            
            if (toDateInput) {
                toDateInput.addEventListener('change', function() {
                    validateDateRange();
                    // When TO date changes, update FROM date maximum
                    if (this.value && fromDateInput) {
                        fromDateInput.max = this.value;
                        // If FROM date is now invalid, clear it
                        if (fromDateInput.value && fromDateInput.value > this.value) {
                            fromDateInput.value = this.value;
                        }
                    }
                });
            }

            // Add click event listeners to calendar icons
            const calendarIcons = document.querySelectorAll('.calendar-icon');
            calendarIcons.forEach(function(icon) {
                icon.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    // Determine which input this icon belongs to
                    const input = this.parentElement.querySelector('input[type="date"]');
                    if (input) {
                        openDatePicker(input.id);
                    }
                });
            });

            // Reset button functionality
            const resetButton = document.getElementById('resetDatesBtn');
            if (resetButton) {
                resetButton.addEventListener('click', function() {
                    // Set default dates (last 7 days to today)
                    const today = new Date();
                    const lastWeek = new Date();
                    lastWeek.setDate(today.getDate() - 7);
                    
                    // Format dates as YYYY-MM-DD
                    const todayStr = today.toISOString().split('T')[0];
                    const lastWeekStr = lastWeek.toISOString().split('T')[0];
                    
                    // Update input values
                    if (fromDateInput) {
                        fromDateInput.value = lastWeekStr;
                    }
                    if (toDateInput) {
                        toDateInput.value = todayStr;
                    }
                    
                    // Re-enable filter button
                    const filterButton = document.querySelector('button[type="submit"]');
                    if (filterButton) {
                        filterButton.disabled = false;
                    }
                    
                    // Show success message
                    showResetSuccess();
                });
            }

            // Function to show reset success message
            function showResetSuccess() {
                // Create success message
                const successDiv = document.createElement('div');
                successDiv.className = 'alert alert-success mt-2';
                successDiv.id = 'resetSuccess';
                successDiv.innerHTML = `<i class="fa fa-check-circle me-2"></i>Date range reset to last 7 days`;
                
                // Insert after the form
                const form = document.querySelector('.date-filter-form');
                if (form) {
                    form.parentNode.insertBefore(successDiv, form.nextSibling);
                }
                
                // Auto-remove after 3 seconds
                setTimeout(() => {
                    if (successDiv.parentNode) {
                        successDiv.remove();
                    }
                }, 3000);
            }

            // Initial validation
            validateDateRange();
        });
    </script>
    <script>
        (function($) {
            "use strict";

            // Spinner
            var spinner = function() {
                setTimeout(function() {
                    if ($('#spinner').length > 0) {
                        $('#spinner').removeClass('show');
                    }
                }, 1);
            };
            spinner();

            // Sidebar Toggler
            $('.sidebar-toggler').on('click', function(e) {
                e.preventDefault();
                $('.sidebar').toggleClass('open');
                $('.content').toggleClass('open');
                return false;
            });

            // Close sidebar when clicking outside on mobile
            $(document).on('click', function(e) {
                if ($(window).width() <= 991.98) {
                    if (!$(e.target).closest('.sidebar, .sidebar-toggler').length) {
                        $('.sidebar').removeClass('open');
                        $('.content').removeClass('open');
                    }
                }
            });

            // Handle window resize
            $(window).on('resize', function() {
                if ($(window).width() > 991.98) {
                    $('.sidebar').removeClass('open');
                    $('.content').removeClass('open');
                }
            });

            // Chart color
            Chart.defaults.color = "#6C7293";
            Chart.defaults.borderColor = "#000000";

            // Sales & Revenue Chart (Adapted from admin-dashboard.php)
            // Note: Chart will only initialize if the canvas element exists
            if (document.getElementById("sales-revenue")) {
                var ctx2 = $("#sales-revenue").get(0).getContext("2d");
                var myChart2 = new Chart(ctx2, {
                    type: "line",
                    data: {
                        labels: <?php echo json_encode($labels); ?>, // Use the labels from PHP
                        datasets: [{
                            label: "Sales",
                            data: <?php echo json_encode($salesData); ?>, // Use the sales data from PHP
                            backgroundColor: "rgba(235, 22, 22, .7)",
                            fill: true
                        },
                        {
                            label: "Revenue", // You can customize this label
                            data: <?php echo json_encode($revenueData); ?>, // You can customize this label
                            backgroundColor: "rgba(235, 22, 22, .5)",
                            fill: true
                        }]
                    },
                    options: {
                        responsive: true
                    }
                });
            }

        })(jQuery);
    </script>

    <!-- Notification System -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const notificationIcon = document.querySelector('.notification-icon');
            const notificationCount = document.getElementById('notificationCount');
            const notificationItems = document.getElementById('notificationItems');

            // Function to fetch notifications
            function fetchNotifications() {
                fetch('get_cashier_notifications.php')
                    .then(response => response.json())
                    .then(data => {
                        // Update notification count
                        const count = data.notifications.length;
                        const previousCount = parseInt(notificationCount.textContent) || 0;
                        notificationCount.textContent = count;
                        notificationCount.style.display = count > 0 ? 'block' : 'none';
                        
                        // Check for new notifications by comparing notification IDs
                        const currentNotificationIds = new Set(data.notifications.map(n => n.order_id));
                        const hasNewNotifications = [...currentNotificationIds].some(id => !lastNotificationIds.has(id));
                        
                        // Play notification sound and show banner for genuinely new notifications
                        const currentTime = Date.now();
                        const isPageVisible = !document.hidden;
                        if (hasNewNotifications && !isInitialLoad && notificationSound && 
                            (currentTime - lastSoundPlayTime) > SOUND_DEBOUNCE_TIME && isPageVisible) {
                            console.log('Dashboard: Playing notification sound - new notification detected');
                            notificationSound.play();
                            lastSoundPlayTime = currentTime;
                            
                            // Show banner notification for new orders
                            const newOrderCount = count - lastNotificationIds.size;
                            if (newOrderCount > 0) {
                                showNewOrderNotification(newOrderCount);
                            }
                        }
                        
            // Update the last known notification IDs
            lastNotificationIds = currentNotificationIds;
            isInitialLoad = false;
            
            // Update barangay filter counts in real-time (if on a page with barangay filters)
            if (typeof updateBarangayCounts === 'function') {
                updateBarangayCounts();
            }

                        // Update notification items
                        notificationItems.innerHTML = '';
                        if (count === 0) {
                            notificationItems.innerHTML = '<div class="dropdown-item text-center">No pending orders</div>';
                        } else {
                            data.notifications.forEach(notification => {
                                const targetHref = (notification.payment_method || '').toUpperCase() === 'COD'
                                    ? `Cashier-COD-Delivery.php?order_id=${notification.order_id}`
                                    : `Cashier-GCASH-Delivery.php?order_id=${notification.order_id}`;
                                const item = document.createElement('a');
                                item.className = 'dropdown-item';
                                item.href = targetHref;
                                const orderIdLabel = (notification.order_id || '');
                                item.innerHTML = `
                                    <div class="notification-item">
                                        ${orderIdLabel ? `<h6 class=\"fw-normal mb-0\">Order #${orderIdLabel}</h6>` : ''}
                                        <p><strong>Items:</strong> ${notification.items}</p>
                                        <p><strong>Subtotal:</strong> ₱${notification.total_price}</p>
                                        <p><strong>Delivery Fee:</strong> ₱${notification.delivery_fee}</p>
                                        <p><strong>Total Amount:</strong> ₱${notification.total_with_delivery}</p>
                                        <p><strong>Payment Method:</strong> ${notification.payment_method}</p>
                                        <p><strong>Delivery Method:</strong> ${notification.delivery_method}</p>
                                        ${notification.rider_name ? `
                                            <p><strong>Rider:</strong> ${notification.rider_name}</p>
                                            <p><strong>Vehicle:</strong> ${notification.rider_motor_type} (${notification.rider_plate_number})</p>
                                        ` : ''}
                                        <p><strong>Status:</strong> ${notification.order_status}</p>
                                        <small>${notification.order_date}</small>
                                    </div>
                                    <hr class="dropdown-divider">
                                `;
                                notificationItems.appendChild(item);
                            });
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching notifications:', error);
                        notificationItems.innerHTML = '<div class="dropdown-item text-center">Error loading notifications</div>';
                    });
            }

            // Initialize notification sound controls
            initNotificationSoundControls();

            // Initial fetch of notifications
            fetchNotifications();

            // Refresh notifications every 30 seconds
            setInterval(fetchNotifications, 30000);
        });

        // Initialize notification sound controls
        function initNotificationSoundControls() {
            const muteToggleBtn = document.getElementById('muteToggleBtn');
            const testSoundBtn = document.getElementById('testSoundBtn');
            const muteIcon = document.getElementById('muteIcon');
            
            if (muteToggleBtn && testSoundBtn) {
                // Update mute button state
                function updateMuteButton() {
                    if (typeof notificationSound !== 'undefined' && notificationSound) {
                        const isMuted = notificationSound.getMuted();
                        muteIcon.className = isMuted ? 'fas fa-volume-mute' : 'fas fa-volume-up';
                        muteToggleBtn.title = isMuted ? 'Unmute notification sound' : 'Mute notification sound';
                    }
                }
                
                // Mute toggle functionality
                muteToggleBtn.addEventListener('click', function() {
                    if (typeof notificationSound !== 'undefined' && notificationSound) {
                        notificationSound.toggleMute();
                        updateMuteButton();
                    } else {
                        console.warn('Notification sound system not initialized');
                    }
                });
                
                // Test sound functionality
                testSoundBtn.addEventListener('click', function() {
                    if (typeof notificationSound !== 'undefined' && notificationSound) {
                        notificationSound.testSound();
                    } else {
                        console.warn('Notification sound system not initialized');
                    }
                });
                
                // Initial button state
                updateMuteButton();
            }
        }
    </script>

    <?php if ($showSessionModal && $sessionData && $userData): ?>
    <!-- Session Resumption Modal -->
    <div class="modal fade" id="sessionModal" tabindex="-1" aria-labelledby="sessionModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="sessionModalLabel">Resume Previous Session</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <span>You have an existing session. Would you like to continue?</span>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <strong>Time In:</strong>
                            <p><?php echo date('M d, Y h:i A', strtotime($sessionData['time_in'])); ?></p>
                        </div>
                        <div class="col-md-6">
                            <strong>Elapsed Time:</strong>
                            <p><?php echo $sessionData['elapsed_hours']; ?>h <?php echo $sessionData['elapsed_mins']; ?>m</p>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <strong>Remaining Duty Time:</strong>
                            <p class="text-success"><?php echo $sessionData['remaining_hours']; ?>h <?php echo $sessionData['remaining_mins']; ?>m</p>
                        </div>
                        <div class="col-md-6">
                            <strong>Required Daily Duty:</strong>
                            <p>8 hours</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="startNewBtn">Start New Session</button>
                    <button type="button" class="btn btn-primary" id="resumeBtn">Resume Session</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Show session modal on page load
        document.addEventListener('DOMContentLoaded', function() {
            const sessionModal = new bootstrap.Modal(document.getElementById('sessionModal'));
            sessionModal.show();
            
            // Resume session button
            document.getElementById('resumeBtn').addEventListener('click', function() {
                fetch('resume_session.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=resume&log_id=<?php echo $sessionData['log_id']; ?>&user_id=<?php echo $userData['id']; ?>&user_role=<?php echo $userData['role']; ?>&user_full_name=<?php echo urlencode($userData['full_name']); ?>'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        sessionModal.hide();
                        location.reload();
                    } else {
                        alert(data.message || 'Failed to resume session');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while resuming session');
                });
            });

            // Start new session button
            document.getElementById('startNewBtn').addEventListener('click', function() {
                fetch('resume_session.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=start_new&log_id=<?php echo $sessionData['log_id']; ?>&user_id=<?php echo $userData['id']; ?>&user_role=<?php echo $userData['role']; ?>&user_full_name=<?php echo urlencode($userData['full_name']); ?>'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        sessionModal.hide();
                        location.reload();
                    } else {
                        alert(data.message || 'Failed to start new session');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while starting new session');
                });
            });
        });
    </script>
    <?php endif; ?>

    <!-- Real-time Dashboard Metrics and Transaction Updates using Server-Sent Events -->
    <script>
        // Initialize notification sound system
        let notificationSound = null;
        let lastNotificationIds = new Set();
        let isInitialLoad = true;
        let lastSoundPlayTime = 0;
        const SOUND_DEBOUNCE_TIME = 2000; // 2 seconds between sounds
        
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize notification sound
            notificationSound = new NotificationSound({
                soundFile: 'uploads/NofiticationCash.mp3',
                volume: 1.0,
                enableMute: true,
                enableTest: true,
                storageKey: 'cashierNotificationSoundSettings'
            });
            
            // Reset tracking on page load
            lastNotificationIds.clear();
            isInitialLoad = true;
            lastSoundPlayTime = 0;
            
            let metricsEventSource = null;
            let transactionEventSource = null;
            let isMetricsConnected = false;
            let isTransactionConnected = false;
            let reconnectAttempts = 0;
            const maxReconnectAttempts = 5;
            const reconnectDelay = 3000; // 3 seconds

            // Function to update dashboard metrics
            function updateDashboardMetrics(metrics) {
                // Update Total Sales Today
                const totalSalesElement = document.querySelector('.col-sm-6.col-xl-3:nth-child(1) .metrics-value');
                if (totalSalesElement) {
                    totalSalesElement.textContent = `₱${metrics.total_sales_today}`;
                    addUpdateAnimation(totalSalesElement);
                }

                // Update Total Orders Today
                const totalOrdersElement = document.querySelector('.col-sm-6.col-xl-3:nth-child(2) .metrics-value');
                if (totalOrdersElement) {
                    totalOrdersElement.textContent = metrics.total_orders_today;
                    addUpdateAnimation(totalOrdersElement);
                }

                // Update Pending Orders Count
                const pendingOrdersElement = document.querySelector('.col-sm-6.col-xl-3:nth-child(3) .metrics-value');
                if (pendingOrdersElement) {
                    pendingOrdersElement.textContent = metrics.pending_orders_count;
                    addUpdateAnimation(pendingOrdersElement);
                }

                // Update Total Weight Sold
                const totalWeightElement = document.querySelector('.col-sm-6.col-xl-3:nth-child(4) .metrics-value');
                if (totalWeightElement) {
                    totalWeightElement.textContent = `${metrics.total_weight_sold} kg`;
                    addUpdateAnimation(totalWeightElement);
                }
            }

            // Function to add update animation
            function addUpdateAnimation(element) {
                element.classList.add('updating');
                element.style.color = '#28a745';
                
                setTimeout(() => {
                    element.classList.remove('updating');
                    element.style.color = '';
                }, 600);
            }

            // Function to update transaction table
            function updateTransactionTable(data) {
                const tbody = document.getElementById('transactionTableBody');
                const recordInfo = document.querySelector('.text-muted');
                
                if (!tbody) return;

                // Clear existing rows
                tbody.innerHTML = '';

                // Add new transaction rows
                if (data.transactions && data.transactions.length > 0) {
                    data.transactions.forEach(function(txn) {
                        const row = document.createElement('tr');
                        // Format the date to AM/PM format
                        const date = new Date(txn.created_at);
                        const formattedDate = date.toLocaleDateString('en-US', {
                            month: 'short',
                            day: '2-digit',
                            year: 'numeric'
                        }) + ' ' + date.toLocaleTimeString('en-US', {
                            hour: 'numeric',
                            minute: '2-digit',
                            hour12: true
                        });
                        
                        row.innerHTML = `
                            <td>${txn.id}</td>
                            <td>${txn.transaction_number}</td>
                            <td>${txn.user_name}</td>
                            <td>${txn.order_id}</td>
                            <td>${formattedDate}</td>
                        `;
                        tbody.appendChild(row);
                    });
                } else {
                    tbody.innerHTML = '<tr><td colspan="5">No transaction data available.</td></tr>';
                }

                // Update record count info
                if (recordInfo && data.transactions) {
                    // Check if this is the record info in the transaction table section
                    const isTransactionSection = recordInfo.closest('.bg-secondary.text-center');
                    if (isTransactionSection) {
                        recordInfo.textContent = `Total: ${data.transactions.length} records`;
                    }
                }
            }


            // Function to show connection status
            function showConnectionStatus(status, message = '') {
                // Status logging only - no visual indicator
                console.log(`SSE Connection: ${status} ${message}`);
            }

            // Function to connect to Metrics SSE
            function connectMetricsSSE() {
                if (isMetricsConnected) return;

                try {
                    metricsEventSource = new EventSource('sse_dashboard_metrics.php');
                    
                    metricsEventSource.onopen = function(event) {
                        isMetricsConnected = true;
                        console.log('Metrics SSE Connected');
                    };

                    metricsEventSource.addEventListener('initial', function(event) {
                        const data = JSON.parse(event.data);
                        if (data.metrics) {
                            updateDashboardMetrics(data.metrics);
                        }
                    });

                    metricsEventSource.addEventListener('update', function(event) {
                        const data = JSON.parse(event.data);
                        if (data.metrics) {
                            updateDashboardMetrics(data.metrics);
                        }
                    });

                    metricsEventSource.addEventListener('heartbeat', function(event) {
                        // Keep connection alive
                    });

                    metricsEventSource.onerror = function(event) {
                        isMetricsConnected = false;
                        console.log('Metrics SSE Error');
                        
                        if (metricsEventSource) {
                            metricsEventSource.close();
                        }
                        
                        // Attempt to reconnect
                        setTimeout(connectMetricsSSE, reconnectDelay);
                    };

                } catch (error) {
                    console.error('Metrics SSE Connection Error:', error);
                }
            }

            // Function to connect to Transaction SSE
            function connectTransactionSSE() {
                if (isTransactionConnected) return;

                const fromDate = document.getElementById('from_date').value;
                const toDate = document.getElementById('to_date').value;
                
                const url = `sse_transactions.php?from_date=${fromDate}&to_date=${toDate}`;
                
                try {
                    transactionEventSource = new EventSource(url);
                    
                    transactionEventSource.onopen = function(event) {
                        isTransactionConnected = true;
                        showConnectionStatus('Connected', 'Real-time updates active');
                    };

                    transactionEventSource.addEventListener('initial', function(event) {
                        const data = JSON.parse(event.data);
                        updateTransactionTable(data);
                    });

                    transactionEventSource.addEventListener('update', function(event) {
                        const data = JSON.parse(event.data);
                        updateTransactionTable(data);
                        
                        // Show notification for new transactions
                        if (data.new_transactions && data.new_transactions.length > 0) {
                            showNewTransactionNotification(data.new_transactions.length);
                            
                            // Play notification sound for new transactions (with debounce)
                            const currentTime = Date.now();
                            const isPageVisible = !document.hidden;
                            if (notificationSound && (currentTime - lastSoundPlayTime) > SOUND_DEBOUNCE_TIME && isPageVisible) {
                                console.log('Dashboard: Playing notification sound - new transaction detected');
                                notificationSound.play();
                                lastSoundPlayTime = currentTime;
                            }
                        }
                    });

                    transactionEventSource.addEventListener('heartbeat', function(event) {
                        // Keep connection alive
                    });

                    transactionEventSource.addEventListener('close', function(event) {
                        isTransactionConnected = false;
                        showConnectionStatus('Disconnected', 'Connection closed by server');
                        transactionEventSource.close();
                    });

                    transactionEventSource.onerror = function(event) {
                        isTransactionConnected = false;
                        showConnectionStatus('Error', 'Connection lost');
                        
                        if (transactionEventSource) {
                            transactionEventSource.close();
                        }
                        
                        // Attempt to reconnect
                        if (reconnectAttempts < maxReconnectAttempts) {
                            reconnectAttempts++;
                            showConnectionStatus('Reconnecting', `Attempt ${reconnectAttempts}/${maxReconnectAttempts}`);
                            setTimeout(connectTransactionSSE, reconnectDelay);
                        } else {
                            showConnectionStatus('Failed', 'Max reconnection attempts reached');
                        }
                    };

                } catch (error) {
                    console.error('Transaction SSE Connection Error:', error);
                    showConnectionStatus('Error', error.message);
                }
            }

            // Function to update barangay filter counts in real-time
            function updateBarangayCounts() {
                // Get the current page type to determine which endpoint to call
                const currentPage = window.location.pathname;
                let endpoint = '';
                
                if (currentPage.includes('COD-Delivery')) {
                    endpoint = 'get_barangay_cod_pending_counts.php';
                } else if (currentPage.includes('COD-Ready')) {
                    endpoint = 'get_barangay_cod_ready_counts.php';
                } else if (currentPage.includes('COD-Onship')) {
                    endpoint = 'get_barangay_cod_onship_counts.php';
                } else if (currentPage.includes('GCASH-Delivery')) {
                    endpoint = 'get_barangay_gcash_pending_counts.php';
                } else if (currentPage.includes('GCASH-Ready')) {
                    endpoint = 'get_barangay_gcash_ready_counts.php';
                } else if (currentPage.includes('GCASH-OnShip')) {
                    endpoint = 'get_barangay_gcash_onship_counts.php';
                }
                
                if (endpoint) {
                    fetch(endpoint)
                        .then(response => response.json())
                        .then(data => {
                            // Update all barangay button badges
                            data.forEach(barangay => {
                                const button = document.querySelector(`a[href*="barangay_id=${barangay.id}"]`);
                                if (button) {
                                    let badge = button.querySelector('.badge');
                                    if (barangay.count > 0) {
                                        if (!badge) {
                                            badge = document.createElement('span');
                                            badge.className = 'badge';
                                            button.appendChild(badge);
                                        }
                                        const oldCount = badge.textContent;
                                        badge.textContent = barangay.count;
                                        
                                        // Add visual feedback for count changes
                                        if (oldCount && oldCount !== barangay.count.toString()) {
                                            badge.style.animation = 'pulse 0.5s ease-in-out';
                                            setTimeout(() => {
                                                badge.style.animation = '';
                                            }, 500);
                                        }
                                    } else if (badge) {
                                        badge.remove();
                                    }
                                }
                            });
                        })
                        .catch(error => {
                            console.error('Error updating barangay counts:', error);
                        });
                }
            }

            // Function to show banner notification for new orders
            function showNewOrderNotification(count) {
                // Create a banner notification
                const notification = document.createElement('div');
                notification.className = 'alert alert-success alert-dismissible fade show position-fixed';
                notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);';
                notification.innerHTML = `
                    <i class="fa fa-shopping-cart me-2"></i>
                    <strong>New Order!</strong> ${count} new order${count > 1 ? 's' : ''} received.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                `;
                
                document.body.appendChild(notification);
                
                // Auto-remove after 5 seconds
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.style.opacity = '0';
                        notification.style.transition = 'opacity 0.3s ease';
                        setTimeout(() => {
                            if (notification.parentNode) {
                                notification.remove();
                            }
                        }, 300);
                    }
                }, 5000);
            }

            // Function to show notification for new transactions
            function showNewTransactionNotification(count) {
                // Create a simple notification
                const notification = document.createElement('div');
                notification.className = 'alert alert-success alert-dismissible fade show position-fixed';
                notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
                notification.innerHTML = `
                    <i class="fa fa-bell me-2"></i>
                    <strong>New Transaction!</strong> ${count} new transaction${count > 1 ? 's' : ''} added.
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                `;
                
                document.body.appendChild(notification);
                
                // Auto-remove after 5 seconds
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.remove();
                    }
                }, 5000);
            }

            // Function to disconnect SSE connections
            function disconnectSSE() {
                if (metricsEventSource) {
                    metricsEventSource.close();
                    metricsEventSource = null;
                }
                if (transactionEventSource) {
                    transactionEventSource.close();
                    transactionEventSource = null;
                }
                isMetricsConnected = false;
                isTransactionConnected = false;
                showConnectionStatus('Disconnected', 'Manually disconnected');
            }

            // Handle date filter changes
            const dateForm = document.querySelector('form[method="GET"]');
            if (dateForm) {
                dateForm.addEventListener('submit', function(e) {
                    // Disconnect current SSE connection
                    disconnectSSE();
                    
                    // Allow form submission to proceed normally
                    // SSE will reconnect on page reload
                });
            }

            // Handle page visibility change
            document.addEventListener('visibilitychange', function() {
                if (document.hidden) {
                    // Page is hidden, disconnect to save resources
                    disconnectSSE();
                } else {
                    // Page is visible again, reconnect
                    setTimeout(() => {
                        connectMetricsSSE();
                        connectTransactionSSE();
                    }, 1000);
                }
            });

            // Handle page unload
            window.addEventListener('beforeunload', function() {
                disconnectSSE();
            });

            // Start SSE connections
            connectMetricsSSE();
            connectTransactionSSE();
        });
    </script>
</body>
</html>
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

// **5. Pending Orders Count (All Time)**
$sql = "SELECT COUNT(*) AS pending_orders
        FROM orders
        WHERE order_status = 'Pending'";
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

// **6. Additional Dashboard Metrics**

// Get COD vs GCash order counts
$sql = "SELECT payment_method, COUNT(*) as count FROM orders WHERE order_status IN ('pending', 'processing', 'confirmed', 'on-ship') GROUP BY payment_method";
$result = $conn->query($sql);
$paymentMethodCounts = ['COD' => 0, 'GCASH' => 0, 'CASH' => 0];
while ($row = $result->fetch_assoc()) {
    $paymentMethodCounts[$row['payment_method']] = $row['count'];
}

// Set up date filtering for order status - default to all time
$selectedFilter = isset($_GET['status_filter']) ? $_GET['status_filter'] : 'all';
$whereClause = "";
$params = [];

switch($selectedFilter) {
    case 'today':
        $whereClause = "DATE(o.order_date) = ?";
        $params[] = date('Y-m-d');
        break;
    case 'week':
        $whereClause = "DATE(o.order_date) >= ? AND DATE(o.order_date) <= ?";
        $startWeek = date('Y-m-d', strtotime('monday this week'));
        $endWeek = date('Y-m-d', strtotime('sunday this week'));
        $params[] = $startWeek;
        $params[] = $endWeek;
        break;
    case 'month':
        $whereClause = "YEAR(o.order_date) = ? AND MONTH(o.order_date) = ?";
        $params[] = date('Y');
        $params[] = date('n');
        break;
    case 'all':
    default:
        $whereClause = "1";
        break;
}

// Get orders by status based on filter (combine Canceled/Cancelled, exclude Returned as it's counted separately)
$sql = "SELECT 
    CASE 
        WHEN order_status IN ('Canceled', 'Cancelled') THEN 'Canceled'
        ELSE order_status 
    END as unified_status,
    COUNT(*) as count 
FROM orders o
WHERE {$whereClause} AND order_status != 'Returned'
GROUP BY unified_status";


$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param(str_repeat("s", count($params)), ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$statusCounts = [];
while ($row = $result->fetch_assoc()) {
    $statusCounts[$row['unified_status']] = $row['count'];
}
$stmt->close();

// Count actual returned orders from orders table based on order_status
$today = date('Y-m-d');
$returnedSql = "SELECT COUNT(*) as count FROM orders WHERE order_status = 'Returned' AND DATE(order_date) = ?";
$returnedStmt = $conn->prepare($returnedSql);
$returnedStmt->bind_param("s", $today);
$returnedStmt->execute();
$returnedResult = $returnedStmt->get_result();
$returnedCount = $returnedResult->fetch_assoc()['count'];
$statusCounts['Returned'] = $returnedCount;
$returnedStmt->close();

// Get top delivery areas based on completed transactions today with actual barangay names
$sql = "SELECT 
            COALESCE(b.barangay_name, TRIM(SUBSTRING_INDEX(o.shipping_address, ',', -1))) as area, 
            COUNT(DISTINCT t.id) as count 
        FROM transactions t
        JOIN orders o ON t.order_id = o.id 
        LEFT JOIN barangays b ON TRIM(SUBSTRING_INDEX(o.shipping_address, ',', -1)) = b.id
        WHERE DATE(t.completed_date_transaction) = ?
        GROUP BY COALESCE(b.barangay_name, TRIM(SUBSTRING_INDEX(o.shipping_address, ',', -1)))
        ORDER BY count DESC 
        LIMIT 5";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $today);
$stmt->execute();
$result = $stmt->get_result();

$topAreas = [];
while ($row = $result->fetch_assoc()) {
    $topAreas[] = ['area' => $row['area'], 'count' => $row['count']];
}
$stmt->close();

// Get order weight distribution
$sql = "SELECT 
        CASE 
            WHEN o.total_weight <= 1 THEN 'Under 1kg'
            WHEN o.total_weight <= 3 THEN '1-3kg'
            WHEN o.total_weight <= 5 THEN '3-5kg'
            ELSE 'Over 5kg'
        END as weight_range,
        COUNT(*) as count
        FROM orders o
        JOIN transactions t ON o.id = t.order_id
        WHERE o.order_status = 'completed' AND DATE(t.completed_date_transaction) = '$today'
        GROUP BY weight_range
        ORDER BY count DESC";
$result = $conn->query($sql);
$weightDistribution = [];
while ($row = $result->fetch_assoc()) {
    $weightDistribution[] = ['range' => $row['weight_range'], 'count' => $row['count']];
}


$conn->close();

// Build base URL dynamically so mobile devices don't get redirected to localhost
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    ? 'https' : 'http';
$host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST']; // includes port if any
$basePath = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$baseURL = $scheme . '://' . $host . $basePath . '/';
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

        /* Bar Chart Styles */
        #orderStatusPieChart {
            max-width: 100%;
            height: 250px;
            width: 100%;
            display: block;
        }

        .chart-legend {
            padding-top: 15px;
        }

        .legend-color {
            flex-shrink: 0;
        }

        .chart-legend .badge {
            font-size: 0.75rem;
            min-width: 25px;
            text-align: center;
        }

        /* Chart container improvements */
        .col-md-8 {
            position: relative;
            overflow: hidden;
        }

        /* Ensure canvas is visible */
        canvas {
            background: #f8f9fa !important;
            border: 1px solid #ddd;
            border-radius: 4px;
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

        /* Responsive Chart Styles */
        @media (max-width: 768px) {
            #orderStatusChart { height: 180px !important; }
            #orderStatsChart { max-width: 160px !important; max-height: 160px !important; }
        }
        
        @media (max-width: 576px) {
            #orderStatusChart { height: 160px !important; }
            #orderStatsChart { max-width: 140px !important; max-height: 140px !important; }
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
            <h3 class="text-danger"><i class="fa fa-user-edit me-2"></i>Cj P'House</h3>
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
                    <a href="Cashier-Pickup-Orders.php" class="nav-item nav-link"><i class="fa fa-store me-2"></i>Pickup Orders</a>
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
                <h2 class="text-danger mb-0"><i class="fa fa-user-edit"></i></h2>
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
    <!-- Date and Time Display -->
    <div class="nav-item d-none d-lg-inline" style="margin-right: 15px;">
        <span class="nav-link text-white-50 small">
            <i class="fas fa-clock me-1"></i>
            <span id="currentDateTime"></span>
        </span>
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
        <div class="row g-4 justify-content-center d-flex align-items-stretch">

            <div class="col-sm-6 col-xl-3">
                <div class="bg-secondary rounded d-flex align-items-center justify-content-between p-4 metrics-card h-100">
                    <i class="fa fa-chart-line fa-3x text-danger"></i>
                    <div class="ms-3">
                        <p class="mb-2">Total Sales Today</p>
                        <h6 class="mb-0 metrics-value">₱<?php echo $totalSalesToday; ?></h6>
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-xl-3">
                <div class="bg-secondary rounded d-flex align-items-center justify-content-between p-4 metrics-card h-100">
                    <i class="fa fa-shopping-cart fa-3x text-danger"></i>
                    <div class="ms-3">
                        <p class="mb-2">Total Completed Orders Today</p>
                        <h6 class="mb-0 metrics-value"><?php echo $totalOrdersToday; ?></h6>
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-xl-3">
                <div class="bg-secondary rounded d-flex align-items-center justify-content-between p-4 metrics-card h-100">
                    <i class="fa fa-clock fa-3x text-danger"></i>
                    <div class="ms-3">
                        <p class="mb-2">Pending Orders</p>
                        <h6 class="mb-0 metrics-value"><?php echo $pendingOrdersCount; ?></h6>
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-xl-3">
                <div class="bg-secondary rounded d-flex align-items-center justify-content-between p-4 metrics-card h-100">
                    <i class="fa fa-weight fa-3x text-danger"></i>
                    <div class="ms-3">
                        <p class="mb-2">Total Weight Sold</p>
                        <h6 class="mb-0 metrics-value"><?php echo $totalWeightSold; ?> kg</h6>
                    </div>
                </div>
            </div>

        </div>
    </div>
    <!-- Cards End -->

    <!-- Enhanced Dashboard Analytics Start -->
    <div class="container-fluid pt-4 px-4">
        

        <!-- Order Status Overview and Top Areas -->
        <div class="row mb-4 d-flex align-items-stretch">
            <!-- Order Status Pie Chart -->
            <div class="col-lg-6 col-md-12 mb-3">
                <div class="bg-secondary rounded p-4 h-100 d-flex flex-column">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="text-white mb-0"><i class="fas fa-chart-bar me-2"></i>Order Status Distribution</h6>
                        <div class="dropdown text-right">
                            <button class="btn btn-outline-white btn-sm" type="button" id="statusFilterDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-filter me-1"></i><span id="selectedFilter"><?php echo ucfirst($selectedFilter); ?></span> <i class="fas fa-chevron-down ms-1"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="statusFilterDropdown">
                                <li><a class="dropdown-item filter-option" href="#" data-filter="all"><i class="fas fa-calendar-check me-2"></i>All Time</a></li>
                                <li><a class="dropdown-item filter-option" href="#" data-filter="today"><i class="fas fa-calendar-day me-2"></i>Today</a></li>
                                <li><a class="dropdown-item filter-option" href="#" data-filter="week"><i class="fas fa-calendar-week me-2"></i>This Week</a></li>
                                <li><a class="dropdown-item filter-option" href="#" data-filter="month"><i class="fas fa-calendar-alt me-2"></i>This Month</a></li>
                            </ul>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-8">
                                            <div id="orderStatusChart" style="width: 100%; height: 200px; background: #ffffff; padding: 5px; border-radius: 4px; border: 1px solid #ddd;">
                                <?php
                                
                                // EMERGENCY: Simple PHP-generated pie chart that WILL work
                                echo '<div style="width: 100%; height: 100%; display: flex; flex-direction: column; gap: 8px; padding: 5px;">';
                                
                                $colors = [
                                    'Canceled' => '#dc3545',
                                    'Cancelled' => '#dc3545', 
                                    'Returned' => '#28a745',
                                    'Pending' => '#ffc107',
                                    'Pending Payment' => '#fd7e14',
                                    'Processing' => '#17a2b8',
                                    'Ready to Ship' => '#6f42c1'
                                ];
                                
                                // Define all possible statuses (Canceled and Cancelled combined)
                                $allStatuses = [
                                    'Pending',
                                    'Processing', 
                                    'Returned',
                                    'Ready to Ship',
                                    'Pending Payment',
                                    'Canceled'  // This now includes both Canceled and Cancelled
                                ];
                                
                                // Merge today's counts with all statuses (show 0 if not found)
                                $allStatusCounts = [];
                                foreach ($allStatuses as $status) {
                                    $allStatusCounts[$status] = isset($statusCounts[$status]) ? $statusCounts[$status] : 0;
                                }
                                
                                $filteredCounts = array_filter($allStatusCounts);
                                $maxCount = !empty($filteredCounts) ? max($filteredCounts) : 1;
                                
                                // Check if there's any data at all
                                if ($maxCount == 1 && empty($filteredCounts)) {
                                    // No data at all - show empty state
                                    echo '<div style="height: 100%; display: flex; align-items: center; justify-content: center; flex-direction: column; color: #666;">';
                                    echo '<i class="fas fa-exclamation-circle fa-2x mb-2" style="color: #ccc;"></i>';
                                    echo '<p style="margin: 0; font-size: 14px;">No orders found</p>';
                                    echo '<small style="color: #999;">Orders will appear here once they are placed</small>';
                                    echo '</div>';
                                } else {
                                    // Show chart data
                                    foreach ($allStatusCounts as $status => $count) {
                                    $percentage = $count > 0 ? round(($count / $maxCount) * 100) : 0;
                                    $color = $colors[$status] ?? '#6c757d';
                                    
                                    echo '<div style="display: flex; align-items: center; height: 20px;">';
                                    echo '<span style="min-width: 80px; font-size: 11px; font-weight: bold; color: ' . ($count > 0 ? '#333' : '#999') . ';">' . htmlspecialchars($status) . '</span>';
                                    echo '<div style="flex: 1; height: 16px; background: #eee; border-radius: 8px; overflow: hidden; margin: 0 8px;">';
                                    echo '<div style="width: ' . $percentage . '%; height: 100%; background: ' . $color . ';"></div>';
                                    echo '</div>';
                                    echo '<span style="min-width: 25px; text-align: center; font-size: 11px; font-weight: bold; color: ' . ($count > 0 ? '#333' : '#999') . ';">' . $count . '</span>';
                                    echo '</div>';
                                    }
                                }
                                
                                echo '</div>';
                                ?>
                                
                                <!-- Hidden Canvas for Chart.js when it works -->
                                <canvas id="orderStatsChart" style="display: none; max-width: 100%; max-height: 100%; width: auto; height: auto;"></canvas>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="chart-legend">
                                <?php 
                                $statusColorsLegend = [
                                    'Pending' => '#ffc107',
                                    'Processing' => '#17a2b8',
                                    'Returned' => '#28a745',
                                    'Ready to Ship' => '#6f42c1',
                                    'Pending Payment' => '#fd7e14',
                                    'Canceled' => '#dc3545'  // Combined Canceled + Cancelled
                                ];
                                
                                foreach ($allStatusCounts as $status => $count): 
                                    $color = $statusColorsLegend[$status] ?? '#f8f9fa';
                                    $textColor = $count > 0 ? 'text-light' : 'text-muted';
                                ?>
                                <div class="d-flex align-items-center mb-2">
                                    <div class="legend-color me-2" style="width: 12px; height: 12px; background-color: <?php echo $color; ?>; border-radius: 2px;"></div>
                                    <small class="<?php echo $textColor; ?>"><?php echo htmlspecialchars($status); ?></small>
                                    <span class="badge <?php echo $count > 0 ? 'bg-dark' : 'bg-secondary'; ?> ms-auto"><?php echo htmlspecialchars($count); ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Top Delivery Areas Today -->
            <div class="col-lg-6 col-md-12 mb-3">
                <div class="bg-secondary rounded p-4 h-100 d-flex flex-column">
                    <h6 class="text-white mb-3"><i class="fas fa-map-marker-alt me-2"></i>Top Delivery Areas Today</h6>
                    <div style="width: 100%; height: 200px; background: #ffffff; padding: 15px; border-radius: 4px; border: 1px solid #ddd;">
                        <?php if (empty($topAreas)): ?>
                            <div style="height: 100%; display: flex; align-items: center; justify-content: center; flex-direction: column; color: #666;">
                                <i class="fas fa-truck fa-3x mb-3" style="color: #ccc;"></i>
                                <h5 style="margin: 0; color: #666;">No Deliveries Today</h5>
                                <p style="margin: 5px 0 0 0; font-size: 14px; color: #999;">Delivery areas will appear here</p>
                            </div>
                        <?php else: ?>
                            <div style="padding: 5px;">
                                <?php 
                                // Calculate max count for percentage calculation
                                $maxCount = 0;
                                foreach ($topAreas as $area) {
                                    if ($area['count'] > $maxCount) {
                                        $maxCount = $area['count'];
                                    }
                                }
                                ?>
                                <?php foreach ($topAreas as $index => $area): ?>
                                    <?php
                                    // Create ranking colors
                                    $rankColors = [
                                        1 => '#ff6b6b',   // Red for #1
                                        2 => '#4ecdc4',   // Teal for #2  
                                        3 => '#45b7d1',   // Blue for #3
                                        4 => '#96ceb4',   // Green for others
                                        5 => '#feca57',   // Yellow for others
                                    ];
                                    $rankColor = $rankColors[$index + 1] ?? '#95a5a6';
                                    // Calculate percentage based on actual count relative to max count
                                    $percentage = $maxCount > 0 ? round(($area['count'] / $maxCount) * 100) : 0;
                                    ?>
                                    <div style="display: flex; align-items: center; height: 25px; margin-bottom: 12px;">
                                        <div style="min-width: 30px; display: flex; align-items: center; justify-content: center;">
                                            <span style="background: <?php echo $rankColor; ?>; color: white; padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; position: relative;">
                                                #<?php echo $index + 1; ?>
                                            </span>
                                        </div>
                                        <span style="min-width: 100px; font-size: 12px; font-weight: bold; color: #333; margin-left: 10px;">
                                            <?php echo htmlspecialchars($area['area']); ?>
                                        </span>
                                        <div style="flex: 1; height: 12px; background: #e9ecef; border-radius: 6px; overflow: hidden; margin: 0 10px;">
                                            <div style="width: <?php echo $percentage; ?>%; height: 100%; background: <?php echo $rankColor; ?>; border-radius: 6px;"></div>
                                        </div>
                                        <span style="min-width: 40px; text-align: center; font-size: 12px; font-weight: bold; color: #333;">
                                            <?php echo $area['count']; ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Weight Distribution and Quick Actions -->
        <div class="row mb-4 d-flex align-items-stretch">
            <!-- Weight Distribution -->
            <div class="col-lg-8 col-md-12 mb-3">
                <div class="bg-secondary rounded p-4 h-100 d-flex flex-column">
                    <h6 class="text-white mb-3"><i class="fas fa-weight-hanging me-2"></i>Order Weight Distribution Today</h6>
                    <?php if (empty($weightDistribution)): ?>
                        <p class="text-muted text-center">No orders completed today</p>
                    <?php else: ?>
                        <div class="row">
                            <?php foreach ($weightDistribution as $weight): ?>
                            <div class="col-md-3 col-6 mb-2">
                                <div class="text-center p-2 bg-dark rounded">
                                    <h6 class="text-white mb-1"><?php echo $weight['count']; ?></h6>
                                    <small class="text-light"><?php echo $weight['range']; ?></small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div class="col-lg-4 col-md-12 mb-3">
                <div class="bg-secondary rounded p-4 text-center h-100 d-flex flex-column">
                    <h6 class="text-white mb-3"><i class="fas fa-bolt me-2"></i>Quick Actions</h6>
                    <div class="d-grid gap-2">
                        <a href="Cashier-PendingOrders.php" class="btn btn-warning btn-sm">
                            <i class="fas fa-clock me-1"></i>Pending Orders
                        </a>
                        <a href="Cashier-COD-Delivery.php" class="btn btn-danger btn-sm">
                            <i class="fas fa-truck me-1"></i>COD Orders
                        </a>
                        <a href="Cashier-GCASH-Delivery.php" class="btn btn-success btn-sm">
                            <i class="fas fa-mobile-alt me-1"></i>GCash Orders
                        </a>
                        <a href="Cashier-Pickup-Orders.php" class="btn btn-info btn-sm">
                            <i class="fas fa-handshake me-1"></i>Pickup Orders
                        </a>
                    </div>
                </div>
            </div>
        </div>

    </div>
    <!-- Enhanced Dashboard Analytics End -->

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
            // Dashboard functionality functions

            // Enhanced dashboard functionality
            console.log('Enhanced dashboard loaded');
            
            // Update date and time in header
            function updateDateTime() {
                const now = new Date();
                const dateTimeElement = document.getElementById('currentDateTime');
                if (dateTimeElement) {
                    const options = { 
                        weekday: 'short', 
                        year: 'numeric', 
                        month: 'short', 
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit',
                        second: '2-digit',
                        hour12: true
                    };
                    dateTimeElement.textContent = now.toLocaleString('en-US', options);
                }
            }
            
            // Update immediately and then every second
            updateDateTime();
            setInterval(updateDateTime, 1000);
            
            // Initialize Order Status Chart using Chart.js
            initializeOrderStatusChart();
            
            // Add hover effects for quick action buttons
            const quickActionBtns = document.querySelectorAll('.btn');
            quickActionBtns.forEach(function(btn) {
                btn.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-2px)';
                    this.style.transition = 'all 0.3s ease';
                });
                
                btn.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });
            
            // Add animation to metric cards
            const metricCards = document.querySelectorAll('.bg-gradient-primary, .bg-gradient-success, .bg-gradient-info');
            metricCards.forEach(function(card, index) {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    card.style.transition = 'all 0.6s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 150);
            });
            
            // Auto-refresh dashboard metrics every 30 seconds
            setInterval(function() {
                console.log('Dashboard metrics refresh check...');
            }, 30000);
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

        // Order Status Chart Initialization Function
        function initializeOrderStatusChart() {
            const ctx = document.getElementById('orderStatsChart');
            if (!ctx) {
                console.error('Chart canvas not found');
                return;
            }
            
            // PHP data for chart - passed from server
            <?php
            $labels = [];
            $data = [];
            $colors = [];
            $statusColorMap = [
                'Canceled' => '#dc3545',
                'Cancelled' => '#dc3545', 
                'Completed' => '#28a745',
                'Pending' => '#ffc107',
                'Pending Payment' => '#fd7e14',
                'Processing' => '#17a2b8',
                'Ready to Ship' => '#6f42c1'
            ];
            
            foreach ($statusCounts as $status => $count) {
                if ($count > 0) {
                    $labels[] = htmlspecialchars($status);
                    $data[] = $count;
                    $colors[] = $statusColorMap[$status] ?? '#6c757d';
                }
            }
            ?>
            
            const chartData = {
                labels: <?php echo json_encode($labels); ?>,
                datasets: [{
                    label: 'Order Status',
                    data: <?php echo json_encode($data); ?>,
                    backgroundColor: <?php echo json_encode($colors); ?>,
                    borderColor: <?php echo json_encode($colors); ?>,
                    borderWidth: 2,
                    hoverBorderWidth: 3,
                    hoverOffset: 8
                }]
            };
            
            new Chart(ctx, {
                type: 'doughnut',
                data: chartData,
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    aspectRatio: 1,
                    plugins: {
                        legend: {
                            display: false // We use custom legend on the right
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            titleColor: '#fff',
                            bodyColor: '#fff',
                            borderColor: '#fff',
                            borderWidth: 1,
                            cornerRadius: 6,
                            displayColors: true,
                            titleFont: { size: 12 },
                            bodyFont: { size: 11 },
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.parsed;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = Math.round((value / total) * 100);
                                    return `${label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    },
                    cutout: '60%', // Make donut hole slightly larger
                    animation: {
                        animateRotate: true,
                        animateScale: true,
                        duration: 800,
                        easing: 'easeOutQuart'
                    },
                    elements: {
                        arc: {
                            borderWidth: 2
                        }
                    }
                }
            });
            
            console.log('Order Status Chart initialized successfully');
        }

        // Order Status Filter Dropdown Handler
        document.addEventListener('DOMContentLoaded', function() {
            const filterDropdown = document.getElementById('statusFilterDropdown');
            const filterOptions = document.querySelectorAll('.filter-option');
            const selectedFilterSpan = document.getElementById('selectedFilter');
            
            filterOptions.forEach(option => {
                option.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    const filterValue = this.getAttribute('data-filter');
                    const filterText = this.textContent.trim();
                    
                    // Update dropdown text
                    selectedFilterSpan.textContent = filterText;
                    
                    // Reload page with new filter
                    const url = new URL(window.location);
                    url.searchParams.set('status_filter', filterValue);
                    window.location.href = url.toString();
                });
            });
        });

        })(jQuery);
    </script>

    <!-- Notification System -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const notificationIcon = document.querySelector('.notification-icon');
            const notificationCount = document.getElementById('notificationCount');
            const notificationItems = document.getElementById('notificationItems');

            // Function to update notification items from SSE data
            function updateNotificationItems(notifications) {
                if (notificationItems) {
                    notificationItems.innerHTML = '';

                    if (notifications.length === 0) {
                        notificationItems.innerHTML = '<div class="dropdown-item text-center">No pending orders</div>';
                    } else {
                        notifications.forEach(notification => {
                            // Determine the correct target page based on delivery method and payment method
                            let targetHref = '';
                            if (notification.delivery_method === 'pickup') {
                                targetHref = `Cashier-Pickup-Orders.php?order_id=${notification.order_id}`;
                            } else if ((notification.payment_method || '').toUpperCase() === 'COD') {
                                targetHref = `Cashier-COD-Delivery.php?order_id=${notification.order_id}`;
                            } else {
                                targetHref = `Cashier-GCASH-Delivery.php?order_id=${notification.order_id}`;
                            }

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
                }
            }

            // Function to update notification count from SSE data
            function updateNotificationCount(count) {
                if (notificationCount) {
                    notificationCount.textContent = count;
                    notificationCount.style.display = count > 0 ? 'block' : 'none';
                }
            }

            // Initialize notification sound controls
            initNotificationSoundControls();

            // SSE will handle all real-time notifications - no polling needed
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

            let notificationEventSource = null;
            let isNotificationConnected = false;
            let reconnectAttempts = 0;
            const maxReconnectAttempts = 5;
            const reconnectDelay = 3000; // 3 seconds

            // Dashboard metrics are now static - loaded once on page load

            // Function to add update animation
            function addUpdateAnimation(element) {
                element.classList.add('updating');
                element.style.color = '#28a745';
                
                setTimeout(() => {
                    element.classList.remove('updating');
                    element.style.color = '';
                }, 600);
            }

            // Transaction table is now static - loaded once on page load


            // Function to show connection status
            function showConnectionStatus(status, message = '') {
                // Status logging only - no visual indicator
                console.log(`SSE Connection: ${status} ${message}`);
            }

            // Metrics SSE removed - dashboard metrics are now static

            // Function to connect to Notification SSE
            function connectNotificationSSE() {
                if (isNotificationConnected) return;

                try {
                    notificationEventSource = new EventSource('cashier_notifications_realtime.php');
                    
                    notificationEventSource.onopen = function(event) {
                        isNotificationConnected = true;
                        console.log('Notifications SSE Connected');
                        showConnectionStatus('Notifications Connected', 'Real-time notifications active');
                    };

                    notificationEventSource.addEventListener('update', function(event) {
                        const data = JSON.parse(event.data);

                        if (data.count !== undefined) {
                            updateNotificationCount(data.count);
                        }

                        if (data.notifications) {
                            updateNotificationItems(data.notifications);
                        }

                        // Play notification sound for new orders (legacy support)
                        if (data.new_orders && data.new_orders > 0) {
                            const currentTime = Date.now();
                            const isPageVisible = !document.hidden;
                            if (notificationSound && (currentTime - lastSoundPlayTime) > SOUND_DEBOUNCE_TIME && isPageVisible) {
                                console.log('Dashboard: Playing notification sound - new order detected');
                                notificationSound.play();
                                lastSoundPlayTime = currentTime;
                            }
                        }
                    });

                    // Listen for cashier notification updates (new real-time system)
                    notificationEventSource.addEventListener('cashier_notification_update', function(event) {
                        const data = JSON.parse(event.data);

                        console.log('Cashier notification update received:', data);

                        // Update notification count
                        if (data.count !== undefined) {
                            updateNotificationCount(data.count);
                        }

                        // Update notification items
                        if (data.notifications) {
                            // Track notification IDs for new notification detection
                            const currentNotificationIds = new Set(data.notifications.map(n => n.order_id));
                            const hasNewNotifications = [...currentNotificationIds].some(id => !lastNotificationIds.has(id));

                            updateNotificationItems(data.notifications);

                            // Play notification sound and show banner for genuinely new notifications
                            if (hasNewNotifications && !isInitialLoad) {
                                const currentTime = Date.now();
                                const isPageVisible = !document.hidden;

                                // Play notification sound
                                if (notificationSound && (currentTime - lastSoundPlayTime) > SOUND_DEBOUNCE_TIME && isPageVisible) {
                                    console.log('Dashboard: Playing notification sound - new cashier order detected');
                                    notificationSound.play();
                                    lastSoundPlayTime = currentTime;
                                }

                                // Show banner notification with order details
                                if (data.new_order_data && data.new_order_data.length > 0) {
                                    showNewOrderNotification(data.new_orders, data.new_order_data);
                                } else {
                                    showNewOrderNotification(data.new_orders);
                                }
                            }

                            // Update the last known notification IDs
                            lastNotificationIds = currentNotificationIds;
                            isInitialLoad = false;
                        }
                    });

                    notificationEventSource.addEventListener('heartbeat', function(event) {
                        // Keep connection alive
                    });

                    notificationEventSource.onerror = function(event) {
                        isNotificationConnected = false;
                        console.log('Notification SSE Error');
                        
                        if (notificationEventSource) {
                            notificationEventSource.close();
                        }
                        
                        // Attempt to reconnect
                        if (reconnectAttempts < maxReconnectAttempts) {
                            reconnectAttempts++;
                            setTimeout(connectNotificationSSE, reconnectDelay);
                        }
                    };

                } catch (error) {
                    console.error('Notification SSE Connection Error:', error);
                }
            }

            // Transaction SSE removed - transaction table is now static

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

            // Function to show professional banner notification for new orders
            function showNewOrderNotification(count, newOrderData = null) {
                // Remove any existing notifications to prevent duplicates
                const existingNotifications = document.querySelectorAll('.alert.position-fixed');
                existingNotifications.forEach(notification => notification.remove());

                // Determine order type and styling from the latest order
                let orderType = 'Order';
                let alertClass = 'alert-success';
                let borderColor = '#28a745';
                let textColor = '#28a745';

                if (newOrderData && newOrderData.length > 0) {
                    const latestOrder = newOrderData[0];
                    
                    if (latestOrder.delivery_method === 'pickup') {
                        orderType = 'Pickup Order';
                        alertClass = 'alert-info';
                        borderColor = '#17a2b8';
                        textColor = '#17a2b8';
                    } else if (latestOrder.payment_method === 'COD') {
                        orderType = 'COD Order';
                        alertClass = 'alert-warning';
                        borderColor = '#ffc107';
                        textColor = '#ffc107';
                    } else if (latestOrder.payment_method === 'GCASH') {
                        orderType = 'GCASH Order';
                        alertClass = 'alert-primary';
                        borderColor = '#007bff';
                        textColor = '#007bff';
                    }
                }

                // Create a clean, professional banner notification
                const notification = document.createElement('div');
                notification.className = `alert ${alertClass} alert-dismissible fade show position-fixed`;
                notification.style.cssText = `
                    top: 20px; 
                    right: 20px; 
                    z-index: 9999; 
                    min-width: 320px; 
                    max-width: 360px; 
                    box-shadow: 0 4px 12px rgba(0,0,0,0.15); 
                    border-left: 4px solid ${borderColor};
                    border-radius: 6px;
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                    animation: slideInRight 0.3s ease-out;
                `;

                // Add CSS animation if not already present
                if (!document.getElementById('notification-animation-styles')) {
                    const style = document.createElement('style');
                    style.id = 'notification-animation-styles';
                    style.textContent = `
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
                        @keyframes slideOutRight {
                            from { 
                                transform: translateX(0); 
                                opacity: 1; 
                            }
                            to { 
                                transform: translateX(100%); 
                                opacity: 0; 
                            }
                        }
                    `;
                    document.head.appendChild(style);
                }

                let notificationContent = `
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6 class="mb-1 fw-bold" style="color: ${textColor};">
                                New ${orderType}!
                            </h6>
                            <p class="mb-2 text-muted small">
                                ${count} new ${orderType.toLowerCase()}${count > 1 ? 's' : ''} received
                            </p>
                `;

                // Add order details if available
                if (newOrderData && newOrderData.length > 0) {
                    const latestOrder = newOrderData[0];
                    const orderNumber = latestOrder.order_id || latestOrder.transaction_number || 'N/A';
                    const totalAmount = latestOrder.total_with_delivery || latestOrder.total_price || '0.00';
                    
                    notificationContent += `
                            <div class="small">
                                <span class="text-muted">Order #${orderNumber}</span> • 
                                <strong style="color: ${textColor};">₱${totalAmount}</strong>
                            </div>
                    `;
                }

                notificationContent += `
                        </div>
                        <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                `;

                notification.innerHTML = notificationContent;
                document.body.appendChild(notification);

                // Auto-remove after 5 seconds with animation
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.style.animation = 'slideOutRight 0.3s ease-out';
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

            // Function to update notification count
            function updateNotificationCount(count) {
                const notificationCountEl = document.getElementById('notificationCount');
                if (notificationCountEl) {
                    if (count > 0) {
                        notificationCountEl.textContent = count;
                        notificationCountEl.style.display = 'inline-block';
                    } else {
                        notificationCountEl.style.display = 'none';
                    }
                }
            }

            // Function to update notification items
            function updateNotificationItems(notifications) {
                const notificationItems = document.getElementById('notificationItems');
                if (notificationItems) {
                    notificationItems.innerHTML = '';
                    
                    if (notifications.length === 0) {
                        notificationItems.innerHTML = '<div class="dropdown-item text-center">No pending orders</div>';
                    } else {
                        notifications.forEach(notification => {
                            // Determine the correct target page based on delivery method and payment method
                            let targetHref = '';
                            if (notification.delivery_method === 'pickup') {
                                targetHref = `Cashier-Pickup-Orders.php?order_id=${notification.order_id}`;
                            } else if ((notification.payment_method || '').toUpperCase() === 'COD') {
                                targetHref = `Cashier-COD-Delivery.php?order_id=${notification.order_id}`;
                            } else {
                                targetHref = `Cashier-GCASH-Delivery.php?order_id=${notification.order_id}`;
                            }

                            const item = document.createElement('a');
                            item.className = 'dropdown-item';
                            item.href = targetHref;
                            const orderIdLabel = (notification.order_id || '');
                            item.innerHTML = `
                                <div class="notification-item">
                                    ${orderIdLabel ? `<h6 class="fw-normal mb-0">Order #${orderIdLabel}</h6>` : ''}
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
                }
            }

            // Function to disconnect SSE connections (only notifications now)
            function disconnectSSE() {
                if (notificationEventSource) {
                    notificationEventSource.close();
                    notificationEventSource = null;
                }
                isNotificationConnected = false;
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
                     // Page is visible again, reconnect only notifications
                     setTimeout(() => {
                         connectNotificationSSE();
                     }, 1000);
                }
            });

            // Handle page unload
            window.addEventListener('beforeunload', function() {
                disconnectSSE();
            });

            // Start only notification SSE connection
            try {
                connectNotificationSSE();
            } catch (e) {
                console.warn('Notification SSE connection failed:', e);
            }

            // Initialize Order Status Pie Chart
            function initializeOrderStatusPieChart() {
                console.log('Initializing Order Status Pie Chart...');
                
                // Check if Chart.js is loaded
                if (typeof Chart === 'undefined') {
                    console.error('Chart.js is not loaded!');
                    document.getElementById('orderStatusPieChart').style.display = 'none';
                    document.querySelector('.col-md-8').innerHTML = '<div class="text-center text-muted p-4"><i class="fas fa-exclamation-triangle me-2"></i>Chart library not loaded</div>';
                    return;
                }
                
                const canvas = document.getElementById('orderStatusPieChart');
                if (!canvas) {
                    console.error('Canvas element not found!');
                    return;
                }
                
                const ctx = canvas.getContext('2d');
                console.log('Canvas context acquired:', ctx);
                
                <?php
                // Prepare data for Chart.js
                $chartLabels = [];
                $chartData = [];
                $chartColors = [];
                $chartStatusColors = [
                    'pending' => '#ffc107',
                    'processing' => '#17a2b8',
                    'confirmed' => '#007bff',
                    'on-ship' => '#6c757d',
                    'delivered' => '#28a745',
                    'completed' => '#343a40',
                    'canceled' => '#dc3545',
                    'cancelled' => '#dc3545'
                ];
                
                foreach ($statusCounts as $status => $count) {
                    if ($count > 0) { // Only include statuses with orders
                        $chartLabels[] = ucfirst(str_replace(['-', '_'], ' ', $status));
                        $chartData[] = $count;
                        $color = $chartStatusColors[strtolower(str_replace([' ', '_'], '-', $status))] ?? '#f8f9fa';
                        $chartColors[] = $color;
                    }
                }
                
                // If no data, create fallback
                if (empty($chartLabels)) {
                    $chartLabels = ['No Orders Yet'];
                    $chartData = [1];
                    $chartColors = ['#6c757d'];
                }
                
                echo "const chartLabels = " . json_encode($chartLabels) . ";\n";
                echo "const chartData = " . json_encode($chartData). ";\n";
                echo "const chartColors = " . json_encode($chartColors) . ";\n";
                ?>
                
                console.log('Chart data prepared:', {chartLabels, chartData, chartColors});
                
                try {
                    const orderChart = new Chart(ctx, {
                        type: 'pie',
                        data: {
                            labels: chartLabels,
                            datasets: [{
                                data: chartData,
                                backgroundColor: chartColors,
                                borderColor: '#ffffff',
                                borderWidth: 2,
                                hoverBorderWidth: 3,
                                hoverBorderColor: '#ffffff'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: true,
                            plugins: {
                                legend: {
                                    display: false // We have our custom legend
                                },
                                tooltip: {
                                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                    titleColor: '#ffffff',
                                    bodyColor: '#ffffff',
                                    borderColor: '#ffffff',
                                    borderWidth: 1,
                                    callbacks: {
                                        label: function(context) {
                                            const label = context.label || '';
                                            const position = context.dataIndex;
                                            const value = context.dataset.data[position];
                                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                            const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : '0';
                                            return label + ': ' + value + ' (' + percentage + '%)';
                                        }
                                    }
                                }
                            },
                            animation: {
                                animateScale: true,
                                animateRotate: true,
                                duration: 1000
                            },
                            cutout: 0, // No donut hole
                            layout: {
                                padding: {
                                    top: 10,
                                    bottom: 10,
                                    left: 10,
                                    right: 10
                                }
                            }
                        }
                    });
                    
                    console.log('Chart created successfully:', orderChart);
                    console.log('Chart dimensions:', canvas.width, 'x', canvas.height);
                } catch (error) {
                    console.error('Error creating pie chart:', error);
                    canvas.style.display = 'none';
                    const errorDiv = document.createElement('div');
                    errorDiv.className = 'text-center text-muted p-4';
                    errorDiv.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i>Chart Error: ' + error.message;
                    canvas.parentNode.insertBefore(errorDiv, canvas);
                }
            }

            // Create Simple HTML Bar Chart
            // Chart is now generated entirely with PHP - no JavaScript needed!
            console.log('Charts loaded via PHP');
            
            function createHTMLBarChart() {
                const chartContainer = document.getElementById('orderStatusChart');
                if (!chartContainer) {
                    console.error('Chart container not found');
                    return;
                }
                
                console.log('Chart container found');
                
                <?php
                // Prepare chart data
                $chartData = [];
                $statusData = [
                    'pending' => ['label' => 'Pending', 'color' => '#ffc107'],
                    'processing' => ['label' => 'Processing', 'color' => '#17a2b8'],
                    'completed' => ['label' => 'Completed', 'color' => '#28a745'],
                    'canceled' => ['label' => 'Canceled', 'color' => '#dc3545'],
                    'cancelled' => ['label' => 'Cancelled', 'color' => '#dc3545'],
                    'ready to ship' => ['label' => 'Ready to Ship', 'color' => '#6f42c1'],
                    'pending payment' => ['label' => 'Pending Payment', 'color' => '#fd7e14']
                ];
                
                foreach ($statusCounts as $status => $count) {
                    if ($count > 0 && isset($statusData[$status])) {
                        $chartData[] = [
                            'label' => $statusData[$status]['label'],
                            'count' => $count,
                            'color' => $statusData[$status]['color']
                        ];
                    }
                }
                
                if (empty($chartData)) {
                    $chartData = [['label' => 'No Orders', 'count' => 0, 'color' => '#6c757d']];
                }
                
                // Find max count for scaling
                $maxCount = max(array_column($chartData, 'count'));
                ?>
                
                const chartData = <?php echo json_encode($chartData); ?>;
                const maxCount = <?php echo $maxCount; ?>;
                
                console.log('Chart data:', chartData);
                
                let chartHTML = '<div style="height: 100%; display: flex; flex-direction: column; gap: 8px;">';
                
                chartData.forEach(function(item, index) {
                    const percentage = maxCount > 0 ? (item.count / maxCount) * 100 : 0;
                    
                    chartHTML += `
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div style="min-width: 80px; font-weight: bold; font-size: 12px;">${item.label}</div>
                            <div style="flex: 1; height: 20px; background: #e0e0e0; border-radius: 10px; overflow: hidden; position: relative;">
                                <div style="width: ${percentage}%; height: 100%; background: ${item.color}; transition: width 0.5s ease; border-radius: 10px;"></div>
                            </div>
                            <div style="min-width: 30px; text-align: center; font-weight: bold; font-size: 14px;">${item.count}</div>
                        </div>
                    `;
                });
                
                chartHTML += '</div>';
                
                chartContainer.innerHTML = chartHTML;
                console.log('HTML bar chart created successfully');
            }
        });
    </script>
    <script src="js/script.js"></script>
</body>
</html>
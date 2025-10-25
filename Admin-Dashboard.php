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

// Get user data for profile image/name (cjusers typically has email/role/profile_image only)
$user_id = $_SESSION['user_id'];
$user_query = $conn->prepare("SELECT email, role, profile_image FROM cjusers WHERE id = ?");
$user_query->bind_param("i", $user_id);
$user_query->execute();
$user_result = $user_query->get_result();
$user_data = $user_result ? $user_result->fetch_assoc() : null;

// Compute safe display values to avoid undefined columns on some deployments
$display_name = 'Admin';
if ($user_data) {
    if (!empty($user_data['role'])) {
        $display_name = ucfirst(strtolower($user_data['role']));
    } elseif (!empty($user_data['email'])) {
        $display_name = $user_data['email'];
    }
}

// Resolve profile image with sensible defaults
$profile_image = 'img/jandi.jpg';
if ($user_data && !empty($user_data['profile_image'])) {
    $profile_image = (strpos($user_data['profile_image'], 'uploads/') === 0)
        ? $user_data['profile_image']
        : 'uploads/' . $user_data['profile_image'];
}

// Fetch sales and revenue data from orders table
$today = date("Y-m-d");
$current_month = date("Y-m");

// Today's Orders (completed orders only) - use transactions.completed_date_transaction
$today_orders_query = "SELECT COUNT(*) as order_count, SUM(o.total_price + o.delivery_fee) as total_amount 
                       FROM orders o
                       JOIN transactions t ON t.order_id = o.id
                       WHERE DATE(t.completed_date_transaction) = CURDATE() AND o.order_status = 'completed'";
$stmt = $conn->prepare($today_orders_query);
$stmt->execute();
$today_result = $stmt->get_result();
$today_data = $today_result->fetch_assoc();
$today_orders_count = $today_data['order_count'] ?? 0;
$today_orders_amount = number_format($today_data['total_amount'] ?? 0, 2);

// Yesterday's Orders for comparison - use transactions.completed_date_transaction
$yesterday_orders_query = "SELECT COUNT(*) as order_count, SUM(o.total_price + o.delivery_fee) as total_amount 
                           FROM orders o
                           JOIN transactions t ON t.order_id = o.id
                           WHERE DATE(t.completed_date_transaction) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND o.order_status = 'completed'";
$stmt2 = $conn->prepare($yesterday_orders_query);
$stmt2->execute();
$yesterday_result = $stmt2->get_result();
$yesterday_data = $yesterday_result->fetch_assoc();
$yesterday_orders_count = $yesterday_data['order_count'] ?? 0;
$yesterday_orders_amount = $yesterday_data['total_amount'] ?? 0;

// Calculate transaction growth percentage
$transaction_growth_percentage = 0;
if ($yesterday_orders_count > 0) {
    $transaction_growth_percentage = round((($today_orders_count - $yesterday_orders_count) / $yesterday_orders_count) * 100, 2);
}

// Calculate sales growth percentage
$sales_growth_percentage = 0;
if ($yesterday_orders_amount > 0) {
    $sales_growth_percentage = round((($today_data['total_amount'] - $yesterday_orders_amount) / $yesterday_orders_amount) * 100, 2);
}

// Total Sales (all completed orders)
$total_sales_query = "SELECT SUM(total_price + delivery_fee) as total_sales 
                      FROM orders 
                      WHERE order_status = 'completed'";
$total_sales_result = $conn->query($total_sales_query);
$total_sales_data = $total_sales_result->fetch_assoc();
$total_sales = number_format($total_sales_data['total_sales'] ?? 0, 2);

// Total Revenue (all time completed orders)
$total_revenue_query = "SELECT SUM(total_price + delivery_fee) as total_revenue 
                        FROM orders 
                        WHERE order_status = 'completed'";
$total_revenue_result = $conn->query($total_revenue_query);
$total_revenue_data = $total_revenue_result->fetch_assoc();
$total_revenue = number_format($total_revenue_data['total_revenue'] ?? 0, 2);

// All Products Value (total inventory worth)
$total_products_value_query = "SELECT SUM(Price * Quantity) as total_value FROM products";
$total_products_value_result = $conn->query($total_products_value_query);
$total_products_value_data = $total_products_value_result->fetch_assoc();
$total_products_value = number_format($total_products_value_data['total_value'] ?? 0, 2);

// Total Earned (all time earnings from completed orders)
$total_earned_query = "SELECT SUM(total_price + delivery_fee) as total_earned FROM orders WHERE order_status = 'completed'";
$total_earned_result = $conn->query($total_earned_query);
$total_earned_data = $total_earned_result->fetch_assoc();
$total_earned = number_format($total_earned_data['total_earned'] ?? 0, 2);

// Compute stock level counts (Good, Low, Critical, Out of Stock)
$stock_counts_sql = "
    SELECT 
        SUM(CASE WHEN quantity >= 21 THEN 1 ELSE 0 END) AS good_count,
        SUM(CASE WHEN quantity BETWEEN 10 AND 20 THEN 1 ELSE 0 END) AS low_count,
        SUM(CASE WHEN quantity BETWEEN 2 AND 9 THEN 1 ELSE 0 END) AS critical_count,
        SUM(CASE WHEN quantity <= 1 THEN 1 ELSE 0 END) AS out_count
    FROM products
";
$stock_counts_res = $conn->query($stock_counts_sql);
$good_count = 0; $low_count = 0; $critical_count = 0; $out_count = 0;
if ($stock_counts_res && $stock_counts_res->num_rows > 0) {
    $row = $stock_counts_res->fetch_assoc();
    $good_count = (int)($row['good_count'] ?? 0);
    $low_count = (int)($row['low_count'] ?? 0);
    $critical_count = (int)($row['critical_count'] ?? 0);
    $out_count = (int)($row['out_count'] ?? 0);
}

// Calculate low stock trend (compare to last month's average)
$last_month = date("Y-m", strtotime("-1 month"));

// Get products quantity by category for pie chart
$category_quantity_query = "SELECT 
    category,
    SUM(quantity) as total_quantity,
    COUNT(*) as product_count
FROM products 
WHERE category IS NOT NULL AND category != ''
GROUP BY category 
ORDER BY total_quantity DESC";

$category_quantity_result = $conn->query($category_quantity_query);
$category_labels = [];
$category_quantities = [];
$category_colors = [];
$category_hover_colors = [];

if ($category_quantity_result && $category_quantity_result->num_rows > 0) {
    // Modern gradient colors with better contrast
    $colors = [
        ['rgba(99, 102, 241, 0.9)', 'rgba(99, 102, 241, 1)'],      // Indigo
        ['rgba(236, 72, 153, 0.9)', 'rgba(236, 72, 153, 1)'],      // Pink
        ['rgba(34, 197, 94, 0.9)', 'rgba(34, 197, 94, 1)'],        // Green
        ['rgba(251, 146, 60, 0.9)', 'rgba(251, 146, 60, 1)'],      // Orange
        ['rgba(168, 85, 247, 0.9)', 'rgba(168, 85, 247, 1)'],      // Purple
        ['rgba(59, 130, 246, 0.9)', 'rgba(59, 130, 246, 1)'],      // Blue
        ['rgba(239, 68, 68, 0.9)', 'rgba(239, 68, 68, 1)'],        // Red
        ['rgba(16, 185, 129, 0.9)', 'rgba(16, 185, 129, 1)'],      // Emerald
        ['rgba(245, 158, 11, 0.9)', 'rgba(245, 158, 11, 1)'],      // Amber
        ['rgba(139, 92, 246, 0.9)', 'rgba(139, 92, 246, 1)']       // Violet
    ];
    
    $colorIndex = 0;
    while ($row = $category_quantity_result->fetch_assoc()) {
        $category_labels[] = $row['category'];
        $category_quantities[] = (int)$row['total_quantity'];
        $category_colors[] = $colors[$colorIndex % count($colors)][0];
        $category_hover_colors[] = $colors[$colorIndex % count($colors)][1];
        $colorIndex++;
    }
}

// Get recent orders for the table
$recent_orders_query = "SELECT 
    o.id,
    o.user_id,
    o.total_price + o.delivery_fee as total_amount,
    o.order_status,
    o.order_date,
    CONCAT(u.first_name, ' ', u.last_name) as customer_name
FROM orders o
LEFT JOIN users u ON o.user_id = u.id
ORDER BY o.order_date DESC 
LIMIT 8";

$recent_orders_result = $conn->query($recent_orders_query);
$recent_orders = [];

if ($recent_orders_result && $recent_orders_result->num_rows > 0) {
    while ($row = $recent_orders_result->fetch_assoc()) {
        $customerName = trim($row['customer_name']);
        if (empty($customerName)) {
            $customerName = 'User ' . $row['user_id'];
        }
        
        $recent_orders[] = [
            'id' => $row['id'],
            'customer_name' => $customerName,
            'total_amount' => number_format($row['total_amount'], 2),
            'order_status' => $row['order_status'],
            'order_date' => date('M d, H:i', strtotime($row['order_date']))
        ];
    }
}

// Function to get weekly orders data
function getWeeklyOrdersData($conn) {
    $weekData = [];
    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    
    // Get the start of the current week (Monday)
    $monday = date('Y-m-d', strtotime('monday this week'));
    
    foreach ($days as $index => $day) {
        $currentDate = date('Y-m-d', strtotime($monday . ' +' . $index . ' days'));
        
        // Get completed orders for this day
        $sql = "SELECT COUNT(*) as order_count, SUM(o.total_price + o.delivery_fee) as total_amount 
                FROM orders o
                JOIN transactions t ON t.order_id = o.id
                WHERE DATE(t.completed_date_transaction) = ? AND o.order_status = 'completed'";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('s', $currentDate);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $completedCount = (int)$row['order_count'];
            $completedAmount = (float)($row['total_amount'] ?? 0);
            $stmt->close();
        } else {
            $completedCount = 0;
            $completedAmount = 0;
        }
        
        // Get returned orders for this day (from orders table with status 'Returned')
        $sql = "SELECT COUNT(*) as returned_count, SUM(total_price + delivery_fee) as total_returned_amount 
                FROM orders 
                WHERE DATE(order_date) = ? AND order_status = 'Returned'";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('s', $currentDate);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $returnedCount = (int)$row['returned_count'];
            $returnedAmount = (float)($row['total_returned_amount'] ?? 0);
            $stmt->close();
        } else {
            $returnedCount = 0;
            $returnedAmount = 0;
        }
        
        $weekData[] = [
            'day' => $day,
            'date' => $currentDate,
            'completed_count' => $completedCount,
            'completed_amount' => $completedAmount,
            'returned_count' => $returnedCount,
            'returned_amount' => $returnedAmount
        ];
    }
    
    return $weekData;
}
$last_month_low_stock_sql = "
    SELECT AVG(low_stock_count) as avg_low_stock 
    FROM (
        SELECT COUNT(*) as low_stock_count 
        FROM products 
        WHERE quantity BETWEEN 10 AND 20 
        AND DATE_FORMAT(NOW(), '%Y-%m') = ?
        GROUP BY DATE(NOW())
    ) as daily_counts
";
$stmt3 = $conn->prepare($last_month_low_stock_sql);
$stmt3->bind_param("s", $last_month);
$stmt3->execute();
$last_month_low_result = $stmt3->get_result();
$last_month_low_data = $last_month_low_result->fetch_assoc();
$last_month_avg_low = $last_month_low_data['avg_low_stock'] ?? 0;

$low_stock_trend_percentage = 0;
if ($last_month_avg_low > 0) {
    $low_stock_trend_percentage = round((($low_count - $last_month_avg_low) / $last_month_avg_low) * 100, 2);
}

// Fetch user activity data for the third card
// Total Shoppers (all registered users)
$total_shoppers_query = "SELECT COUNT(*) as total_shoppers FROM users";
$total_shoppers_result = $conn->query($total_shoppers_query);
$total_shoppers_data = $total_shoppers_result->fetch_assoc();
$total_shoppers_count = $total_shoppers_data['total_shoppers'] ?? 0;

// Monthly User Growth
$monthly_users_query = "SELECT COUNT(*) as monthly_users FROM users WHERE DATE_FORMAT(created_at, '%Y-%m') = ?";
$stmt2 = $conn->prepare($monthly_users_query);
$stmt2->bind_param("s", $current_month);
$stmt2->execute();
$monthly_result = $stmt2->get_result();
$monthly_data = $monthly_result->fetch_assoc();
$monthly_users = $monthly_data['monthly_users'] ?? 0;

// Calculate percentage growth (comparing this month to last month)
$last_month = date("Y-m", strtotime("-1 month"));
$last_month_users_query = "SELECT COUNT(*) as last_month_users FROM users WHERE DATE_FORMAT(created_at, '%Y-%m') = ?";
$stmt3 = $conn->prepare($last_month_users_query);
$stmt3->bind_param("s", $last_month);
$stmt3->execute();
$last_month_result = $stmt3->get_result();
$last_month_data = $last_month_result->fetch_assoc();
$last_month_users = $last_month_data['last_month_users'] ?? 0;

$user_growth_percentage = 0;
if ($last_month_users > 0) {
    $user_growth_percentage = round((($monthly_users - $last_month_users) / $last_month_users) * 100, 2);
}

$stmt->close();
$stmt2->close();
$stmt3->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Admin Dashboard</title>
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
    
    <!-- Custom CSS for responsive dashboard -->
    <style>
        /* Ensure full width containers */
        .container-fluid {
            max-width: none !important;
            width: 100% !important;
        }
        
        /* Chart container responsive height */
        .chart-container {
            min-height: 300px;
        }
        
        @media (min-width: 992px) {
            .chart-container {
                min-height: 400px;
            }
        }
        
        /* Table responsive behavior */
        .table-responsive {
            max-height: 400px;
            overflow-y: auto;
        }
        
        /* Ensure equal height cards on desktop */
        @media (min-width: 992px) {
            .h-100 {
                height: 100% !important;
            }
        }
        
        /* Mobile optimizations */
        @media (max-width: 767.98px) {
            .chart-container {
                min-height: 250px;
            }
            
            .table-responsive {
                max-height: 300px;
            }
            
            .bg-secondary.rounded {
                margin-bottom: 1rem;
            }
        }
        
        /* Tablet optimizations */
        @media (min-width: 768px) and (max-width: 991.98px) {
            .chart-container {
                min-height: 350px;
            }
        }
        
        /* Ensure proper spacing for metric cards */
        .col-sm-6.col-xl-3 {
            margin-bottom: 1rem;
        }
        
        @media (min-width: 992px) {
            .col-sm-6.col-xl-3 {
                margin-bottom: 0;
            }
        }
        
        /* Force white text in AmCharts legend */
        #chartdiv .amcharts-legend-label,
        #chartdiv .amcharts-legend-value,
        #chartdiv .amcharts-legend-text {
            color: #ffffff !important;
            fill: #ffffff !important;
        }
        
        /* Override any dark text in chart elements */
        #chartdiv text {
            fill: #ffffff !important;
        }
        
        /* Custom scrollable container for Rescue Requests */
        .rescue-requests-scrollable {
            max-height: 400px;
            overflow-y: auto;
            overflow-x: hidden;
            padding-right: 8px;
            margin-right: -8px;
        }
        
        /* Custom scrollbar styling */
        .rescue-requests-scrollable::-webkit-scrollbar {
            width: 8px;
        }
        
        .rescue-requests-scrollable::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
        }
        
        .rescue-requests-scrollable::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #ffc107 0%, #ff8c00 100%);
            border-radius: 10px;
            transition: background 0.3s ease;
        }
        
        .rescue-requests-scrollable::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #ff8c00 0%, #ffc107 100%);
        }
        
        /* Firefox scrollbar styling */
        .rescue-requests-scrollable {
            scrollbar-width: thin;
            scrollbar-color: #ffc107 rgba(255, 255, 255, 0.1);
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .rescue-requests-scrollable {
                max-height: 300px;
            }
        }
        
        @media (min-width: 1200px) {
            .rescue-requests-scrollable {
                max-height: 500px;
            }
        }
        
        /* Calendar styling to match dashboard theme */
        #calendar {
            min-height: 300px;
            background: transparent;
        }
        
        /* Main calendar widget styling */
        .bootstrap-datetimepicker-widget {
            background-color: #000000 !important; /* black background */
            border: 1px solid #333333 !important;
            border-radius: 0.375rem !important;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075) !important;
            color: #dc3545 !important; /* red font color */
            font-family: inherit !important;
        }
        
        /* Calendar header styling */
        .bootstrap-datetimepicker-widget .datepicker-days table thead tr th {
            background-color: #000000 !important; /* black background */
            color: #dc3545 !important; /* red font color */
            border: none !important;
            padding: 0.5rem !important;
            font-weight: 600 !important;
            font-size: 0.875rem !important;
        }
        
        /* Calendar body styling */
        .bootstrap-datetimepicker-widget .datepicker-days table tbody tr td {
            color: #dc3545 !important; /* red font color */
            border: none !important;
            padding: 0.5rem !important;
            font-size: 0.875rem !important;
            transition: all 0.2s ease !important;
        }
        
        /* Day hover effect - using red background */
        .bootstrap-datetimepicker-widget .datepicker-days table tbody tr td.day:hover {
            background-color: #dc3545 !important; /* red background */
            color: #ffffff !important;
            border-radius: 0.25rem !important;
        }
        
        /* Active/selected day - using danger color */
        .bootstrap-datetimepicker-widget .datepicker-days table tbody tr td.active {
            background-color: #dc3545 !important; /* Bootstrap danger */
            color: #ffffff !important;
            border-radius: 0.25rem !important;
            font-weight: 600 !important;
        }
        
        /* Today's date - using red background with white text */
        .bootstrap-datetimepicker-widget .datepicker-days table tbody tr td.today {
            background-color: #dc3545 !important; /* red background */
            color: #ffffff !important; /* white text */
            border-radius: 0.25rem !important;
            font-weight: 600 !important;
        }
        
        /* Previous/next month days - muted red color */
        .bootstrap-datetimepicker-widget .datepicker-days table tbody tr td.old,
        .bootstrap-datetimepicker-widget .datepicker-days table tbody tr td.new {
            color: #dc3545 !important; /* red color */
            opacity: 0.3 !important;
        }
        
        /* Calendar navigation buttons */
        .bootstrap-datetimepicker-widget .datepicker-days table thead tr th.prev,
        .bootstrap-datetimepicker-widget .datepicker-days table thead tr th.next {
            background-color: #000000 !important; /* black background */
            color: #dc3545 !important; /* red color */
            border-radius: 0.25rem !important;
            transition: all 0.2s ease !important;
        }
        
        .bootstrap-datetimepicker-widget .datepicker-days table thead tr th.prev:hover,
        .bootstrap-datetimepicker-widget .datepicker-days table thead tr th.next:hover {
            background-color: #dc3545 !important; /* red background on hover */
            color: #ffffff !important;
        }
        
        /* Calendar switch (month/year) */
        .bootstrap-datetimepicker-widget .datepicker-days table thead tr th.switch {
            background-color: #000000 !important; /* black background */
            color: #dc3545 !important; /* red color */
            font-weight: 600 !important;
            font-size: 1rem !important;
        }
        
        /* Calendar footer buttons */
        .bootstrap-datetimepicker-widget .datepicker-days table tfoot tr th {
            background-color: #000000 !important; /* black background */
            color: #dc3545 !important; /* red color */
            border: none !important;
            padding: 0.5rem !important;
        }
        
        .bootstrap-datetimepicker-widget .datepicker-days table tfoot tr th.today,
        .bootstrap-datetimepicker-widget .datepicker-days table tfoot tr th.clear {
            background-color: #000000 !important; /* black background */
            color: #dc3545 !important; /* red color */
            border-radius: 0.25rem !important;
            transition: all 0.2s ease !important;
        }
        
        .bootstrap-datetimepicker-widget .datepicker-days table tfoot tr th.today:hover,
        .bootstrap-datetimepicker-widget .datepicker-days table tfoot tr th.clear:hover {
            background-color: #dc3545 !important; /* red background on hover */
            color: #ffffff !important;
        }
        
        /* Hide the close button */
        .bootstrap-datetimepicker-widget .datepicker-days table tfoot tr th.close {
            display: none !important;
        }
        
        /* Ensure calendar fits within the widget */
        .bootstrap-datetimepicker-widget.dropdown-menu {
            position: relative !important;
            display: block !important;
            float: none !important;
            width: 100% !important;
            margin: 0 !important;
            border: none !important;
            box-shadow: none !important;
        }
        
        /* Calendar table styling */
        .bootstrap-datetimepicker-widget table {
            width: 100% !important;
            margin: 0 !important;
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            #calendar {
                min-height: 250px;
            }
            
            .bootstrap-datetimepicker-widget .datepicker-days table tbody tr td {
                padding: 0.25rem !important;
                font-size: 0.75rem !important;
            }
        }
        
    </style>

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
                <img src="<?= htmlspecialchars($profile_image) ?>" alt="" class="rounded-circle" style="width: 40px; height: 40px;">
                <div class="bg-success rounded-circle border border-2 border-white position-absolute end-0 bottom-0 p-1"></div>
            </div>
            <div class="ms-3">
                <h6 class="mb-0"><?= htmlspecialchars($display_name) ?></h6>
                <span><?= htmlspecialchars($display_name) ?></span>
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
                <?php include 'admin_notifications.php'; ?>
                <?php include 'admin_rescue_notifications.php'; ?>
                <?php include 'admin_user_notifications.php'; ?>
            <div class="nav-item dropdown">
                <a href="" class="nav-link dropdown-toggle" 
                data-bs-toggle="dropdown">
                <img src="<?= htmlspecialchars($profile_image) ?>" alt="" class="rounded-circle me-lg-2" 
                    alt="" style="width: 40px; height: 40px;">
                <span class="d-none d-lg-inline"><?= htmlspecialchars($display_name) ?></span>
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


         <!--Sales & Revenue start-->
         <div class="container-fluid pt-4 px-4">
             <div class="row g-4">
                 <div class="col-sm-6 col-xl-3">
                     <div class="bg-secondary rounded d-flex align-items-center
                     justify-content-between p-4" style="min-height: 200px;">
                         <i class="fa fa-chart-line fa-3x text-primary"></i>
                         <div class="ms-3">
                             <p class="mb-2">Today Success Transactions</p>
                             <h6 class="mb-0" style="font-size: 3.5rem; font-weight: bold; color: #ffffff;"><?php echo $today_orders_count; ?></h6>
                             <small class="<?php echo $transaction_growth_percentage >= 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo $transaction_growth_percentage >= 0 ? '↗' : '↘'; ?> 
                                <?php echo $transaction_growth_percentage; ?>% from yesterday
                            </small>
                             <div class="mt-2">
                                 <a href="Admin-OrderLogs.php" class="btn btn-danger btn-sm">View Report</a>
                             </div>
                         </div>
                     </div>
                 </div>
                 <div class="col-sm-6 col-xl-3">
                     <div class="bg-secondary rounded d-flex align-items-center
                     justify-content-between p-4" style="min-height: 200px;">
                         <i class="fa fa-boxes fa-3x text-primary"></i>
                         <div class="ms-3">
                             <p class="mb-2">Low Stock Items</p>
                             <h6 class="mb-0" style="font-size: 3.5rem; font-weight: bold; color: #ffffff;"><?php echo $low_count; ?></h6>
                             <small class="<?php echo $low_stock_trend_percentage >= 0 ? 'text-warning' : 'text-danger'; ?>">
                                <?php echo $low_stock_trend_percentage >= 0 ? '↗' : '↘'; ?> 
                                <?php echo $low_stock_trend_percentage; ?>% from last month
                            </small>
                             <div class="mt-2">
                                 <a href="Admin-Stockmanagement.php?status=Low%20Stock" class="btn btn-danger btn-sm">View Report</a>
                             </div>
                         </div>
                     </div>
                 </div>
                 <div class="col-sm-6 col-xl-3">
                     <div class="bg-secondary rounded d-flex align-items-center
                     justify-content-between p-4" style="min-height: 200px;">
                         <i class="fa fa-users fa-3x text-primary"></i>
                         <div class="ms-3">
                             <p class="mb-2">Total Shoppers</p>
                             <h6 class="mb-0" style="font-size: 3.5rem; font-weight: bold; color: #ffffff;"><?php echo $total_shoppers_count; ?></h6>
                             <small class="text-success">↗ All registered users</small>
                             <div class="mt-2">
                                 <a href="Admin-ManageUser.php" class="btn btn-danger btn-sm">View Report</a>
                             </div>
                         </div>
                     </div>
                 </div>
                 <div class="col-sm-6 col-xl-3">
                     <div class="bg-secondary rounded d-flex align-items-center
                     justify-content-between p-4" style="min-height: 200px;">
                         <i class="fa fa-chart-bar fa-3x text-primary"></i>
                         <div class="ms-3" style="flex: 1; text-align: center;">
                             <p class="mb-1">Today's Earned</p>
                             <h6 class="mb-0">₱<?php echo $today_orders_amount; ?></h6>
                             <small class="<?php echo $sales_growth_percentage >= 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo $sales_growth_percentage >= 0 ? '↗' : '↘'; ?> 
                                <?php echo $sales_growth_percentage; ?>% from yesterday
                            </small>
                             <div class="mt-1">
                                 <small class="text-info d-block mb-0">Inventory: ₱<?php echo $total_products_value; ?></small>
                                 <small class="text-warning d-block">Total Earned: ₱<?php echo $total_earned; ?></small>
                             </div>
                             <div class="mt-2">
                                 <a href="Admin-SalesReport.php" class="btn btn-danger btn-sm">View Report</a>
                             </div>
                         </div>
                     </div>
                 </div>
             </div>
         </div>
         <!--Sales & Revenue End-->



             <!--Sales Chart Start-->                
            <div class="container-fluid pt-4 px-4">
                <div class="row g-4">
                    <!-- Modern Products Quantity by Category Chart -->
                    <div class="col-12 col-lg-6">
                        <div class="bg-secondary text-center rounded p-4 h-100">
                            <div class="d-flex align-items-center justify-content-between mb-4">
                                <div class="d-flex align-items-center">
                                    <div class="me-3">
                                        <i class="fas fa-chart-pie text-primary fs-4"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-0 text-white fw-semibold">Inventory Distribution</h6>
                                        <small class="text-muted">Products by Category</small>
                                    </div>
                                </div>
                            <div class="btn-group">
                                <a href="Admin-Stockmanagement.php" class="btn btn-outline-light btn-sm">
                                    <i class="fas fa-external-link-alt me-1"></i>View All
                                </a>
                                <button onclick="toggleChartSize()" class="btn btn-outline-success btn-sm" id="chartSizeBtn">
                                    <i class="fas fa-expand me-1"></i>Maximize
                                </button>
                            </div>
                            </div>
                            <div class="chart-container position-relative" id="chartContainer" style="height: 600px;">
                                <div id="chartdiv" style="width: 100%; height: 100%;"></div>
                            </div>
                        </div>   
                    </div>
                    <!-- Weekly Orders Overview (Right Column) -->
                    <div class="col-12 col-lg-6">
                        <div class="bg-secondary text-center rounded p-4 h-100">
                            <div class="d-flex align-items-center justify-content-between mb-4">
                                <h6 class="mb-0 text-white">Weekly Orders Overview</h6>
                                <button class="btn btn-primary btn-sm" onclick="refreshWeeklyChart()">
                                    <i class="fas fa-sync-alt me-1"></i>Refresh
                                </button>
                            </div>
                            <div class="chart-container" style="position: relative; height: 400px;">
                                <canvas id="weeklyOrdersChart"></canvas>
                            </div>
                            <!-- Hidden data for JavaScript -->
                            <div id="weeklyOrdersData" style="display: none;">
                                <?php 
                                $weeklyData = getWeeklyOrdersData($conn);
                                echo htmlspecialchars(json_encode($weeklyData));
                                ?>
                            </div>
                        </div>
                    </div>
                    <!-- Recent Orders Table -->
                    
                </div>
            </div>
<!--Sales Chart End-->     

 

<!--widget Start-->
<div class="container-fluid pt-4 px-4">
    <div class="row g-4">
        <div class="col-sm-12 col-md-6 col-xl-4">
            <div class="h-100 bg-secondary rounded p-4">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h6 class="mb-0">Rescue Request</h6>
                    <div class="d-flex align-items-center">
                        <a href="Admin-RescueLogs.php" class="text-white">Show All</a>
                    </div>
                </div>
                
                <!-- Help Request Statistics -->
                <div class="row g-2 mb-3">
                    <div class="col-4">
                        <div class="text-center p-2 rounded" style="background: rgba(255, 193, 7, 0.1); border: 1px solid rgba(255, 193, 7, 0.3);">
                            <small class="text-warning d-block">Pending</small>
                            <span class="text-white fw-bold" id="pendingCount">0</span>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="text-center p-2 rounded" style="background: rgba(13, 110, 253, 0.1); border: 1px solid rgba(13, 110, 253, 0.3);">
                            <small class="text-info d-block">In Progress</small>
                            <span class="text-white fw-bold" id="inProgressCount">0</span>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="text-center p-2 rounded" style="background: rgba(40, 167, 69, 0.1); border: 1px solid rgba(40, 167, 69, 0.3);">
                            <small class="text-success d-block">Completed</small>
                            <span class="text-white fw-bold" id="completedCount">0</span>
                        </div>
                    </div>
                </div>
                
                <!-- Help Requests List - Scrollable -->
                <div id="helpRequestsList" class="rescue-requests-scrollable">
                    <!-- Dynamic content will be loaded here -->
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-spinner fa-spin fa-2x mb-2"></i>
                        <p class="mb-0">Loading rescue requests...</p>
                    </div>
                </div>
                
                <!-- No Requests Message (hidden by default) -->
                <div id="noRequestsMessage" class="text-center text-muted py-4" style="display: none;">
                    <i class="fas fa-check-circle fa-2x mb-2 text-success"></i>
                    <p class="mb-0">No pending rescue requests</p>
                </div>
            </div>
        </div>
        <div class="col-sm-12 col-md-6 col-xl-4">
            <div class="h-100 bg-secondary rounded p-4">
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <h6 class="mb-0">Calendar</h6>
                    <a href="#"></a>
                </div>
                <div id="calendar"></div>
            </div>
        </div>
        <div class="col-sm-12 col-md-6 col-xl-4">
            <div class="h-100 bg-secondary rounded p-4">
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <h6 class="mb-0">Low Stock Alerts</h6>
                </div>
                <?php
                // Fetch top 8 lowest-stock products
                $lowStockItems = [];
                if (isset($conn) && $conn instanceof mysqli) {
                    $lowSql = "SELECT ProductID, ProductName, Quantity FROM products ORDER BY quantity ASC, ProductID DESC LIMIT 8";
                    if ($lowRes = $conn->query($lowSql)) {
                        while ($row = $lowRes->fetch_assoc()) { $lowStockItems[] = $row; }
                        $lowRes->close();
                    }
                }
                ?>
                <?php if (empty($lowStockItems)): ?>
                    <div class="text-center text-muted py-3">
                        <i class="fas fa-check-circle text-success me-2"></i>No low stock items
                    </div>
                <?php else: ?>
                    <div class="low-stock-list" style="max-height: 320px; overflow: auto;">
                    <?php foreach ($lowStockItems as $item): 
                        $qty = (int)$item['Quantity'];
                        $badge = 'bg-success';
                        if ($qty <= 1) { $badge = 'bg-danger'; }
                        elseif ($qty <= 9) { $badge = 'bg-warning text-dark'; }
                        elseif ($qty <= 20) { $badge = 'bg-info'; }
                    ?>
                    <div class="d-flex align-items-center border-bottom py-2">
                        <div class="w-100">
                            <div class="d-flex w-100 justify-content-between align-items-center">
                                <span class="text-truncate" title="<?php echo htmlspecialchars($item['ProductName']); ?>"><?php echo htmlspecialchars($item['ProductName']); ?></span>
                                <span class="badge <?php echo $badge; ?> ms-2">Qty: <?php echo $qty; ?></span>
                            </div>
                            <div class="small text-muted mt-1">
                                <a href="Admin-Stockmanagement.php?q=<?php echo urlencode($item['ProductName']); ?>" class="text-decoration-none">View in stock</a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="mt-3">
                    <a href="Admin-Stockmanagement.php" class="btn btn-sm btn-primary w-100">
                        <i class="fa fa-boxes me-2"></i>Go to Stock Management
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
<!--Widgets End-->

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
   <script src="js/notification-sound.js"></script>
   <script src="lib/easing/easing.min.js"></script>
   <script src="lib/waypoints/waypoints.min.js"></script>
   <script src="lib/owlcarousel/owl.carousel.min.js"></script>
   <script src="lib/tempusdominus/js/moment.min.js"></script>
   <script src="lib/tempusdominus/js/moment-timezone.min.js"></script>
   <script src="lib/tempusdominus/js/tempusdominus-bootstrap-4.min.js"></script>
   
   <!-- Calendar Initialization -->
   <script>
   $(document).ready(function() {
       // Initialize calendar
       $('#calendar').datetimepicker({
           inline: true,
           format: 'L',
           sideBySide: false,
           icons: {
               time: 'fa fa-clock',
               date: 'fa fa-calendar',
               up: 'fa fa-arrow-up',
               down: 'fa fa-arrow-down',
               previous: 'fa fa-chevron-left',
               next: 'fa fa-chevron-right',
               today: 'fa fa-calendar-check',
               clear: 'fa fa-trash',
               close: 'fa fa-times'
           },
           buttons: {
               showToday: true,
               showClear: true,
               showClose: false
           },
           dayViewHeaderFormat: 'MMMM YYYY',
           calendarWeeks: true,
           locale: 'en'
        });
    });
    </script>
    
    
    <!-- AmCharts 4 Resources -->
   <script src="https://cdn.amcharts.com/lib/4/core.js"></script>
   <script src="https://cdn.amcharts.com/lib/4/charts.js"></script>
   <script src="https://cdn.amcharts.com/lib/4/themes/animated.js"></script>
   
   

    <!-- Template Javascript -->
    <script src="js/main.js">
    </script>

     <!-- Custom JavaScript for Dashboard -->
     <script>
         
         // Function to get product image (fallback for products without ImagePath in database)
         function getProductImage(productName, category) {
             // Fallback to category-based images when database doesn't have ImagePath
             var categoryImages = {
                 'Bolts': 'uploads/hexagon.jpg',
                 'Oil': 'uploads/oil.jfif',
                 'Motor Chains': 'uploads/RK.jpg',
                 'Accessories': 'uploads/HandGrip.png',
                 'Fuel Components': 'uploads/Gasket.jpg',
                 'Electrical Components': 'uploads/Digital Speedometer.jpg',
                 'Batteries': 'uploads/Motolite.jpg',
                 'Tires': 'uploads/tire.webp',
                 'Brakes': 'uploads/brake-pad.jpg',
                 'Engine Components': 'uploads/XRM 125 cylinder Head.jpg',
                 'Exhaust': 'uploads/Exhaust.png'
             };
             
             return categoryImages[category] || 'uploads/product/default.png';
         }

        // AmCharts 4 3D Pie Chart for Products Quantity by Category
        <?php if (!empty($category_labels)): ?>
        am4core.ready(function() {
            try {
                // Themes begin
                am4core.useTheme(am4themes_animated);
                // Themes end

                // Create chart instance
                var chart = am4core.create("chartdiv", am4charts.PieChart3D);
                chart.hiddenState.properties.opacity = 0; // this creates initial fade-in

                // Add legend with proper text color configuration
                chart.legend = new am4charts.Legend();
                chart.legend.position = "bottom";
                chart.legend.contentAlign = "center";
                chart.legend.marginTop = 10;
                chart.legend.marginBottom = 10;
                
                // Configure legend labels with white text - larger for better readability
                chart.legend.labels.template.fill = am4core.color("#ffffff");
                chart.legend.labels.template.fontSize = 16;
                chart.legend.labels.template.fontWeight = "600";
                chart.legend.labels.template.fontFamily = "'Inter', 'Segoe UI', sans-serif";
                
                // Disable automatic value labels to prevent double percentages
                chart.legend.valueLabels.template.disabled = true;
                
                // Configure legend markers to be larger
                chart.legend.markers.template.width = 20;
                chart.legend.markers.template.height = 20;
                chart.legend.markers.template.marginRight = 12;

                // Prepare data from PHP
                var chartData = [];
                <?php 
                if (!empty($category_labels) && !empty($category_quantities) && !empty($category_colors)) {
                    for ($i = 0; $i < count($category_labels); $i++) {
                        if (isset($category_labels[$i]) && isset($category_quantities[$i]) && isset($category_colors[$i])) {
                            echo "chartData.push({
                                category: " . json_encode($category_labels[$i]) . ",
                                quantity: " . (int)$category_quantities[$i] . ",
                                color: am4core.color(" . json_encode($category_colors[$i]) . ")
                            });";
                        }
                    }
                }
                ?>
                
                // Validate chart data
                if (chartData.length === 0) {
                    chartData = [{
                        category: "No Data",
                        quantity: 1,
                        color: am4core.color("#6b7280")
                    }];
                }
                
                console.log("Chart data:", chartData); // Debug log
                chart.data = chartData;

                // Create series
                var series = chart.series.push(new am4charts.PieSeries3D());
                series.dataFields.value = "quantity";
                series.dataFields.category = "category";
                
                // Configure series appearance
                series.slices.template.stroke = am4core.color("#1f2937");
                series.slices.template.strokeWidth = 2;
                series.slices.template.strokeOpacity = 0.8;
                
                // Configure colors
                series.slices.template.propertyFields.fill = "color";
                
                // Configure labels
                series.labels.template.fill = am4core.color("#f9fafb");
                series.labels.template.fontSize = 12;
                series.labels.template.fontWeight = "600";
                series.labels.template.fontFamily = "'Inter', 'Segoe UI', sans-serif";
                
                // Configure tooltips
                series.tooltip.label.fill = am4core.color("#f3f4f6");
                series.tooltip.label.fontSize = 13;
                series.tooltip.label.fontFamily = "'Inter', 'Segoe UI', sans-serif";
                series.tooltip.background.fill = am4core.color("#1f2937");
                series.tooltip.background.stroke = am4core.color("rgba(255, 255, 255, 0.1)");
                series.tooltip.background.strokeWidth = 1;
                series.tooltip.background.cornerRadius = 8;
                
                // Custom tooltip content
                series.tooltip.label.adapter.add("text", function(labelText, target) {
                    var dataItem = target.tooltipDataItem;
                    var category = dataItem.category || "Unknown";
                    var value = dataItem.value || 0;
                    var total = chartData.reduce((sum, item) => sum + (item.quantity || 0), 0);
                    var percentage = total > 0 ? ((value / total) * 100).toFixed(1) : "0.0";
                    
                    return "📦 " + category + "\n" +
                           "Quantity: " + (value || 0).toLocaleString() + " items\n" +
                           "Share: " + percentage + "% of total inventory\n" +
                           "Click to see products in this category";
                });

                // Add click event for drill-down functionality
                series.slices.template.events.on("hit", function(ev) {
                    var dataItem = ev.target.dataItem;
                    var category = dataItem.category;
                    console.log("Clicked on category:", category);
                    
                    // Show loading state
                    showDrillDownLoading();
                    
                    // Fetch products for this category
                    fetchProductsByCategory(category);
                });

                // Configure 3D settings
                chart.angle = 15;
                chart.depth = 30;
                
                // Configure chart container
                chart.padding(10, 10, 10, 10);
                chart.radius = am4core.percent(95);
                
                // Add responsive behavior
                chart.responsive.enabled = true;
                chart.responsive.useDefault = false;
                chart.responsive.rules.push({
                    relevant: am4core.ResponsiveBreakpoints.widthS,
                    state: function(target, stateId) {
                        if (target instanceof am4charts.PieChart3D) {
                            target.radius = am4core.percent(85);
                            target.legend.position = "bottom";
                            target.legend.labels.template.fontSize = 14;
                            target.legend.valueLabels.template.fontSize = 14;
                            target.legend.markers.template.width = 18;
                            target.legend.markers.template.height = 18;
                        }
                    }
                });

                // Override legend labels to show category names with percentages
                chart.legend.labels.template.adapter.add("text", function(labelText, target) {
                    var dataItem = target.dataItem;
                    if (dataItem && dataItem.category) {
                        var category = dataItem.category;
                        var value = dataItem.value || 0;
                        var total = chartData.reduce((sum, item) => sum + (item.quantity || 0), 0);
                        var percentage = total > 0 ? ((value / total) * 100).toFixed(1) : "0.0";
                        return category + " " + percentage + "%";
                    }
                    return labelText;
                });
                
                // Force legend to use custom text formatting
                chart.legend.useDefaultMarker = false;

                            console.log("Chart created successfully"); // Debug log
                            
                            // Store chart reference for resize functionality
                            window.currentChart = chart;

            } catch (error) {
                console.error("Error creating AmCharts 3D pie chart:", error);
                // Fallback: show error message in chart container
                var chartContainer = document.getElementById("chartdiv");
                if (chartContainer) {
                    chartContainer.innerHTML = '<div class="text-center text-muted p-4"><i class="fas fa-exclamation-triangle me-2"></i>Chart could not be loaded. Please refresh the page.<br><small>Error: ' + error.message + '</small></div>';
                }
            }

        }); // end am4core.ready()
        <?php else: ?>
        // No data available - show message and create test chart
        document.addEventListener('DOMContentLoaded', function() {
            var chartContainer = document.getElementById("chartdiv");
            if (chartContainer) {
                // Try to create a test chart with sample data
                createTestChart();
            }
        });

        function createTestChart() {
            try {
                var chartContainer = document.getElementById("chartdiv");
                if (!chartContainer) {
                    console.error("Chart container not found for test chart");
                    return;
                }
                
                // Clear the container
                chartContainer.innerHTML = '<canvas id="testPieChart" width="400" height="400"></canvas>';
                var canvas = document.getElementById("testPieChart");
                if (!canvas) {
                    console.error("Test chart canvas element not created");
                    return;
                }
                var ctx = canvas.getContext('2d');
                
                // Create test data
                new Chart(ctx, {
                    type: 'pie',
                    data: {
                        labels: ["No Categories Found", "Add Products"],
                        datasets: [{
                            data: [1, 1],
                            backgroundColor: ["#6b7280", "#ef4444"],
                            borderColor: '#1f2937',
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    color: 'white',
                                    font: {
                                        size: 12
                                    }
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                titleColor: 'white',
                                bodyColor: 'white'
                            }
                        }
                    }
                });
                
                console.log("Test chart created successfully");
            } catch (error) {
                console.error("Error creating test chart:", error);
                var chartContainer = document.getElementById("chartdiv");
                if (chartContainer) {
                    chartContainer.innerHTML = '<div class="text-center text-muted p-4"><i class="fas fa-chart-pie me-2"></i>No product categories found.<br><small>Add products with categories to see the distribution chart.</small><br><small>Error: ' + error.message + '</small></div>';
                }
            }
        }
        <?php endif; ?>

        // Fallback Chart.js implementation if AmCharts fails
        function createFallbackPieChart() {
            try {
                var chartContainer = document.getElementById("chartdiv");
                if (!chartContainer) {
                    console.error("Chart container not found");
                    return;
                }
                
                // Clear the container
                chartContainer.innerHTML = '<canvas id="fallbackPieChart" width="400" height="400"></canvas>';
                var canvas = document.getElementById("fallbackPieChart");
                if (!canvas) {
                    console.error("Canvas element not created");
                    return;
                }
                var ctx = canvas.getContext('2d');
                
                // Prepare data
                var chartData = [];
                var chartLabels = [];
                var chartColors = [];
                
                <?php 
                if (!empty($category_labels) && !empty($category_quantities) && !empty($category_colors)) {
                    for ($i = 0; $i < count($category_labels); $i++) {
                        if (isset($category_labels[$i]) && isset($category_quantities[$i]) && isset($category_colors[$i])) {
                            echo "chartLabels.push(" . json_encode($category_labels[$i]) . ");";
                            echo "chartData.push(" . (int)$category_quantities[$i] . ");";
                            echo "chartColors.push(" . json_encode($category_colors[$i]) . ");";
                        }
                    }
                }
                ?>
                
                if (chartData.length === 0) {
                    chartLabels = ["No Data"];
                    chartData = [1];
                    chartColors = ["#6b7280"];
                }
                
                var fallbackChart = new Chart(ctx, {
                    type: 'pie',
                    data: {
                        labels: chartLabels,
                        datasets: [{
                            data: chartData,
                            backgroundColor: chartColors,
                            borderColor: '#1f2937',
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    color: 'white',
                                    font: {
                                        size: 12
                                    }
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                titleColor: 'white',
                                bodyColor: 'white',
                                callbacks: {
                                    label: function(context) {
                                        var label = context.label || '';
                                        var value = context.parsed || 0;
                                        var total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        var percentage = total > 0 ? ((value / total) * 100).toFixed(1) : "0.0";
                                        return label + ': ' + value + ' items (' + percentage + '%)\nClick to see products in this category';
                                    }
                                }
                            }
                        },
                        onClick: function(event, elements) {
                            if (elements.length > 0) {
                                var elementIndex = elements[0].index;
                                var category = chartLabels[elementIndex];
                                console.log("Clicked on category:", category);
                                
                                // Show loading state
                                showDrillDownLoading();
                                
                                // Fetch products for this category
                                fetchProductsByCategory(category);
                            }
                        }
                    }
                });
                
                console.log("Fallback Chart.js pie chart created successfully");
            } catch (error) {
                console.error("Error creating fallback chart:", error);
                var chartContainer = document.getElementById("chartdiv");
                if (chartContainer) {
                    chartContainer.innerHTML = '<div class="text-center text-muted p-4"><i class="fas fa-exclamation-triangle me-2"></i>Chart could not be loaded.<br><small>Please refresh the page or check your internet connection.</small></div>';
                }
            }
        }

        // Check if AmCharts loaded successfully, if not use fallback
        document.addEventListener('DOMContentLoaded', function() {
            console.log("DOM loaded, initializing chart...");
            
            // Check AmCharts status
            setTimeout(function() {
                var amchartsStatus = document.getElementById("amchartsStatus");
                if (amchartsStatus) {
                    if (typeof am4core !== 'undefined') {
                        amchartsStatus.textContent = "Loaded ✓";
                        amchartsStatus.style.color = "green";
                    } else {
                        amchartsStatus.textContent = "Failed ✗";
                        amchartsStatus.style.color = "red";
                    }
                }
            }, 1000);
            
            // Try to create a simple chart immediately
            setTimeout(function() {
                console.log("Attempting to create chart...");
                var chartContainer = document.getElementById("chartdiv");
                if (chartContainer) {
                    console.log("Chart container found, checking content...");
                    if (chartContainer.innerHTML.trim() === '') {
                        console.log("Chart container is empty, creating fallback chart");
                        createFallbackPieChart();
                    } else {
                        console.log("Chart container has content:", chartContainer.innerHTML.substring(0, 100));
                    }
                } else {
                    console.error("Chart container not found!");
                }
            }, 2000);
            
            // Final fallback after 5 seconds
            setTimeout(function() {
                var chartContainer = document.getElementById("chartdiv");
                if (chartContainer && chartContainer.innerHTML.trim() === '') {
                    console.log("Final fallback: Creating simple test chart");
                    createTestChart();
                }
            }, 5000);
        });

        // Chart maximize/minimize functionality
        let isMaximized = false;
        let originalChartContainer = null;
        let originalChartDiv = null;

        window.toggleChartSize = function() {
            var chartContainer = document.getElementById("chartContainer");
            var chartSizeBtn = document.getElementById("chartSizeBtn");
            var chartDiv = document.getElementById("chartdiv");
            
            if (!chartContainer || !chartSizeBtn) return;
            
            if (!isMaximized) {
                // Store original container reference
                originalChartContainer = chartContainer;
                originalChartDiv = chartDiv.cloneNode(true);
                
                // Create fullscreen overlay
                var overlay = document.createElement('div');
                overlay.id = 'chartOverlay';
                overlay.style.cssText = `
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100vw;
                    height: 100vh;
                    background: rgba(0, 0, 0, 0.95);
                    z-index: 9999;
                    display: flex;
                    flex-direction: column;
                    padding: 20px;
                `;
                
                // Create header with title and buttons
                var header = document.createElement('div');
                header.style.cssText = `
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-bottom: 20px;
                    color: white;
                `;
                header.innerHTML = `
                    <div>
                        <h4 class="mb-0 text-white fw-semibold">Inventory Distribution</h4>
                        <small class="text-muted">Products by Category - Full View with AI Insights</small>
                    </div>
                    <button onclick="toggleChartSize()" class="btn btn-outline-light btn-sm">
                        <i class="fas fa-compress me-1"></i>Minimize
                    </button>
                `;
                
                // Create chart container for fullscreen with side-by-side layout
                var fullscreenContainer = document.createElement('div');
                fullscreenContainer.id = 'fullscreenChartContainer';
                fullscreenContainer.style.cssText = `
                    flex: 1;
                    position: relative;
                    background: #1f2937;
                    border-radius: 10px;
                    padding: 20px;
                    display: flex;
                    gap: 20px;
                `;
                
                // Create chart area (left side)
                var chartArea = document.createElement('div');
                chartArea.style.cssText = `
                    flex: 2;
                    position: relative;
                `;
                
                // Create a new chart div for fullscreen
                var fullscreenChartDiv = document.createElement('div');
                fullscreenChartDiv.id = 'fullscreenChartDiv';
                fullscreenChartDiv.style.cssText = 'width: 100%; height: 100%;';
                
                // Create AI Insight Panel (right side - sticky note style)
                var aiInsightPanel = document.createElement('div');
                aiInsightPanel.id = 'aiInsightPanel';
                aiInsightPanel.style.cssText = `
                    flex: 1;
                    min-width: 600px;
                    max-width: 800px;
                    position: sticky;
                    top: 20px;
                    height: fit-content;
                `;
                aiInsightPanel.innerHTML = `
                    <div class="card bg-gradient-primary border-0 shadow-lg" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                        <div class="card-header bg-transparent border-0 pb-0">
                            <div class="d-flex align-items-center">
                                <div class="me-2">
                                    <i class="fas fa-robot text-white" style="font-size: 1.2rem;"></i>
                                </div>
                                <h6 class="mb-0 text-white fw-bold">AI Insights</h6>
                            </div>
                        </div>
                            <div class="card-body pt-3">
                                <div id="aiInsightContent" class="text-white" style="font-size: 1.3rem; line-height: 1.7;">
                                    <div class="text-center">
                                        <i class="fas fa-mouse-pointer me-2"></i>
                                        Click on any category to get AI insights
                                    </div>
                                </div>
                            </div>
                    </div>
                `;
                
                chartArea.appendChild(fullscreenChartDiv);
                fullscreenContainer.appendChild(chartArea);
                fullscreenContainer.appendChild(aiInsightPanel);
                overlay.appendChild(header);
                overlay.appendChild(fullscreenContainer);
                document.body.appendChild(overlay);
                
                // Update button
                chartSizeBtn.innerHTML = '<i class="fas fa-compress me-1"></i>Minimize';
                chartSizeBtn.className = 'btn btn-outline-warning btn-sm';
                
                isMaximized = true;
                
                // Create a new chart in fullscreen mode with full functionality
                setTimeout(() => {
                    createFullscreenChart();
                }, 100);
                
            } else {
                // Minimize chart - restore original state
                var overlay = document.getElementById('chartOverlay');
                if (overlay) {
                    // Remove overlay
                    document.body.removeChild(overlay);
                }
                
                // Restore original chart container content
                if (originalChartContainer && originalChartDiv) {
                    originalChartContainer.innerHTML = '<div id="chartdiv" style="width: 100%; height: 100%;"></div>';
                    
                    // Recreate the chart in the original container
                    setTimeout(() => {
                        recreateMainChart();
                    }, 100);
                }
                
                // Update button
                chartSizeBtn.innerHTML = '<i class="fas fa-expand me-1"></i>Maximize';
                chartSizeBtn.className = 'btn btn-outline-success btn-sm';
                
                isMaximized = false;
            }
        };

        // Create fullscreen chart with full functionality
        function createFullscreenChart() {
            try {
                console.log("Creating fullscreen chart with full functionality");
                
                // Check if AmCharts is available
                if (typeof am4core !== 'undefined' && typeof am4charts !== 'undefined') {
                    // Use AmCharts ready to ensure proper initialization
                    am4core.ready(function() {
                        try {
                            // Themes begin
                            am4core.useTheme(am4themes_animated);
                            // Themes end

                            // Create chart instance for fullscreen
                            var fullscreenChart = am4core.create("fullscreenChartDiv", am4charts.PieChart3D);
                            fullscreenChart.hiddenState.properties.opacity = 0;

                            // Add legend with two-column layout for fullscreen
                            fullscreenChart.legend = new am4charts.Legend();
                            fullscreenChart.legend.position = "bottom";
                            fullscreenChart.legend.contentAlign = "center";
                            fullscreenChart.legend.marginTop = 20;
                            fullscreenChart.legend.marginBottom = 20;
                            fullscreenChart.legend.marginLeft = 20;
                            fullscreenChart.legend.marginRight = 20;
                            
                            // Configure legend for two-column layout
                            fullscreenChart.legend.maxWidth = am4core.percent(95);
                            fullscreenChart.legend.maxHeight = am4core.percent(25);
                            
                            // Configure legend labels with larger white text for fullscreen
                            fullscreenChart.legend.labels.template.fill = am4core.color("#ffffff");
                            fullscreenChart.legend.labels.template.fontSize = 18;
                            fullscreenChart.legend.labels.template.fontWeight = "600";
                            fullscreenChart.legend.labels.template.fontFamily = "'Inter', 'Segoe UI', sans-serif";
                            fullscreenChart.legend.labels.template.paddingTop = 5;
                            fullscreenChart.legend.labels.template.paddingBottom = 5;
                            
                            // Disable automatic value labels to prevent double percentages
                            fullscreenChart.legend.valueLabels.template.disabled = true;
                            
                            // Configure legend markers (colored squares) to be larger
                            fullscreenChart.legend.markers.template.width = 20;
                            fullscreenChart.legend.markers.template.height = 20;
                            fullscreenChart.legend.markers.template.marginRight = 10;
                            
                            // Create two-column layout by adjusting item spacing
                            fullscreenChart.legend.itemContainers.template.paddingTop = 8;
                            fullscreenChart.legend.itemContainers.template.paddingBottom = 8;
                            fullscreenChart.legend.itemContainers.template.paddingLeft = 15;
                            fullscreenChart.legend.itemContainers.template.paddingRight = 15;

                            // Prepare data from PHP (same as original)
                            var chartData = [];
                            <?php 
                            if (!empty($category_labels) && !empty($category_quantities) && !empty($category_colors)) {
                                for ($i = 0; $i < count($category_labels); $i++) {
                                    if (isset($category_labels[$i]) && isset($category_quantities[$i]) && isset($category_colors[$i])) {
                                        echo "chartData.push({
                                            category: " . json_encode($category_labels[$i]) . ",
                                            quantity: " . (int)$category_quantities[$i] . ",
                                            color: am4core.color(" . json_encode($category_colors[$i]) . ")
                                        });";
                                    }
                                }
                            }
                            ?>
                            
                            // Validate chart data
                            if (chartData.length === 0) {
                                chartData = [{
                                    category: "No Data",
                                    quantity: 1,
                                    color: am4core.color("#6b7280")
                                }];
                            }
                            
                            fullscreenChart.data = chartData;

                            // Create series
                            var series = fullscreenChart.series.push(new am4charts.PieSeries3D());
                            series.dataFields.value = "quantity";
                            series.dataFields.category = "category";
                            
                            // Configure series appearance
                            series.slices.template.stroke = am4core.color("#1f2937");
                            series.slices.template.strokeWidth = 2;
                            series.slices.template.strokeOpacity = 0.8;
                            
                            // Configure colors
                            series.slices.template.propertyFields.fill = "color";
                            
                            // Hide labels and lines for cleaner fullscreen view
                            series.labels.template.disabled = true;
                            series.ticks.template.disabled = true;
                            
                            // Configure tooltips - larger for fullscreen
                            series.tooltip.label.fill = am4core.color("#f3f4f6");
                            series.tooltip.label.fontSize = 15;
                            series.tooltip.label.fontFamily = "'Inter', 'Segoe UI', sans-serif";
                            series.tooltip.background.fill = am4core.color("#1f2937");
                            series.tooltip.background.stroke = am4core.color("rgba(255, 255, 255, 0.1)");
                            series.tooltip.background.strokeWidth = 1;
                            series.tooltip.background.cornerRadius = 8;
                            
                            // Custom tooltip content
                            series.tooltip.label.adapter.add("text", function(labelText, target) {
                                var dataItem = target.tooltipDataItem;
                                var category = dataItem.category || "Unknown";
                                var value = dataItem.value || 0;
                                var total = chartData.reduce((sum, item) => sum + (item.quantity || 0), 0);
                                var percentage = total > 0 ? ((value / total) * 100).toFixed(1) : "0.0";
                                
                                return "📦 " + category + "\n" +
                                       "Quantity: " + (value || 0).toLocaleString() + " items\n" +
                                       "Share: " + percentage + "% of total inventory";
                            });

                            // Add click event for AI insights and drill-down functionality
                            series.slices.template.events.on("hit", function(ev) {
                                var dataItem = ev.target.dataItem;
                                var category = dataItem.category;
                                var quantity = dataItem.value;
                                console.log("Clicked on category in fullscreen:", category);
                                
                                // Show AI insights for the clicked category
                                showCategoryInsights(category, quantity, chartData);
                                
                                // Also show loading state for drill-down
                                showDrillDownLoading();
                                
                                // Fetch products for this category
                                fetchProductsByCategory(category);
                            });

                            // Configure 3D settings
                            fullscreenChart.angle = 15;
                            fullscreenChart.depth = 30;
                            fullscreenChart.padding(20, 20, 20, 20);
                            fullscreenChart.radius = am4core.percent(90);
                            
                            // Add responsive behavior
                            fullscreenChart.responsive.enabled = true;
                            fullscreenChart.responsive.useDefault = false;
                            fullscreenChart.responsive.rules.push({
                                relevant: am4core.ResponsiveBreakpoints.widthS,
                                state: function(target, stateId) {
                                    if (target instanceof am4charts.PieChart3D) {
                                        target.radius = am4core.percent(80);
                                        target.legend.position = "bottom";
                                        // Keep larger fonts even on smaller screens for readability
                                        target.legend.labels.template.fontSize = 16;
                                        target.legend.valueLabels.template.fontSize = 16;
                                        target.legend.markers.template.width = 18;
                                        target.legend.markers.template.height = 18;
                                    }
                                }
                            });

                            // Override legend labels to show category names with percentages
                            fullscreenChart.legend.labels.template.adapter.add("text", function(labelText, target) {
                                var dataItem = target.dataItem;
                                if (dataItem && dataItem.category) {
                                    var category = dataItem.category;
                                    var value = dataItem.value || 0;
                                    var total = chartData.reduce((sum, item) => sum + (item.quantity || 0), 0);
                                    var percentage = total > 0 ? ((value / total) * 100).toFixed(1) : "0.0";
                                    return category + " " + percentage + "%";
                                }
                                return labelText;
                            });
                            
                            // Force legend to use custom text formatting
                            fullscreenChart.legend.useDefaultMarker = false;

                            // Store chart reference for fullscreen
                            window.currentChart = fullscreenChart;
                            console.log("Fullscreen chart created successfully with full functionality");
                            
                            // Show initial category insights
                            setTimeout(() => {
                                showInitialCategoryInsights();
                            }, 500);
                            
                        } catch (error) {
                            console.error("Error creating fullscreen AmCharts chart:", error);
                            // Fallback to Chart.js for fullscreen
                            createFullscreenFallbackChart();
                        }
                    });
                } else {
                    console.log("AmCharts not available, using Chart.js fallback for fullscreen");
                    createFullscreenFallbackChart();
                }
            } catch (error) {
                console.error("Error creating fullscreen chart:", error);
                createFullscreenFallbackChart();
            }
        }

        // Create fullscreen fallback chart with Chart.js
        function createFullscreenFallbackChart() {
            try {
                var fullscreenContainer = document.getElementById("fullscreenChartDiv");
                if (!fullscreenContainer) {
                    console.error("Fullscreen chart container not found");
                    return;
                }
                
                // Clear the container
                fullscreenContainer.innerHTML = '<canvas id="fullscreenFallbackChart" width="800" height="600"></canvas>';
                var canvas = document.getElementById("fullscreenFallbackChart");
                if (!canvas) {
                    console.error("Fullscreen canvas element not created");
                    return;
                }
                var ctx = canvas.getContext('2d');
                
                // Prepare data from PHP
                var chartLabels = [];
                var chartData = [];
                var chartColors = [];
                
                <?php 
                if (!empty($category_labels) && !empty($category_quantities) && !empty($category_colors)) {
                    for ($i = 0; $i < count($category_labels); $i++) {
                        if (isset($category_labels[$i]) && isset($category_quantities[$i]) && isset($category_colors[$i])) {
                            echo "chartLabels.push(" . json_encode($category_labels[$i]) . ");";
                            echo "chartData.push(" . (int)$category_quantities[$i] . ");";
                            echo "chartColors.push(" . json_encode($category_colors[$i]) . ");";
                        }
                    }
                }
                ?>
                
                // Validate data
                if (chartLabels.length === 0) {
                    chartLabels = ["No Data"];
                    chartData = [1];
                    chartColors = ["#6b7280"];
                }
                
                var fullscreenFallbackChart = new Chart(ctx, {
                    type: 'pie',
                    data: {
                        labels: chartLabels,
                        datasets: [{
                            data: chartData,
                            backgroundColor: chartColors,
                            borderColor: '#1f2937',
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    color: 'white',
                                    font: {
                                        size: 18,
                                        weight: '600'
                                    },
                                    padding: 15,
                                    usePointStyle: true,
                                    pointStyle: 'rect'
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                titleColor: 'white',
                                bodyColor: 'white',
                                callbacks: {
                                    label: function(context) {
                                        var label = context.label || '';
                                        var value = context.parsed || 0;
                                        var total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        var percentage = total > 0 ? ((value / total) * 100).toFixed(1) : "0.0";
                                        return label + ': ' + value + ' items (' + percentage + '%)\nClick to see products in this category';
                                    }
                                }
                            }
                        },
                        onClick: function(event, elements) {
                            if (elements.length > 0) {
                                var elementIndex = elements[0].index;
                                var category = chartLabels[elementIndex];
                                var quantity = chartData[elementIndex];
                                console.log("Clicked on category in fullscreen fallback:", category);
                                
                                // Show AI insights for the clicked category
                                showCategoryInsights(category, quantity, chartLabels.map((label, index) => ({
                                    category: label,
                                    quantity: chartData[index]
                                })));
                                
                                // Show loading state
                                showDrillDownLoading();
                                
                                // Fetch products for this category
                                fetchProductsByCategory(category);
                            }
                        }
                    }
                });
                
                console.log("Fullscreen fallback Chart.js pie chart created successfully");
                
                // Show initial category insights
                setTimeout(() => {
                    showInitialCategoryInsights();
                }, 500);
            } catch (error) {
                console.error("Error creating fullscreen fallback chart:", error);
            }
        }

        // AI Insights functionality
        function showCategoryInsights(category, quantity, allData) {
            var aiInsightContent = document.getElementById("aiInsightContent");
            if (!aiInsightContent) return;
            
            // Show loading state
            aiInsightContent.innerHTML = `
                <div class="text-center">
                    <i class="fas fa-spinner fa-spin me-2"></i>
                    Analyzing ${category}...
                </div>
            `;
            
            // Generate insights after a short delay
            setTimeout(() => {
                var insights = generateCategoryInsights(category, quantity, allData);
                displayAIInsights(insights);
            }, 800);
        }

        function generateCategoryInsights(category, quantity, allData) {
            var totalItems = allData.reduce((sum, item) => sum + item.quantity, 0);
            var percentage = ((quantity / totalItems) * 100).toFixed(1);
            var sortedData = allData.sort((a, b) => b.quantity - a.quantity);
            var categoryRank = sortedData.findIndex(item => item.category === category) + 1;
            
            var insights = {
                category: category,
                quantity: quantity,
                percentage: percentage,
                rank: categoryRank,
                totalCategories: allData.length,
                totalItems: totalItems
            };
            
            return insights;
        }

        function displayAIInsights(insights) {
            var aiInsightContent = document.getElementById("aiInsightContent");
            if (!aiInsightContent) return;
            
            var content = '';
            
            // Check if this is the initial overview or a specific category
            var isOverview = insights.title && insights.title.includes("Inventory Overview");
            
            if (isOverview) {
                // Overview header
                content += `<div class="mb-4">
                    <h4 class="text-white fw-bold mb-2">${insights.title}</h4>
                    <span class="text-white" style="font-size: 1.2rem; opacity: 0.9;">Complete inventory analysis</span>
                </div>`;
                
                // Overview metrics
                content += `<div class="mb-4">
                    <div class="d-flex justify-content-between mb-3">
                        <span style="font-size: 1.3rem;">Total Items:</span>
                        <span class="fw-bold" style="font-size: 1.5rem;">${insights.items}</span>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span style="font-size: 1.3rem;">Categories:</span>
                        <span class="fw-bold" style="font-size: 1.5rem;">${insights.categoryCount}</span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span style="font-size: 1.3rem;">Top Category:</span>
                        <span class="fw-bold" style="font-size: 1.5rem;">${insights.topCategory} (${insights.topPercentage}%)</span>
                    </div>
                </div>`;
                
                // AI Analysis for overview
                content += `<div class="mb-4">
                    <h4 class="text-white fw-bold mb-3">🤖 AI Analysis</h4>
                    <div class="alert ${insights.alertClass} p-4 mb-3" style="background: rgba(255, 255, 255, 0.1); border: 1px solid rgba(255, 255, 255, 0.2);">
                        <span style="font-size: 1.3rem;"><i class="fas fa-${insights.alertClass === 'alert-warning' ? 'exclamation-triangle' : insights.alertClass === 'alert-success' ? 'check-circle' : 'info-circle'} me-3"></i>${insights.analysis}</span>
                    </div>
                </div>`;
                
                // Recommendations for overview
                content += `<div class="mb-3">
                    <h4 class="text-white fw-bold mb-3">💡 Recommendations</h4>
                    <ul class="list-unstyled mb-0" style="font-size: 1.2rem;">
                        ${insights.recommendations.map(rec => `<li class="mb-3">• ${rec}</li>`).join('')}
                    </ul>
                </div>`;
                
                // Quick actions for overview
                content += `<div class="mt-4 pt-3 border-top border-light border-opacity-25">
                    <span class="text-light opacity-75" style="font-size: 1.2rem;">
                        <i class="fas fa-mouse-pointer me-3"></i>
                        Click on any category to see detailed analysis
                    </span>
                </div>`;
                
            } else {
                // Category header
                content += `<div class="mb-4">
                    <h4 class="text-white fw-bold mb-2">${insights.category}</h4>
                    <span class="text-white" style="font-size: 1.2rem; opacity: 0.9;">Rank #${insights.rank} of ${insights.totalCategories} categories</span>
                </div>`;
                
                // Key metrics
                content += `<div class="mb-4">
                    <div class="d-flex justify-content-between mb-3">
                        <span style="font-size: 1.3rem;">Items:</span>
                        <span class="fw-bold" style="font-size: 1.5rem;">${insights.quantity.toLocaleString()}</span>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span style="font-size: 1.3rem;">Share:</span>
                        <span class="fw-bold" style="font-size: 1.5rem;">${insights.percentage}%</span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span style="font-size: 1.3rem;">Total Inventory:</span>
                        <span class="fw-bold" style="font-size: 1.5rem;">${insights.totalItems.toLocaleString()}</span>
                    </div>
                </div>`;
                
                // AI Analysis
                content += `<div class="mb-4">
                    <h4 class="text-white fw-bold mb-3">🤖 AI Analysis</h4>`;
                
                if (parseFloat(insights.percentage) > 50) {
                    content += `<div class="alert alert-warning p-4 mb-3" style="background: rgba(255, 193, 7, 0.2); border: 1px solid rgba(255, 193, 7, 0.3);">
                        <span style="font-size: 1.3rem;"><i class="fas fa-exclamation-triangle me-3"></i><strong>High Concentration:</strong> This category dominates your inventory. Consider diversifying.</span>
                    </div>`;
                } else if (parseFloat(insights.percentage) > 20) {
                    content += `<div class="alert alert-info p-4 mb-3" style="background: rgba(13, 202, 240, 0.2); border: 1px solid rgba(13, 202, 240, 0.3);">
                        <span style="font-size: 1.3rem;"><i class="fas fa-chart-line me-3"></i><strong>Strong Category:</strong> This is a major component of your inventory.</span>
                    </div>`;
                } else if (parseFloat(insights.percentage) > 5) {
                    content += `<div class="alert alert-success p-4 mb-3" style="background: rgba(25, 135, 84, 0.2); border: 1px solid rgba(25, 135, 84, 0.3);">
                        <span style="font-size: 1.3rem;"><i class="fas fa-check-circle me-3"></i><strong>Balanced:</strong> Good representation in your inventory mix.</span>
                    </div>`;
                } else {
                    content += `<div class="alert alert-secondary p-4 mb-3" style="background: rgba(108, 117, 125, 0.2); border: 1px solid rgba(108, 117, 125, 0.3);">
                        <span style="font-size: 1.3rem;"><i class="fas fa-info-circle me-3"></i><strong>Niche Category:</strong> Small but important part of your inventory.</span>
                    </div>`;
                }
                
                // Recommendations
                content += `<div class="mb-3">
                    <h4 class="text-white fw-bold mb-3">💡 Recommendations</h4>`;
                
                if (parseFloat(insights.percentage) > 50) {
                    content += `<ul class="list-unstyled mb-0" style="font-size: 1.2rem;">
                        <li class="mb-3">• Monitor stock levels closely</li>
                        <li class="mb-3">• Consider inventory diversification</li>
                        <li class="mb-3">• Focus on supply chain stability</li>
                    </ul>`;
                } else if (parseFloat(insights.percentage) > 20) {
                    content += `<ul class="list-unstyled mb-0" style="font-size: 1.2rem;">
                        <li class="mb-3">• Maintain consistent stock levels</li>
                        <li class="mb-3">• Monitor sales velocity</li>
                        <li class="mb-3">• Consider expansion opportunities</li>
                    </ul>`;
                } else if (parseFloat(insights.percentage) > 5) {
                    content += `<ul class="list-unstyled mb-0" style="font-size: 1.2rem;">
                        <li class="mb-3">• Good balance in inventory mix</li>
                        <li class="mb-3">• Monitor for growth potential</li>
                        <li class="mb-3">• Maintain adequate stock</li>
                    </ul>`;
                } else {
                    content += `<ul class="list-unstyled mb-0" style="font-size: 1.2rem;">
                        <li class="mb-3">• Consider if stock levels are adequate</li>
                        <li class="mb-3">• Monitor for restocking needs</li>
                        <li class="mb-3">• Evaluate category importance</li>
                    </ul>`;
                }
                
                content += `</div></div>`;
                
                // Quick actions
                content += `<div class="mt-4 pt-3 border-top border-light border-opacity-25">
                    <span class="text-light opacity-75" style="font-size: 1.2rem;">
                        <i class="fas fa-mouse-pointer me-3"></i>
                        Click to drill down and see individual products
                    </span>
                </div>`;
            }
            
            aiInsightContent.innerHTML = content;
        }

        function generateFullscreenInitialAnalysis() {
            // Get chart data for analysis
            var chartData = [];
            <?php 
            if (!empty($category_labels) && !empty($category_quantities)) {
                for ($i = 0; $i < count($category_labels); $i++) {
                    if (isset($category_labels[$i]) && isset($category_quantities[$i])) {
                        echo "chartData.push({
                            category: " . json_encode($category_labels[$i]) . ",
                            quantity: " . (int)$category_quantities[$i] . "
                        });";
                    }
                }
            }
            ?>
            
            // Generate AI analysis
            var analysis = generateInventoryAnalysis(chartData);
            displayFullscreenAIResponse(analysis);
        }

        function generateInventoryAnalysis(data) {
            if (!data || data.length === 0) {
                return "I don't see any inventory data to analyze. Please ensure your products are properly categorized.";
            }

            // Sort data by quantity
            var sortedData = data.sort((a, b) => b.quantity - a.quantity);
            var totalItems = data.reduce((sum, item) => sum + item.quantity, 0);
            
            var analysis = "📊 **Inventory Distribution Analysis**\n\n";
            
            // Top category analysis
            var topCategory = sortedData[0];
            var topPercentage = ((topCategory.quantity / totalItems) * 100).toFixed(1);
            
            analysis += `🎯 **Key Insight**: ${topCategory.category} dominates your inventory at ${topPercentage}% (${topCategory.quantity.toLocaleString()} items).\n\n`;
            
            // Category distribution analysis
            analysis += "📈 **Category Breakdown**:\n";
            sortedData.slice(0, 5).forEach((item, index) => {
                var percentage = ((item.quantity / totalItems) * 100).toFixed(1);
                analysis += `${index + 1}. ${item.category}: ${percentage}% (${item.quantity.toLocaleString()} items)\n`;
            });
            
            // Recommendations
            analysis += "\n💡 **Recommendations**:\n";
            
            if (topPercentage > 50) {
                analysis += `• Consider diversifying inventory - ${topCategory.category} is over 50% of stock\n`;
            }
            
            var lowStockCategories = sortedData.filter(item => {
                var percentage = (item.quantity / totalItems) * 100;
                return percentage < 2;
            });
            
            if (lowStockCategories.length > 0) {
                analysis += `• Low stock categories need attention: ${lowStockCategories.map(c => c.category).join(', ')}\n`;
            }
            
            var balancedCategories = sortedData.filter(item => {
                var percentage = (item.quantity / totalItems) * 100;
                return percentage >= 2 && percentage <= 10;
            });
            
            if (balancedCategories.length > 0) {
                analysis += `• Well-balanced categories: ${balancedCategories.map(c => c.category).join(', ')}\n`;
            }
            
            analysis += "\n❓ **Ask me anything** about your inventory distribution!";
            
            return analysis;
        }

        function displayFullscreenAIResponse(response) {
            var aiResponse = document.getElementById("fullscreenAIResponse");
            if (aiResponse) {
                // Convert markdown-like formatting to HTML
                var formattedResponse = response
                    .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
                    .replace(/\*(.*?)\*/g, '<em>$1</em>')
                    .replace(/\n/g, '<br>')
                    .replace(/•/g, '&bull;');
                
                aiResponse.innerHTML = formattedResponse;
            }
        }

        window.askFullscreenAI = function() {
            var question = document.getElementById("fullscreenAIQuestion");
            var aiResponse = document.getElementById("fullscreenAIResponse");
            var aiAskBtn = document.getElementById("fullscreenAIAskBtn");
            
            if (!question || !aiResponse || !aiAskBtn) return;
            
            var userQuestion = question.value.trim();
            if (!userQuestion) return;
            
            // Show loading state
            aiAskBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            aiAskBtn.disabled = true;
            aiResponse.innerHTML = '<div class="text-center text-muted"><i class="fas fa-spinner fa-spin me-2"></i>Thinking...</div>';
            
            // Get chart data for context
            var chartData = [];
            <?php 
            if (!empty($category_labels) && !empty($category_quantities)) {
                for ($i = 0; $i < count($category_labels); $i++) {
                    if (isset($category_labels[$i]) && isset($category_quantities[$i])) {
                        echo "chartData.push({
                            category: " . json_encode($category_labels[$i]) . ",
                            quantity: " . (int)$category_quantities[$i] . "
                        });";
                    }
                }
            }
            ?>
            
            // Generate AI response based on question
            setTimeout(() => {
                var response = generateAIResponse(userQuestion, chartData);
                displayFullscreenAIResponse(response);
                
                // Reset button
                aiAskBtn.innerHTML = '<i class="fas fa-paper-plane"></i>';
                aiAskBtn.disabled = false;
                question.value = '';
            }, 1500);
        };

        function generateAIResponse(question, data) {
            var totalItems = data.reduce((sum, item) => sum + item.quantity, 0);
            var sortedData = data.sort((a, b) => b.quantity - a.quantity);
            
            var lowerQuestion = question.toLowerCase();
            
            if (lowerQuestion.includes('focus') || lowerQuestion.includes('priority') || lowerQuestion.includes('important')) {
                var topCategory = sortedData[0];
                var topPercentage = ((topCategory.quantity / totalItems) * 100).toFixed(1);
                
                return `🎯 **Focus Recommendation**:\n\nYour top priority should be **${topCategory.category}** (${topPercentage}% of inventory).\n\n` +
                       `This category represents ${topCategory.quantity.toLocaleString()} items and is your main revenue driver. ` +
                       `Consider optimizing stock levels and ensuring consistent availability.\n\n` +
                       `Also monitor low-stock categories that might need restocking.`;
                       
            } else if (lowerQuestion.includes('low') || lowerQuestion.includes('stock') || lowerQuestion.includes('restock')) {
                var lowStockCategories = sortedData.filter(item => {
                    var percentage = (item.quantity / totalItems) * 100;
                    return percentage < 2;
                });
                
                if (lowStockCategories.length > 0) {
                    return `⚠️ **Low Stock Alert**:\n\nThese categories need attention:\n\n` +
                           lowStockCategories.map(item => {
                               var percentage = ((item.quantity / totalItems) * 100).toFixed(1);
                               return `• ${item.category}: ${percentage}% (${item.quantity} items)`;
                           }).join('\n') +
                           `\n\nConsider restocking these categories to maintain product availability.`;
                } else {
                    return `✅ **Stock Levels Look Good**:\n\nAll categories have adequate stock levels (above 2% of total inventory). ` +
                           `Continue monitoring to maintain this balance.`;
                }
                
            } else if (lowerQuestion.includes('balance') || lowerQuestion.includes('diversify') || lowerQuestion.includes('distribution')) {
                var topCategory = sortedData[0];
                var topPercentage = ((topCategory.quantity / totalItems) * 100).toFixed(1);
                
                if (topPercentage > 50) {
                    return `⚖️ **Inventory Balance Analysis**:\n\nYour inventory is **heavily concentrated** in ${topCategory.category} (${topPercentage}%).\n\n` +
                           `**Recommendation**: Consider diversifying your inventory to reduce risk and capture more market segments. ` +
                           `Aim for a more balanced distribution across categories.`;
                } else {
                    return `✅ **Well-Balanced Inventory**:\n\nYour inventory distribution looks healthy with good diversification across categories. ` +
                           `The top category (${topCategory.category}) represents ${topPercentage}% of total stock, which is within a reasonable range.`;
                }
                
            } else if (lowerQuestion.includes('trend') || lowerQuestion.includes('pattern') || lowerQuestion.includes('insight')) {
                var top3 = sortedData.slice(0, 3);
                var top3Total = top3.reduce((sum, item) => sum + item.quantity, 0);
                var top3Percentage = ((top3Total / totalItems) * 100).toFixed(1);
                
                return `📈 **Inventory Trends & Insights**:\n\n` +
                       `**Top 3 Categories** (${top3Percentage}% of total inventory):\n` +
                       top3.map((item, index) => {
                           var percentage = ((item.quantity / totalItems) * 100).toFixed(1);
                           return `${index + 1}. ${item.category}: ${percentage}%`;
                       }).join('\n') +
                       `\n\n**Key Insight**: Your inventory is concentrated in core categories, which suggests a focused product strategy. ` +
                       `This can be efficient but consider monitoring for diversification opportunities.`;
                       
            } else {
                return `🤖 **AI Analysis**:\n\nI can help you understand your inventory distribution better. Here's what I see:\n\n` +
                       `• Total items: ${totalItems.toLocaleString()}\n` +
                       `• Categories: ${data.length}\n` +
                       `• Top category: ${sortedData[0].category} (${((sortedData[0].quantity / totalItems) * 100).toFixed(1)}%)\n\n` +
                       `Try asking me about:\n` +
                       `• "What should I focus on?"\n` +
                       `• "Which categories need attention?"\n` +
                       `• "How balanced is my inventory?"\n` +
                       `• "What trends do you see?"`;
            }
        }

        window.handleFullscreenAIKeyPress = function(event) {
            if (event.key === 'Enter') {
                askFullscreenAI();
            }
        };

        // Drill-down functionality
        let currentChart = null;
        let drillDownHistory = [];

        function showDrillDownLoading() {
            // Check if we're in fullscreen mode
            var fullscreenContainer = document.getElementById("fullscreenChartDiv");
            var normalContainer = document.getElementById("chartdiv");
            
            if (fullscreenContainer) {
                // We're in fullscreen mode
                fullscreenContainer.innerHTML = '<div class="text-center text-muted p-4"><i class="fas fa-spinner fa-spin fa-2x mb-2"></i><p class="mb-0">Loading products...</p></div>';
            } else if (normalContainer) {
                // We're in normal mode
                normalContainer.innerHTML = '<div class="text-center text-muted p-4"><i class="fas fa-spinner fa-spin fa-2x mb-2"></i><p class="mb-0">Loading products...</p></div>';
            }
        }

        function fetchProductsByCategory(category) {
            // Create a simple AJAX request to get products by category
            fetch('get_products_by_category.php?category=' + encodeURIComponent(category))
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        createDrillDownChart(category, data.products);
                    } else {
                        showDrillDownError(data.message || 'Failed to load products');
                    }
                })
                .catch(error => {
                    console.error('Error fetching products:', error);
                    showDrillDownError('Error loading products for ' + category);
                });
        }

        function createDrillDownChart(category, products) {
            try {
                // Check if we're in fullscreen mode or normal mode
                var fullscreenContainer = document.getElementById("fullscreenChartDiv");
                var normalContainer = document.getElementById("chartdiv");
                var chartContainer = fullscreenContainer || normalContainer;
                
                if (!chartContainer) return;

                // Store current chart for back navigation
                if (currentChart) {
                    drillDownHistory.push(currentChart);
                }

                // Clear container and create new chart
                chartContainer.innerHTML = '<div id="drillDownChart" style="width: 100%; height: 100%;"></div>';
                
                // Add back button - position differently for fullscreen vs normal
                var backButton = document.createElement('div');
                backButton.id = 'drillDownBackButton';
                backButton.innerHTML = '<button onclick="goBackToMainChart()" class="btn btn-outline-light btn-sm mb-2"><i class="fas fa-arrow-left me-1"></i>Back to Categories</button>';
                backButton.style.position = 'absolute';
                backButton.style.top = '10px';
                backButton.style.left = '10px';
                backButton.style.zIndex = '1000';
                
                // If in fullscreen, add to the fullscreen container's parent
                if (fullscreenContainer) {
                    var fullscreenParent = fullscreenContainer.parentElement;
                    if (fullscreenParent) {
                        fullscreenParent.appendChild(backButton);
                    }
                } else {
                    chartContainer.appendChild(backButton);
                }

                // Prepare data for products
                var productData = [];
                var colors = [
                    'rgba(99, 102, 241, 0.9)', 'rgba(236, 72, 153, 0.9)', 'rgba(34, 197, 94, 0.9)',
                    'rgba(251, 146, 60, 0.9)', 'rgba(168, 85, 247, 0.9)', 'rgba(59, 130, 246, 0.9)',
                    'rgba(239, 68, 68, 0.9)', 'rgba(16, 185, 129, 0.9)', 'rgba(245, 158, 11, 0.9)',
                    'rgba(139, 92, 246, 0.9)'
                ];

                products.forEach((product, index) => {
                    productData.push({
                        product: product.ProductName,
                        quantity: parseInt(product.Quantity),
                        color: am4core.color(colors[index % colors.length]),
                        imagePath: product.ImagePath ? 'uploads/' + product.ImagePath : getProductImage(product.ProductName, category)
                    });
                });

                // Create drill-down chart
                var drillChart = am4core.create("drillDownChart", am4charts.PieChart3D);
                drillChart.hiddenState.properties.opacity = 0;

                // Configure legend with larger fonts
                drillChart.legend = new am4charts.Legend();
                drillChart.legend.position = "bottom";
                drillChart.legend.labels.template.fill = am4core.color("#ffffff");
                drillChart.legend.labels.template.fontSize = 16;
                drillChart.legend.labels.template.fontWeight = "600";
                drillChart.legend.labels.template.fontFamily = "'Inter', 'Segoe UI', sans-serif";
                
                // Disable automatic value labels to prevent double percentages
                drillChart.legend.valueLabels.template.disabled = true;
                
                // Configure legend markers to be larger
                drillChart.legend.markers.template.width = 20;
                drillChart.legend.markers.template.height = 20;
                drillChart.legend.markers.template.marginRight = 12;

                drillChart.data = productData;

                // Create series
                var drillSeries = drillChart.series.push(new am4charts.PieSeries3D());
                drillSeries.dataFields.value = "quantity";
                drillSeries.dataFields.category = "product";
                drillSeries.slices.template.propertyFields.fill = "color";
                drillSeries.slices.template.stroke = am4core.color("#1f2937");
                drillSeries.slices.template.strokeWidth = 2;

                // Hide labels and lines for cleaner product view
                drillSeries.labels.template.disabled = true;
                drillSeries.ticks.template.disabled = true;

                // Configure tooltips with product images
                drillSeries.tooltip.getFillFromObject = false;
                drillSeries.tooltip.background.fill = am4core.color("#1f2937");
                drillSeries.tooltip.background.stroke = am4core.color("#4b5563");
                drillSeries.tooltip.background.strokeWidth = 2;
                drillSeries.tooltip.background.cornerRadius = 8;
                drillSeries.tooltip.pointerOrientation = "vertical";
                drillSeries.tooltip.label.adapter.add("html", function(html, target) {
                    var dataItem = target.tooltipDataItem;
                    var product = dataItem.category || "Unknown";
                    var quantity = dataItem.value || 0;
                    var total = productData.reduce((sum, item) => sum + (item.quantity || 0), 0);
                    var percentage = total > 0 ? ((quantity / total) * 100).toFixed(1) : "0.0";
                    
                    // Get product image from the data or fallback to category image
                    var productDataItem = productData.find(item => item.product === product);
                    var productImage = productDataItem ? productDataItem.imagePath : getProductImage(product, category);
                    
                    return '<div style="padding: 12px; text-align: center; min-width: 200px;">' +
                           '<img src="' + productImage + '" style="width: 80px; height: 80px; object-fit: cover; border-radius: 8px; margin-bottom: 8px; border: 2px solid #4b5563;" onerror="this.src=\'uploads/product/default.png\'">' +
                           '<div style="color: #f3f4f6; font-size: 14px; font-weight: bold;">📦 ' + product + '</div>' +
                           '<div style="color: #d1d5db; font-size: 12px; margin-top: 4px;">Quantity: ' + quantity.toLocaleString() + ' items</div>' +
                           '<div style="color: #d1d5db; font-size: 12px;">Share: ' + percentage + '% of ' + category + '</div>' +
                           '</div>';
                });

                // Override legend labels to show product names with percentages
                drillChart.legend.labels.template.adapter.add("text", function(labelText, target) {
                    var dataItem = target.dataItem;
                    if (dataItem && dataItem.category) {
                        var product = dataItem.category;
                        var value = dataItem.value || 0;
                        var total = productData.reduce((sum, item) => sum + (item.quantity || 0), 0);
                        var percentage = total > 0 ? ((value / total) * 100).toFixed(1) : "0.0";
                        return product + " " + percentage + "%";
                    }
                    return labelText;
                });

                // Configure 3D settings
                drillChart.angle = 15;
                drillChart.depth = 30;
                drillChart.radius = am4core.percent(95);

                currentChart = drillChart;
                window.currentChart = drillChart; // Store for resize functionality
                console.log("Drill-down chart created for category:", category);

            } catch (error) {
                console.error("Error creating drill-down chart:", error);
                showDrillDownError("Failed to create product chart");
            }
        }

        function showDrillDownError(message) {
            // Check if we're in fullscreen mode or normal mode
            var fullscreenContainer = document.getElementById("fullscreenChartDiv");
            var normalContainer = document.getElementById("chartdiv");
            var chartContainer = fullscreenContainer || normalContainer;
            
            if (chartContainer) {
                chartContainer.innerHTML = '<div class="text-center text-muted p-4"><i class="fas fa-exclamation-triangle me-2"></i>' + message + '<br><button onclick="goBackToMainChart()" class="btn btn-outline-light btn-sm mt-2"><i class="fas fa-arrow-left me-1"></i>Back</button></div>';
            }
        }

        window.goBackToMainChart = function() {
            console.log("Going back to main chart");
            
            // Check if we're in fullscreen mode or normal mode
            var fullscreenContainer = document.getElementById("fullscreenChartDiv");
            var normalContainer = document.getElementById("chartdiv");
            var chartContainer = fullscreenContainer || normalContainer;
            
            if (!chartContainer) return;
            
            // Remove the back button
            var backButton = document.getElementById('drillDownBackButton');
            if (backButton) {
                backButton.remove();
            }
            
            // Reset AI assistant to default state
            resetAIAssistant();
            
            // Show loading state
            chartContainer.innerHTML = '<div class="text-center text-muted p-4"><i class="fas fa-spinner fa-spin fa-2x mb-2"></i><p class="mb-0">Loading categories...</p></div>';
            
            // Recreate the main chart without page reload
            setTimeout(() => {
                if (fullscreenContainer) {
                    // We're in fullscreen mode, recreate fullscreen chart
                    createFullscreenChart();
                } else {
                    // We're in normal mode, recreate normal chart
                    recreateMainChart();
                }
            }, 300);
        };

        function resetAIAssistant() {
            var aiInsightContent = document.getElementById("aiInsightContent");
            if (aiInsightContent) {
                // Show initial category overview insights
                showInitialCategoryInsights();
            }
        }

        function showInitialCategoryInsights() {
            var aiInsightContent = document.getElementById("aiInsightContent");
            if (!aiInsightContent) return;

            // Get the current chart data
            var chartData = [];
            <?php 
            if (!empty($category_labels) && !empty($category_quantities) && !empty($category_colors)) {
                for ($i = 0; $i < count($category_labels); $i++) {
                    if (isset($category_labels[$i]) && isset($category_quantities[$i]) && isset($category_colors[$i])) {
                        echo "chartData.push({";
                        echo "category: " . json_encode($category_labels[$i]) . ",";
                        echo "quantity: " . (int)$category_quantities[$i];
                        echo "});";
                    }
                }
            }
            ?>

            if (chartData.length === 0) {
                aiInsightContent.innerHTML = `
                    <div class="text-center">
                        <i class="fas fa-mouse-pointer me-2"></i>
                        Click on any category to get AI insights
                    </div>
                `;
                return;
            }

            // Generate initial insights
            var insights = generateInitialInsights(chartData);
            displayAIInsights(insights);
        }

        function generateInitialInsights(chartData) {
            // Sort categories by quantity
            var sortedData = chartData.slice().sort((a, b) => b.quantity - a.quantity);
            var totalItems = chartData.reduce((sum, item) => sum + item.quantity, 0);
            
            // Find top category
            var topCategory = sortedData[0];
            var topPercentage = ((topCategory.quantity / totalItems) * 100).toFixed(1);
            
            // Find bottom categories (less than 2%)
            var lowStockCategories = sortedData.filter(item => 
                ((item.quantity / totalItems) * 100) < 2.0
            );
            
            // Calculate diversity score
            var diversityScore = sortedData.length;
            var concentrationScore = parseFloat(topPercentage);
            
            var analysis = "";
            var alertClass = "alert-info";
            
            if (concentrationScore > 60) {
                analysis = "High Concentration: One category dominates your inventory. Consider diversifying to reduce risk.";
                alertClass = "alert-warning";
            } else if (concentrationScore > 40) {
                analysis = "Moderate Concentration: Good balance with some focus areas. Monitor for growth opportunities.";
                alertClass = "alert-info";
            } else {
                analysis = "Well Distributed: Excellent inventory diversity across categories. Maintain this balance.";
                alertClass = "alert-success";
            }

            var recommendations = [];
            
            if (concentrationScore > 60) {
                recommendations.push("Consider expanding underperforming categories");
                recommendations.push("Monitor supply chain for dominant category");
                recommendations.push("Diversify to reduce business risk");
            } else if (lowStockCategories.length > 0) {
                recommendations.push("Review low-stock categories for potential growth");
                recommendations.push("Consider promotional strategies for smaller categories");
                recommendations.push("Monitor market demand for niche products");
            } else {
                recommendations.push("Maintain current inventory balance");
                recommendations.push("Focus on optimizing high-performing categories");
                recommendations.push("Continue monitoring market trends");
            }

            return {
                title: "📊 Inventory Overview",
                category: "All Categories",
                items: totalItems.toLocaleString(),
                share: "100%",
                totalInventory: totalItems.toLocaleString(),
                analysis: analysis,
                alertClass: alertClass,
                recommendations: recommendations,
                topCategory: topCategory.category,
                topPercentage: topPercentage,
                categoryCount: chartData.length
            };
        }

        function recreateMainChart() {
            try {
                console.log("Recreating main chart...");
                var chartContainer = document.getElementById("chartdiv");
                if (!chartContainer) {
                    console.error("Chart container not found");
                    return;
                }

                // Clear container and recreate the div
                chartContainer.innerHTML = '<div id="chartdiv" style="width: 100%; height: 100%;"></div>';
                
                // Check if AmCharts is available and recreate the chart
                if (typeof am4core !== 'undefined' && typeof am4charts !== 'undefined') {
                    console.log("AmCharts available, recreating 3D pie chart");
                    
                    // Use AmCharts ready to ensure proper initialization
                    am4core.ready(function() {
                        try {
                            // Themes begin
                            am4core.useTheme(am4themes_animated);
                            // Themes end

                            // Create chart instance
                            var chart = am4core.create("chartdiv", am4charts.PieChart3D);
                            chart.hiddenState.properties.opacity = 0;

                            // Add legend with proper text color configuration
                            chart.legend = new am4charts.Legend();
                            chart.legend.position = "bottom";
                            chart.legend.contentAlign = "center";
                            chart.legend.marginTop = 10;
                            chart.legend.marginBottom = 10;
                            
                            // Configure legend labels with white text
                            chart.legend.labels.template.fill = am4core.color("#ffffff");
                            chart.legend.labels.template.fontSize = 12;
                            chart.legend.labels.template.fontWeight = "500";
                            chart.legend.labels.template.fontFamily = "'Inter', 'Segoe UI', sans-serif";
                            
                            // Disable automatic value labels to prevent double percentages
                            chart.legend.valueLabels.template.disabled = true;

                            // Prepare data from PHP (same as original)
                            var chartData = [];
                            <?php 
                            if (!empty($category_labels) && !empty($category_quantities) && !empty($category_colors)) {
                                for ($i = 0; $i < count($category_labels); $i++) {
                                    if (isset($category_labels[$i]) && isset($category_quantities[$i]) && isset($category_colors[$i])) {
                                        echo "chartData.push({
                                            category: " . json_encode($category_labels[$i]) . ",
                                            quantity: " . (int)$category_quantities[$i] . ",
                                            color: am4core.color(" . json_encode($category_colors[$i]) . ")
                                        });";
                                    }
                                }
                            }
                            ?>
                            
                            // Validate chart data
                            if (chartData.length === 0) {
                                chartData = [{
                                    category: "No Data",
                                    quantity: 1,
                                    color: am4core.color("#6b7280")
                                }];
                            }
                            
                            chart.data = chartData;

                            // Create series
                            var series = chart.series.push(new am4charts.PieSeries3D());
                            series.dataFields.value = "quantity";
                            series.dataFields.category = "category";
                            
                            // Configure series appearance
                            series.slices.template.stroke = am4core.color("#1f2937");
                            series.slices.template.strokeWidth = 2;
                            series.slices.template.strokeOpacity = 0.8;
                            
                            // Configure colors
                            series.slices.template.propertyFields.fill = "color";
                            
                            // Configure labels
                            series.labels.template.fill = am4core.color("#f9fafb");
                            series.labels.template.fontSize = 12;
                            series.labels.template.fontWeight = "600";
                            series.labels.template.fontFamily = "'Inter', 'Segoe UI', sans-serif";
                            
                            // Configure tooltips
                            series.tooltip.label.fill = am4core.color("#f3f4f6");
                            series.tooltip.label.fontSize = 13;
                            series.tooltip.label.fontFamily = "'Inter', 'Segoe UI', sans-serif";
                            series.tooltip.background.fill = am4core.color("#1f2937");
                            series.tooltip.background.stroke = am4core.color("rgba(255, 255, 255, 0.1)");
                            series.tooltip.background.strokeWidth = 1;
                            series.tooltip.background.cornerRadius = 8;
                            
                            // Custom tooltip content
                            series.tooltip.label.adapter.add("text", function(labelText, target) {
                                var dataItem = target.tooltipDataItem;
                                var category = dataItem.category || "Unknown";
                                var value = dataItem.value || 0;
                                var total = chartData.reduce((sum, item) => sum + (item.quantity || 0), 0);
                                var percentage = total > 0 ? ((value / total) * 100).toFixed(1) : "0.0";
                                
                                return "📦 " + category + "\n" +
                                       "Quantity: " + (value || 0).toLocaleString() + " items\n" +
                                       "Share: " + percentage + "% of total inventory\n" +
                                       "Click to see products in this category";
                            });

                            // Override click for THIS panel only: toggle slice (no drill-down)
                            series.slices.template.events.off("hit");
                            series.slices.template.events.on("hit", function(ev){
                                try {
                                    if (ev && ev.event && typeof ev.event.stopImmediatePropagation === 'function') {
                                        ev.event.stopImmediatePropagation();
                                    }
                                } catch(e) {}
                                // Toggle slice pull-out
                                var slice = ev.target;
                                slice.isActive = !slice.isActive;
                            });
                            if (am4core && am4core.MouseCursorStyle) {
                                series.slices.template.cursorOverStyle = am4core.MouseCursorStyle.pointer;
                            }

                            // Configure 3D settings
                            chart.angle = 15;
                            chart.depth = 30;
                            chart.padding(10, 10, 10, 10);
                            chart.radius = am4core.percent(95);
                            
                            // Add responsive behavior
                            chart.responsive.enabled = true;
                            chart.responsive.useDefault = false;
                            chart.responsive.rules.push({
                                relevant: am4core.ResponsiveBreakpoints.widthS,
                                state: function(target, stateId) {
                                    if (target instanceof am4charts.PieChart3D) {
                                        target.radius = am4core.percent(85);
                                        target.legend.position = "bottom";
                                        target.legend.labels.template.fontSize = 11;
                                    }
                                }
                            });

                            // Override legend labels to show category names with percentages
                            chart.legend.labels.template.adapter.add("text", function(labelText, target) {
                                var dataItem = target.dataItem;
                                if (dataItem && dataItem.category) {
                                    var category = dataItem.category;
                                    var value = dataItem.value || 0;
                                    var total = chartData.reduce((sum, item) => sum + (item.quantity || 0), 0);
                                    var percentage = total > 0 ? ((value / total) * 100).toFixed(1) : "0.0";
                                    return category + " " + percentage + "%";
                                }
                                return labelText;
                            });
                            
                            // Force legend to use custom text formatting
                            chart.legend.useDefaultMarker = false;

                            console.log("Main chart recreated successfully");
                            
                            // Store chart reference for resize functionality
                            window.currentChart = chart;
                            
                        } catch (error) {
                            console.error("Error recreating AmCharts chart:", error);
                            createFallbackPieChart();
                        }
                    });
                } else {
                    console.log("AmCharts not available, using Chart.js fallback");
                    createFallbackPieChart();
                }
            } catch (error) {
                console.error("Error recreating main chart:", error);
                createFallbackPieChart();
            }
        }



        // Real-time Dashboard Updates using SSE
        function initDashboardSSE() {
            try {
                const eventSource = new EventSource('sse_dashboard_metrics.php');
                
                eventSource.onmessage = function(event) {
                    try {
                        const data = JSON.parse(event.data);
                        updateDashboardMetrics(data);
                        
                        // Play notification sound for important updates
                        const currentTime = Date.now();
                        const isPageVisible = !document.hidden;
                        const hasNewNotifications = data.notification_count > lastNotificationCount;
                        
                        if (hasNewNotifications && !isInitialLoad && notificationSound && 
                            (currentTime - lastSoundPlayTime) > SOUND_DEBOUNCE_TIME && isPageVisible) {
                            console.log('Admin Dashboard: Playing notification sound - new notification detected');
                            notificationSound.play();
                            lastSoundPlayTime = currentTime;
                        }
                        
                        // Update tracking
                        lastNotificationCount = data.notification_count;
                        isInitialLoad = false;
                    } catch (error) {
                        console.error('Error parsing SSE message:', error);
                    }
                };
                
                eventSource.onerror = function(event) {
                    console.error('SSE Error:', event);
                    eventSource.close();
                    
                    // Retry connection after 5 seconds
                    setTimeout(() => {
                        initDashboardSSE();
                    }, 5000);
                };
            } catch (error) {
                console.error('Error initializing SSE:', error);
                // Retry after 5 seconds
                setTimeout(() => {
                    initDashboardSSE();
                }, 5000);
            }
        }

        function updateDashboardMetrics(data) {
            // Update Today Success Transactions
            if (data.today_transactions !== undefined) {
                const transactionCard = document.querySelector('.col-sm-6.col-xl-3:nth-child(1)');
                if (transactionCard) {
                    const valueElement = transactionCard.querySelector('h6.mb-0');
                    const trendElement = transactionCard.querySelector('small.text-success');
                    if (valueElement) valueElement.textContent = data.today_transactions.count;
                    if (trendElement) trendElement.textContent = `↗ ${data.today_transactions.growth}% from yesterday`;
                }
            }

            // Update Low Stock Items
            if (data.low_stock !== undefined) {
                const lowStockCard = document.querySelector('.col-sm-6.col-xl-3:nth-child(2)');
                if (lowStockCard) {
                    const valueElement = lowStockCard.querySelector('h6.mb-0');
                    const trendElement = lowStockCard.querySelector('small.text-warning');
                    if (valueElement) valueElement.textContent = data.low_stock.count;
                    if (trendElement) trendElement.textContent = `↗ ${data.low_stock.trend}% from last month`;
                }
            }

            // Update Total Shoppers
            if (data.total_shoppers !== undefined) {
                const shoppersCard = document.querySelector('.col-sm-6.col-xl-3:nth-child(3)');
                if (shoppersCard) {
                    const valueElement = shoppersCard.querySelector('h6.mb-0');
                    if (valueElement) valueElement.textContent = data.total_shoppers;
                }
            }

            // Update Today's Sale
            if (data.today_sales !== undefined) {
                const salesCard = document.querySelector('.col-sm-6.col-xl-3:nth-child(4)');
                if (salesCard) {
                    const valueElement = salesCard.querySelector('h6.mb-0');
                    const trendElement = salesCard.querySelector('small.text-success');
                    if (valueElement) valueElement.textContent = '₱' + parseFloat(data.today_sales.amount).toLocaleString('en-US', {minimumFractionDigits: 2});
                    if (trendElement) trendElement.textContent = `↗ ${data.today_sales.growth}% from yesterday`;
                }
            }

            // Update All Products Value and Total Earned
            if (data.all_products_value !== undefined || data.total_earned !== undefined) {
                // Target the "Today's Earned" widget specifically (4th widget)
                const salesCard = document.querySelector('.col-sm-6.col-xl-3:nth-child(4)');
                if (salesCard) {
                    if (data.all_products_value !== undefined) {
                        const inventoryElement = salesCard.querySelector('small.text-info');
                        if (inventoryElement) inventoryElement.textContent = 'Inventory: ₱' + parseFloat(data.all_products_value).toLocaleString('en-US', {minimumFractionDigits: 2});
                    }
                    if (data.total_earned !== undefined) {
                        const earnedElement = salesCard.querySelector('small.text-warning');
                        if (earnedElement) earnedElement.textContent = 'Total Earned: ₱' + parseFloat(data.total_earned).toLocaleString('en-US', {minimumFractionDigits: 2});
                    }
                }
            }

            // Show update notification
            showUpdateNotification();
        }

        function showUpdateNotification() {
            // Create a subtle notification that data was updated
            const notification = document.createElement('div');
            notification.className = 'position-fixed top-0 end-0 p-3';
            notification.style.zIndex = '9999';
            notification.innerHTML = `
                <div class="toast align-items-center text-white bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
                    <div class="d-flex">
                        <div class="toast-body">
                            <i class="fas fa-sync-alt me-2"></i>
                            Dashboard updated
                        </div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                    </div>
                </div>
            `;
            
            document.body.appendChild(notification);
            
            // Auto-remove after 3 seconds
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
            }, 3000);
        }

        // Initialize notification sound system
        let notificationSound = null;
        let lastNotificationCount = 0;
        let isInitialLoad = true;
        let lastSoundPlayTime = 0;
        const SOUND_DEBOUNCE_TIME = 2000; // 2 seconds between sounds
        
        // Initialize real-time updates when page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize notification sound
            notificationSound = new NotificationSound({
                soundFile: 'uploads/NofiticationCash.mp3',
                volume: 1.0,
                enableMute: true,
                enableTest: true,
                storageKey: 'adminNotificationSoundSettings'
            });
            
            // Reset tracking on page load
            lastNotificationCount = 0;
            isInitialLoad = true;
            lastSoundPlayTime = 0;
            
            initDashboardSSE();
            
            // Initialize weekly orders chart
            initWeeklyOrdersChart();
            
            // Real-time revenue removed
            
            // Initialize real-time help requests
            initHelpRequestsSSE();
            
            // Update every 15 seconds as fallback
            setInterval(() => {
                fetch('get_dashboard_metrics.php')
                    .then(response => response.json())
                    .then(data => updateDashboardMetrics(data))
                    .catch(error => console.error('Fallback update failed:', error));
            }, 15000);
        });
        
        // Weekly Orders Chart Functions
        let weeklyOrdersChart = null;
        
        function initWeeklyOrdersChart() {
            const chartData = JSON.parse(document.getElementById('weeklyOrdersData').textContent);
            createWeeklyChart(chartData);
        }
        
        function createWeeklyChart(data) {
            const ctx = document.getElementById('weeklyOrdersChart').getContext('2d');
            
            if (weeklyOrdersChart) {
                weeklyOrdersChart.destroy();
            }
            
            weeklyOrdersChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: data.map(item => item.day),
                    datasets: [
                        {
                            label: 'Completed Orders',
                            data: data.map(item => item.completed_count),
                            backgroundColor: 'rgba(40, 167, 69, 0.8)',
                            borderColor: 'rgba(40, 167, 69, 1)',
                            borderWidth: 2,
                            borderRadius: 5,
                            borderSkipped: false,
                        },
                        {
                            label: 'Returned Orders',
                            data: data.map(item => item.returned_count),
                            backgroundColor: 'rgba(220, 53, 69, 0.8)',
                            borderColor: 'rgba(220, 53, 69, 1)',
                            borderWidth: 2,
                            borderRadius: 5,
                            borderSkipped: false,
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                            labels: {
                                color: 'white',
                                font: {
                                    size: 12
                                }
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            titleColor: 'white',
                            bodyColor: 'white',
                            borderColor: 'rgba(220, 53, 69, 1)',
                            borderWidth: 1,
                                                            callbacks: {
                                    label: function(context) {
                                        const dataIndex = context.dataIndex;
                                        const orderCount = context.parsed.y;
                                        const datasetLabel = context.dataset.label;
                                        
                                        if (datasetLabel === 'Completed Orders') {
                                            const totalAmount = data[dataIndex].completed_amount;
                                            return [
                                                `Completed Orders: ${orderCount}`,
                                                `Total: ₱${totalAmount.toFixed(2)}`
                                            ];
                                        } else if (datasetLabel === 'Returned Orders') {
                                            const totalAmount = data[dataIndex].returned_amount;
                                            return [
                                                `Returned Orders: ${orderCount}`,
                                                `Total: ₱${totalAmount.toFixed(2)}`
                                            ];
                                        }
                                        return `${datasetLabel}: ${orderCount}`;
                                    }
                                }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)',
                                drawBorder: false
                            },
                            ticks: {
                                color: 'white',
                                font: {
                                    size: 11
                                },
                                callback: function(value) {
                                    return value + ' orders';
                                }
                            }
                        },
                        x: {
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)',
                                drawBorder: false
                            },
                            ticks: {
                                color: 'white',
                                font: {
                                    size: 11
                                }
                            }
                        }
                    },
                    animation: {
                        duration: 1000,
                        easing: 'easeInOutQuart'
                    }
                }
            });
        }
        
        function refreshWeeklyChart() {
            // Fetch fresh data from server
            fetch('get_weekly_orders.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        createWeeklyChart(data.data);
                    } else {
                        console.error('Failed to fetch weekly orders data:', data.message);
                    }
                })
                .catch(error => {
                    console.error('Error refreshing chart:', error);
                });
        }
        
        // Real-Time Revenue Chart Functions
        let revenueChart = null;
        let revenueData = [];
        
        function initRevenueChart() {
            // Initialize with empty data
            createRevenueChart();
            
            // Start real-time updates
            startRevenueUpdates();
        }
        
        function createRevenueChart() {
            const ctx = document.getElementById('revenueChart').getContext('2d');
            
            if (revenueChart) {
                revenueChart.destroy();
            }
            
            // Load real revenue data from database
            loadHourlyRevenueData();
        }
        
        function loadHourlyRevenueData() {
            fetch('get_hourly_revenue.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update chart with real data
                        updateRevenueChartWithRealData(data.labels, data.data);
                        
                        // Update summary cards
                        updateRevenueSummaryWithRealData(data.current_hour_revenue, data.today_total);
                    } else {
                        console.error('Failed to load hourly revenue data:', data.message);
                        // Fallback to empty chart
                        createEmptyRevenueChart();
                    }
                })
                .catch(error => {
                    console.error('Error loading hourly revenue data:', error);
                    // Fallback to empty chart
                    createEmptyRevenueChart();
                });
        }
        
        function createEmptyRevenueChart() {
            const ctx = document.getElementById('revenueChart').getContext('2d');
            
            // Initialize with empty data structure
            const labels = [];
            const data = [];
            const now = new Date();
            
            for (let i = 23; i >= 0; i--) {
                const time = new Date(now.getTime() - (i * 60 * 60 * 1000));
                
                let timeLabel;
                const isToday = time.toDateString() === now.toDateString();
                const isYesterday = time.toDateString() === new Date(now.getTime() - 24 * 60 * 60 * 1000).toDateString();
                
                if (isToday) {
                    timeLabel = time.toLocaleTimeString('en-US', { 
                        hour: 'numeric', 
                        minute: '2-digit',
                        hour12: true 
                    });
                } else if (isYesterday) {
                    timeLabel = time.toLocaleTimeString('en-US', { 
                        hour: 'numeric', 
                        minute: '2-digit',
                        hour12: true 
                    }) + ' (Yesterday)';
                } else {
                    timeLabel = time.toLocaleTimeString('en-US', { 
                        hour: 'numeric', 
                        minute: '2-digit',
                        hour12: true 
                    }) + ' (' + time.toLocaleDateString('en-US', { 
                        month: 'short', 
                        day: 'numeric' 
                    }) + ')';
                }
                
                labels.push(timeLabel);
                data.push(0);
            }
            
            revenueData = data;
            createChartInstance(labels, data);
        }
        
        function updateRevenueChartWithRealData(labels, data) {
            revenueData = data;
            createChartInstance(labels, data);
        }
        
        function createChartInstance(labels, data) {
            const ctx = document.getElementById('revenueChart').getContext('2d');
            
            revenueChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Revenue (₱)',
                        data: data,
                        borderColor: 'rgba(34, 197, 94, 1)',
                        backgroundColor: 'rgba(34, 197, 94, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: 'rgba(34, 197, 94, 1)',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            titleColor: 'white',
                            bodyColor: 'white',
                            borderColor: 'rgba(34, 197, 94, 1)',
                            borderWidth: 1,
                            callbacks: {
                                label: function(context) {
                                    return `Revenue: ₱${context.parsed.y.toFixed(2)}`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)',
                                drawBorder: false
                            },
                            ticks: {
                                color: 'white',
                                font: {
                                    size: 11
                                },
                                callback: function(value) {
                                    return '₱' + value.toFixed(0);
                                }
                            }
                        },
                        x: {
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)',
                                drawBorder: false
                            },
                            ticks: {
                                color: 'white',
                                font: {
                                    size: 11
                                },
                                maxTicksLimit: 8
                            }
                        }
                    },
                    animation: {
                        duration: 1000,
                        easing: 'easeInOutQuart'
                    },
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    }
                }
            });
        }
        
        function startRevenueUpdates() {
            // Update chart every 30 seconds with new data
            setInterval(() => {
                updateRevenueChart();
            }, 30000);
        }
        
        function updateRevenueChart() {
            if (!revenueChart) return;
            
            // Refresh real data from database every 30 seconds
            loadHourlyRevenueData();
        }
        
        function updateRevenueSummary() {
            // This function is now handled by updateRevenueSummaryWithRealData
            // Keep for backward compatibility
        }
        
        function updateRevenueSummaryWithRealData(currentHourRevenue, todayTotal) {
            // Update hourly revenue
            document.getElementById('hourlyRevenue').textContent = '₱' + currentHourRevenue.toFixed(2);
            
            // Update today's revenue
            document.getElementById('todayRevenue').textContent = '₱' + todayTotal.toFixed(2);
            
            // Update last transaction info
            const now = new Date();
            document.getElementById('lastTransactionInfo').textContent = 'Revenue data updated';
            document.getElementById('lastTransactionTime').textContent = now.toLocaleTimeString();
            
            // Pulse the live status if there's revenue
            if (currentHourRevenue > 0) {
                pulseRevenueStatus();
            }
        }
        
        function pulseRevenueStatus() {
            const statusBadge = document.getElementById('revenueStatus');
            statusBadge.style.animation = 'pulse 1s ease-in-out';
            setTimeout(() => {
                statusBadge.style.animation = '';
            }, 1000);
        }
        
        // Add CSS animation for pulse effect
        const pulseStyle = document.createElement('style');
        pulseStyle.textContent = `
            @keyframes pulse {
                0% { transform: scale(1); }
                50% { transform: scale(1.1); }
                100% { transform: scale(1); }
            }
        `;
        document.head.appendChild(pulseStyle);
        
        // Real-Time Help Requests Functions
        let helpRequestsEventSource = null;
        
        function initHelpRequestsSSE() {
            try {
                // Initialize with current data
                loadHelpRequests();
                
                // Start SSE connection for real-time updates
                startHelpRequestsSSE();
                
            } catch (error) {
                console.error('Error initializing help requests:', error);
                // Fallback: load data every 30 seconds
                setInterval(loadHelpRequests, 30000);
            }
        }
        
        function loadHelpRequests() {
            fetch('get_help_requests.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateHelpRequestsDisplay(data.help_requests, data.stats);
                    } else {
                        console.error('Failed to load help requests:', data.message);
                        showHelpRequestsError('Failed to load rescue requests');
                    }
                })
                .catch(error => {
                    console.error('Error loading help requests:', error);
                    showHelpRequestsError('Error loading rescue requests');
                });
        }
        
        function startHelpRequestsSSE() {
            try {
                helpRequestsEventSource = new EventSource('sse_help_requests.php');
                
                helpRequestsEventSource.onmessage = function(event) {
                    try {
                        const data = JSON.parse(event.data);
                        handleHelpRequestsSSE(data);
                    } catch (error) {
                        console.error('Error parsing help requests SSE message:', error);
                    }
                };
                
                helpRequestsEventSource.onerror = function(event) {
                    console.error('Help Requests SSE Error:', event);
                    helpRequestsEventSource.close();
                    
                    // Retry connection after 5 seconds
                    setTimeout(() => {
                        startHelpRequestsSSE();
                    }, 5000);
                };
                
            } catch (error) {
                console.error('Error starting help requests SSE:', error);
                // Fallback to polling
                setInterval(loadHelpRequests, 30000);
            }
        }
        
        function handleHelpRequestsSSE(data) {
            switch (data.type) {
                case 'connection':
                    console.log('Help Requests SSE connected:', data.message);
                    break;
                    
                case 'update':
                    updateHelpRequestsDisplay(data.help_requests, data.stats);
                    pulseRescueStatus();
                    break;
                    
                case 'heartbeat':
                    // Keep connection alive
                    break;
                    
                case 'error':
                    console.error('Help Requests SSE Error:', data.message);
                    break;
                    
                default:
                    console.log('Unknown SSE message type:', data.type);
            }
        }
        
        function updateHelpRequestsDisplay(helpRequests, stats) {
            // Update statistics
            if (stats) {
                document.getElementById('pendingCount').textContent = stats.pending_count || 0;
                document.getElementById('inProgressCount').textContent = stats.in_progress_count || 0;
                document.getElementById('completedCount').textContent = stats.completed_count || 0;
            }
            
            // Update help requests list
            const container = document.getElementById('helpRequestsList');
            const noRequestsMessage = document.getElementById('noRequestsMessage');
            
            if (!helpRequests || helpRequests.length === 0) {
                container.style.display = 'none';
                noRequestsMessage.style.display = 'block';
                return;
            }
            
            container.style.display = 'block';
            noRequestsMessage.style.display = 'none';
            
            // Generate HTML for help requests
            let html = '';
            helpRequests.forEach(request => {
                const statusClass = request.status === 'Pending' ? 'text-warning' : 'text-info';
                const statusIcon = request.status === 'Pending' ? 'fa-clock' : 'fa-tools';
                
                html += `
                    <div class="d-flex align-items-center border-bottom py-3">
                        <img src="${request.user_image}" alt="${request.name}" class="rounded-circle flex-shrink-0" style="width: 40px; height: 40px; object-fit: cover;">
                        <div class="w-100 ms-3">
                            <div class="d-flex w-100 justify-content-between align-items-center">
                                <h6 class="mb-0 text-white">${request.name}</h6>
                                <div class="d-flex align-items-center">
                                    <i class="fas ${statusIcon} ${statusClass} me-1"></i>
                                    <small class="text-muted">${request.time_ago}</small>
                                </div>
                            </div>
                            <p class="mb-1 text-white-50">${request.problem_description}</p>
                            <div class="d-flex align-items-center justify-content-between">
                                <small class="text-muted">
                                    <i class="fas fa-motorcycle me-1"></i>${request.bike_unit}
                                </small>
                                <small class="text-muted">
                                    <i class="fas fa-map-marker-alt me-1"></i>${request.barangay_name}
                                </small>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            container.innerHTML = html;
        }
        
        function showHelpRequestsError(message) {
            const container = document.getElementById('helpRequestsList');
            container.innerHTML = `
                <div class="text-center text-danger py-4">
                    <i class="fas fa-exclamation-triangle fa-2x mb-2"></i>
                    <p class="mb-0">${message}</p>
                    <button class="btn btn-outline-danger btn-sm mt-2" onclick="loadHelpRequests()">
                        <i class="fas fa-redo me-1"></i>Retry
                    </button>
                </div>
            `;
        }
        
        function pulseRescueStatus() {
            const statusBadge = document.getElementById('rescueStatus');
            statusBadge.style.animation = 'pulse 1s ease-in-out';
            setTimeout(() => {
                statusBadge.style.animation = '';
            }, 1000);
        }
        
        // Cleanup SSE connection when page unloads
        window.addEventListener('beforeunload', function() {
            if (helpRequestsEventSource) {
                helpRequestsEventSource.close();
            }
        });
    </script>
    <script src="js/script.js"></script>
    
    <!-- Force logout to work properly -->
    <script>
        // Override any logout link behavior to ensure it works
        document.addEventListener('DOMContentLoaded', function() {
            const logoutLinks = document.querySelectorAll('a[href*="logout.php"]');
            logoutLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    // Force immediate redirect to logout.php
                    window.location.replace('logout.php');
                });
            });
        });
    </script>
</body>
</html> 
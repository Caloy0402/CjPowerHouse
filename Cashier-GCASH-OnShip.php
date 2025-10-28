<?php
require 'dbconn.php'; // Ensure this file contains your database connection logic

// Start the session to access session variables
session_start();

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

// Function to count orders for a given status and payment method
function getOrderCount($conn, $status, $paymentMethod, $date = null) {
    $sql = "SELECT COUNT(*) FROM orders WHERE order_status = ? AND payment_method = ?";
    $params = [$status, $paymentMethod];
    $types = "ss";

    if ($date !== null) {
        $sql .= " AND DATE(order_date) = ?";
        $params[] = $date;
        $types .= "s";
    }

    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        error_log("Prepare failed: " . $conn->error);
        return "Error";
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $count = 0;
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return $count;
}

// Modified function to count ON-SHIP GCASH orders from a specific barangay
function getBarangayOnShipGCASHOrderCount($conn, $barangayId) {
    $sql = "SELECT COUNT(o.id)
            FROM orders o
            JOIN users u ON o.user_id = u.id
            WHERE u.barangay_id = ? AND o.payment_method = 'GCASH' AND o.order_status = 'On-Ship'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $barangayId);
    $stmt->execute();
    $count = 0;
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return $count;
}

// Get counts for GCASH orders (general - all time)
$pendingGCASHCount = getOrderCount($conn, 'Pending Payment', 'GCASH') + getOrderCount($conn, 'Processing', 'GCASH');
$readyToShipGCASHCount = getOrderCount($conn, 'Ready to Ship', 'GCASH');
$onShipGCASHCount = getOrderCount($conn, 'On-Ship', 'GCASH');

// Get today's counts
$today = date("Y-m-d"); // Get current date in YYYY-MM-DD format
$todayPendingGCASHCount = getOrderCount($conn, 'Pending Payment', 'GCASH', $today) + getOrderCount($conn, 'Processing', 'GCASH', $today);
$todayReadyToShipCount = getOrderCount($conn, 'Ready to Ship', 'GCASH', $today);
$todayOnDeliveryCount = getOrderCount($conn, 'On-Ship', 'GCASH', $today); // Assuming "On-Ship" is your "On-Delivery" status
$todaySuccessfulCount = getOrderCount($conn, 'Completed', 'GCASH', $today); // Assuming "Completed" is your "Successful" status

// Fetch all barangays from the barangays table
$sql_barangays = "SELECT id, barangay_name FROM barangays";
$result_barangays = $conn->query($sql_barangays);

$barangays = [];
if ($result_barangays->num_rows > 0) {
    while ($row = $result_barangays->fetch_assoc()) {
        $barangays[] = $row;
    }
}

// Check if a specific barangay is selected
$selectedBarangayId = isset($_GET['barangay_id']) ? (int)$_GET['barangay_id'] : null;

// Construct the WHERE clause for filtering orders
$whereClause = " WHERE o.payment_method = 'GCASH' AND o.order_status = 'On-Ship'";
if ($selectedBarangayId !== null) {
    $whereClause .= " AND u.barangay_id = " . $selectedBarangayId;
}

// Simple check if delivery fee columns exist by trying to select them
$ordersHasDeliveryCols = false;
try {
    $testQuery = "SELECT delivery_fee, total_amount_with_delivery FROM orders LIMIT 1";
    $conn->query($testQuery);
    $ordersHasDeliveryCols = true;
} catch (Exception $e) {
    // Columns don't exist, use fallback
    $ordersHasDeliveryCols = false;
}

$selectDeliveryCols = $ordersHasDeliveryCols
    ? ", o.delivery_fee, o.total_amount_with_delivery"
    : ", 0 AS delivery_fee, o.total_price AS total_amount_with_delivery";

// Always join barangay_fares and compute effective fee/total from fare when order fields are empty
// Apply free shipping logic and handle different delivery methods
$selectFareFallback = ", 
    CASE 
        WHEN o.delivery_method = 'staff' THEN COALESCE(NULLIF(o.delivery_fee, 0), bf.staff_fare_amount, 0)
        ELSE COALESCE(NULLIF(o.delivery_fee, 0), bf.fare_amount, 0) 
    END AS delivery_fee_effective,
    CASE 
        WHEN (o.total_amount_with_delivery IS NULL OR o.total_amount_with_delivery = 0)
        THEN (o.total_price + 
            CASE 
                WHEN o.delivery_method = 'staff' THEN COALESCE(NULLIF(o.delivery_fee, 0), bf.staff_fare_amount, 0)
                ELSE COALESCE(NULLIF(o.delivery_fee, 0), bf.fare_amount, 0) 
            END)
        ELSE o.total_amount_with_delivery 
    END AS total_with_delivery_effective";

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Jandi - Cashier Dashboard</title>
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

        <style>
        /* Dark theme styles for GCASH Onship table */
        .table-responsive {
            margin-top: 10px;
            max-height: 300px !important;
            overflow-y: auto !important;
            border: 1px solid #dee2e6 !important;
            border-radius: 8px !important;
        }
        
        .table-responsive::-webkit-scrollbar {
            width: 8px !important;
        }
        
        .table-responsive::-webkit-scrollbar-track {
            background: #f1f1f1 !important;
            border-radius: 4px !important;
        }
        
        .table-responsive::-webkit-scrollbar-thumb {
            background: #888 !important;
            border-radius: 4px !important;
        }
        
        .table-responsive::-webkit-scrollbar-thumb:hover {
            background: #555 !important;
        }
        
        .table-responsive .table thead th {
            position: sticky !important;
            top: 0 !important;
            z-index: 10 !important;
            background-color: #212529 !important;
            color: white !important;
            font-weight: bold !important;
        }
        
        .bg-secondary.rounded.p-4 {
            background-color: #212529 !important;
        }
        
        .table-hover tbody tr {
            background-color: #fffbdb !important;
            border-bottom: 1px solid #dee2e6 !important;
        }
        
        .table-hover tbody tr:hover {
            background-color: #fff3cd !important;
        }
        
        .table-hover tbody tr:first-child {
            border-top: 1px solid #dee2e6 !important;
        }
        
        .btn-sm.btn-success {
            background: #28a745 !important;
            background-color: #28a745 !important;
            border: 3px solid #155724 !important;
            color: #ffffff !important;
            border-radius: 8px !important;
            padding: 10px 20px !important;
            font-weight: 700 !important;
            font-size: 15px !important;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.8) !important;
            box-shadow: 0 4px 12px rgba(40,167,69,0.6) !important;
            transition: all 0.3s ease !important;
            min-width: 90px !important;
            text-align: center !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            opacity: 1 !important;
        }
        
        .btn-sm.btn-success:hover {
            background: #1e7e34 !important;
            background-color: #1e7e34 !important;
            border-color: #0f5132 !important;
            transform: translateY(-2px) scale(1.05) !important;
            box-shadow: 0 6px 16px rgba(40,167,69,0.5), inset 0 1px 0 rgba(255,255,255,0.3) !important;
            color: #ffffff !important;
        }
        
        .btn-sm.btn-success i {
            font-size: 14px !important;
            margin-right: 8px !important;
            font-weight: bold !important;
            color: #ffffff !important;
        }
        
        .bg-secondary.rounded.p-4 h4 {
            color: white !important;
            font-weight: 700 !important;
            margin-bottom: 1rem !important;
        }
        
        .table.table-hover {
            border: none !important;
        }
        
        .table.table-hover td, 
        .table.table-hover th {
            border: none !important;
        }

    .barangay-buttons {
    display: flex;
    flex-wrap: wrap; /* Allow buttons to wrap to the next line */
    justify-content: flex-start; /* Align items to the start of the container */
    align-items: center; /* Vertically align items */
    margin-bottom: 10px; /* Adjust margin as needed */
}

.barangay-button {
    background-color: #6c757d; /* Grey background color */
    color: white; /* Text color */
    border: none;
    padding: 8px 12px; /* Button padding */
    margin: 5px; /* Spacing around buttons */
    border-radius: 5px; /* Rounded corners */
    font-size: 14px; /* Font size */
    cursor: pointer; /* Change cursor to pointer on hover */
    transition: background-color 0.3s ease; /* Smooth transition on hover */
    position: relative; /* For badge positioning */
}

.barangay-button:hover {
    background-color: #5a6268; /* Darker grey on hover */
}

.barangay-button .badge {
    position: absolute;
    top: -5px; /* Adjust position as needed */
    right: -5px; /* Adjust position as needed */
    padding: 2px 5px; /* Adjust padding as needed */
    border-radius: 50%;
    background-color: red;
    color: white;
    font-size: 10px; /* Font size */
}
        /* Custom styles for status tracker */
        .status-tracker {
            display: flex;
            justify-content: space-between; /* Distribute buttons evenly */
            align-items: center;
            padding: 20px;
            position: relative;
             margin-bottom: 10px; /* Reduced margin */
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
            z-index: 1; /* Ensure buttons are above the line */
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
            z-index: 0; /* Place the line behind the buttons */
        }
        .table-responsive {
             margin-top: 10px; /* Reduced margin */
        }
        /* Custom styles for modal inputs */
    #orderDetailsModal .form-control {
        background-color: white;
        color: black; /* Ensure text is readable */
    }

/* Custom styles for modal select (dropdown) */
#orderDetailsModal .form-select {
    background-color: white;
    color: black; /* Ensure text is readable */
}

/* Professional Modal Styles */
.section-header {
    background-color: #dc3545;
    color: white;
    padding: 12px 16px;
    margin: 0 0 15px 0;
    border-radius: 8px;
    font-weight: 600;
    font-size: 16px;
    display: flex;
    align-items: center;
}

.section-content {
    background-color: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
    border: 1px solid #e9ecef;
}

.customer-profile-img {
    width: 120px;
    height: 120px;
    object-fit: cover;
    border-radius: 8px;
    border: 3px solid #dc3545;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.customer-info-grid, .order-info-grid, .rider-info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
    width: 100%;
}

.info-row {
    display: flex;
    flex-direction: column;
    margin-bottom: 12px;
}

.info-row label {
    font-weight: 600;
    color: #495057;
    font-size: 14px;
    margin-bottom: 4px;
}

.info-value {
    background-color: white;
    padding: 8px 12px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    font-size: 14px;
    color: #212529;
    min-height: 36px;
    display: flex;
    align-items: center;
}

.price-value {
    color: #28a745 !important;
    font-weight: 600;
}

.customer-details-section, .order-info-section {
    margin-bottom: 25px;
}

/* Staff Delivery Notice Styling */
.staff-delivery-notice .alert {
    border: none;
    border-radius: 8px;
    padding: 15px;
    margin: 0;
}

.staff-delivery-notice .alert-info {
    background-color: #e3f2fd;
    border-left: 4px solid #2196f3;
}

/* Rider Details Section */
.rider-details-section {
    margin-bottom: 25px;
}

/* Order Items Section */
.order-items-section {
    margin-bottom: 25px;
}

.order-item-card {
    display: flex;
    align-items: center;
    padding: 15px;
    margin-bottom: 10px;
    background-color: white;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.order-item-image {
    width: 60px;
    height: 60px;
    object-fit: cover;
    border-radius: 6px;
    border: 2px solid #dc3545;
    margin-right: 15px;
}

.order-item-details {
    flex: 1;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.order-item-info h6 {
    margin: 0 0 5px 0;
    color: #333;
    font-weight: 600;
    font-size: 14px;
}

.order-item-info p {
    margin: 0;
    color: #666;
    font-size: 12px;
}

.order-item-quantity {
    background-color: #dc3545;
    color: white;
    padding: 4px 8px;
    border-radius: 4px;
    font-weight: 600;
    font-size: 12px;
    margin-right: 10px;
}

.order-item-price {
    color: #28a745;
    font-weight: 600;
    font-size: 14px;
}

/* Return Order Form Styling */
.return-order-form {
    background-color: #fff3cd;
    border: 1px solid #ffeaa7;
    border-radius: 8px;
    padding: 20px;
}

.return-order-form .section-header {
    background-color: #ffc107;
    color: #212529;
}

.return-order-form .section-content {
    background-color: #fff;
    border: 1px solid #dee2e6;
}

.return-order-form .form-label {
    font-weight: 600;
    color: #495057;
}

.return-order-form .form-select.is-invalid {
    border-color: #dc3545;
    box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
}

.return-order-form .invalid-feedback {
    color: #dc3545;
    font-size: 0.875rem;
    margin-top: 0.25rem;
}
        </style>
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
        <div class="navbar-brand mx-4 mb-3">
            <h3 class="text-primary"><i class="fa fa-user-edit me-2"></i>Cj P'House</h3>
        </div>
        <div class="d-flex align-items-center ms-4 mb-4">
            <div class="position-relative">
                <img src="<?php echo $profile_image; ?>" alt="" class="rounded-circle" style="width: 40px; height: 40px;">
                <div class="bg-success rounded-circle border border-2 border-white position-absolute end-0 bottom-0 p-1"></div>
            </div>
            <div class="ms-3">
                <h6 class="mb-0"><?php echo htmlspecialchars($user_name); ?></h6>
                <span id="role">Cashier</span>
            </div>
        </div>
        <div class="navbar-nav w-100">
            <a href="Cashier-Dashboard.php" class="nav-item nav-link"><i class="fa fa-tachometer-alt me-2"></i>Dashboard</a>
            <div class="nav-item dropdown">
                <a href="#" class="nav-link dropdown-toggle active" data-bs-toggle="dropdown">
                    <i class="fa fa-shopping-cart me-2"></i>Pending Orders
                </a>
                <div class="dropdown-menu bg-transparent border-0">
                    <a href="Cashier-COD-Delivery.php" class="dropdown-item active">Pending COD orders</a>
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
            <a href="index.php" class="navbar-brand d-flex d-lg-none me-4">
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
    <?php include 'cashier_notifications.php'; ?>
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
 <!-- Sales & Revenue Start -->
<div class="container-fluid pt-4 px-4">
    <div class="row g-3"> <!-- Reduced spacing (g-3) for better fit -->
        <div class="col-md-3 col-sm-6">  <!-- Reduced to col-md-3 to fit four cards -->
            <div class="bg-secondary rounded d-flex align-items-center justify-content-between p-3">
                <i class="fa fa-clock fa-3x text-danger"></i>
               <div class="ms-2">
                                   <p class="mb-1 text-white">Today's Pending/Processing GCASH Orders</p>
                                   <h6 class="mb-0 text-white"><?php echo $todayPendingGCASHCount !== "Error" ? htmlspecialchars($todayPendingGCASHCount) : "Error"; ?></h6>
                               </div>
            </div>
        </div>
               <div class="col-md-3 col-sm-6">   <!-- Reduced to col-md-3 to fit four cards -->
            <div class="bg-secondary rounded d-flex align-items-center justify-content-between p-3">
                <i class="fa fa-check-circle fa-3x text-danger"></i>  <!-- Updated icon -->
               <div class="ms-2">
                                   <p class="mb-1 text-white">Today's Ready to Ship</p>  <!-- Updated text -->
                                   <h6 class="mb-0 text-white"><?php echo $todayReadyToShipCount !== "Error" ? htmlspecialchars($todayReadyToShipCount) : "Error"; ?></h6>
                               </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">  <!-- Reduced to col-md-3 to fit four cards -->
            <div class="bg-secondary rounded d-flex align-items-center justify-content-between p-3">
                <i class="fa fa-truck fa-3x text-danger"></i>
                <div class="ms-2">
                    <p class="mb-1 text-white">Today's On-delivery Orders</p>
                    <h6 class="mb-0 text-white"><?php echo $todayOnDeliveryCount !== "Error" ? htmlspecialchars($todayOnDeliveryCount) : "Error"; ?></h6>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">  <!-- Reduced to col-md-3 to fit four cards -->
            <div class="bg-secondary rounded d-flex align-items-center justify-content-between p-3">
                <i class="fa fa-cash-register fa-3x text-danger"></i>
                <div class="ms-2">
                    <p class="mb-1 text-white">Today's Successful Orders</p>
                    <h6 class="mb-0 text-white"><?php echo $todaySuccessfulCount !== "Error" ? htmlspecialchars($todaySuccessfulCount) : "Error"; ?></h6>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- Sales & Revenue End -->
 <!-- Barangay Buttons Start -->
<div class="container-fluid pt-4 px-4">
    <div class="barangay-buttons">
        <?php foreach ($barangays as $barangay): ?>
            <?php
                $barangayOrderCount = getBarangayOnShipGCASHOrderCount($conn, $barangay['id']);
            ?>
            <a href="?barangay_id=<?php echo $barangay['id']; ?>" class="barangay-button">
                <?php echo htmlspecialchars($barangay['barangay_name']); ?>
                <?php if ($barangayOrderCount > 0): ?>
                    <span class="badge"><?php echo $barangayOrderCount; ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<!-- Barangay Buttons End -->

<!-- Status Buttons Start -->
<div class="container-fluid pt-4 px-4">
    <div class="status-tracker">
        <a href="Cashier-GCASH-Delivery.php" class="status-button">
            <i class="fa fa-exclamation-triangle"></i> Pending/Processing GCASH
            <?php if ($pendingGCASHCount > 0): ?>
                <span class="badge"><?php echo $pendingGCASHCount; ?></span>
            <?php endif; ?>
        </a>
        <a href="Cashier-GCASH-Ready.php" class="status-button">
            <i class="fa fa-check-circle"></i> Ready to Ship
            <?php if ($readyToShipGCASHCount > 0): ?>
                <span class="badge"><?php echo $readyToShipGCASHCount; ?></span>
            <?php endif; ?>
        </a>
        <a href="Cashier-GCASH-OnShip.php" class="status-button">
            <i class="fa fa-truck"></i> On-Ship
            <?php if ($onShipGCASHCount > 0): ?>
                <span class="badge"><?php echo $onShipGCASHCount; ?></span>
            <?php endif; ?>
        </a>
    </div>
</div>
<!-- Status Buttons End -->
<!-- Pending Orders Via COD Payment Start -->
<div class="container-fluid pt-4 px-4">
    <div class="bg-secondary rounded p-4">
       <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="mb-0">Ready to On Ship Orders</h4>
        </div>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr class="text-white">
                        <th scope="col" class="text-center">Order ID</th>
                        <th scope="col" class="text-center">TRN #</th>
                        <th scope="col" class="text-center">Time Stamp Order</th>
                        <th scope="col" class="text-center">Customer Name</th>
                        <th scope="col" class="text-center">Address</th>
                        <th scope="col" class="text-center">Status</th>
                        <th scope="col" class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody id="pending-gcash-orders">
                    <!-- Ready to Ship GCASH orders will be dynamically populated here -->
                    <?php
                    // Construct the base SQL query
                    $sql = "SELECT o.id, t.transaction_number, o.order_date,
                                   u.first_name, u.last_name, o.order_status,
                                   u.barangay_id, u.purok, b.barangay_name,
                                   o.rider_name, o.rider_contact,
                                   o.rider_motor_type, o.rider_plate_number,
                                   o.total_price,o.payment_method,o.total_weight,o.delivery_method, o.home_description,
                                   u.ImagePath, -- Get ImagePath
                                   COALESCE(gt.reference_number, '') as reference_number $selectDeliveryCols $selectFareFallback, COALESCE(bf.fare_amount, 0) AS barangay_fare, COALESCE(bf.staff_fare_amount, 0) AS barangay_staff_fare
                             FROM orders o
                             JOIN transactions t ON o.id = t.order_id
                             JOIN users u ON o.user_id = u.id
                             JOIN barangays b ON u.barangay_id = b.id
                             LEFT JOIN gcash_transactions gt ON o.id = gt.order_id
                             LEFT JOIN barangay_fares bf ON b.id = bf.barangay_id";

                    // Add the WHERE clause for filtering orders
                    $sql .= $whereClause;

                    $result = $conn->query($sql);

                    if ($result->num_rows > 0) {
                        while ($row = $result->fetch_assoc()) {
                            echo '<tr>
                                    <td class="text-center">' . htmlspecialchars($row['id']) . '</td>
                                    <td class="text-center">' . htmlspecialchars($row['transaction_number']) . '</td>
                                    <td class="text-center">' . htmlspecialchars($row['order_date']) . '</td>
                                    <td class="text-center">' . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . '</td>
                                    <td class="text-center">' . htmlspecialchars($row['purok'] . ', ' . $row['barangay_name']) . '</td>
                                    <td class="text-center">' . htmlspecialchars($row['order_status']) . '</td>
                                    <td class="text-center">
                                        <a href="#" class="btn btn-sm btn-success"
                                           data-bs-toggle="modal"
                                           data-bs-target="#orderDetailsModal"
                                           data-order-id="' . htmlspecialchars($row['id']) . '"
                                           data-transaction-number="' . htmlspecialchars($row['transaction_number']) . '"
                                           data-order-date="' . htmlspecialchars($row['order_date']) . '"
                                           data-customer-name="' . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . '"
                                           data-barangay="' . htmlspecialchars($row['barangay_name']) . '"
                                           data-purok="' . htmlspecialchars($row['purok']) . '"
                                           data-rider-name="' . htmlspecialchars($row['rider_name']) . '"
                                           data-rider-contact="' . htmlspecialchars($row['rider_contact']) . '"
                                           data-rider-motor-type="' . htmlspecialchars($row['rider_motor_type']) . '"
                                           data-rider-plate-number="' . htmlspecialchars($row['rider_plate_number']) . '"
                                           data-total-price="' . htmlspecialchars($row['total_price']) . '"
                                           data-payment-method="' . htmlspecialchars($row['payment_method']) . '"
                                           data-reference-number="' . htmlspecialchars($row['reference_number']) . '"
                                           data-total-weight="' . htmlspecialchars($row['total_weight']) . '"
                                           data-delivery-method="' . htmlspecialchars($row['delivery_method']) . '"
                                           data-home-description="' . htmlspecialchars($row['home_description']) . '"
                                           data-delivery-fee="' . htmlspecialchars($row['delivery_fee_effective']) . '"
                                           data-total-with-delivery="' . htmlspecialchars($row['total_with_delivery_effective']) . '"
                                           data-barangay-fare="' . htmlspecialchars($row['barangay_fare']) . '"
                                           data-image-path="' . htmlspecialchars($row['ImagePath']) . '"
                                           >
                                            <i class="fas fa-eye me-1"></i>View Details
                                        </a>
                                    </td>
                                  </tr>';
                        }
                    } else {
                        echo '<tr><td colspan="7">No Ready to Ship GCASH orders found.</td></tr>';
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<!-- Pending Orders Via GCash Payment End -->

<!-- Modal for Order Details -->
<div class="modal fade" id="orderDetailsModal" tabindex="-1" aria-labelledby="orderDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-dark w-100 text-center" id="orderDetailsModalLabel">Customer & Order Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" style="max-height: 75vh; overflow-y: auto;">
                <form id="updateOrderForm">
                    <input type="hidden" id="modalOrderId" name="order_id">

                <!-- Customer Details Section -->
                <div class="customer-details-section mb-4">
                    <div class="section-header">
                        <i class="fas fa-user me-2"></i>Customer Details
                    </div>
                    <div class="section-content">
                    <div class="d-flex flex-row align-items-start">
                        <!-- Customer Image on the Left -->
                            <div class="customer-image me-4">
                                <img id="modalCustomerImage" src="" alt="Customer Image" class="customer-profile-img">
                        </div>
                        <!-- Customer Details on the Right -->
                            <div class="customer-info-grid">
                                <div class="info-row">
                                    <label>Full Name</label>
                                    <div class="info-value" id="modalCustomerName"></div>
                            </div>
                                <div class="info-row">
                                    <label>Shipping Address</label>
                                    <div class="info-value" id="modalShippingAddress"></div>
                                </div>
                                <div class="info-row">
                                    <label>Transaction Number</label>
                                    <div class="info-value" id="modalTransactionNumber"></div>
                            </div>
                                <div class="info-row">
                                    <label>Order Date</label>
                                    <div class="info-value" id="modalOrderDate"></div>
                            </div>
                            </div>
                            </div>
                            </div>
                            </div>

                <!-- Order Information Section -->
                <div class="order-info-section mb-4">
                    <div class="section-header">
                        <i class="fas fa-shopping-cart me-2"></i>Order Information
                    </div>
                    <div class="section-content">
                        <div class="order-info-grid">
                            <div class="info-row">
                                <label>Total Price</label>
                                <div class="info-value price-value" id="modalTotalPrice"></div>
                            </div>
                            <div class="info-row">
                                <label>Delivery Fee</label>
                                <div class="info-value price-value" id="modalDeliveryFee"></div>
                            </div>
                            <div class="info-row">
                                <label>Total with Delivery</label>
                                <div class="info-value price-value" id="modalTotalWithDelivery"></div>
                            </div>
                            <div class="info-row">
                                <label>Payment Method</label>
                                <div class="info-value" id="modalPaymentMethod"></div>
                            </div>
                            <div class="info-row">
                                <label>Total Weight</label>
                                <div class="info-value" id="modalTotalWeight"></div>
                            </div>
                            <div class="info-row">
                                <label>Delivery Method</label>
                                <div class="info-value" id="modalDeliveryMethod"></div>
                            </div>
                            <div class="info-row">
                                <label>Home Description</label>
                                <div class="info-value" id="modalHomeDescription"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Order Items Section -->
                <div class="order-items-section mb-4">
                    <div class="section-header">
                        <i class="fas fa-box me-2"></i>Order Items
                    </div>
                    <div class="section-content">
                        <div id="orderItemsContainer">
                            <!-- Order items will be loaded here -->
                        </div>
                    </div>
                </div>
                
               
                <!-- Rider Details Section - Only show for local rider delivery -->
                <div class="rider-details-section mb-4" id="riderDetailsSection" style="display: none;">
                    <div class="section-header">
                        <i class="fas fa-motorcycle me-2"></i>Rider Details
                    </div>
                    <div class="section-content">
                        <div class="rider-info-grid">
                            <div class="info-row">
                                <label>Rider Name</label>
                                <div class="info-value" id="modalRiderName"></div>
                    </div>
                            <div class="info-row">
                                <label>Rider Contact</label>
                                <div class="info-value" id="modalRiderContactInfo"></div>
                    </div>
                            <div class="info-row">
                                <label>Motor Type</label>
                                <div class="info-value" id="modalMotorType"></div>
                            </div>
                            <div class="info-row">
                                <label>Plate Number</label>
                                <div class="info-value" id="modalPlateNumber"></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Staff Delivery Notice - Only show for staff delivery -->
                <div class="staff-delivery-notice mb-4" id="staffDeliveryNotice" style="display: none;">
                    <div class="alert alert-info">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-truck me-3 fs-4 text-primary"></i>
                            <div>
                                <h6 class="mb-1 text-primary">Staff Delivery Order</h6>
                                <p class="mb-0 text-muted">This order will be delivered by CJ PowerHouse staff. No rider assignment required.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Return Order Form - Hidden by default -->
                <div class="return-order-form mb-4" id="returnOrderForm" style="display: none;">
                    <div class="section-header">
                        <i class="fas fa-undo me-2"></i>Return Order
                    </div>
                    <div class="section-content">
                        <div class="mb-3">
                            <label for="returnReasonSelect" class="form-label"><strong>Reason for Return:</strong></label>
                            <select class="form-select" id="returnReasonSelect" name="return_reason" required>
                                <option value="">Select a reason...</option>
                                <option value="Customer not available">Customer not available</option>
                                <option value="Wrong address">Wrong address</option>
                                <option value="Customer refused delivery">Customer refused delivery</option>
                                <option value="Package damaged">Package damaged</option>
                                <option value="Customer requested return">Customer requested return</option>
                                <option value="Delivery failed">Delivery failed</option>
                                <option value="Other">Other</option>
                            </select>
                            <div class="form-text text-muted">Please select a reason for returning this order</div>
                        </div>
                        <div class="mb-3">
                            <label for="returnNotesInput" class="form-label"><strong>Additional Notes:</strong></label>
                            <textarea class="form-control" id="returnNotesInput" name="return_notes" rows="3" placeholder="Enter additional details about the return..."></textarea>
                        </div>
                    </div>
                </div>
                    
            <div class="modal-footer">
                <!-- Default buttons -->
                <div id="defaultButtons">
                    <button type="button" class="btn btn-success" id="completeOrderBtn">
                        <i class="fas fa-check-circle me-1"></i>Mark as Completed
                    </button>
                    <button type="button" class="btn btn-warning" id="returnOrderBtn">
                        <i class="fas fa-undo me-1"></i>Mark as Returned
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>

                <!-- Return confirmation buttons -->
                <div id="returnButtons" style="display: none;">
                    <button type="button" class="btn btn-secondary" id="cancelReturnBtn">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-warning" id="confirmReturnBtn">
                        <i class="fas fa-check me-1"></i>Confirm Return
                    </button>
                </div>
            </div>
        </div>
    </div>
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

    <!-- Template Javascript -->
    <script src="js/main.js"></script>
    <script>
        // Function to populate the modal with data
        $('#orderDetailsModal').on('show.bs.modal', function (event) {
            console.log('Modal opening...');
            var button = $(event.relatedTarget); // Button that triggered the modal
            console.log('Button data:', button.data());

            var orderId = button.data('order-id');
            var transactionNumber = button.data('transaction-number');
            var orderDate = button.data('order-date');
            var customerName = button.data('customer-name');
            var barangay = button.data('barangay'); // Get Barangay
            var purok = button.data('purok'); // Get Purok
            var riderName = button.data('rider-name');
            var riderContact = button.data('rider-contact');
            var motorType = button.data('rider-motor-type');
            var plateNumber = button.data('rider-plate-number');

            var totalPrice = button.data('total-price');
            var paymentMethod = button.data('payment-method');
            var referenceNumber = button.data('reference-number');
            var totalWeight = button.data('total-weight');
            var deliveryMethod = button.data('delivery-method');
            var homeDescription = button.data('home-description');
            var deliveryFee = button.data('delivery-fee');
            var totalWithDelivery = button.data('total-with-delivery');
            var barangayFare = button.data('barangay-fare');
            var imagePath = button.data('image-path'); // Get ImagePath

            console.log('Extracted data:', {
                orderId, transactionNumber, orderDate, customerName, barangay, purok,
                riderName, riderContact, motorType, plateNumber, totalPrice,
                paymentMethod, referenceNumber, totalWeight, deliveryMethod,
                homeDescription, deliveryFee, totalWithDelivery, barangayFare, imagePath
            });

            // Check if order_id is valid
            if (!orderId || orderId === 'undefined' || orderId === '') {
                console.error('Order ID is missing or invalid:', orderId);
                $('#orderItemsContainer').html('<p class="text-danger text-center">Error: Invalid order ID</p>');
                return;
            }

            // Set the values in the modal
            console.log('Setting modal values...');
            console.log('Modal elements exist:', {
                modalOrderId: $('#modalOrderId').length,
                modalTransactionNumber: $('#modalTransactionNumber').length,
                modalOrderDate: $('#modalOrderDate').length,
                modalCustomerName: $('#modalCustomerName').length
            });

            $('#modalOrderId').val(orderId || '');
            $('#modalTransactionNumber').text(transactionNumber || 'N/A');
            $('#modalOrderDate').text(orderDate || 'N/A');
            $('#modalCustomerName').text(customerName || 'N/A');

            // Construct the shipping address with labels
            var shippingAddress = "Purok: " + (purok || 'N/A') + ", Brgy: " + (barangay || 'N/A') + ", Valencia City, Bukidnon";
            $('#modalShippingAddress').text(shippingAddress);

            $('#modalTotalPrice').text('₱' + (parseFloat(totalPrice) || 0).toFixed(2));
            // Display delivery fee (removed free shipping logic)
            $('#modalDeliveryFee').text('₱' + (parseFloat(deliveryFee) || 0).toFixed(2));
            $('#modalTotalWithDelivery').text('₱' + (parseFloat(totalWithDelivery) || parseFloat(totalPrice) || 0).toFixed(2));
            if (paymentMethod && paymentMethod.toUpperCase() === 'GCASH' && referenceNumber) {
                $('#modalPaymentMethod').text(paymentMethod + ' (RFN#: ' + referenceNumber + ')');
            } else {
                $('#modalPaymentMethod').text(paymentMethod || 'N/A');
            }
            $('#modalTotalWeight').text(totalWeight || 'N/A');
            $('#modalDeliveryMethod').text(deliveryMethod || 'N/A');
            $('#modalHomeDescription').text(homeDescription || 'N/A');

            // Set the rider details (display only like GCASH modal)
            $('#modalRiderName').text(riderName || 'N/A');
            $('#modalRiderContactInfo').text(riderContact || 'N/A');
            $('#modalMotorType').text(motorType || 'N/A');
            $('#modalPlateNumber').text(plateNumber || 'N/A');

            // Set the profile picture
            $('#modalCustomerImage').attr('src', imagePath || 'img/default.jpg');
            
            // Show/hide rider section based on delivery method
            if (deliveryMethod && deliveryMethod.toLowerCase() === 'staff') {
                // Hide rider details section for staff delivery
                $('#riderDetailsSection').hide();
                $('#staffDeliveryNotice').show();
            } else {
                // Show rider details section for local rider delivery
                $('#riderDetailsSection').show();
                $('#staffDeliveryNotice').hide();
            }
            
            // Load order items after a short delay to ensure modal is fully shown
            setTimeout(function() {
                loadOrderItems(orderId);
            }, 100);
        });
        
        // Function to load order items
        function loadOrderItems(orderId) {
            console.log('Loading order items for order ID:', orderId);

            // Ensure the container exists
            if ($('#orderItemsContainer').length === 0) {
                console.error('Order items container not found!');
                return;
            }

            if (!orderId || orderId <= 0) {
                console.error('Invalid order ID:', orderId);
                $('#orderItemsContainer').html('<p class="text-danger text-center">Invalid order ID</p>');
                return;
            }

            console.log('Making AJAX call to fetch_return_items.php with order_id:', orderId);
            $.ajax({
                url: 'fetch_return_items.php',
                method: 'GET',
                data: { order_id: orderId },
                dataType: 'json',
                timeout: 10000, // 10 second timeout
                success: function(response) {
                    console.log('Order items response:', response);
                    if (response.error) {
                        console.error('Server error:', response.error);
                        $('#orderItemsContainer').html('<p class="text-danger text-center">Error: ' + response.error + '</p>');
                    } else if (response.items && response.items.length > 0) {
                        console.log('Found ' + response.items.length + ' items');
                        displayOrderItems(response.items);
                    } else {
                        console.log('No items found in response');
                        $('#orderItemsContainer').html('<p class="text-muted text-center">No items found for this order</p>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', {
                        status: status,
                        error: error,
                        responseText: xhr.responseText,
                        statusCode: xhr.status
                    });
                    var errorMsg = 'Error loading items';
                    if (xhr.status === 404) {
                        errorMsg = 'File not found: fetch_return_items.php';
                    } else if (xhr.status === 500) {
                        errorMsg = 'Server error: ' + xhr.responseText;
                    } else if (status === 'timeout') {
                        errorMsg = 'Request timed out';
                    }
                    $('#orderItemsContainer').html('<p class="text-danger text-center">' + errorMsg + '</p>');
                }
            });
        }
        
        // Function to display order items
        function displayOrderItems(items) {
            console.log('Displaying order items:', items);
            var itemsHtml = '';

            if (items.length === 0) {
                itemsHtml = '<p class="text-muted text-center">No items found for this order</p>';
            } else {
                items.forEach(function(item) {
                    // Construct the correct image path
                    var imagePath = '';
                    if (item.image && item.image !== 'img/default-product.jpg' && item.image !== '') {
                        // For cashier interface, use uploads/ prefix
                        imagePath = 'uploads/' + item.image;
                    } else {
                        imagePath = 'img/default-product.jpg';
                    }

                    itemsHtml += `
                        <div class="order-item-card">
                            <img src="${imagePath}" alt="${item.product_name || 'Product'}" class="order-item-image" onerror="this.src='img/default-product.jpg'">
                            <div class="order-item-details">
                                <div class="order-item-info">
                                    <h6>${item.product_name || 'Unknown Product'}</h6>
                                    <p>Product ID: ${item.product_id || 'N/A'}</p>
                                </div>
                                <div class="d-flex align-items-center">
                                    <span class="order-item-quantity">Qty: ${item.quantity || 0}</span>
                                    <span class="order-item-price">₱${parseFloat(item.price || 0).toFixed(2)}</span>
                                </div>
                            </div>
                        </div>
                    `;
                });
            }

            $('#orderItemsContainer').html(itemsHtml);
        }

        // Complete Order functionality
        $('#completeOrderBtn').on('click', function() {
            var orderId = $('#modalOrderId').val();
            
            // Disable button during request
            $(this).prop('disabled', true);
            $(this).html('<i class="fas fa-spinner fa-spin me-1"></i>Processing...');
            
            $.ajax({
                url: 'update-order-status.php',
                type: 'POST',
                data: {
                    order_id: orderId,
                    new_status: 'Completed'
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showNotification('Order has been marked as completed successfully!', 'success');
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        showNotification('Error: ' + response.message, 'error');
                    }
                },
                error: function() {
                    showNotification('Error updating order status', 'error');
                },
                complete: function() {
                    // Re-enable button
                    $('#completeOrderBtn').prop('disabled', false);
                    $('#completeOrderBtn').html('<i class="fas fa-check-circle me-1"></i>Mark as Completed');
                }
            });
        });

        // Return Order functionality
        $('#returnOrderBtn').on('click', function() {
            // Show return form and hide default buttons
            $('#returnOrderForm').slideDown();
            $('#defaultButtons').hide();
            $('#returnButtons').show();

            // Reset form
            $('#returnReasonSelect').val('');
            $('#returnNotesInput').val('');
        });

        // Cancel Return functionality
        $('#cancelReturnBtn').on('click', function() {
            // Hide return form and show default buttons
            $('#returnOrderForm').slideUp();
            $('#defaultButtons').show();
            $('#returnButtons').hide();
        });

        // Confirm Return functionality
        $('#confirmReturnBtn').on('click', function() {
            var returnReason = $('#returnReasonSelect').val();
            var returnNotes = $('#returnNotesInput').val();

            // Validate reason selection
            if (!returnReason) {
                // Show inline error instead of notification
                $('#returnReasonSelect').addClass('is-invalid');
                $('#returnReasonSelect').after('<div class="invalid-feedback d-block">Please select a reason for return.</div>');
                return;
            } else {
                $('#returnReasonSelect').removeClass('is-invalid');
                $('#returnReasonSelect').next('.invalid-feedback').remove();
            }

            var orderId = $('#modalOrderId').val();

            // Disable button during request
            $(this).prop('disabled', true);
            $(this).html('<i class="fas fa-spinner fa-spin me-1"></i>Processing...');

            $.ajax({
                url: 'update-order-status.php',
                type: 'POST',
                data: {
                    order_id: orderId,
                    new_status: 'Returned',
                    return_reason: returnReason,
                    return_notes: returnNotes
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Automatically restock inventory for returned orders
                        $.ajax({
                            url: 'restock_return_order.php',
                            method: 'POST',
                            data: { order_id: orderId },
                            dataType: 'json',
                            success: function(restockResponse) {
                                if (restockResponse.success) {
                                    showNotification('Order returned and inventory restocked successfully!', 'success');
                                } else {
                                    showNotification('Order returned but inventory restock failed: ' + restockResponse.message, 'warning');
                                }
                                setTimeout(function() {
                                    location.reload();
                                }, 1500);
                            },
                            error: function() {
                                showNotification('Order returned but inventory restock failed due to network error', 'warning');
                                setTimeout(function() {
                                    location.reload();
                                }, 1500);
                            }
                        });
                    } else {
                        showNotification('Error: ' + response.message, 'error');
                    }
                },
                error: function() {
                    showNotification('Error updating order status', 'error');
                },
                complete: function() {
                    // Re-enable button
                    $('#confirmReturnBtn').prop('disabled', false);
                    $('#confirmReturnBtn').html('<i class="fas fa-check me-1"></i>Confirm Return');
                }
            });
        });

        // Notification function
        function showNotification(message, type) {
            var alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
            var notification = `
                <div class="alert ${alertClass} alert-dismissible fade show position-fixed" 
                     style="top: 20px; right: 20px; z-index: 9999; min-width: 300px;" role="alert">
                    <strong>${type === 'success' ? 'Success!' : 'Error!'}</strong> ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            `;
            $('body').append(notification);
            
            // Auto remove after 5 seconds
            setTimeout(function() {
                $('.alert').fadeOut();
            }, 5000);
        }
    </script>
    </script>
</body>
</html>
<?php $conn->close(); ?>
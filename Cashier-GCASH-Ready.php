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
    $count = 0; // Initialize count variable
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
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return $count;
}

// Modified function to count READY TO SHIP GCASH orders from a specific barangay
function getBarangayReadyToShipGCASHOrderCount($conn, $barangayId) {
    $count = 0; // Initialize count variable
    $sql = "SELECT COUNT(o.id)
            FROM orders o
            JOIN users u ON o.user_id = u.id
            WHERE u.barangay_id = ? AND o.payment_method = 'GCASH' AND o.order_status = 'Ready to Ship'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $barangayId);
    $stmt->execute();
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
$whereClause = " WHERE o.payment_method = 'GCASH' AND o.order_status = 'Ready to Ship'";
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
// Handle different delivery methods (removed free shipping logic)
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
    /* Ensure rider section is hidden by default for staff delivery */
    #riderDetailsSection {
        display: none !important;
    }
    
    #riderDetailsSection.show-rider {
        display: block !important;
    }
    
    /* Hide rider section when receipt modal is open */
    .modal.show #riderDetailsSection {
        display: none !important;
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

/* Print styles for receipt */
@media print {
    body * {
        visibility: hidden;
    }
    #receiptModal, #receiptModal * {
        visibility: visible;
    }
     #receiptContent {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: white !important;
        color: black !important;
        overflow: visible !important;
    }

    .modal-header,
    .modal-footer {
        display: none !important;
    }
}

.soft-edge-square {
    border-radius: 5px;
    object-fit: cover;
}

/* Table container styling */
.table-responsive {
    max-height: 500px;
    overflow-y: auto;
    border: 1px solid #495057;
    border-radius: 8px;
    scrollbar-width: thin;
    scrollbar-color: #6c757d #212529;
}

.table-responsive::-webkit-scrollbar {
    width: 8px;
}

.table-responsive::-webkit-scrollbar-track {
    background: #212529;
}

.table-responsive::-webkit-scrollbar-thumb {
    background: #6c757d;
    border-radius: 4px;
}

.table-responsive::-webkit-scrollbar-thumb:hover {
    background: #495057;
}

.table-responsive .table thead th {
    position: sticky;
    top: 0;
    background-color: #343a40;
    color: #ffffff !important;
    font-weight: 600;
    text-align: center;
    padding: 12px 8px;
    border-bottom: 2px solid #495057;
}

.bg-secondary.rounded.p-4 { background-color: #212529 !important; }

.table-hover tbody tr {
    background-color: #fffbdb !important;
    border-bottom: 1px solid #dee2e6 !important;
}
.table-hover tbody tr:hover { background-color: #fff3cd !important; }
.table-hover tbody tr:first-child { border-top: 1px solid #dee2e6 !important; }

.table.table-hover, .table.table-hover td, .table.table-hover th { border: none !important; }

.table.table-hover td *, .table.table-hover th * {
    list-style: none !important;
}

.table.table-hover td *::before, .table.table-hover th *::before,
.table.table-hover td *::after, .table.table-hover th *::after {
    content: none !important;
}

/* Green action button */
.btn-sm.btn-primary {
    background: #28a745 !important;
    background-color: #28a745 !important;
    border: 2px solid #155724 !important;
    color: #ffffff !important;
    border-radius: 20px !important;
    padding: 6px 14px !important;
    font-weight: 600 !important;
    font-size: 11px !important;
    text-shadow: 1px 1px 1px rgba(0,0,0,0.7) !important;
    box-shadow: 0 2px 6px rgba(40,167,69,0.4) !important;
    transition: all 0.3s ease !important;
    min-width: 65px !important;
    text-align: center !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    opacity: 1 !important;
}

.btn-sm.btn-primary:not(:hover):not(:active):not(:focus) {
    background: #28a745 !important;
    background-color: #28a745 !important;
}

.btn-sm.btn-primary:hover {
    background: #1e7e34 !important;
    background-color: #1e7e34 !important;
    border-color: #0f5132 !important;
    transform: translateY(-1px) scale(1.02) !important;
    box-shadow: 0 3px 8px rgba(40,167,69,0.4), inset 0 1px 0 rgba(255,255,255,0.2) !important;
    color: #ffffff !important;
}

.btn-sm.btn-primary i { font-size: 11px !important; }

/* Professional Modal Styling */
#orderDetailsModal .modal-header {
    background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%) !important;
    border-bottom: 3px solid #1a365d !important;
    padding: 1rem 1.5rem !important;
    border-radius: 0.5rem 0.5rem 0 0 !important;
}

#orderDetailsModal .modal-body {
    padding: 2rem !important;
}

.bg-gradient-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
}

.info-group {
    margin-bottom: 1rem;
    padding: 0.75rem;
    border-radius: 8px;
    transition: all 0.3s ease;
}

.info-group:hover {
    background-color: #f8f9fa;
    transform: translateX(5px);
}

.info-group label {
    font-weight: 600;
    color: #495057;
    margin-bottom: 0.25rem;
    font-size: 0.875rem;
}

.info-group div {
    color: #212529;
    font-size: 1rem;
}

.info-card {
    background: white;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 0.75rem;
    margin-bottom: 0.75rem;
    transition: all 0.3s ease;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    height: 100%;
}

.info-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
    border-color: #6c757d;
}

/* Compact order info cards */
.info-card.compact {
    padding: 0.5rem 0.75rem;
    margin-bottom: 0.5rem;
    min-height: auto;
}

.info-card.compact .fw-bold {
    font-size: 0.875rem;
    margin-bottom: 0.25rem;
}

.info-card.compact div {
    font-size: 0.9rem;
}

.customer-image-card img {
    width: 100%;
    height: 200px;
    object-fit: cover;
    border-radius: 8px;
    transition: all 0.3s ease;
}

.customer-image-card img:hover {
    transform: scale(1.02);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    border: 2px solid #6c757d;
}

.card-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
    border-bottom: 2px solid #5a67d8 !important;
    color: white !important;
    font-weight: 600 !important;
    padding: 1rem !important;
}

.text-primary { color: #667eea !important; }
.text-success { color: #28a745 !important; }
.text-warning { color: #ffc107 !important; }
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
            <span class="navbar-brand d-flex d-lg-none me-4" style="pointer-events: none; cursor: default;">
                <h2 class="text-danger mb-0"><i class="fa fa-user-edit"></i></h2>
            </span>
            <a href="#" class="sidebar-toggler flex-shrink-0">
                <i class="fa fa-bars"></i>
            </a>
 
            <div class="navbar-nav align-items-center ms-auto">
                <div class="nav-item dropdown">
                    <div class="dropdown-menu dropdown-menu-end bg-secondary border-0 rounded-0 rounded-bottom m-0">
                        <a href="#" class="dropdown-item">
                            <div class="d-flex aligns-items-center">
                                <img src="img/johanns.jpg" alt="User Profile" class="rounded-circle"
                                    style="width: 40px; height: 40px;">
                                <div class="ms-2">
                                    <h6 class="fw-normal mb-0">Johanns send you a message</h6>
                                    <small>5 minutes ago</small>
                                </div>
                            </div>
                        </a>
                        <hr class="dropdown-divider">
                        <a href="#" class="dropdown-item">
                            <div class="d-flex aligns-items-center">
                                <img src="img/carlo.jpg" alt="" class="rounded-circle"
                                    style="width: 40px; height: 40px;">
                                <div class="ms-2">
                                    <h6 class="fw-normal mb-0">Carlo send you a message</h6>
                                    <small>10 minutes ago</small>
                                </div>
                            </div>
                        </a>
                        <hr class="dropdown-divider">
                        <a href="#" class="dropdown-item">
                            <div class="d-flex aligns-items-center">
                                <img src="img/alquin.jpg" alt="" class="rounded-circle"
                                    style="width: 40px; height: 40px;">
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
                $barangayOrderCount = getBarangayReadyToShipGCASHOrderCount($conn, $barangay['id']);
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
        <a href="Cashier-GCASH-Onship.php" class="status-button">
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
            <h4 class="mb-0">Ready to Ship Orders</h4>
        </div>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr class="text-white">
                        <th scope="col" class="text-center">Order ID</th>
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
                                   gt.reference_number $selectDeliveryCols $selectFareFallback, COALESCE(bf.fare_amount, 0) AS barangay_fare, COALESCE(bf.staff_fare_amount, 0) AS barangay_staff_fare
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
                                    <td class="text-center">' . htmlspecialchars(date('Y-m-d h:i A', strtotime($row['order_date']))) . '</td>
                                    <td class="text-center">' . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . '</td>
                                    <td class="text-center">' . htmlspecialchars($row['purok'] . ', ' . $row['barangay_name']) . '</td>
                                    <td class="text-center">' . htmlspecialchars($row['order_status']) . '</td>
                                    <td class="text-center">
                                        <a href="#" class="btn btn-sm btn-primary"
                                           data-bs-toggle="modal"
                                           data-bs-target="#orderDetailsModal"
                                           data-order-id="' . htmlspecialchars($row['id']) . '"
                                           data-transaction-number="' . htmlspecialchars($row['transaction_number']) . '"
                                           data-order-date="' . htmlspecialchars(date('Y-m-d h:i A', strtotime($row['order_date']))) . '"
                                           data-customer-name="' . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . '"
                                           data-barangay="' . htmlspecialchars($row['barangay_name']) . '"
                                           data-purok="' . htmlspecialchars($row['purok']) . '"
                                           data-rider-name="' . htmlspecialchars($row['rider_name']) . '"
                                           data-rider-contact="' . htmlspecialchars($row['rider_contact']) . '"
                                           data-rider-motor-type="' . htmlspecialchars($row['rider_motor_type']) . '"
                                           data-rider-plate-number="' . htmlspecialchars($row['rider_plate_number']) . '"
                                           data-total-price="' . htmlspecialchars($row['total_price']) . '"
                                           data-payment-method="' . htmlspecialchars($row['payment_method']) . '"
                                           data-reference-number="' . htmlspecialchars($row['reference_number'] ?? '') . '"
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
                        echo '<tr><td colspan="6">No Ready to Ship GCASH orders found.</td></tr>';
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
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" style="max-height: 75vh; overflow-y: auto;">
                <form id="updateOrderForm">
                    <input type="hidden" id="modalOrderId" name="order_id">
                    <input type="hidden" id="modalReferenceNumber" name="reference_number">

                <!-- Customer Details Section -->
                <div class="mb-4">
                    <div class="d-flex justify-content-center mb-3">
                        <h4 class="bg-danger text-white px-4 py-3 rounded-pill shadow-sm mb-0">
                            <i class="fas fa-user me-3 fs-5"></i>Customer Details
                        </h4>
                    </div>
                    
                    <div class="row g-4">
                        <!-- Customer Image -->
                        <div class="col-md-4">
                            <div class="customer-image-card border rounded-3 shadow-sm p-3 bg-light">
                                <img id="modalCustomerImage" src="" alt="Customer Image" class="img-fluid rounded-3" style="width: 100%; height: 200px; object-fit: cover;">
                            </div>
                        </div>
                        
                        <!-- Customer Information -->
                        <div class="col-md-8">
                            <div class="info-group">
                                <label>Name:</label>
                                <div id="modalCustomerName"></div>
                            </div>
                            <div class="info-group">
                                <label>Shipping Address:</label>
                                <div id="modalShippingAddress"></div>
                            </div>
                            <div class="info-group">
                                <label>Transaction Number:</label>
                                <div id="modalTransactionNumber"></div>
                            </div>
                            <div class="info-group">
                                <label>Order Date:</label>
                                <div id="modalOrderDate"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Order Information Section -->
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-gradient-primary text-white">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-shopping-cart me-2"></i>
                            Order Information
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row g-2">
                            <!-- Top Row: Pricing Information -->
                            <div class="col-md-4">
                                <div class="info-card compact text-success">
                                    <div class="fw-bold fs-6">Total Price</div>
                                    <div id="modalTotalPrice"></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-card compact text-warning">
                                    <div class="fw-bold fs-6">Delivery Fee</div>
                                    <div id="modalDeliveryFee"></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-card compact text-primary">
                                    <div class="fw-bold fs-6">Total with Delivery</div>
                                    <div id="modalTotalWithDelivery"></div>
                                </div>
                            </div>

                            <!-- Middle Row: Payment & Weight -->
                            <div class="col-md-6">
                                <div class="info-card compact">
                                    <div class="fw-bold fs-6">Payment Method</div>
                                    <div id="modalPaymentMethod"></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-card compact">
                                    <div class="fw-bold fs-6">Total Weight</div>
                                    <div id="modalTotalWeight"></div>
                                </div>
                            </div>

                            <!-- Bottom Row: Delivery Details -->
                            <div class="col-md-6">
                                <div class="info-card compact">
                                    <div class="fw-bold fs-6">Delivery Method</div>
                                    <div id="modalDeliveryMethod"></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-card compact">
                                    <div class="fw-bold fs-6">Home Description</div>
                                    <div id="modalHomeDescription"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Rider Details Section - Only show for local rider delivery -->
                <div class="mb-4" id="riderDetailsSection" style="display: none;">
                    <div class="d-flex justify-content-center mb-3">
                        <h4 class="bg-danger text-white px-4 py-3 rounded-pill shadow-sm mb-0">
                            <i class="fas fa-motorcycle me-3 fs-5"></i>Rider Details
                        </h4>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="info-card">
                                <div class="fw-bold fs-6">Rider Name</div>
                                <div id="modalRiderName"></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-card">
                                <div class="fw-bold fs-6">Rider Contact</div>
                                <div id="modalRiderContactInfo"></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-card">
                                <div class="fw-bold fs-6">Motor Type</div>
                                <div id="modalMotorType"></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-card">
                                <div class="fw-bold fs-6">Plate Number</div>
                                <div id="modalPlateNumber"></div>
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
                    
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" id="onShipBtn">On-Ship Order</button>
                <button type="button" class="btn btn-success" id="printReceiptBtn">Print Receipt</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Receipt Modal -->
<div class="modal fade" id="receiptModal" tabindex="-1" aria-labelledby="receiptModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-dark w-100 text-center" id="receiptModalLabel">Order Receipt</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="receiptContent" style="background-color: white; color: black; padding: 20px;">
                <!-- Receipt content will be generated here -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="printReceiptAction">Print</button>
            </div>
        </div>
    </div>
</div>
<!---end of modal-->
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

    <!-- Template Javascript -->
    <script src="js/main.js"></script>
    <script>
        // Function to populate the modal with data
        $('#orderDetailsModal').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget); // Button that triggered the modal
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

            // Set the values in the modal
            $('#modalOrderId').val(orderId);
            $('#modalReferenceNumber').text(referenceNumber || '');
            $('#modalTransactionNumber').text(transactionNumber);
            $('#modalOrderDate').text(orderDate);
            $('#modalCustomerName').text(customerName);

            // Construct the shipping address with cleaned labels/values
            function cleanPurok(value){
                value = (value || '').toString();
                value = value.replace(/purok\s*/gi, '')
                             .replace(/brgy\.?\s*/gi, '')
                             .replace(/barangay\s*/gi, '')
                             .replace(/valencia\s*city/gi, '')
                             .replace(/bukidnon/gi, '')
                             .replace(/,+/g, ',')
                             .trim();
                return value;
            }
            function titleCase(str){
                return (str||'').toString().toLowerCase().replace(/\b\w/g, function(c){return c.toUpperCase();});
            }
            var barangayClean = titleCase((barangay||'').toString().replace(/brgy\.?\s*/i,'').replace(/barangay\s*/i,''));
            var purokClean = cleanPurok(purok);
            // Remove barangay name from purok if user typed it there
            if (barangayClean) {
                var barangayRegex = new RegExp(barangayClean.replace(/[-/\\^$*+?.()|[\]{}]/g, '\\$&'), 'i');
                purokClean = purokClean.replace(barangayRegex, '').replace(/\s+,/g, ',').replace(/\s{2,}/g, ' ').trim();
            }
            var shippingAddress = "Purok " + purokClean + ", Brgy. " + barangayClean + ", Valencia City, Bukidnon";
            $('#modalShippingAddress').text(shippingAddress);

            $('#modalTotalPrice').text('₱' + parseFloat(totalPrice).toFixed(2));
            // Display delivery fee (removed free shipping logic)
            $('#modalDeliveryFee').text('₱' + parseFloat(deliveryFee || 0).toFixed(2));
            
            // Always compute to avoid stale/incorrect server value
            var computedTotalWithDelivery = parseFloat(totalPrice) + parseFloat(deliveryFee || 0);
            $('#modalTotalWithDelivery').text('₱' + computedTotalWithDelivery.toFixed(2));
            if (paymentMethod && paymentMethod.toUpperCase() === 'GCASH' && referenceNumber) {
                $('#modalPaymentMethod').text('GCASH (RFN#: ' + referenceNumber + ')');
            } else {
                $('#modalPaymentMethod').text((paymentMethod||'').toString().toUpperCase());
            }
            $('#modalTotalWeight').text(totalWeight);
            $('#modalDeliveryMethod').text(deliveryMethod);
            $('#modalHomeDescription').text(homeDescription);

            // Set the rider details
            $('#modalRiderName').text(riderName);
            $('#modalRiderContactInfo').text(riderContact);
            $('#modalMotorType').text(motorType);
            $('#modalPlateNumber').text(plateNumber);

            // Set the profile picture
            $('#modalCustomerImage').attr('src', imagePath);
            
            // Show/hide rider section based on delivery method
            if (deliveryMethod && deliveryMethod.toLowerCase() === 'staff') {
                // Hide rider details section for staff delivery
                $('#riderDetailsSection').removeClass('show-rider');
                $('#staffDeliveryNotice').show();
            } else {
                // Show rider details section for local rider delivery
                $('#riderDetailsSection').addClass('show-rider');
                $('#staffDeliveryNotice').hide();
            }

             console.log("Rider Name:", riderName);
             console.log("Rider Contact:", riderContact);
             console.log("Motor Type:", motorType);
             console.log("Plate Number:", plateNumber);

        });

        // Function to handle the "Print Receipt" button click
        $('#printReceiptBtn').on('click', function() {
            // Store all the data
            var orderId = $('#modalOrderId').val();
            var transactionNumber = $('#modalTransactionNumber').text();
            var orderDate = $('#modalOrderDate').text();
            var customerName = $('#modalCustomerName').text();
            var shippingAddress = $('#modalShippingAddress').text();
            var totalPriceText = $('#modalTotalPrice').text();
            var modalDeliveryFeeText = ($('#modalDeliveryFee').text() || '').trim();
            var totalWithDeliveryText = $('#modalTotalWithDelivery').text();
            var paymentMethod = ($('#modalPaymentMethod').text() || '').toUpperCase();
            var referenceNumber = $('#modalReferenceNumber').text() || '';
            var totalWeight = $('#modalTotalWeight').text();
            var deliveryMethod = $('#modalDeliveryMethod').text();
            var homeDescription = $('#modalHomeDescription').text();
            var riderName = $('#modalRiderName').text();
            var riderContact = $('#modalRiderContactInfo').text();
            var motorType = $('#modalMotorType').text();
            var plateNumber = $('#modalPlateNumber').text();

            // Parse amounts (removed free shipping logic)
            var subtotal = parseFloat((totalPriceText || '').toString().replace(/[^\d.]/g, '')) || 0;
            var deliveryFeeValue = parseFloat(modalDeliveryFeeText.replace(/[^\d.]/g, '')) || 0;
            var receiptShippingFeeText = '₱' + deliveryFeeValue.toFixed(2);
            var totalAmount = subtotal + deliveryFeeValue;

            var watermarkText = paymentMethod.includes('GCASH') ? 'GCASH' : 'COD';

            // Optimized receipt layout with better spacing and sizing
            var receiptHTML = `
                <style>
                    .print-only {
                        display: none !important;
                    }
                    @media print {
                        .print-only {
                            display: block !important;
                        }
                    }
                </style>
                <div class="receipt-outer" style="width:100%; display:flex; justify-content:center;">
                <div class="receipt-container" style="position: relative; width: 100mm; height: 150mm; box-sizing: border-box; padding: 6mm; border: 1px solid #000; font-family: Arial, sans-serif; color: #000;">
                    <div class="watermark" style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%) rotate(-20deg); font-size: 15mm; color: rgba(0,0,0,0.03); font-weight: 700; letter-spacing: 1mm; user-select: none; z-index: 1;">${watermarkText}</div>
                    <div style="text-align: center; margin-bottom: 2mm; position: relative; z-index: 2;">
                        <div style="font-size: 5mm; font-weight: 700; margin-bottom: 1.5mm;">ORDER RECEIPT</div>
                    </div>
                    <div style="display:flex; justify-content: space-between; align-items:center; margin-bottom: 2mm; position: relative; z-index: 2;">
                        <img src="Image/logo.png" alt="CJ PowerHouse" style="height: 10mm;">
                        <div style="text-align:right; padding-right: 2mm;">
                            <div style="font-size: 3mm;">Send Date: ${orderDate}</div>
                            <div style="font-size: 4.5mm; font-weight:700;">TRN#: ${transactionNumber}</div>
                        </div>
                    </div>
                    <div class="section" style="margin-top: 1.5mm; border-top: 1px dashed #000; padding-top: 1.5mm; position: relative; z-index: 2;">
                        <div style="font-weight:700; font-size: 3.5mm; margin-bottom:0.8mm;">BUYER</div>
                        <div style="font-size: 3mm;">${customerName}</div>
                        <div style="font-size: 3mm;">${shippingAddress}</div>
                        <div style="font-size: 3mm;">Home Desc: ${homeDescription}</div>
                    </div>
                    <div class="section" style="margin-top: 1.5mm; border-top: 1px dashed #000; padding-top: 1.5mm; position: relative; z-index: 2;">
                        <div style="font-weight:700; font-size: 3.5mm; margin-bottom:0.8mm;">SELLER</div>
                        <div style="font-size: 3mm;">CJ PowerHouse</div>
                        <div style="font-size: 3mm;">Brgy: Sinayawan Purok 3, Fronting 3-Star Hardware, Valencia City, Bukidnon, 8709</div>
                    </div>
                    <div class="section" style="margin-top: 1.5mm; border-top: 1px dashed #000; padding-top: 1.5mm; position: relative; z-index: 2;">
                        <div style="display:flex; justify-content: space-between; font-size: 3mm; padding: 0 2mm;">
                            <span class="title" style="font-weight:700;">Payment:</span>
                            <span>GCASH${referenceNumber ? ' (RFN#: ' + referenceNumber + ')' : ''}</span>
                        </div>
                        <div style="display:flex; justify-content: space-between; font-size: 3mm; padding: 0 2mm;">
                            <span class="title" style="font-weight:700;">Weight:</span>
                            <span>${totalWeight} kg</span>
                        </div>
                        <div style="display:flex; justify-content: space-between; font-size: 3mm; padding: 0 2mm;">
                            <span class="title" style="font-weight:700;">Delivery:</span>
                            <span>${deliveryMethod}</span>
                        </div>
                    </div>
                    <div class="section" style="margin-top: 1.5mm; border-top: 1px dashed #000; padding-top: 1.5mm; position: relative; z-index: 2;">
                        <div style="font-weight:700; font-size: 3.5mm; margin-bottom:0.8mm;">ORDER SUMMARY</div>
                        <div style="display:flex; justify-content: space-between; font-size: 3mm; padding: 0 2mm;">
                            <span>Subtotal</span><span>₱${subtotal.toFixed(2)}</span>
                        </div>
                        <div style="display:flex; justify-content: space-between; font-size: 3mm; padding: 0 2mm;">
                            <span>Shipping</span><span>${receiptShippingFeeText}</span>
                        </div>
                        <div style="display:flex; justify-content: space-between; font-size: 3.5mm; font-weight:700; border-top: 1px solid #000; margin-top:0.8mm; padding:0.8mm 2mm 0 2mm;">
                            <span>Total</span><span>₱${totalAmount.toFixed(2)}</span>
                        </div>
                    </div>
                    ${deliveryMethod && deliveryMethod.toLowerCase() === 'staff' ? 
                        `<div class="section" style="margin-top: 1.5mm; border-top: 1px dashed #000; padding-top: 1.5mm; position: relative; z-index: 2;">
                            <div style="font-weight:700; font-size: 3.5mm; margin-bottom:0.8mm;">DELIVERY</div>
                            <div style="font-size: 3mm;">CJ PowerHouse Staff Delivery</div>
                            <div style="font-size: 3mm;">Staff delivery service</div>
                        </div>` :
                        `<div class="section" style="margin-top: 1.5mm; border-top: 1px dashed #000; padding-top: 1.5mm; position: relative; z-index: 2;">
                            <div style="font-weight:700; font-size: 3.5mm; margin-bottom:0.8mm;">RIDER</div>
                            <div style="font-size: 3mm;">${riderName} | ${riderContact}</div>
                            <div style="font-size: 3mm;">${motorType} | ${plateNumber}</div>
                        </div>`
                    }
                    <div class="section print-only" style="margin-top: 1.5mm; border-top: 1px dashed #000; padding-top: 1.5mm; text-align: center; position: relative; z-index: 2; padding-bottom: 3mm;">
                        <div style="font-weight:800; font-size: 3.2mm; margin-bottom:0.8mm; color: #d32f2f;">⚠️ IMPORTANT NOTICE</div>
                        <div style="font-size: 2.8mm; margin-bottom:1.5mm; color: #000; font-weight:500;">Please record a video while opening this package to document the condition of items for quality assurance purposes.</div>
                        <div style="display: flex; justify-content: center; align-items: center; gap: 2.5mm; margin-top: 1.5mm; padding: 0 2mm;">
                            <div style="font-size: 2.8mm; color: #000; font-weight:500;">Any Concern or help? Scan the QR:</div>
                            <div style="width: 16mm; height: 16mm; border: 1px solid #ddd; display: flex; align-items: center; justify-content: center; background: white;">
                                <img src="uploads/qr_codes/contact_qr.png" alt="Contact QR Code" style="width: 14mm; height: 14mm; max-width: 100%; max-height: 100%;" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                                <div style="display: none; font-size: 2mm; text-align: center; color: #999;">QR Code<br/>Not Available</div>
                            </div>
                        </div>
                    </div>
                </div>
                </div>
            `;

            // Insert into receipt modal
            $('#receiptContent').html(receiptHTML);

            // Show receipt modal using jQuery
            $('#receiptModal').modal('show');

            console.log("Receipt HTML:", receiptHTML);
            console.log("Receipt modal should be visible now");
        });

        // Function to handle the "On-Ship Order" button click
        $('#onShipBtn').on('click', function() {
            var orderId = $('#modalOrderId').val();
            
            if (!orderId) {
                showNotification('Order ID not found. Please try again.', 'error');
                return;
            }
            
            // Disable button to prevent double-clicking
            $('#onShipBtn').prop('disabled', true).text('Processing...');
            
            // Make AJAX request to update order status
            $.ajax({
                url: 'update-order-status.php',
                method: 'POST',
                data: {
                    order_id: orderId,
                    new_status: 'On-Ship'
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showNotification('Order has been updated to On-Ship', 'success');
                        // Close the modal
                        $('#orderDetailsModal').modal('hide');
                        // Reload the page to show updated status
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        showNotification('Error updating order status: ' + (response.message || 'Unknown error'), 'error');
                        // Re-enable button
                        $('#onShipBtn').prop('disabled', false).text('On-Ship Order');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    showNotification('Error updating order status. Please try again.', 'error');
                    // Re-enable button
                    $('#onShipBtn').prop('disabled', false).text('On-Ship Order');
                }
            });
        });

        // Function to show notification banner
        function showNotification(message, type) {
            // Remove any existing notifications
            $('.notification-banner').remove();
            
            var alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
            var icon = type === 'success' ? 'fas fa-check-circle' : 'fas fa-exclamation-circle';
            
            var notification = $(`
                <div class="notification-banner alert ${alertClass} alert-dismissible fade show position-fixed" 
                     style="top: 20px; right: 20px; z-index: 9999; min-width: 300px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); border-left: 4px solid ${type === 'success' ? '#28a745' : '#dc3545'};">
                    <i class="${icon} me-2"></i>
                    <strong>${type === 'success' ? 'Success!' : 'Error!'}</strong> ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            `);
            
            $('body').append(notification);
            
            // Auto-hide after 5 seconds
            setTimeout(function() {
                notification.fadeOut(300, function() {
                    notification.remove();
                });
            }, 5000);
        }

        function printReceipt() {
            var content = document.getElementById('receiptContent').innerHTML;
            var printWindow = window.open('', '', 'width=420,height=680');
            printWindow.document.write('<html><head><title>Receipt</title>');
            printWindow.document.write('<style>@page{size:100mm 150mm;margin:2mm;} body{margin:0; padding:0;} .receipt-container{width:96mm;height:146mm;margin:0 auto;} .receipt-outer{width:100%;} img{max-width:100%; height:auto;} *{-webkit-print-color-adjust:exact; color-adjust:exact;}</style>');
            printWindow.document.write('</head><body>');
            printWindow.document.write(content);
            printWindow.document.write('</body></html>');
            printWindow.document.close();
            printWindow.focus();
            setTimeout(function(){ printWindow.print(); printWindow.close(); }, 250);
        }

        // Bind click handler safely after DOM is ready
        $(document).on('click', '#printReceiptAction', function() {
            printReceipt();
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>
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

// Function to count orders from a specific barangay
function getBarangayOrderCount($conn, $barangayId) {
    $sql = "SELECT COUNT(o.id)
            FROM orders o
            JOIN users u ON o.user_id = u.id
            WHERE u.barangay_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $barangayId);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return $count;
}

// Get counts for COD orders (general - all time)
$pendingCODCount = getOrderCount($conn, 'Pending', 'COD');
$readyToShipCODCount = getOrderCount($conn, 'Ready to Ship', 'COD');
$onShipCODCount = getOrderCount($conn, 'On-Ship', 'COD');

// Get today's counts
$today = date("Y-m-d"); // Get current date in YYYY-MM-DD format
$todayPendingCODCount = getOrderCount($conn, 'Pending', 'COD', $today);
$todayReadyToShipCount = getOrderCount($conn, 'Ready to Ship', 'COD', $today);
$todayOnDeliveryCount = getOrderCount($conn, 'On-Ship', 'COD', $today); // Assuming "On-Ship" is your "On-Delivery" status
$todaySuccessfulCount = getOrderCount($conn, 'Completed', 'COD', $today); // Assuming "Completed" is your "Successful" status

// Fetch all barangays from the barangays table
$sql_barangays = "SELECT id, barangay_name FROM barangays";
$result_barangays = $conn->query($sql_barangays);

$barangays = [];
if ($result_barangays->num_rows > 0) {
    while ($row = $result_barangays->fetch_assoc()) {
        $barangays[] = $row;
    }
}

// Fetch completed transactions with necessary details including delivery fees
$sql_transactions = "SELECT o.id, t.transaction_number, o.order_date,
                               u.first_name, u.last_name, o.total_price, o.payment_method,
                               u.email, u.phone_number, b.barangay_name, u.purok,  -- User info
                               o.rider_name, o.rider_contact, o.rider_motor_type, o.rider_plate_number, -- Rider info
                               o.order_status, o.delivery_method, o.home_description,
                               u.ImagePath,
                               -- Include delivery fee calculation
                               o.delivery_fee, o.total_amount_with_delivery,
                               bf.fare_amount, bf.staff_fare_amount,
                               -- Include GCash reference number
                               gt.reference_number AS gcash_reference,
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
                               END AS total_with_delivery_effective
                        FROM orders o
                        LEFT JOIN transactions t ON t.order_id = o.id
                        JOIN users u ON o.user_id = u.id
                        JOIN barangays b ON u.barangay_id = b.id
                        LEFT JOIN barangay_fares bf ON b.id = bf.barangay_id
                        LEFT JOIN gcash_transactions gt ON o.id = gt.order_id
                        WHERE o.order_status = 'Completed'
                        ORDER BY o.order_date DESC";

$result_transactions = $conn->query($sql_transactions);

$transactions = [];
if ($result_transactions->num_rows > 0) {
    while ($row = $result_transactions->fetch_assoc()) {
        // Format the order_date to Philippine time (AM/PM)
        date_default_timezone_set('Asia/Manila');
        $row['order_date_formatted'] = date("Y-m-d h:i A", strtotime($row['order_date'])); // Format date
        $transactions[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Jandi - Cashier Dashboard - Transactions</title>
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
      .modal-content {
            background-color: #fff; /* White Background */
            color: #000; /* Black Text */
        }
          .soft-edge-square {
                border-radius: 10px; /* Adjust the radius to control the roundness */
                overflow: hidden; /* Ensures the rounded corners are visible */
            }
            /* Make modal body scrollable so long content fits within viewport */
            #transactionDetailsModal .modal-body { max-height: 70vh; overflow-y: auto; }
   </style>
   <style>
      /* Make transactions table area scrollable */
      .transactions-scroll { max-height: 65vh; overflow-y: auto; }
      
      /* Pagination styling - show only 10 rows with scroll */
      .table-pagination-container {
          max-height: 600px !important; /* Approximately 10 rows height */
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
      
      /* Filter button styling */
      .filter-btn {
          transition: all 0.3s ease;
      }
      
      .filter-btn.active {
          background-color: #0d6efd !important;
          border-color: #0d6efd !important;
          color: white !important;
      }
      
      .filter-btn:hover {
          background-color: #0d6efd !important;
          border-color: #0d6efd !important;
          color: white !important;
      }
      
      /* Pagination button styling */
      .btn-outline-light {
          color: #f8f9fa !important;
          border-color: #f8f9fa !important;
          background-color: transparent !important;
      }
      
      .btn-outline-light:hover:not(:disabled) {
          background-color: #f8f9fa !important;
          border-color: #f8f9fa !important;
          color: #212529 !important;
      }
      
      .btn-outline-light:disabled {
          color: #6c757d !important;
          border-color: #6c757d !important;
          background-color: transparent !important;
          opacity: 0.5 !important;
      }
      
      .btn-light {
          background-color: #f8f9fa !important;
          border-color: #f8f9fa !important;
          color: #212529 !important;
      }
      
      /* Date Range Calendar Icon Styling */
      .calendar-icon {
          background-color: #007bff !important;
          border-color: #007bff !important;
          color: white !important;
          cursor: pointer !important;
          transition: all 0.3s ease !important;
      }
      
      .calendar-icon:hover {
          background-color: #0056b3 !important;
          border-color: #0056b3 !important;
          transform: scale(1.05) !important;
      }
      
      .calendar-icon i {
          font-size: 14px !important;
      }
      
      /* Input group styling for dark theme */
      .input-group .form-control {
          background-color: #495057 !important;
          border-color: #6c757d !important;
          color: white !important;
      }
      
      .input-group .form-control:focus {
          background-color: #495057 !important;
          border-color: #007bff !important;
          color: white !important;
          box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25) !important;
      }
      
      .input-group .form-control::placeholder {
          color: #adb5bd !important;
      }
      
      /* Mobile responsiveness for date inputs */
      @media (max-width: 768px) {
          .input-group {
              margin-bottom: 10px;
          }
          
          .calendar-icon {
              padding: 8px 12px !important;
          }
          
          .calendar-icon i {
              font-size: 12px !important;
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
                <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="fa fa-shopping-cart me-2"></i>Pending Orders
                </a>
                <div class="dropdown-menu bg-transparent border-0">
                    <a href="Cashier-COD-Delivery.php" class="dropdown-item">Pending COD orders</a>
                    <a href="Cashier-GCASH-Delivery.php" class="dropdown-item">Pending GCASH orders</a>
                </div>
            </div>
            <a href="Cashier-Pickup-Orders.php" class="nav-item nav-link"><i class="fa fa-store me-2"></i>Pickup Orders</a>
            <a href="Cashier-Transactions.php" class="nav-item nav-link active"><i class="fa fa-list-alt me-2"></i>Transactions</a>
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

<!-- Transactions History Start -->
<div class="container-fluid pt-4 px-4">
    <div class="bg-secondary rounded p-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="mb-0">Completed Transactions History</h4>
            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-outline-primary filter-btn active" data-filter="all">All Transactions</button>
                <button class="btn btn-sm btn-outline-primary filter-btn" data-filter="today">Today</button>
                <button class="btn btn-sm btn-outline-primary filter-btn" data-filter="daterange">Date Range</button>
            </div>
        </div>
        
        <!-- Date Range Filter -->
        <div class="row mb-3" id="dateRangeFilter" style="display: none;">
            <div class="col-md-4">
                <label for="fromDate" class="form-label text-white">From Date:</label>
                <div class="input-group">
                    <input type="date" class="form-control" id="fromDate" name="fromDate">
                    <span class="input-group-text calendar-icon" onclick="document.getElementById('fromDate').showPicker()">
                        <i class="fa fa-calendar-alt"></i>
                    </span>
                </div>
            </div>
            <div class="col-md-4">
                <label for="toDate" class="form-label text-white">To Date:</label>
                <div class="input-group">
                    <input type="date" class="form-control" id="toDate" name="toDate">
                    <span class="input-group-text calendar-icon" onclick="document.getElementById('toDate').showPicker()">
                        <i class="fa fa-calendar-alt"></i>
                    </span>
                </div>
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <button class="btn btn-sm btn-primary me-2" id="applyDateFilter">
                    <i class="fa fa-filter"></i> Apply Filter
                </button>
                <button class="btn btn-sm btn-outline-secondary" id="clearDateFilter">
                    <i class="fa fa-times"></i> Clear
                </button>
            </div>
        </div>
        
        <!-- Pagination Info -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="text-muted">
                Showing <span id="showing-start">1</span> to <span id="showing-end">10</span> of <span id="total-transactions"><?php echo count($transactions); ?></span> transactions
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-outline-light" id="prev-page" disabled>
                    <i class="fa fa-chevron-left"></i> Previous
                </button>
                <span class="btn btn-sm btn-light text-dark" id="page-info">Page 1</span>
                <button class="btn btn-sm btn-outline-light" id="next-page">
                    Next <i class="fa fa-chevron-right"></i>
                </button>
            </div>
        </div>
        
         <div class="table-responsive table-pagination-container ideal-table-wrapper">
             <table class="table ideal-transactions-table">
                 <thead class="ideal-table-header">
                     <tr>
                         <th scope="col">Order ID</th>
                         <th scope="col">Transaction #</th>
                         <th scope="col">Order Date</th>
                         <th scope="col">Customer Name</th>
                         <th scope="col">Total Price</th>
                         <th scope="col">Payment Method</th>
                         <th scope="col">Action</th>
                     </tr>
                 </thead>
                 <tbody class="ideal-table-body">
                    <?php if (!empty($transactions)): ?>
                        <?php foreach ($transactions as $transaction): ?>
                            <tr class="ideal-table-row">
                                <td class="ideal-order-id"><?php echo htmlspecialchars($transaction['id']); ?></td>
                                <td class="ideal-transaction-number"><?php echo htmlspecialchars($transaction['transaction_number']); ?></td>
                                <td class="ideal-order-date"><?php echo htmlspecialchars($transaction['order_date_formatted']); ?></td>
                                <td class="ideal-customer-name"><?php echo htmlspecialchars($transaction['first_name'] . ' ' . $transaction['last_name']); ?></td>
                                <td class="ideal-total-price">₱<?php echo number_format($transaction['total_with_delivery_effective'] ?? ($transaction['total_price'] + ($transaction['delivery_fee_effective'] ?? 0)), 2); ?></td>
                                <td class="ideal-payment-method"><?php echo htmlspecialchars($transaction['payment_method']); ?></td>
                                <td class="ideal-action-cell">
                                    <button type="button" class="btn ideal-view-btn view-details-btn"
                                        data-bs-toggle="modal"
                                        data-bs-target="#transactionDetailsModal"
                                        data-order-id="<?php echo htmlspecialchars($transaction['id']); ?>"
                                        data-transaction-number="<?php echo htmlspecialchars($transaction['transaction_number']); ?>"
                                        data-order-date="<?php echo htmlspecialchars($transaction['order_date_formatted']); ?>"
                                        data-customer-name="<?php echo htmlspecialchars($transaction['first_name'] . ' ' . $transaction['last_name']); ?>"
                                        data-customer-email="<?php echo htmlspecialchars($transaction['email']); ?>"
                                        data-customer-phone="<?php echo htmlspecialchars($transaction['phone_number']); ?>"
                                        data-barangay="<?php echo htmlspecialchars($transaction['barangay_name']); ?>"
                                        data-purok="<?php echo htmlspecialchars($transaction['purok']); ?>"
                                        data-rider-name="<?php echo htmlspecialchars($transaction['rider_name']); ?>"
                                        data-rider-contact="<?php echo htmlspecialchars($transaction['rider_contact']); ?>"
                                        data-rider-motor-type="<?php echo htmlspecialchars($transaction['rider_motor_type']); ?>"
                                        data-rider-plate-number="<?php echo htmlspecialchars($transaction['rider_plate_number']); ?>"
                                        data-payment-method="<?php echo htmlspecialchars($transaction['payment_method']); ?>"
                                        data-subtotal-price="<?php echo htmlspecialchars($transaction['total_price']); ?>"
                                        data-delivery-fee="<?php echo htmlspecialchars($transaction['delivery_fee_effective'] ?? 0); ?>"
                                        data-total-price="<?php echo htmlspecialchars($transaction['total_with_delivery_effective'] ?? ($transaction['total_price'] + ($transaction['delivery_fee_effective'] ?? 0))); ?>"
                                        data-delivery-method="<?php echo htmlspecialchars($transaction['delivery_method']); ?>"
                                        data-home-description="<?php echo htmlspecialchars($transaction['home_description']); ?>"
                                        data-image-path="<?php echo htmlspecialchars($transaction['ImagePath']); ?>"
                                        data-gcash-reference="<?php echo htmlspecialchars($transaction['gcash_reference'] ?? ''); ?>"
                                    >
                                        View Details
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7">No completed transactions found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<!-- Transactions History End -->

<!-- Modal for Transaction Details -->
<div class="modal fade" id="transactionDetailsModal" tabindex="-1" aria-labelledby="transactionDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content modern-modal">
            <div class="modal-header modern-header">
                <div class="header-content">
                    <div class="header-icon">
                        <i class="fas fa-receipt"></i>
                    </div>
                    <div class="header-text">
                        <h5 class="modal-title" id="transactionDetailsModalLabel">Transaction Details</h5>
                        <p class="header-subtitle">Order Information & Customer Details</p>
                    </div>
                </div>
                <button type="button" class="btn-close modern-close" data-bs-dismiss="modal" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body modern-body">
                <!-- Customer Profile Section -->
                <div class="customer-profile-section">
                    <div class="profile-image-container">
                        <img id="modalCustomerImage" src="" alt="Customer Image" class="profile-image">
                        <div class="profile-status-badge">
                            <i class="fas fa-check-circle"></i>
                        </div>
                    </div>
                    <div class="customer-info">
                        <h4 id="customerName" class="customer-name"></h4>
                        <p id="transactionNumber" class="transaction-id"></p>
                    </div>
                </div>

                <!-- Information Cards -->
                <div class="info-cards-container">
                    <!-- Customer Details Card -->
                    <div class="info-card">
                        <div class="card-header">
                            <i class="fas fa-user"></i>
                            <h6>Customer Information</h6>
                        </div>
                        <div class="card-body">
                            <div class="info-item">
                                <span class="info-label">Order Date</span>
                                <span id="orderDate" class="info-value"></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Email</span>
                                <span id="email" class="info-value"></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Phone Number</span>
                                <span id="phoneNumber" class="info-value phone-number"></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Shipping Address</span>
                                <span id="shippingAddress" class="info-value address"></span>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Details Card -->
                    <div class="info-card">
                        <div class="card-header">
                            <i class="fas fa-credit-card"></i>
                            <h6>Payment Details</h6>
                        </div>
                        <div class="card-body">
                            <div class="info-item">
                                <span class="info-label">Payment Method</span>
                                <span id="paymentMethod" class="info-value payment-method"></span>
                            </div>
                            <div class="info-item gcash-reference-section" style="display: none;">
                                <span class="info-label">GCash Reference</span>
                                <span id="gcashReference" class="info-value gcash-ref"></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Delivery Method</span>
                                <span id="deliveryMethod" class="info-value delivery-method"></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Home Description</span>
                                <span id="homeDescription" class="info-value"></span>
                            </div>
                        </div>
                    </div>

                    <!-- Order Summary Card -->
                    <div class="info-card order-summary">
                        <div class="card-header">
                            <i class="fas fa-shopping-cart"></i>
                            <h6>Order Summary</h6>
                        </div>
                        <div class="card-body">
                            <div class="summary-item">
                                <span class="summary-label">Subtotal</span>
                                <span id="subtotalPrice" class="summary-value"></span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Delivery Fee</span>
                                <span id="deliveryFee" class="summary-value"></span>
                            </div>
                            <div class="summary-item total-item">
                                <span class="summary-label">Total Amount</span>
                                <span id="totalPrice" class="summary-value total-amount"></span>
                            </div>
                        </div>
                    </div>

                    <!-- Rider Details Card -->
                    <div class="info-card rider-details-section">
                        <div class="card-header">
                            <i class="fas fa-motorcycle"></i>
                            <h6>Rider Information</h6>
                        </div>
                        <div class="card-body">
                            <div class="info-item">
                                <span class="info-label">Rider Name</span>
                                <span id="riderName" class="info-value"></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Rider Contact</span>
                                <span id="riderContact" class="info-value phone-number"></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Motor Type</span>
                                <span id="motorType" class="info-value"></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Plate Number</span>
                                <span id="plateNumber" class="info-value"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer modern-footer">
                <button type="button" class="btn btn-modern-close" data-bs-dismiss="modal">
                    <i class="fas fa-times"></i>
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.modern-modal {
    border: none;
    border-radius: 20px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
    overflow: hidden;
}

.modern-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    padding: 25px 30px;
    position: relative;
}

.modern-header::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="white" opacity="0.1"/><circle cx="75" cy="75" r="1" fill="white" opacity="0.1"/><circle cx="50" cy="10" r="0.5" fill="white" opacity="0.1"/><circle cx="10" cy="60" r="0.5" fill="white" opacity="0.1"/><circle cx="90" cy="40" r="0.5" fill="white" opacity="0.1"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
    opacity: 0.3;
}

.header-content {
    display: flex;
    align-items: center;
    gap: 15px;
    position: relative;
    z-index: 1;
}

.header-icon {
    width: 50px;
    height: 50px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    backdrop-filter: blur(10px);
}

.header-text h5 {
    margin: 0;
    font-size: 24px;
    font-weight: 700;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.header-subtitle {
    margin: 0;
    font-size: 14px;
    opacity: 0.9;
    font-weight: 400;
}

.modern-close {
    background: rgba(255, 255, 255, 0.2);
    border: none;
    border-radius: 10px;
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 16px;
    transition: all 0.3s ease;
    position: relative;
    z-index: 1;
}

.modern-close:hover {
    background: rgba(255, 255, 255, 0.3);
    transform: scale(1.05);
}

.modern-body {
    padding: 30px;
    background: #f8f9fa;
}

.customer-profile-section {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 20px;
    margin-bottom: 30px;
    padding: 20px;
    background: white;
    border-radius: 15px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    text-align: center;
}

.profile-image-container {
    position: relative;
}

.profile-image {
    width: 80px;
    height: 80px;
    border-radius: 20px;
    object-fit: cover;
    border: 4px solid #e9ecef;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
}

.profile-status-badge {
    position: absolute;
    bottom: -5px;
    right: -5px;
    width: 25px;
    height: 25px;
    background: #28a745;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 12px;
    border: 3px solid white;
}

.customer-info {
    flex: 1;
}

.customer-name {
    margin: 0;
    font-size: 24px;
    font-weight: 700;
    color: #2c3e50;
}

.transaction-id {
    margin: 5px 0 0 0;
    font-size: 14px;
    color: #6c757d;
    font-family: 'Courier New', monospace;
    background: #f8f9fa;
    padding: 5px 10px;
    border-radius: 8px;
    display: inline-block;
}

.info-cards-container {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
}

.info-card {
    background: white;
    border-radius: 15px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    overflow: hidden;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.info-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
}

.card-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    padding: 15px 20px;
    border-bottom: 1px solid #e9ecef;
    display: flex;
    align-items: center;
    gap: 10px;
}

.card-header i {
    color: #667eea;
    font-size: 18px;
}

.card-header h6 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    color: #2c3e50;
}

.card-body {
    padding: 20px;
}

.info-item {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 15px;
    padding-bottom: 15px;
    border-bottom: 1px solid #f8f9fa;
}

.info-item:last-child {
    margin-bottom: 0;
    padding-bottom: 0;
    border-bottom: none;
}

.info-label {
    font-weight: 600;
    color: #495057;
    font-size: 14px;
    min-width: 120px;
}

.info-value {
    color: #2c3e50;
    font-size: 14px;
    text-align: right;
    flex: 1;
    margin-left: 15px;
}

.phone-number {
    font-family: 'Courier New', monospace;
    font-weight: 600;
    color: #667eea;
}

.address {
    text-align: left;
    line-height: 1.4;
}

.payment-method {
    text-transform: uppercase;
    font-weight: 600;
    padding: 4px 8px;
    border-radius: 6px;
    background: #e3f2fd;
    color: #1976d2;
    display: inline-block;
}

.gcash-ref {
    font-family: 'Courier New', monospace;
    font-weight: 600;
    color: #4caf50;
    background: #e8f5e8;
    padding: 4px 8px;
    border-radius: 6px;
    display: inline-block;
}

.delivery-method {
    text-transform: capitalize;
    font-weight: 600;
    padding: 4px 8px;
    border-radius: 6px;
    background: #fff3e0;
    color: #f57c00;
    display: inline-block;
}

.order-summary .card-body {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
}

.summary-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    padding: 8px 0;
}

.summary-item:last-child {
    margin-bottom: 0;
}

.summary-label {
    font-weight: 500;
    color: #495057;
    font-size: 14px;
}

.summary-value {
    font-weight: 600;
    color: #2c3e50;
    font-size: 14px;
}

.total-item {
    border-top: 2px solid #dee2e6;
    padding-top: 12px;
    margin-top: 8px;
}

.total-item .summary-label {
    font-size: 16px;
    font-weight: 700;
    color: #2c3e50;
}

.total-amount {
    font-size: 18px;
    font-weight: 700;
    color: #28a745;
}

.modern-footer {
    background: #f8f9fa;
    border-top: 1px solid #e9ecef;
    padding: 20px 30px;
    display: flex;
    justify-content: flex-end;
}

.btn-modern-close {
    background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
    border: none;
    color: white;
    padding: 12px 24px;
    border-radius: 10px;
    font-weight: 600;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(108, 117, 125, 0.3);
}

.btn-modern-close:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(108, 117, 125, 0.4);
    color: white;
}

.btn-modern-close i {
    font-size: 12px;
}

@media (max-width: 768px) {
    .modern-body {
        padding: 20px;
    }
    
    .info-cards-container {
        grid-template-columns: 1fr;
    }
    
    .customer-profile-section {
        flex-direction: column;
        text-align: center;
        justify-content: center;
    }
    
    .info-item {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .info-value {
        margin-left: 0;
        margin-top: 5px;
        text-align: left;
    }
}

/* Ideal Table Styling - Matching Your Design */
.ideal-table-wrapper {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    margin-top: 20px;
}

.ideal-transactions-table {
    background: white;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    border: 1px solid #dee2e6;
    margin: 0;
}

.ideal-table-header {
    background: #212529;
    color: #f8f9fa;
}

.ideal-table-header th {
    border: none;
    padding: 15px 12px;
    font-weight: 600;
    font-size: 14px;
    text-align: left;
    color: #f8f9fa;
}

.ideal-table-body {
    background: #FDFDF5;
}

.ideal-table-row {
    transition: all 0.2s ease;
    border-bottom: 1px solid #e9ecef;
}

.ideal-table-row:hover {
    background: #f8f9fa;
}

.ideal-table-row td {
    border: none;
    padding: 15px 12px;
    vertical-align: middle;
    color: #212529;
    font-size: 14px;
}

/* Specific cell styling */
.ideal-order-id {
    font-weight: 600;
    color: #495057;
}

.ideal-transaction-number {
    font-family: 'Courier New', monospace;
    font-size: 12px;
    color: #6c757d;
}

.ideal-order-date {
    color: #495057;
}

.ideal-customer-name {
    font-weight: 600;
    color: #212529;
}

.ideal-total-price {
    font-weight: 700;
    color: #28a745;
    font-size: 15px;
}

.ideal-payment-method {
    text-transform: uppercase;
    font-weight: 600;
    font-size: 12px;
    color: #495057;
}

/* Green Action Button */
.ideal-view-btn {
    background: #28a745;
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 6px;
    font-weight: 600;
    font-size: 12px;
    transition: all 0.2s ease;
    box-shadow: 0 2px 4px rgba(40, 167, 69, 0.3);
}

.ideal-view-btn:hover {
    background: #218838;
    color: white;
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(40, 167, 69, 0.4);
}

.ideal-view-btn:focus {
    background: #28a745;
    color: white;
    box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.25);
}

/* Empty state */
.ideal-table-row td[colspan="7"] {
    text-align: center;
    padding: 40px;
    color: #6c757d;
    font-style: italic;
    background: #FDFDF5;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .ideal-table-wrapper {
        padding: 10px;
    }
    
    .ideal-table-header th,
    .ideal-table-row td {
        padding: 12px 8px;
        font-size: 12px;
    }
    
    .ideal-view-btn {
        padding: 6px 12px;
        font-size: 11px;
    }
}
</style>

<!-- JavaScript to handle modal population -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const detailButtons = document.querySelectorAll('.view-details-btn');

        detailButtons.forEach(button => {
            button.addEventListener('click', function() {
                const transaction = this.dataset; // Get all data attributes in one object
                const modal = document.getElementById('transactionDetailsModal'); // Get the modal

                // Helper function to format phone number
                function formatPhoneNumber(phone) {
                    if (!phone) return '';
                    // Remove any non-digit characters
                    const digits = phone.replace(/\D/g, '');
                    // If it starts with 63, remove it and add (+63)-
                    if (digits.startsWith('63')) {
                        return '(+63)-' + digits.substring(2);
                    }
                    // If it's 10 digits and starts with 9, add (+63)-
                    if (digits.length === 10 && digits.startsWith('9')) {
                        return '(+63)-' + digits;
                    }
                    // Default formatting
                    return '(+63)-' + digits;
                }

                //Modal Customer Image
                modal.querySelector("#modalCustomerImage").src = transaction.imagePath || ''; // Set profile picture (with fallback)
                modal.querySelector("#customerName").innerText = transaction.customerName || '';
                modal.querySelector("#transactionNumber").innerText = transaction.transactionNumber || '';
                modal.querySelector("#orderDate").innerText = transaction.orderDate || '';
                modal.querySelector("#email").innerText = transaction.customerEmail || '';
                modal.querySelector("#phoneNumber").innerText = formatPhoneNumber(transaction.customerPhone);
                modal.querySelector("#shippingAddress").innerText = 'Purok: ' + transaction.purok + ', Barangay: ' + transaction.barangay + ', Valencia City';

                // Set other modal content
                modal.querySelector("#riderName").innerText = transaction.riderName || '';
                modal.querySelector("#riderContact").innerText = formatPhoneNumber(transaction.riderContact);
                modal.querySelector("#motorType").innerText = transaction.riderMotorType || '';
                modal.querySelector("#plateNumber").innerText = transaction.riderPlateNumber || '';
                
                // Handle pickup vs delivery orders
                const deliveryMethod = transaction.deliveryMethod || '';
                const paymentMethod = transaction.paymentMethod || '';
                const isPickup = deliveryMethod.toLowerCase() === 'pickup';
                const isStaffDelivery = deliveryMethod.toLowerCase() === 'staff';
                const isGcash = paymentMethod.toLowerCase() === 'gcash';
                
                // Show/hide rider information section based on delivery method
                const riderSection = modal.querySelector('.rider-details-section');
                if (riderSection) {
                    riderSection.style.display = (isPickup || isStaffDelivery) ? 'none' : 'block';
                }
                
                // Show/hide GCash reference section based on payment method
                const gcashSection = modal.querySelector('.gcash-reference-section');
                if (gcashSection) {
                    gcashSection.style.display = isGcash ? 'flex' : 'none';
                }
                
                // Set GCash reference number (you may need to add this data attribute to the button)
                if (isGcash) {
                    modal.querySelector("#gcashReference").innerText = transaction.gcashReference || 'N/A';
                }
                
                // Set pricing based on delivery method
                const subtotal = parseFloat(transaction.subtotalPrice || 0);
                const deliveryFee = isPickup ? 0 : parseFloat(transaction.deliveryFee || 0);
                const total = subtotal + deliveryFee;
                
                modal.querySelector("#subtotalPrice").innerText = '₱' + subtotal.toFixed(2);
                modal.querySelector("#deliveryFee").innerText = '₱' + deliveryFee.toFixed(2);
                modal.querySelector("#totalPrice").innerText = '₱' + total.toFixed(2);
                modal.querySelector("#paymentMethod").innerText = transaction.paymentMethod || '';
                modal.querySelector("#deliveryMethod").innerText = transaction.deliveryMethod || '';
                modal.querySelector("#homeDescription").innerText = transaction.homeDescription || '';

            });
        });
    });
</script>

<!-- JavaScript for Filtering and Pagination -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Get all transaction rows
    const allRows = Array.from(document.querySelectorAll('tbody tr'));
    const rowsPerPage = 10;
    let currentPage = 1;
    let currentFilter = 'all';
    let filteredRows = allRows;

    // Filter buttons
    const filterButtons = document.querySelectorAll('.filter-btn');
    const prevButton = document.getElementById('prev-page');
    const nextButton = document.getElementById('next-page');
    const pageInfo = document.getElementById('page-info');
    const showingStart = document.getElementById('showing-start');
    const showingEnd = document.getElementById('showing-end');
    const totalTransactions = document.getElementById('total-transactions');

    // Filter functionality
    function filterTransactions(filter) {
        currentFilter = filter;
        currentPage = 1; // Reset to first page when filtering

        if (filter === 'today') {
            const today = new Date().toISOString().split('T')[0]; // Get today's date in YYYY-MM-DD format
            filteredRows = allRows.filter(row => {
                const dateCell = row.cells[2]; // Order Date column
                const dateText = dateCell.textContent.trim();
                const rowDate = dateText.split(' ')[0]; // Extract date part (YYYY-MM-DD)
                return rowDate === today;
            });
        } else if (filter === 'daterange') {
            // Show date range inputs
            document.getElementById('dateRangeFilter').style.display = 'block';
            filteredRows = allRows; // Don't filter yet, wait for apply button
        } else {
            // Hide date range inputs for other filters
            document.getElementById('dateRangeFilter').style.display = 'none';
            filteredRows = allRows;
        }

        updatePagination();
        updateDisplay();
    }
    
    // Date range filtering function
    function filterByDateRange() {
        const fromDate = document.getElementById('fromDate').value;
        const toDate = document.getElementById('toDate').value;
        
        if (!fromDate || !toDate) {
            alert('Please select both From Date and To Date');
            return;
        }
        
        if (fromDate > toDate) {
            alert('From Date cannot be after To Date');
            return;
        }
        
        currentPage = 1;
        filteredRows = allRows.filter(row => {
            const dateCell = row.cells[2]; // Order Date column
            const dateText = dateCell.textContent.trim();
            const rowDate = dateText.split(' ')[0]; // Extract date part (YYYY-MM-DD)
            return rowDate >= fromDate && rowDate <= toDate;
        });
        
        updatePagination();
        updateDisplay();
    }
    
    // Clear date range filter
    function clearDateRange() {
        const fromDateInput = document.getElementById('fromDate');
        const toDateInput = document.getElementById('toDate');
        
        // Clear values and reset restrictions
        fromDateInput.value = '';
        toDateInput.value = '';
        fromDateInput.min = '';
        fromDateInput.max = '';
        toDateInput.min = '';
        toDateInput.max = '';
        
        document.getElementById('dateRangeFilter').style.display = 'none';
        
        // Reset to all transactions
        currentFilter = 'all';
        filteredRows = allRows;
        currentPage = 1;
        
        // Update active button
        document.querySelectorAll('.filter-btn').forEach(btn => btn.classList.remove('active'));
        document.querySelector('[data-filter="all"]').classList.add('active');
        
        updatePagination();
        updateDisplay();
    }

    // Pagination functionality
    function updatePagination() {
        const totalPages = Math.ceil(filteredRows.length / rowsPerPage);
        
        // Update pagination buttons
        prevButton.disabled = currentPage === 1;
        nextButton.disabled = currentPage === totalPages || totalPages === 0;
        
        // Update page info
        pageInfo.textContent = `Page ${currentPage} of ${totalPages}`;
        
        // Update showing info
        const start = filteredRows.length === 0 ? 0 : (currentPage - 1) * rowsPerPage + 1;
        const end = Math.min(currentPage * rowsPerPage, filteredRows.length);
        
        showingStart.textContent = start;
        showingEnd.textContent = end;
        totalTransactions.textContent = filteredRows.length;
    }

    function updateDisplay() {
        // Hide all rows first
        allRows.forEach(row => row.style.display = 'none');
        
        // Show rows for current page
        const startIndex = (currentPage - 1) * rowsPerPage;
        const endIndex = startIndex + rowsPerPage;
        
        for (let i = startIndex; i < endIndex && i < filteredRows.length; i++) {
            filteredRows[i].style.display = '';
        }
    }

    // Event listeners
    filterButtons.forEach(button => {
        button.addEventListener('click', function() {
            // Update active button
            filterButtons.forEach(btn => btn.classList.remove('active'));
            this.classList.add('active');
            
            // Apply filter
            filterTransactions(this.dataset.filter);
        });
    });
    
    // Date range filter event listeners
    document.getElementById('applyDateFilter').addEventListener('click', filterByDateRange);
    document.getElementById('clearDateFilter').addEventListener('click', clearDateRange);
    
    // Date input event listeners for dynamic validation
    document.getElementById('fromDate').addEventListener('change', function() {
        const fromDate = this.value;
        const toDateInput = document.getElementById('toDate');
        
        if (fromDate) {
            // Set minimum date for "To Date" to be the same as "From Date"
            toDateInput.min = fromDate;
            
            // If "To Date" is already selected and is before "From Date", clear it
            if (toDateInput.value && toDateInput.value < fromDate) {
                toDateInput.value = '';
            }
        } else {
            // If "From Date" is cleared, remove the min restriction
            toDateInput.min = '';
        }
    });
    
    document.getElementById('toDate').addEventListener('change', function() {
        const toDate = this.value;
        const fromDateInput = document.getElementById('fromDate');
        
        if (toDate) {
            // Set maximum date for "From Date" to be the same as "To Date"
            fromDateInput.max = toDate;
            
            // If "From Date" is already selected and is after "To Date", clear it
            if (fromDateInput.value && fromDateInput.value > toDate) {
                fromDateInput.value = '';
            }
        } else {
            // If "To Date" is cleared, remove the max restriction
            fromDateInput.max = '';
        }
    });

    prevButton.addEventListener('click', function() {
        if (currentPage > 1) {
            currentPage--;
            updatePagination();
            updateDisplay();
        }
    });

    nextButton.addEventListener('click', function() {
        const totalPages = Math.ceil(filteredRows.length / rowsPerPage);
        if (currentPage < totalPages) {
            currentPage++;
            updatePagination();
            updateDisplay();
        }
    });

    // Initialize display
    updatePagination();
    updateDisplay();
});
</script>
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
    <script src="js/main.js">
    </script>
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
<script>
     window.addEventListener('DOMContentLoaded', function() {
         setTimeout(function() {
             var spinner = document.getElementById('spinner');
             if (spinner) spinner.style.display = 'none';
         }, 500); // 0.5 second delay
     });
     </script>
</body>
</html>
<?php $conn->close(); ?>
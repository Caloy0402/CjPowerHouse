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

// Modified function to count ON-SHIP GCASH orders from a specific barangay
function getBarangayOnShipGCASHOrderCount($conn, $barangayId) {
    $sql = "SELECT COUNT(o.id)
            FROM orders o
            JOIN users u ON o.user_id = u.id
            WHERE u.barangay_id = ? AND o.payment_method = 'GCASH' AND o.order_status = 'On-Ship'";
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
        WHEN o.total_price >= 1500 THEN 0 
        WHEN o.delivery_method = 'staff' THEN COALESCE(NULLIF(o.delivery_fee, 0), bf.staff_fare_amount, 0)
        ELSE COALESCE(NULLIF(o.delivery_fee, 0), bf.fare_amount, 0) 
    END AS delivery_fee_effective,
    CASE 
        WHEN o.total_price >= 1500 THEN o.total_price
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
        <a href="index.html" class="navbar-brand mx-4 mb-3">
            <h3 class="text-primary"><i class="fa fa-user-edit me-2"></i>Cj P'House</h3>
        </a>
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
                <i class="fa fa-clock fa-3x text-primary"></i>
                <div class="ms-2">
                    <p class="mb-1 text-white">Today's Pending/Processing GCASH Orders</p>
                    <h6 class="mb-0 text-white"><?php echo $todayPendingGCASHCount !== "Error" ? htmlspecialchars($todayPendingGCASHCount) : "Error"; ?></h6>
                </div>
            </div>
        </div>
               <div class="col-md-3 col-sm-6">   <!-- Reduced to col-md-3 to fit four cards -->
            <div class="bg-secondary rounded d-flex align-items-center justify-content-between p-3">
                <i class="fa fa-check-circle fa-3x text-primary"></i>  <!-- Updated icon -->
                <div class="ms-2">
                    <p class="mb-1 text-white">Today's Ready to Ship</p>  <!-- Updated text -->
                    <h6 class="mb-0 text-white"><?php echo $todayReadyToShipCount !== "Error" ? htmlspecialchars($todayReadyToShipCount) : "Error"; ?></h6>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">  <!-- Reduced to col-md-3 to fit four cards -->
            <div class="bg-secondary rounded d-flex align-items-center justify-content-between p-3">
                <i class="fa fa-truck fa-3x text-primary"></i>
                <div class="ms-2">
                    <p class="mb-1 text-white">Today's On-delivery Orders</p>
                    <h6 class="mb-0 text-white"><?php echo $todayOnDeliveryCount !== "Error" ? htmlspecialchars($todayOnDeliveryCount) : "Error"; ?></h6>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">  <!-- Reduced to col-md-3 to fit four cards -->
            <div class="bg-secondary rounded d-flex align-items-center justify-content-between p-3">
                <i class="fa fa-cash-register fa-3x text-primary"></i>
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
            <h4 class="mb-0">Ready to On Ship Orders</h4>
        </div>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr class="text-white">
                        <th scope="col">
                            <input type="checkbox" class="form-check-input">
                        </th>
                        <th scope="col">Order ID</th>
                        <th scope="col">TRN #</th>
                        <th scope="col">Time Stamp Order</th>
                        <th scope="col">Customer Name</th>
                         <th scope="col">Address</th> <!-- New Address Column -->
                        <th scope="col">Status</th>
                        <th scope="col">Action</th>
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
                                    <td><input class="form-check-input" type="checkbox"></td>
                                    <td>' . htmlspecialchars($row['id']) . '</td>
                                    <td>' . htmlspecialchars($row['transaction_number']) . '</td>
                                    <td>' . htmlspecialchars(date('Y-m-d h:i A', strtotime($row['order_date']))) . '</td>
                                    <td>' . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . '</td>
                                     <td>' . htmlspecialchars($row['purok'] . ', ' . $row['barangay_name']) . '</td>
                                    <td>' . htmlspecialchars($row['order_status']) . '</td>
                                    <td>
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
                                            Update
                                        </a>
                                    </td>
                                  </tr>';
                        }
                    } else {
                        echo '<tr><td colspan="8">No Ready to Ship GCASH orders found.</td></tr>';
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

                <div class="customer-details mb-4">
                    <h6>Customer Details</h6>
                    <div class="d-flex flex-row align-items-start">
                        <!-- Customer Image on the Left -->
                        <div class="customer-image me-3">
                            <img id="modalCustomerImage" src="" alt="Customer Image" class="soft-edge-square" width="250" height="250">
                        </div>
                        <!-- Customer Details on the Right -->
                        <div class="customer-info">
                            <div class="row mb-2">
                                    <div class="col-4"><strong>Name:</strong></div>
                                    <div class="col-8" id="modalCustomerName"></div>
                            </div>
                             <div class="row mb-2">
                                    <div class="col-4"><strong>Shipping Address:</strong></div>
                                      <div class="col-8" id="modalShippingAddress"></div>
                                </div>
                            <div class="row mb-2">
                                    <div class="col-4"><strong>Transaction Number:</strong></div>
                                    <div class="col-8" id="modalTransactionNumber"></div>
                            </div>
                            <div class="row mb-2">
                                    <div class="col-4"><strong>Order Date:</strong></div>
                                    <div class="col-8" id="modalOrderDate"></div>
                            </div>

                            <div class="row mb-2">
                                    <div class="col-4"><strong>Total Price:</strong></div>
                                    <div class="col-8" id="modalTotalPrice"></div>
                            </div>

                            <div class="row mb-2">
                                    <div class="col-4"><strong>Delivery Fee:</strong></div>
                                    <div class="col-8" id="modalDeliveryFee"></div>
                            </div>

                            <div class="row mb-2">
                                    <div class="col-4"><strong>Total with Delivery:</strong></div>
                                    <div class="col-8" id="modalTotalWithDelivery"></div>
                            </div>

                            <div class="row mb-2">
                                    <div class="col-4"><strong>Payment Method:</strong></div>
                                    <div class="col-8" id="modalPaymentMethod"></div>
                            </div>
                                    
                            <div class="row mb-2">
                                    <div class="col-4"><strong>Total Weight:</strong></div>
                                    <div class="col-8" id="modalTotalWeight"></div>
                            </div>

                            <div class="row mb-2">
                                    <div class="col-4"><strong>Delivery Method:</strong></div>
                                    <div class="col-8" id="modalDeliveryMethod"></div>
                            </div>
                            <div class="row mb-2">
                                    <div class="col-4"><strong>Home Description:</strong></div>
                                    <div class="col-8" id="modalHomeDescription"></div>
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
            $('#modalTransactionNumber').text(transactionNumber);
            $('#modalOrderDate').text(orderDate);
            $('#modalCustomerName').text(customerName);

            // Construct the shipping address with labels
            var shippingAddress = "Purok: " + purok + ", Brgy: " + barangay + ", Valencia City, Bukidnon";
            $('#modalShippingAddress').text(shippingAddress);

            $('#modalTotalPrice').text('₱' + parseFloat(totalPrice).toFixed(2));
            // Display delivery fee with free shipping logic
            if (parseFloat(totalPrice) >= 1500) {
                $('#modalDeliveryFee').html('<span style="color: #28a745; font-weight: bold;">FREE</span>');
            } else {
                $('#modalDeliveryFee').text('₱' + parseFloat(deliveryFee || 0).toFixed(2));
            }
            $('#modalTotalWithDelivery').text('₱' + parseFloat(totalWithDelivery || totalPrice).toFixed(2));
            if (paymentMethod && paymentMethod.toUpperCase() === 'GCASH' && referenceNumber) {
                $('#modalPaymentMethod').text(paymentMethod + ' (RFN#: ' + referenceNumber + ')');
            } else {
                $('#modalPaymentMethod').text(paymentMethod);
            }
            $('#modalTotalWeight').text(totalWeight);
            $('#modalDeliveryMethod').text(deliveryMethod);
            $('#modalHomeDescription').text(homeDescription);

            $('#modalRiderName').val(riderName);
            $('#modalRiderContactInfo').val(riderContact);
            $('#modalMotorType').val(motorType);
            $('#modalPlateNumber').val(plateNumber);

            // Set the profile picture
            $('#modalCustomerImage').attr('src', imagePath);
        });

        // Function to handle the "Update Order" button click
        $('#modalShippingBtn').on('click', function() {
            // Gather the data from the modal
            var orderId = $('#modalOrderId').val();
            var riderName = $('#modalRiderName').val();
            var riderContact = $('#modalRiderContactInfo').val();
          
            var motorType = $('#modalMotorType').val();
            var plateNumber = $('#modalPlateNumber').val();
            var orderStatus = $('#orderStatus').val();

            // Make an AJAX request to update-order.php
            $.ajax({
                url: 'update-order.php',
                method: 'POST',
                data: {
                    order_id: orderId,
                    rider_name: riderName,
                    rider_contact: riderContact,
                    rider_motor_type: motorType,
                    rider_plate_number: plateNumber,
                    order_status: orderStatus
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        alert(response.message);
                        $('#orderDetailsModal').modal('hide'); // Close the modal
                        location.reload(); // Reload the page to reflect changes
                    } else {
                        alert(response.message);
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error('Error updating order:', textStatus, errorThrown);
                    alert('Error updating order. Please check the console.');
                }
            });
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>
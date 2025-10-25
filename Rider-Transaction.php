<?php
session_start();
require_once 'dbconn.php';

// Dynamically determine the base URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$path = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$baseURL = $protocol . '://' . $host . $path . '/';

// Check if the user is logged in and has the 'Rider' role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Rider') {
    header("Location: {$baseURL}signin.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch rider data from the database (Riders table)
$sql = "SELECT * FROM riders WHERE id = ?";
$stmt = $conn->prepare($sql);

if ($stmt === false) {
    echo "Error preparing statement: " . $conn->error;
    exit();
}

$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if (!$user) {
    echo "Rider not found!";
    exit(); // Or redirect to an error page
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

// Fetch current deliveries for the rider - JOIN with users and transactions tables
$riderFullName = $user['first_name'] . ' ' . $user['last_name'];
$sql_deliveries = "SELECT o.*, t.transaction_number, gt.reference_number, u.first_name AS customer_first_name, u.last_name AS customer_last_name, u.phone_number, u.purok, b.barangay_name, o.total_price, o.total_weight, o.order_date, o.home_description, o.rider_contact, o.reason,
                          bf.fare_amount AS barangay_fare, bf.staff_fare_amount AS barangay_staff_fare
                          $selectDeliveryCols $selectFareFallback
                    FROM orders o
                    LEFT JOIN transactions t ON o.id = t.order_id
                    LEFT JOIN gcash_transactions gt ON o.id = gt.order_id
                    JOIN users u ON o.user_id = u.id
                    JOIN barangays b ON u.barangay_id = b.id
                    LEFT JOIN barangay_fares bf ON b.id = bf.barangay_id
                    WHERE o.rider_name = ? AND o.order_status = 'On-Ship'";

$stmt_deliveries = $conn->prepare($sql_deliveries);
if ($stmt_deliveries === false) {
    echo "Error preparing deliveries statement: " . $conn->error;
    exit();
}

$stmt_deliveries->bind_param("s", $riderFullName);
$stmt_deliveries->execute();
$result_deliveries = $stmt_deliveries->get_result();
$current_deliveries = [];

while ($row = $result_deliveries->fetch_assoc()) {
    $current_deliveries[] = $row;
}

// Remove premature connection close so we can run more queries below
// $stmt->close();
// $stmt_deliveries->close();
// $conn->close();

// ----- Completed/Past Orders History (with optional date filter) -----
$start_date = isset($_GET['start_date']) && $_GET['start_date'] !== '' ? $_GET['start_date'] : null;
$end_date = isset($_GET['end_date']) && $_GET['end_date'] !== '' ? $_GET['end_date'] : null;

$sql_history = "SELECT o.id, o.order_date, o.order_status, o.total_price, o.payment_method,
                       t.transaction_number, gt.reference_number,
                       u.first_name AS customer_first_name, u.last_name AS customer_last_name,
                       u.phone_number, u.purok, b.barangay_name,
                       bf.fare_amount AS barangay_fare, bf.staff_fare_amount AS barangay_staff_fare
                       $selectDeliveryCols $selectFareFallback
                FROM orders o
                LEFT JOIN transactions t ON o.id = t.order_id
                LEFT JOIN gcash_transactions gt ON o.id = gt.order_id
                JOIN users u ON o.user_id = u.id
                JOIN barangays b ON u.barangay_id = b.id
                LEFT JOIN barangay_fares bf ON b.id = bf.barangay_id
                WHERE o.rider_name = ? AND o.order_status IN ('Completed','Returned')";

$params = [$riderFullName];
$types = 's';

if ($start_date && $end_date) {
    $sql_history .= " AND DATE(o.order_date) BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
    $types .= 'ss';
} elseif ($start_date) {
    $sql_history .= " AND DATE(o.order_date) = ?";
    $params[] = $start_date;
    $types .= 's';
}

$sql_history .= " ORDER BY o.order_date DESC";

$stmt_history = $conn->prepare($sql_history);
if ($stmt_history === false) {
    echo "Error preparing history statement: " . $conn->error;
    exit();
}

$stmt_history->bind_param($types, ...$params);
$stmt_history->execute();
$result_history = $stmt_history->get_result();
$completed_history = [];
while ($row = $result_history->fetch_assoc()) {
    $completed_history[] = $row;
}

// Close statements after queries prepared
$stmt->close();
$stmt_deliveries->close();
$stmt_history->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Current Deliveries - Rider</title>
    <link rel="icon" type="image/png" href="<?= $baseURL ?>Image/logo.png">
    <link href="<?= $baseURL ?>css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= $baseURL ?>css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="stylesheet" href="<?= $baseURL ?>css/rider-styles.css">
    <style>
        /* Add your custom styles here */
        .delivery-item {
            background-color: #fff;
            border: 1px solid black;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            color: #333; /* Darker text color */
        }

        .delivery-item h3 {
            margin-top: 0;
            margin-bottom: 10px;
            font-size: 1.2em;
        }

        .delivery-table {
            width: 100%;
            border-collapse: collapse;
        }

        .delivery-table td {
            padding: 8px;
            border: none; /* Remove cell borders */
            text-align: left;
        }

        .delivery-table td:first-child {
            font-weight: bold; /* Make labels bold */
            width: 30%; /* Adjust as needed */
        }

        .horizontal-timeline {
            display: flex;
           /* justify-content: space-around;  /*items horizontally aligned */
            align-items: flex-start; /* items to top */
            padding: 20px;
            position: relative; /* Needed for the progress line */
            width: 100%; /* Ensure timeline takes full width */
        }
        .step-label {
            white-space: nowrap; /* Prevent labels from wrapping */
              width: fit-content; /* Allows for stretching */
        }
        .horizontal-timeline::before { /* The progress line */
            content: '';
            position: absolute;
            top: 50%;
            left: 10%; /* Start line a bit inside */
            right: 10%; /* End line a bit inside */
            height: 2px;
            background-color: #ccc;
            transform: translateY(-50%);
            z-index: 0; /* Place behind the steps */
        }

         /*Style for Delivery Status Timeline */
        .horizontal-timeline {
           justify-content: space-around; /*items horizontally aligned */
           align-items: flex-start; /* items to top */
           padding: 20px;
           position: relative; /* Needed for the progress line */
           width: 100%; /* Ensure timeline takes full width */
           display:flex;
           justify-content:space-between
        }

        .timeline-step {
            position: relative;
            z-index: 1;
             display: flex;
            flex-direction: column;
            align-items: center;
            width: 33%;  /*Make each timeline step consistent width for equal distribution*/
            text-align: center
        }
        .form-select,
        .form-control {
            background-color: white !important; /* Force white background */
            color: black !important; /* Force black text */
            border: 1px solid #ced4da; /* Optional: Keep a subtle border */
        }

        .form-select option {
            color: black; /* Ensure option text is also black */
        }
       .timeline-step .step-icon {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background-color: #fff;
            border: 2px solid #ccc;
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 0 auto 5px; /* Spacing between icon and the icons */
            position: relative;
            z-index: 1;
        }

        .timeline-step.completed .step-icon {
            border-color: #4CAF50;
            background-color: white;
             color: #4CAF50;
        }

        .timeline-step.completed .step-icon i {
            color: #4CAF50;
        }

        .timeline-step.active .step-icon {
            border-color: #4CAF50;
             background-color: white;
        }

        .timeline-step.active .step-label {
             font-weight: bold;
        }
        .timeline-step .check-icon {
             color: white;
        }

        .timeline-step .order-icon { /* Style for the shopping cart, truck, wallet */
            font-size: 24px;
            margin-top: 5px; /* Adjust as needed for spacing */
            color: #777; /* Adjust color as needed */
        }
        .timeline-step.completed .order-icon{
            color: #4CAF50;
        }

        .timeline-step .step-label {
            color: #333;
            margin-top: 5px; /* Spacing between icon and label */
        }
         /* Style for Delivery Status Timeline */
        .delivery-timeline {
            padding: 20px;
        }

        .status-item {
            padding: 10px;
            border-left: 3px solid #ccc;
            margin-left: 20px;
            position: relative;
        }

        .status-item:last-child {
            border-left: none;
        }

        .status-item .status-circle {
            position: absolute;
            left: -8px;
            top: 50%;
            transform: translateY(-50%);
            width: 15px;
            height: 15px;
            border-radius: 50%;
            background-color: #ccc;
        }

        .status-item.active .status-circle {
            background-color: green;
        }

        .status-item.returned .status-circle {
            background-color: red;
        }

        .status-item .status-text {
            margin-left: 20px;
        }
        .return-reason {
            margin-top: 10px;
        }
        .modal {
                z-index: 1050 !important; /* Or higher */

            }

        /* Make date filter labels easier to read */
        .completed-history .form-label {
            color: #111 !important;
            font-weight: 700;
            letter-spacing: 0.3px;
        }

        /* Center section headings */
        .current-deliveries h2,
        .completed-history h2 {
            text-align: center;
        }

        /* Center and emphasize empty state messages */
        .current-deliveries p,
        .completed-history p {
            text-align: center;
            color: #222;
            font-weight: 600;
        }

        /* Tooltip styling for return reason validation */
        .tooltip {
            position: relative;
            display: block;
            width: 100%;
        }

        .tooltip .tooltiptext {
            visibility: hidden;
            width: 250px;
            background-color: #dc3545;
            color: white;
            text-align: center;
            border-radius: 6px;
            padding: 8px 12px;
            position: absolute;
            z-index: 1;
            bottom: 125%;
            left: 50%;
            margin-left: -125px;
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 14px;
            font-weight: 500;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }

        .tooltip .tooltiptext::after {
            content: "";
            position: absolute;
            top: 100%;
            left: 50%;
            margin-left: -5px;
            border-width: 5px;
            border-style: solid;
            border-color: #dc3545 transparent transparent transparent;
        }

        .tooltip.show .tooltiptext {
            visibility: visible;
            opacity: 1;
        }

        .form-error {
            border: 2px solid #dc3545 !important;
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25) !important;
        }

        /* Ensure textarea is visible and properly styled */
        .tooltip textarea {
            display: block !important;
            width: 100% !important;
            min-height: 80px !important;
            resize: vertical;
            background-color: white !important;
            color: black !important;
            border: 1px solid #ced4da !important;
            border-radius: 0.375rem !important;
            padding: 0.375rem 0.75rem !important;
        }

        /* Ensure the return reason container is visible when shown */
        #returnReasonContainer {
            display: block !important;
        }

        #returnReasonContainer[style*="display:none"] {
            display: none !important;
        }

        /* Modern Notification System */
        .notification-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            max-width: 400px;
        }

        .notification {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            padding: 16px 20px;
            margin-bottom: 10px;
            border-radius: 12px;
            box-shadow: 0 8px 25px rgba(40, 167, 69, 0.3);
            display: flex;
            align-items: center;
            animation: slideInRight 0.4s ease-out;
            position: relative;
            overflow: hidden;
        }

        .notification::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #fff, rgba(255,255,255,0.5));
        }

        .notification-icon {
            font-size: 24px;
            margin-right: 12px;
            animation: bounce 0.6s ease-in-out;
        }

        .notification-content {
            flex: 1;
        }

        .notification-title {
            font-weight: 600;
            font-size: 16px;
            margin: 0 0 4px 0;
        }

        .notification-message {
            font-size: 14px;
            margin: 0;
            opacity: 0.9;
        }

        .notification-close {
            background: none;
            border: none;
            color: white;
            font-size: 18px;
            cursor: pointer;
            padding: 0;
            margin-left: 12px;
            opacity: 0.7;
            transition: opacity 0.2s;
        }

        .notification-close:hover {
            opacity: 1;
        }

        /* Error notification */
        .notification.error {
            background: linear-gradient(135deg, #dc3545, #c82333);
            box-shadow: 0 8px 25px rgba(220, 53, 69, 0.3);
        }

        .notification.error::before {
            background: linear-gradient(90deg, #fff, rgba(255,255,255,0.5));
        }

        /* Warning notification */
        .notification.warning {
            background: linear-gradient(135deg, #ffc107, #e0a800);
            color: #212529;
            box-shadow: 0 8px 25px rgba(255, 193, 7, 0.3);
        }

        .notification.warning::before {
            background: linear-gradient(90deg, #212529, rgba(33,37,41,0.5));
        }

        /* Animations */
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

        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% {
                transform: translateY(0);
            }
            40% {
                transform: translateY(-10px);
            }
            60% {
                transform: translateY(-5px);
            }
        }

        .notification.slide-out {
            animation: slideOutRight 0.3s ease-in forwards;
        }
    </style>
</head>
<body>
    <div class="app">
        <header class="header">
            <div class="header-left">
                <?php if (!empty($user['ImagePath'])): ?>
                    <img src="<?= $baseURL . $user['ImagePath'] ?>" alt="Profile" class="profile-icon">
                <?php else: ?>
                    <img src="<?= $baseURL ?>uploads/profile.png" alt="Profile" class="profile-icon">
                <?php endif; ?>
                <span class="header-welcome">Hi, Rider <?= htmlspecialchars($user['first_name']) ?></span>
            </div>
            <img src="<?= $baseURL ?>uploads/Cjhouse.png" alt="Powerhouse Logo" class="powerhouse-logo app-logo">
        </header>

        <main>
            <section class="current-deliveries">
                <h2>Current Deliveries</h2>
                <?php if (!empty($current_deliveries)): ?>
                    <?php foreach ($current_deliveries as $delivery): ?>
                        <div class="delivery-item">
                            <h3>Order ID: <?= htmlspecialchars($delivery['id']) ?></h3>
                            <table class="delivery-table">
                            <tbody>
                                <tr>
                                    <th scope="row">Customer:</th>
                                    <td><?= htmlspecialchars($delivery['customer_first_name'] . ' ' . $delivery['customer_last_name']) ?></td>
                                </tr>
                                <tr>
                                    <th scope="row">Contact Info:</th>
                                    <td>+(63)-<?= htmlspecialchars($delivery['phone_number']) ?></td>
                                </tr>
                                <tr>
                                    <th scope="row">Shipping Address:</th>
                                    <td>Purok: <?= isset($delivery['purok']) ? htmlspecialchars($delivery['purok']) : 'N/A' ?>, Brgy: <?= htmlspecialchars($delivery['barangay_name']) ?>, Valencia City, Bukidnon</td>
                                </tr>
                                <tr>
                                    <th scope="row">Order Date:</th>
                                    <td><?= date("F j, Y, g:i a", strtotime($delivery['order_date'])) ?></td>
                                </tr>
                                <tr>
                                    <th scope="row">Home Description:</th>
                                    <td><?= htmlspecialchars($delivery['home_description']) ?></td>
                                </tr>
                                <tr>
                                    <th scope="row">Rider Contact:</th>
                                    <td><?= htmlspecialchars($delivery['rider_contact']) ?></td>
                                </tr>
                                <tr>
                                    <th scope="row">Payment Method:</th>
                                    <td>
                                        <?php
                                            $pm = $delivery['payment_method'] ?? '';
                                            if (strtoupper($pm) === 'GCASH' && !empty($delivery['reference_number'])) {
                                                echo 'GCASH (RFN#: ' . htmlspecialchars($delivery['reference_number']) . ')';
                                            } else {
                                                echo htmlspecialchars($pm);
                                            }
                                        ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Total Weight:</th>
                                    <td><?= htmlspecialchars($delivery['total_weight']) ?> kg</td>
                                </tr>
                                <tr>
                                    <th scope="row">Total Price:</th>
                                    <td>₱<?= htmlspecialchars($delivery['total_price']) ?></td>
                                </tr>
                                <tr>
                                    <th scope="row">Delivery Fee:</th>
                                    <td>
                                        <?php
                                            $fee = $delivery['delivery_fee_effective'] ?? $delivery['delivery_fee'] ?? 0;
                                            echo '₱' . htmlspecialchars($fee);
                                        ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Total Amount:</th>
                                    <td>₱<?= htmlspecialchars($delivery['total_with_delivery_effective'] ?? $delivery['total_amount_with_delivery'] ?? $delivery['total_price']) ?></td>
                                </tr>
                            </tbody>
                        </table>
                             <button data-bs-toggle="modal" data-bs-target="#deliveryDetailsModal<?= $delivery['id'] ?>">View Details</button>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>No Current Deliveries Assigned.</p>
                <?php endif; ?>
            </section>

            <!-- Completed/Past Orders History with Date Filter -->
            <section class="completed-history" style="margin-top: 20px;">
                <h2>Order History</h2>
                <form method="get" class="row g-2 align-items-end" style="margin-bottom: 10px;">
                    <div class="col-6">
                        <label for="start_date" class="form-label">From</label>
                        <input type="date" id="start_date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date ?? '') ?>">
                    </div>
                    <div class="col-6">
                        <label for="end_date" class="form-label">To</label>
                        <input type="date" id="end_date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date ?? '') ?>">
                    </div>
                    <div class="col-12 d-flex gap-2" style="margin-top: 8px;">
                        <button type="submit" class="btn btn-primary">Filter</button>
                        <a href="<?= $baseURL ?>Rider-Transaction.php" class="btn btn-secondary">Clear</a>
                    </div>
                </form>

                <?php if (!empty($completed_history)): ?>
                    <?php foreach ($completed_history as $history): ?>
                        <div class="delivery-item">
                            <h3>Order ID: <?= htmlspecialchars($history['id']) ?> | TRN: <?= htmlspecialchars($history['transaction_number'] ?? 'N/A') ?></h3>
                            <table class="delivery-table">
                                <tbody>
                                    <tr>
                                        <th scope="row">Customer:</th>
                                        <td><?= htmlspecialchars($history['customer_first_name'] . ' ' . $history['customer_last_name']) ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Contact Info:</th>
                                        <td>+(63)-<?= htmlspecialchars($history['phone_number']) ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Shipping Address:</th>
                                        <td>Purok: <?= isset($history['purok']) ? htmlspecialchars($history['purok']) : 'N/A' ?>, Brgy: <?= htmlspecialchars($history['barangay_name']) ?>, Valencia City, Bukidnon</td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Order Date:</th>
                                        <td><?= date("F j, Y, g:i a", strtotime($history['order_date'])) ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Total Price:</th>
                                        <td>₱<?= htmlspecialchars($history['total_price']) ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Delivery Fee:</th>
                                        <td>
                                            <?php
                                                $hfee = $history['delivery_fee_effective'] ?? $history['delivery_fee'] ?? 0;
                                                echo '₱' . htmlspecialchars($hfee);
                                            ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Total Amount:</th>
                                        <td>₱<?= htmlspecialchars($history['total_with_delivery_effective'] ?? $history['total_amount_with_delivery'] ?? $history['total_price']) ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Status:</th>
                                        <td><?= htmlspecialchars($history['order_status']) ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>No order history found for this rider<?= ($start_date || $end_date) ? ' for the selected date(s).' : '.' ?></p>
                <?php endif; ?>
            </section>

                <?php foreach ($current_deliveries as $delivery): ?>
                    <div class="modal fade" id="deliveryDetailsModal<?= $delivery['id'] ?>" tabindex="-1" aria-labelledby="deliveryDetailsModalLabel" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="deliveryDetailsModalLabel" style="color: black;">Delivery Details - Order ID: <?= htmlspecialchars($delivery['id']) ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="horizontal-timeline d-flex justify-content-between">
                                        <div class="timeline-step text-center <?php if ($delivery['order_status'] == 'Ready to Ship' || $delivery['order_status'] == 'On-Ship' || $delivery['order_status'] == 'Completed' || $delivery['order_status'] == 'Returned') echo 'completed'; if ($delivery['order_status'] == 'Ready to Ship') echo ' active'; ?>">
                                            <div class="step-icon">
                                                <?php if ($delivery['order_status'] == 'Ready to Ship' || $delivery['order_status'] == 'On-Ship' || $delivery['order_status'] == 'Completed' || $delivery['order_status'] == 'Returned'): ?>
                                                    <i class="fas fa-check"></i>
                                                <?php endif; ?>
                                            </div>
                                            <i class="fas fa-shopping-cart order-icon"></i>
                                            <div class="step-label">RT'Ship</div>
                                        </div>

                                        <div class="timeline-step text-center <?php if ($delivery['order_status'] == 'On-Ship' || $delivery['order_status'] == 'Completed' || $delivery['order_status'] == 'Returned') echo 'completed'; if ($delivery['order_status'] == 'On-Ship') echo ' active'; ?>">
                                            <div class="step-icon">
                                                <?php if ($delivery['order_status'] == 'On-Ship' || $delivery['order_status'] == 'Completed' || $delivery['order_status'] == 'Returned'): ?>
                                                    <i class="fas fa-check"></i>
                                                <?php endif; ?>
                                            </div>
                                            <i class="fas fa-truck order-icon"></i>
                                            <div class="step-label">Onship</div>
                                        </div>

                                        <div class="timeline-step text-center <?php if ($delivery['order_status'] == 'Completed' || $delivery['order_status'] == 'Returned') echo 'completed'; if ($delivery['order_status'] == 'Completed') echo ' active'; ?>">
                                            <div class="step-icon">
                                                <?php if ($delivery['order_status'] == 'Completed' || $delivery['order_status'] == 'Returned'): ?>
                                                    <i class="fas fa-check"></i>
                                                <?php endif; ?>
                                            </div>
                                            <i class="fas fa-wallet order-icon"></i>
                                            <div class="step-label">Complete</div>
                                        </div>
                                    </div>

                                    <table class="table table-bordered">
                                        <tbody>
                                            <tr>
                                                <th scope="row">Customer:</th>
                                                <td><?= htmlspecialchars($delivery['customer_first_name'] . ' ' . $delivery['customer_last_name']) ?></td>
                                            </tr>
                                            <tr>
                                                <th scope="row">Contact Info:</th>
                                                <td>+(63)-<?= htmlspecialchars($delivery['phone_number']) ?></td>
                                            </tr>
                                            <tr>
                                                <th scope="row">Shipping Address:</th>
                                                <td>Purok: <?= isset($delivery['purok']) ? htmlspecialchars($delivery['purok']) : 'N/A' ?>, Brgy: <?= htmlspecialchars($delivery['barangay_name']) ?>, Valencia City, Bukidnon</td>
                                            </tr>
                                            <tr>
                                                <th scope="row">Order Date:</th>
                                                <td><?= date("F j, Y, g:i a", strtotime($delivery['order_date'])) ?></td>
                                            </tr>
                                            <tr>
                                                <th scope="row">Home Description:</th>
                                                <td><?= htmlspecialchars($delivery['home_description']) ?></td>
                                            </tr>
                                            <tr>
                                                <th scope="row">Rider Contact:</th>
                                                <td><?= htmlspecialchars($delivery['rider_contact']) ?></td>
                                            </tr>
                                            <tr>
                                                <th scope="row">Total Weight:</th>
                                                <td><?= htmlspecialchars($delivery['total_weight']) ?> kg</td>
                                            </tr>
                                            <tr>
                                                <th scope="row">Delivery Fee:</th>
                                                <td>
                                                    <?php
                                                        $fee = $delivery['delivery_fee_effective'] ?? $delivery['delivery_fee'] ?? 0;
                                                        echo '₱' . htmlspecialchars($fee);
                                                    ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th scope="row">Total Amount:</th>
                                                <td>₱<?= htmlspecialchars($delivery['total_with_delivery_effective'] ?? $delivery['total_amount_with_delivery'] ?? $delivery['total_price']) ?></td>
                                            </tr>
                                        </tbody>
                                    </table>

                                    <!-- Display Return Reason Input Only If Status is Returned -->
                                    <div class="mb-3" id="returnReasonContainer<?= htmlspecialchars($delivery['id']) ?>" style="<?php echo $delivery['order_status'] == 'Returned' ? '' : 'display:none;' ?>">
                                        <label for="returnReason<?= htmlspecialchars($delivery['id']) ?>" class="form-label">Reason for Return: <span class="text-danger">*</span></label>
                                        
                                        <!-- Simple textarea without tooltip wrapper for testing -->
                                        <textarea class="form-control" id="returnReason<?= htmlspecialchars($delivery['id']) ?>" name="return_reason" rows="3" placeholder="Please provide a reason for returning this order..." style="display: block !important; width: 100% !important; min-height: 80px !important; background-color: white !important; color: black !important; border: 1px solid #ced4da !important; border-radius: 0.375rem !important; padding: 0.375rem 0.75rem !important; resize: vertical; margin-bottom: 10px;"><?= isset($delivery['reason']) ? htmlspecialchars($delivery['reason']) : '' ?></textarea>
                                        
                                        <!-- Tooltip for validation messages -->
                                        <div class="tooltip" style="position: relative; display: block; width: 100%;">
                                            <span class="tooltiptext" id="returnReasonTooltip<?= htmlspecialchars($delivery['id']) ?>" style="visibility: hidden; width: 250px; background-color: #dc3545; color: white; text-align: center; border-radius: 6px; padding: 8px 12px; position: absolute; z-index: 1; bottom: 125%; left: 50%; margin-left: -125px; opacity: 0; transition: opacity 0.3s; font-size: 14px; font-weight: 500; box-shadow: 0 4px 8px rgba(0,0,0,0.2);">Please fill in the reason for return before updating the status.</span>
                                        </div>
                                    </div>

                                </div>
                                <!-- Form for updating status (and return reason) -->
                                <form id="updateDeliveryForm<?= $delivery['id'] ?>" data-order-id="<?= htmlspecialchars($delivery['id']) ?>">
                                    <input type="hidden" name="order_id" value="<?= htmlspecialchars($delivery['id']) ?>">
                                    <div class="mb-3">
                                        <label for="orderStatus" class="form-label">Update Status:</label>
                                        <select class="form-select" id="orderStatus<?= htmlspecialchars($delivery['id']) ?>" name="order_status">
                                            <?php if (strtoupper($delivery['payment_method']) === 'GCASH'): ?>
                                                <option value="Completed" <?= $delivery['order_status'] == 'Completed' ? 'selected' : '' ?>>Completed</option>
                                            <?php else: ?>
                                                <option value="Completed" <?= $delivery['order_status'] == 'Completed' ? 'selected' : '' ?>>Completed</option>
                                                <option value="Returned" <?= $delivery['order_status'] == 'Returned' ? 'selected' : '' ?>>Returned</option>
                                            <?php endif; ?>
                                        </select>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                        <button type="button" class="btn btn-primary update-delivery-btn" data-order-id="<?= htmlspecialchars($delivery['id']) ?>">Update</button>
                                    </div>
                                </form>

                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
        </main>

        <!-- Modern Notification Container -->
        <div id="notificationContainer" class="notification-container"></div>

        <nav class="bottom-nav">
            <a href="<?= $baseURL ?>Rider-Dashboard.php">
                <span class="material-icons">home</span>
                <span>Home</span>
            </a>
            <a href="<?= $baseURL ?>Rider-Transaction.php" class="active">
                <span class="material-icons">history</span>
                <span>Delivery Status</span>
            </a>
            <a href="<?= $baseURL ?>Rider-Profile.php">
                <span class="material-icons">person</span>
                <span>Profile</span>
            </a>
        </nav>
    </div>
     <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="<?= $baseURL ?>js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= $baseURL ?>js/script.js"></script>
    <script src="<?= $baseURL ?>js/rider-script.js"></script>

    <script>
        // Function to show tooltip
        function showTooltip(orderId, message) {
            const tooltip = document.getElementById(`returnReasonTooltip${orderId}`);
            if (tooltip) {
                tooltip.textContent = message;
                tooltip.style.visibility = 'visible';
                tooltip.style.opacity = '1';
                setTimeout(() => {
                    tooltip.style.visibility = 'hidden';
                    tooltip.style.opacity = '0';
                }, 4000);
            }
        }

        // Modern Notification System
        function showNotification(type, title, message, duration = 4000) {
            const container = document.getElementById('notificationContainer');
            if (!container) return;

            // Create notification element
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            
            // Set icon based on type
            let icon = '✓';
            if (type === 'error') icon = '⚠';
            else if (type === 'warning') icon = '⚠';
            else if (type === 'success') icon = '✓';
            else icon = 'ℹ';

            notification.innerHTML = `
                <div class="notification-icon">${icon}</div>
                <div class="notification-content">
                    <div class="notification-title">${title}</div>
                    <div class="notification-message">${message}</div>
                </div>
                <button class="notification-close" onclick="closeNotification(this)">&times;</button>
            `;

            // Add to container
            container.appendChild(notification);

            // Auto remove after duration
            setTimeout(() => {
                closeNotification(notification.querySelector('.notification-close'));
            }, duration);
        }

        function closeNotification(closeBtn) {
            const notification = closeBtn.closest('.notification');
            if (notification) {
                notification.classList.add('slide-out');
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }
        }

        // Debug function to check textarea visibility
        function debugTextareaVisibility(orderId) {
            const container = $(`#returnReasonContainer${orderId}`);
            const textarea = $(`#returnReason${orderId}`);
            console.log('Container visibility:', container.is(':visible'));
            console.log('Textarea visibility:', textarea.is(':visible'));
            console.log('Container display:', container.css('display'));
            console.log('Textarea display:', textarea.css('display'));
        }

        // Function to validate return reason
        function validateReturnReason(orderId) {
            const orderStatus = $(`#orderStatus${orderId}`).val();
            const returnReason = $(`#returnReason${orderId}`).val().trim();
            const textarea = $(`#returnReason${orderId}`);

            // If status is Returned, validate return reason
            if (orderStatus === 'Returned') {
                if (returnReason === '') {
                    // Show tooltip
                    showTooltip(orderId, 'Please fill in the reason for return before updating the status.');
                    textarea.addClass('form-error');
                    textarea.focus();
                    return false;
                } else {
                    // Clear any previous errors
                    textarea.removeClass('form-error');
                    return true;
                }
            }
            return true;
        }

        $(document).ready(function() {
        // Listen for a click on any element with class "update-delivery-btn"
        $(".update-delivery-btn").on("click", function() {
        // Get the order ID from the data attribute
        var orderId = $(this).data("order-id");
        
        // Validate return reason before proceeding
        if (!validateReturnReason(orderId)) {
            return; // Stop execution if validation fails
        }
        
        // Get the new order status from the select input
        var orderStatus = $(`#orderStatus${orderId}`).val();
        var returnReason = $(`#returnReason${orderId}`).val();


        // AJAX request to update the order status
        $.ajax({
        url: "update-order.php", // Replace with the correct URL
        type: "POST",
        data: {
            order_id: orderId,
            order_status: orderStatus,
            return_reason: returnReason,
        },
        dataType: "json", // Expect JSON response
        success: function(response) {
            // Check if the update was successful
            if (response.success) {
                // Show a modern success notification
                showNotification('success', 'Success!', 'Order status updated successfully!', 3000);

                // Refresh the page to reflect the changes after a short delay
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            } else {
                // Show an error notification
                showNotification('error', 'Update Failed', 'Failed to update order status: ' + response.message, 5000);
            }
        },
        error: function(xhr, status, error) {
        // Show an error notification
            console.error("AJAX error: " + status + " - " + error);
            showNotification('error', 'Network Error', 'An error occurred while updating the order status. Please try again.', 5000);
        }
        });
        });

         // Add an event listener to the order status select element
         <?php foreach ($current_deliveries as $delivery): ?>
            $(`#orderStatus${<?= htmlspecialchars($delivery['id']) ?>}`).change(function() {
                var deliveryId = <?= htmlspecialchars($delivery['id']) ?>;
                // For GCASH, never show return reason (non-refundable)
                <?php if (strtoupper($delivery['payment_method']) === 'GCASH'): ?>
                    $(`#returnReasonContainer${deliveryId}`).hide();
                <?php else: ?>
                if ($(this).val() === "Returned") {
                    $(`#returnReasonContainer${deliveryId}`).show();
                    console.log('Showing return reason container for delivery:', deliveryId);
                    debugTextareaVisibility(deliveryId);
                } else {
                    $(`#returnReasonContainer${deliveryId}`).hide();
                    console.log('Hiding return reason container for delivery:', deliveryId);
                }
                <?php endif; ?>
            });

            // Add event listener to clear error styling when user types in return reason
            $(`#returnReason${<?= htmlspecialchars($delivery['id']) ?>}`).on('input', function() {
                $(this).removeClass('form-error');
            });
             // Initialize the visibility on page load
             var initialStatus = $(`#orderStatus${<?= htmlspecialchars($delivery['id']) ?>}`).val();
             <?php if (strtoupper($delivery['payment_method']) === 'GCASH'): ?>
                $(`#returnReasonContainer${<?= htmlspecialchars($delivery['id']) ?>}`).hide();
             <?php else: ?>
                if (initialStatus === "Returned") {
                    $(`#returnReasonContainer${<?= htmlspecialchars($delivery['id']) ?>}`).show();
                } else {
                    $(`#returnReasonContainer${<?= htmlspecialchars($delivery['id']) ?>}`).hide();
                }
             <?php endif; ?>
        <?php endforeach; ?>
        });
    </script>

</body>
</html>
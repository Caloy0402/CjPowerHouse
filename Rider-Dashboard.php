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

$riderFullName = $user['first_name'] . ' ' . $user['last_name'];

// Initialize the selected barangay ID
$selectedBarangayId = isset($_GET['barangay']) ? $_GET['barangay'] : null;

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
    COALESCE(NULLIF(o.delivery_fee, 0), bf.fare_amount, 0) AS delivery_fee_effective,
    CASE WHEN (o.total_amount_with_delivery IS NULL OR o.total_amount_with_delivery = 0)
         THEN (o.total_price + COALESCE(NULLIF(o.delivery_fee, 0), bf.fare_amount, 0))
         ELSE o.total_amount_with_delivery END AS total_with_delivery_effective";

// Construct SQL query based on selected barangay
$sql_deliveries = "SELECT o.*, t.transaction_number, u.first_name AS customer_first_name, u.last_name AS customer_last_name, u.phone_number, u.purok, b.barangay_name,
                          bf.fare_amount AS barangay_fare, o.payment_method
                          $selectDeliveryCols $selectFareFallback
                    FROM orders o
                    LEFT JOIN transactions t ON o.id = t.order_id
                    JOIN users u ON o.user_id = u.id
                    JOIN barangays b ON u.barangay_id = b.id
                    LEFT JOIN barangay_fares bf ON b.id = bf.barangay_id
                    WHERE o.rider_name = ? AND o.order_status = 'Ready to Ship'";

if ($selectedBarangayId) {
    $sql_deliveries .= " AND u.barangay_id = ?";
}

$sql_deliveries .= " ORDER BY o.order_date DESC";

$stmt_deliveries = $conn->prepare($sql_deliveries);
if ($stmt_deliveries === false) {
    echo "Error preparing deliveries statement: " . $conn->error;
    exit();
}

if ($selectedBarangayId) {
    $stmt_deliveries->bind_param("si", $riderFullName, $selectedBarangayId);
} else {
    $stmt_deliveries->bind_param("s", $riderFullName);
}

$stmt_deliveries->execute();
$result_deliveries = $stmt_deliveries->get_result();
$current_deliveries = [];

while ($row = $result_deliveries->fetch_assoc()) {
    $current_deliveries[] = $row;
}

// Function from cashier-COD-Delivery.php to count READY TO SHIP orders (modified for rider)
function getRiderReadyToShipOrderCount($conn, $riderFullName, $barangayId = null) {
    $sql = "SELECT COUNT(*) FROM orders o JOIN users u ON o.user_id = u.id WHERE o.rider_name = ? AND o.order_status = 'Ready to Ship'";
    if ($barangayId) {
        $sql .= " AND u.barangay_id = ?";
    }

    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        error_log("Prepare failed: " . $conn->error);
        return "Error";
    }

    if ($barangayId) {
        $stmt->bind_param("si", $riderFullName, $barangayId);
    } else {
        $stmt->bind_param("s", $riderFullName);
    }

    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return $count;
}

// Get the count of pending orders for the rider
$ReadyToShipOrderCount = getRiderReadyToShipOrderCount($conn, $riderFullName, $selectedBarangayId);

// Function to count READY TO SHIP orders for the rider in a specific barangay (reused)
function getRiderBarangayReadyToShipOrderCount2($conn, $riderFullName, $barangayId) {
    $sql = "SELECT COUNT(*) FROM orders o JOIN users u ON o.user_id = u.id WHERE o.rider_name = ? AND o.order_status = 'Ready to Ship' AND u.barangay_id = ?";
    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        error_log("Prepare failed: " . $conn->error);
        return "Error";
    }

    $stmt->bind_param("si", $riderFullName, $barangayId);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return $count;
}

// Function to count all READY TO SHIP orders for the rider (regardless of barangay) (reused)
function getRiderAllBarangayReadyToShipOrderCount2($conn, $riderFullName) {
  $sql = "SELECT COUNT(*) FROM orders WHERE rider_name = ? AND order_status = 'Ready to Ship'";
  $stmt = $conn->prepare($sql);

  if ($stmt === false) {
      error_log("Prepare failed: " . $conn->error);
      return "Error";
  }

  $stmt->bind_param("s", $riderFullName);
  $stmt->execute();
  $stmt->bind_result($count);
  $stmt->fetch();
  $stmt->close();
  return $count;
}

// Fetch all barangays from the barangays table
$sql_barangays = "SELECT id, barangay_name FROM barangays";
$result_barangays = $conn->query($sql_barangays);

$barangays = [];
if ($result_barangays->num_rows > 0) {
    while ($row = $result_barangays->fetch_assoc()) {
        $barangays[] = $row;
    }
}

$stmt->close();
$stmt_deliveries->close();

// ----- Recent Order History Logic -----

// Fetch recent order history for the rider, regardless of barangay
$sql_order_history = "SELECT o.id, o.order_date, o.order_status, u.first_name AS customer_first_name, u.last_name AS customer_last_name
                        FROM orders o
                        JOIN users u ON o.user_id = u.id
                        WHERE o.rider_name = ? AND o.order_status IN ('Ready to Ship', 'On-Ship', 'Completed', 'Returned')
                        ORDER BY o.order_date DESC
                        LIMIT 10"; // Limiting to the 10 most recent orders

$stmt_order_history = $conn->prepare($sql_order_history);
if ($stmt_order_history === false) {
    echo "Error preparing order history statement: " . $conn->error;
    exit();
}

$stmt_order_history->bind_param("s", $riderFullName);
$stmt_order_history->execute();
$result_order_history = $stmt_order_history->get_result();
$order_history = [];

while ($row = $result_order_history->fetch_assoc()) {
    $order_history[] = $row;
}

$stmt_order_history->close();

//$conn->close(); // will close later to use in pending order counter for barangay
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rider Dashboard</title>
    <link href="<?= $baseURL ?>css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= $baseURL ?>css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="stylesheet" href="<?= $baseURL ?>css/rider-styles.css"> <!-- New file for rider styles -->
    <!-- Favicon for better browser compatibility -->
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="/image/logo.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/image/logo.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/image/logo.png">
    <link rel="shortcut icon" href="/favicon.ico">
    <style>
        /* Add your custom styles here */
        .header {
            background-color: #530707; /* Set your desired background color */
            color: white;
            padding: 10px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .header-left {
            display: flex;
            align-items: center;
        }

        .profile-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            margin-right: 10px;
            object-fit: cover;
        }

        .powerhouse-logo {
            width: 80px;  /* Adjust width as needed */
            height: auto; /* Maintain aspect ratio */
        }

        .header-welcome {
            font-size: 1.2em;
            font-weight: bold;
        }

        .ready-to-ship-container {
            background-color: #f44336; /* Red background for visibility */
            color: white;
            padding: 10px;
            border-radius: 5px;
            font-size: 1em;
            text-align: center;
            margin-bottom: 15px;
        }

        .ready-to-ship-count {
            font-weight: bold;
            font-size: 1.2em;
        }

        /* Barangay Buttons */
        .barangay-buttons {
            display: flex;
            gap: 0.5rem;
            padding: 0.5rem;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            -ms-overflow-style: none;
            white-space: nowrap;
            touch-action: pan-x;  /* ADDED for touch scrolling */
            cursor: grab; /* Add this for a visual cue */
        }

        .barangay-buttons::-webkit-scrollbar {
            display: none;
        }

        .barangay-button {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 20px;
            background: white;
            color: #666;
            white-space: nowrap;
            flex-shrink: 0;
            transition: all 0.3s ease;
            position: relative;
            text-decoration: none; /* Remove underlines from links */
            display: inline-block; /* Make sure the anchor behaves like a button */
        }

        .barangay-button:hover {
            background: #e8f5e9;
            cursor: pointer;
        }

        .barangay-button .badge { /* Style the badge within the button */
            position: absolute;
            top: -5px;
            right: -5px;
            padding: 2px 5px;
            border-radius: 50%;
            background-color: red;
            color: white;
            font-size: 0.7em;
        }

        .badge {
            position: absolute;
            top: -5px;
            right: -5px;
            padding: 2px 5px;
            border-radius: 50%;
            background-color: red;
            color: white;
            font-size: 0.7em;
        }
        /* Table Styles */
        .delivery-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .delivery-table th, .delivery-table td {
            padding: 8px;
            border: 1px solid #ddd;
            text-align: left;
        }

        .delivery-table th {
            background-color: #f2f2f2;
        }

        .delivery-table tbody tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        .delivery-table .action-buttons {
            text-align: center;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.4);
        }

        .modal-content {
            background-color: #fefefe;
            margin: 5% auto; /* Reduced margin for larger modal */
            padding: 20px;
            border: 1px solid #888;
            width: 90%; /* Wider modal for more content */
            max-width: 800px; /* Maximum width to prevent excessively wide modals */
            overflow-y: auto; /* Enable vertical scrolling */
            max-height: 80vh; /* Set maximum height to 80% of viewport height */
        }

        .close {
            color: #aaa;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            position: absolute;
            top: 10px;
            right: 20px;
            text-decoration: none;
        }

        .close:hover,
        .close:focus {
            color: black;
        }

        /* Style the table inside the modal */
        .modal-table {
            width: 100%;
            border-collapse: collapse;
            color: black; /* Set font color to black */
            font-size: 14px; /* Adjust font size as needed */
        }

        .modal-table th, .modal-table td {
            border: 1px solid black; /* Black border */
            padding: 8px;
            text-align: left;
        }

        .modal-table th {
            background-color: #f2f2f2;
        }

        .modal-table td:nth-child(2) {
            font-weight: bold; /* Make the values bold */
        }

        .table-section-title {
            font-weight: bold;
            font-size: 1.1em;
            margin-top: 10px; /* Adjust spacing above section titles */
            margin-bottom: 5px;
        }

        /* General Styles */
        .ready-to-ship-container {
            background-color: #f44336;
            color: white;
            padding: 10px;
            border-radius: 5px;
            font-size: 1em;
            text-align: center;
            margin-bottom: 15px;
        }

        .ready-to-ship-count {
            font-weight: bold;
            font-size: 1.2em;
        }

        /* Invoice Styling */
        .invoice-header {
            text-align: center;
            margin-bottom: 10px; /* Reduced margin for more space */
        }

        .invoice-header img {
            max-width: 150px; /* Adjust logo size */
            height: auto;
            filter: grayscale(100%); /* Make logo black and white */
            margin-bottom: 5px;  /* Reduce spacing between logo and tagline */
        }

        .invoice-header p { /* Style the tagline */
            font-size: 12px; /* Adjust tagline size */
            margin-top: 0px; /* Remove spacing between logo and tagline */
            margin-bottom: 5px;
            font-style: italic; /* Add style if needed */
        }

         /* New styles for order history section */
        .order-history {
            margin-top: 20px;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background-color: #f9f9f9;
        }

        .order-history h2 {
            font-size: 1.3em;
            margin-bottom: 10px;
        }

        .order-history-table {
            width: 100%;
            border-collapse: collapse;
        }

        .order-history-table th, .order-history-table td {
            padding: 8px;
            border: 1px solid #ddd;
            text-align: left;
        }

        .order-history-table th {
            background-color: #f2f2f2;
            font-weight: bold;
        }

         .order-history-table .action-buttons {
            text-align: center; /* Center the buttons */
        }

         .order-history-table .update-button {
            background-color: #4CAF50; /* Green */
            border: none;
            color: white;
            padding: 5px 10px;
            text-align: center;
            text-decoration: none;
            display: inline-block;
            font-size: 14px;
            cursor: pointer;
            border-radius: 4px;
        }

        /* Scrollable Order History Styles */
        .order-history-scrollable {
            max-height: 300px; /* Height for exactly 5 rows (60px per row) */
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 8px;
            position: relative;
        }

        .order-history-scrollable::-webkit-scrollbar {
            width: 8px;
        }

        .order-history-scrollable::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        .order-history-scrollable::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 4px;
        }

        .order-history-scrollable::-webkit-scrollbar-thumb:hover {
            background: #555;
        }

        /* Scroll indicator */
        .scroll-indicator {
            position: absolute;
            bottom: 5px;
            right: 5px;
            background: rgba(0, 0, 0, 0.6);
            color: white;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 10px;
            z-index: 10;
        }

        /* Fade effect at bottom when scrollable */
        .order-history-scrollable::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 20px;
            background: linear-gradient(transparent, #f9f9f9);
            pointer-events: none;
            z-index: 5;
        }
        /* Update Success Modal Styles */
        #updateSuccessModal {
            display: none;
            position: fixed;
            z-index: 2; /* Above the delivery details modal */
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.4);
        }

        #updateSuccessModal .modal-content {
            background-color: #fefefe;
            margin: 20% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 500px;
            text-align: center;
        }

        #updateSuccessModal .close {
            position: absolute;
            top: 10px;
            right: 10px;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }

        /* Modern notification styles */
        .notification {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 30px;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.3);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
            z-index: 1000;
            display: none;
            opacity: 0;
            animation: fadeIn 0.6s ease-out forwards;
            max-width: 500px;
            min-width: 400px;
        }
        .notification .notif-close {
            position: absolute;
            top: 10px;
            right: 10px;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: transparent;
            border: none;
            color: rgba(255,255,255,0.9);
            cursor: pointer;
            line-height: 24px;
            text-align: center;
            font-weight: 700;
            font-size: 16px;
            padding: 0;
            transition: background .2s ease, color .2s ease, transform .12s ease;
        }
        .notification .notif-close:hover { background: rgba(255,255,255,0.15); color: #fff; }
        .notification .notif-close:active { transform: scale(0.96); }

        .notification-icon {
            width: 80px;
            height: 80px;
            margin-right: 20px;
            vertical-align: middle;
            display: inline-block;
        }

        .notification-content {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 20px;
        }

        #notificationText {
            flex: 1;
            text-align: left;
            font-size: 16px;
            font-weight: 600;
            line-height: 1.4;
        }

        .notification::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0.05) 100%);
            border-radius: 12px;
            pointer-events: none;
        }

        .notification-content {
            position: relative;
            z-index: 1;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateX(-50%) translateY(-20px) scale(0.9);
            }
            to {
                opacity: 1;
                transform: translateX(-50%) translateY(0) scale(1);
            }
        }

        @keyframes fadeOut {
            from {
                opacity: 1;
                transform: translateX(-50%) translateY(0) scale(1);
            }
            to {
                opacity: 0;
                transform: translateX(-50%) translateY(-20px) scale(0.9);
            }
        }

        /* Real-time banner pulse animation */
        .ready-to-ship-container.real-time-update {
            animation: pulse 1s ease-in-out;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

    </style>
    <style>
        /* Rider one-time loader */
        .rider-loader-overlay {
            position: fixed; inset: 0; z-index: 9999;
            background: radial-gradient(1200px 600px at 50% -20%, #073a53 0%, #031a2a 60%, #010b14 100%);
            display: none; align-items: center; justify-content: center;
            opacity: 1; transition: opacity 0.6s ease;
        }
        .rider-loader-overlay.show { display: flex; }
        .rider-loader-overlay.fading { opacity: 0; }
        /* Scoped loader container */
        .rider-loader { width: 240px; height: 160px; position: relative; filter: drop-shadow(0 12px 24px rgba(0,0,0,0.35)); display:flex; flex-direction:column; align-items:center; justify-content:center; }
        .rider-loader .text { margin-top: 16px; font-weight: 700; letter-spacing: 0.08em; color: #69d1ff; text-shadow: 0 2px 8px rgba(0,0,0,0.35); }

        /* Provided truck loader (lightly scoped) */
        .rider-loader .loader { width: fit-content; height: fit-content; display:flex; align-items:center; justify-content:center; }
        .rider-loader .truckWrapper { width: 200px; height: 100px; display:flex; flex-direction:column; position:relative; align-items:center; justify-content:flex-end; overflow-x:hidden; }
        .rider-loader .truckBody { width:130px; height: fit-content; margin-bottom:6px; animation: riderMotion 1s linear infinite; }
        @keyframes riderMotion { 0%{ transform: translateY(0) } 50%{ transform: translateY(3px) } 100%{ transform: translateY(0) } }
        .rider-loader .truckTires { width:130px; height: fit-content; display:flex; align-items:center; justify-content:space-between; padding:0 10px 0 15px; position:absolute; bottom:0; }
        .rider-loader .truckTires svg { width:24px; }
        .rider-loader .road { width:100%; height:1.5px; background-color:#282828; position:relative; bottom:0; align-self:flex-end; border-radius:3px; }
        .rider-loader .road::before { content:""; position:absolute; width:20px; height:100%; background-color:#282828; right:-50%; border-radius:3px; animation: riderRoad 1.4s linear infinite; border-left:10px solid white; }
        .rider-loader .road::after { content:""; position:absolute; width:10px; height:100%; background-color:#282828; right:-65%; border-radius:3px; animation: riderRoad 1.4s linear infinite; border-left:4px solid white; }
        .rider-loader .lampPost { position:absolute; bottom:0; right:-90%; height:90px; animation: riderRoad 1.4s linear infinite; }
        @keyframes riderRoad { 0%{ transform: translateX(0) } 100%{ transform: translateX(-350px) } }

        /* CJ logo reveal - above the truck with stylish glow */
        .rider-loader .logoWrap { position: relative; display:flex; align-items:center; justify-content:center; margin-bottom: 18px; }
        .rider-loader .logoWrap::before {
            content:""; position:absolute; width:120px; height:120px; border-radius:50%;
            background: radial-gradient(circle at 50% 50%, rgba(105,209,255,0.35), rgba(105,209,255,0) 60%);
            filter: blur(6px); opacity:0; transform: scale(0.8);
            transition: opacity 0.6s ease, transform 0.6s ease;
            animation: logoPulse 2s ease-in-out infinite;
        }
        @keyframes logoPulse { 0%,100% { box-shadow: 0 0 0 0 rgba(105,209,255,0.35) } 50% { box-shadow: 0 0 0 12px rgba(105,209,255,0.12) } }
        .rider-loader .shopLogo { width:94px; height:94px; border-radius:50%; background:#fff url('<?= $baseURL ?>image/logo.png') center/70% no-repeat; box-shadow: 0 10px 28px rgba(0,0,0,0.35), 0 0 0 6px rgba(255,255,255,0.08) inset; opacity:0; transform: scale(0.8); transition: transform 0.6s ease, opacity 0.6s ease; }
        .rider-loader.showLogo .shopLogo { opacity:1; transform: scale(1); }
        .rider-loader.showLogo .logoWrap::before { opacity:1; transform: scale(1); }
        .rider-loader.fading .shopLogo { transform: scale(1.2); }
    </style>
    <style>
        /* Center headings for sections */
        .current-deliveries h2,
        .order-history h2 {
            text-align: center;
        }

        /* Center empty-state text */
        .current-deliveries p,
        .order-history p {
            text-align: center;
            color: #222;
            font-weight: 600;
        }

        /* Improve visibility of any small labels inside filters (if added later) */
        .order-history .form-label { color: #111; font-weight: 700; }

        /* Notification bell (UIverse-inspired) */
        .rbell { width: fit-content; height: fit-content; background-color: rgb(58,58,58); border-radius: 7px; padding: 10px; display:flex; align-items:center; justify-content:center; position: relative; cursor: pointer; transition: .2s; }
        .rbell:hover { background-color: rgb(26,26,26); }
        .rbell:hover svg { color: #fff; }
        .rbell svg { color: rgba(255,255,255,.75); transform: scale(1.1); transition: .2s; }
        .rbell .point { position:absolute; bottom: 5px; left: 5px; width:6px; height:6px; background-color: rgb(0,255,0); border-radius: 25px; display:flex; align-items:center; justify-content:center; }
        .rbell .point::before { content:""; position:absolute; width:1px; height:1px; background-color: rgb(0,255,0); border-radius:25px; animation: rbell-ping 1s 0s infinite; }
        @keyframes rbell-ping { 0% { background-color: rgb(0,255,0); width:1px; height:1px; } 100% { background-color: rgba(0,255,0,0); width:30px; height:30px; } }
        .rbell .point.muted { background-color: #888; }
        .rbell .point.muted::before { background-color: #888; }

        /* Bell dropdown */
        #riderNotifDropdown { display:none; position:absolute; right: 16px; top: 62px; background:#fff; color:#333; min-width: 300px; max-width:360px; max-height: 380px; overflow-y:auto; border-radius:10px; box-shadow: 0 8px 24px rgba(0,0,0,.25); z-index: 1200; }
        #riderNotifDropdown .hdr { font-weight:700; padding:10px 12px; border-bottom:1px solid #eee; background:#f7f7f7; border-top-left-radius:10px; border-top-right-radius:10px; }
        #riderNotifDropdown ul { list-style: none; padding:0; margin:0; }
        #riderNotifDropdown li { padding:10px 12px; border-bottom:1px solid #f0f0f0; font-size:.95rem; }
        #riderNotifDropdown li time { display:block; color:#888; font-size:.8rem; margin-top:4px; }
        #riderNotifDropdown .empty { padding:14px; text-align:center; color:#888; }

        /* Attach bell to logo */
        .header-right-wrap { position: relative; display: inline-block; height: 100%; min-width: 48px; }
        .header-right-wrap .rbell { position: absolute; top: 50%; right: 8px; transform: translateY(-50%); padding: 8px; border-radius: 10px; }
    </style>
</head>
<body>
    <?php
    $__showRiderLoader = false;
    if (isset($_SESSION['show_rider_loader']) && $_SESSION['show_rider_loader'] === true) {
        $__showRiderLoader = true;
        unset($_SESSION['show_rider_loader']);
    } else if (isset($_SESSION['rider_login_time'])) {
        if (time() - (int)$_SESSION['rider_login_time'] <= 5) { $__showRiderLoader = true; }
        unset($_SESSION['rider_login_time']);
    }
    ?>
    <div id="riderLoader" class="rider-loader-overlay<?= $__showRiderLoader ? ' show' : '' ?>">
        <div id="riderCore" class="rider-loader">
            <div class="logoWrap"><div class="shopLogo"></div></div>
            <div class="loader">
              <div class="truckWrapper">
                <div class="truckBody">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 198 93" class="trucksvg">
                    <path stroke-width="3" stroke="#282828" fill="#F83D3D" d="M135 22.5H177.264C178.295 22.5 179.22 23.133 179.594 24.0939L192.33 56.8443C192.442 57.1332 192.5 57.4404 192.5 57.7504V89C192.5 90.3807 191.381 91.5 190 91.5H135C133.619 91.5 132.5 90.3807 132.5 89V25C132.5 23.6193 133.619 22.5 135 22.5Z"></path>
                    <path stroke-width="3" stroke="#282828" fill="#7D7C7C" d="M146 33.5H181.741C182.779 33.5 183.709 34.1415 184.078 35.112L190.538 52.112C191.16 53.748 189.951 55.5 188.201 55.5H146C144.619 55.5 143.5 54.3807 143.5 53V36C143.5 34.6193 144.619 33.5 146 33.5Z"></path>
                    <path stroke-width="2" stroke="#282828" fill="#282828" d="M150 65C150 65.39 149.763 65.8656 149.127 66.2893C148.499 66.7083 147.573 67 146.5 67C145.427 67 144.501 66.7083 143.873 66.2893C143.237 65.8656 143 65.39 143 65C143 64.61 143.237 64.1344 143.873 63.7107C144.501 63.2917 145.427 63 146.5 63C147.573 63 148.499 63.2917 149.127 63.7107C149.763 64.1344 150 64.61 150 65Z"></path>
                    <rect stroke-width="2" stroke="#282828" fill="#FFFCAB" rx="1" height="7" width="5" y="63" x="187"></rect>
                    <rect stroke-width="2" stroke="#282828" fill="#282828" rx="1" height="11" width="4" y="81" x="193"></rect>
                    <rect stroke-width="3" stroke="#282828" fill="#DFDFDF" rx="2.5" height="90" width="121" y="1.5" x="6.5"></rect>
                    <rect stroke-width="2" stroke="#282828" fill="#DFDFDF" rx="2" height="4" width="6" y="84" x="1"></rect>
                  </svg>
                </div>
                <div class="truckTires">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 30 30" class="tiresvg">
                    <circle stroke-width="3" stroke="#282828" fill="#282828" r="13.5" cy="15" cx="15"></circle>
                    <circle fill="#DFDFDF" r="7" cy="15" cx="15"></circle>
                  </svg>
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 30 30" class="tiresvg">
                    <circle stroke-width="3" stroke="#282828" fill="#282828" r="13.5" cy="15" cx="15"></circle>
                    <circle fill="#DFDFDF" r="7" cy="15" cx="15"></circle>
                  </svg>
                </div>
                <div class="road"></div>
                <svg xml:space="preserve" viewBox="0 0 453.459 453.459" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns="http://www.w3.org/2000/svg" id="Capa_1" version="1.1" fill="#000000" class="lampPost">
                  <path d="M252.882,0c-37.781,0-68.686,29.953-70.245,67.358h-6.917v8.954c-26.109,2.163-45.463,10.011-45.463,19.366h9.993 c-1.65,5.146-2.507,10.54-2.507,16.017c0,28.956,23.558,52.514,52.514,52.514c28.956,0,52.514-23.558,52.514-52.514 c0-5.478-0.856-10.872-2.506-16.017h9.992c0-9.354-19.352-17.204-45.463-19.366v-8.954h-6.149C200.189,38.779,223.924,16,252.882,16 c29.952,0,54.32,24.368,54.32,54.32c0,28.774-11.078,37.009-25.105,47.437c-17.444,12.968-37.216,27.667-37.216,78.884v113.914 h-0.797c-5.068,0-9.174,4.108-9.174,9.177c0,2.844,1.293,5.383,3.321,7.066c-3.432,27.933-26.851,95.744-8.226,115.459v11.202h45.75 v-11.202c18.625-19.715-4.794-87.527-8.227-115.459c2.029-1.683,3.322-4.223,3.322-7.066c0-5.068-4.107-9.177-9.176-9.177h-0.795 V196.641c0-43.174,14.942-54.283,30.762-66.043c14.793-10.997,31.559-23.461,31.559-60.277C323.202,31.545,291.656,0,252.882,0z M232.77,111.694c0,23.442-19.071,42.514-42.514,42.514c-23.442,0-42.514-19.072-42.514-42.514c0-5.531,1.078-10.957,3.141-16.017 h78.747C231.693,100.736,232.77,106.162,232.77,111.694z"></path>
                </svg>
              </div>
            </div>
            <div class="text">CJ Powerhouse • Rider</div>
        </div>
    </div>
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
            <div class="header-right-wrap">
                <div id="riderBell" class="rbell" title="Notifications">
                    <svg viewBox="0 0 24 24" fill="none" height="24" width="24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path d="M12 5.365V3m0 2.365a5.338 5.338 0 0 1 5.133 5.368v1.8c0 2.386 1.867 2.982 1.867 4.175 0 .593 0 1.292-.538 1.292H5.538C5 18 5 17.301 5 16.708c0-1.193 1.867-1.789 1.867-4.175v-1.8A5.338 5.338 0 0 1 12 5.365ZM8.733 18c.094.852.306 1.54.944 2.112a3.48 3.48 0 0 0 4.646 0c.638-.572 1.236-1.26 1.33-2.112h-6.92Z" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" stroke="currentColor"></path>
                    </svg>
                    <div class="point"></div>
                </div>
            </div>
        </header>
        <div id="riderNotifDropdown">
            <div class="hdr">Notifications</div>
            <ul id="riderNotifList"><li class="empty">No notifications yet</li></ul>
        </div>
        <main>
            <!-- Ready To Ship Container -->
            <?php if ($ReadyToShipOrderCount !== "Error"): ?>
                <div class="ready-to-ship-container">  <!--  Descriptive Class Name -->
                    <h2>Ready To Ship Orders</h2>
                    <p>There are <span class="ready-to-ship-count"><?= htmlspecialchars($ReadyToShipOrderCount) ?></span> orders ready to ship</p> <!-- Descriptive Class Name -->
                </div>
            <?php endif; ?>

             <!-- Barangay Buttons Start -->
             <div class="barangay-buttons">
                <?php
                    $allBarangayOrderCount = getRiderAllBarangayReadyToShipOrderCount2($conn, $riderFullName);
                 ?>
                 <a href="<?= $baseURL ?>Rider-Dashboard.php" class="barangay-button<?= !$selectedBarangayId ? ' active' : '' ?>">
                     All
                     <?php if ($allBarangayOrderCount > 0 && $allBarangayOrderCount !== "Error"): ?>
                         <span class="badge"><?= htmlspecialchars($allBarangayOrderCount) ?></span>
                     <?php endif; ?>
                 </a>
                <?php foreach ($barangays as $barangay): ?>
                    <?php
                        $barangayOrderCount = getRiderBarangayReadyToShipOrderCount2($conn, $riderFullName, $barangay['id']);
                    ?>
                    <a href="<?= $baseURL ?>Rider-Dashboard.php?barangay=<?= htmlspecialchars($barangay['id']) ?>" class="barangay-button<?= $selectedBarangayId == $barangay['id'] ? ' active' : '' ?>">
                        <?= htmlspecialchars($barangay['barangay_name']) ?>
                        <?php if ($barangayOrderCount > 0 && $barangayOrderCount !== "Error"): ?>
                            <span class="badge"><?= htmlspecialchars($barangayOrderCount) ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <!-- Barangay Buttons End -->

          <section class="current-deliveries">
              <h2>Ready To Ship</h2>
              <?php if (!empty($current_deliveries)): ?>
                  <table class="delivery-table">
                      <thead>
                          <tr>
                              <th>Order ID</th>
                              <th>Date and Time</th>
                              <th>Action</th>
                          </tr>
                      </thead>
                      <tbody>
                          <?php foreach ($current_deliveries as $delivery): ?>
                              <tr>
                                  <td><?= htmlspecialchars($delivery['id']) ?></td>
                                  <td><?= date('Y-m-d h:i A', strtotime($delivery['order_date'])) ?></td> <!-- Format date and time -->
                                  <td class="action-buttons">
                                      <button onclick="openModal(<?= htmlspecialchars(json_encode($delivery)) ?>)">View Details</button>
                                  </td>
                              </tr>
                          <?php endforeach; ?>
                      </tbody>
                  </table>
              <?php else: ?>
                  <p>No current deliveries assigned.</p>
              <?php endif; ?>
          </section>

            <!-- Recent Order History Section -->
            <section class="order-history">
                <h2>Recent Order History</h2>
                <?php if (!empty($order_history)): ?>
                    <div class="order-history-scrollable" id="orderHistoryScrollable">
                        <table class="order-history-table">
                            <thead>
                                <tr>
                                    <th>Order ID</th>
                                    <th>Date and Time</th>
                                    <th>Status</th>
                                    <th>Customer Name</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($order_history as $order): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($order['id']) ?></td>
                                        <td><?= date('Y-m-d h:i A', strtotime($order['order_date'])) ?></td>
                                        <td><?= htmlspecialchars($order['order_status']) ?></td>
                                        <td><?= htmlspecialchars($order['customer_first_name'] . ' ' . $order['customer_last_name']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if (count($order_history) > 5): ?>
                            <div class="scroll-indicator" id="scrollIndicator">Scroll for more</div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <p>No order history found for this rider.</p>
                <?php endif; ?>
            </section>

            <section class="support">
                <h2>Support</h2>
                <p>Need help? Contact support:</p>
                <p>Phone: 09568706652</p>
            </section>
        </main>

        <nav class="bottom-nav">
            <a href="<?= $baseURL ?>Rider-Dashboard.php" class="active">
                <span class="material-icons">home</span>
                <span>Home</span>
            </a>
            <a href="<?= $baseURL ?>Rider-Transaction.php">
                <span class="material-icons">history</span>
                <span>Delivery Status</span>
            </a>
            <a href="<?= $baseURL ?>Rider-Profile.php">
                <span class="material-icons">person</span>
                <span>Profile</span>
            </a>
        </nav>
    </div>

   <!-- The Modal -->
    <div id="deliveryModal" class="modal">
        <div class="modal-content">
          <div class="invoice-header">
              <img src="<?= $baseURL ?>uploads/Cjhouse.png" alt="Company Logo">
              <p>"Get Premium MotorCycle Accessories and Parts at affordable Price Visit CjPowerhouse Located @Sinayawan P-2 Fronting Barangay hall"</p>
          </div>
            <a class="close" onclick="closeModal()">×</a>

            <table class="modal-table">
              <tr>
                <th></th>
                <th></th>
             </tr>
              <tr>
                  <td colspan="2"><strong class="table-section-title">Customer Info</strong></td>
              </tr>
              <tr>
                <td>Transaction Number</td>
                <td id="transactionNumber"></td>
              </tr>
              <tr>
                <td>Order Date</td>
                <td id="orderDate"></td>
              </tr>
              <tr>
                <td>Customer Name</td>
                <td id="customerName"></td>
              </tr>
              <tr>
                <td>Contact Info</td>
                <td id="contactInfo"></td>
              </tr>
              <tr>
                <td>Shipping Address</td>
                <td id="shippingAddress"></td>
              </tr>
              <tr>
                <td>Home Description</td>
                <td id="homeDescription"></td>
              </tr>

              <tr>
                <td colspan="2"><strong class="table-section-title">Rider Info</strong></td>
              </tr>
              <tr>
                <td>Rider Contact</td>
                <td id="riderContact"></td>
              </tr>
              <tr>
                <td>Rider Name</td>
                <td id="riderName"></td>
              </tr>
               <tr>
                  <td>Motor Type</td>
                  <td id="riderMotorType"></td>
              </tr>
              <tr>
                 <td>Plate Number</td>
                 <td id="riderPlateNumber"></td>
              </tr>
              <tr>
                <td colspan="2"><strong class="table-section-title">Order Details</strong></td>
              </tr>
              <tr>
                <td>Total Weight</td>
                <td id="totalWeight"></td>
              </tr>
              <tr>
                <td>Total Price</td>
                <td id="totalPrice"></td>
              </tr>
              <tr>
                <td>Delivery Fee</td>
                <td id="deliveryFee"></td>
              </tr>
              <tr>
                <td>Total Amount</td>
                <td id="totalAmount"></td>
              </tr>

               <tr>
                   <td colspan="2">
                        <div id="onShipButtonContainer"></div>
                   </td>  <!-- Container for the button -->
               </tr>
          </table>
        </div>
    </div>
  <!-- Update success Modal -->
    <div class="modal" id="updateSuccessModal">
        <div class="modal-content">
            <span class="close" onclick="closeUpdateSuccessModal()">×</span>
            <p id="updateSuccessMessage"></p>
        </div>
    </div>

    <!-- Modern notification div -->
    <div id="orderNotification" class="notification">
        <div class="notification-content">
            <img src="<?= $baseURL ?>uploads/Receive_order.gif" alt="New Order" class="notification-icon">
            <span id="notificationText">New orders are ready to ship!</span>
        </div>
        <button id="notifCloseBtn" class="notif-close" aria-label="Close">×</button>
    </div>

   <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="<?= $baseURL ?>js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
        // Get the modal
        var modal = document.getElementById("deliveryModal");

        // Get the update success modal
        var updateSuccessModal = document.getElementById("updateSuccessModal");

        // Function to open the modal
        function openModal(delivery) {
            document.getElementById("transactionNumber").innerText = delivery.transaction_number;
            // format order date
            try {
                var d = new Date(delivery.order_date);
                var formatted = d.toLocaleString('en-US', {
                    month: 'long', day: 'numeric', year: 'numeric',
                    hour: 'numeric', minute: '2-digit', hour12: true
                });
                document.getElementById("orderDate").innerText = formatted;
            } catch (e) {
                document.getElementById("orderDate").innerText = delivery.order_date;
            }
            document.getElementById("customerName").innerText = delivery.customer_first_name + ' ' + delivery.customer_last_name;
            document.getElementById("contactInfo").innerText = '+(63)-' + delivery.phone_number;
            document.getElementById("shippingAddress").innerText = 'Purok: ' + (delivery.purok || 'N/A') + ', Brgy: ' + delivery.barangay_name + ', Valencia City, Bukidnon';
            document.getElementById("homeDescription").innerText = delivery.home_description || 'N/A';
            document.getElementById("riderContact").innerText = delivery.rider_contact;
            document.getElementById("riderName").innerText = delivery.rider_name;
            document.getElementById("riderMotorType").innerText = delivery.rider_motor_type || 'N/A';
            document.getElementById("riderPlateNumber").innerText = delivery.rider_plate_number || 'N/A';
            document.getElementById("totalWeight").innerText =  delivery.total_weight + " Kg";
            document.getElementById("totalPrice").innerText =  "₱" + delivery.total_price;
            document.getElementById("deliveryFee").innerText =  "₱" + (delivery.delivery_fee_effective || delivery.delivery_fee || 0);
            // Display total amount with "PAID" for GCash payments
            const totalAmount = delivery.total_with_delivery_effective || delivery.total_amount_with_delivery || delivery.total_price;
            const paymentMethod = delivery.payment_method;
            
            if (paymentMethod && paymentMethod.toUpperCase() === 'GCASH') {
                document.getElementById("totalAmount").innerText = "Total Amount: ₱" + totalAmount + " (PAID GCASH)";
            } else {
                document.getElementById("totalAmount").innerText = "Total Amount: ₱" + totalAmount;
            }

             document.getElementById("onShipButtonContainer").innerHTML = `
                <form action="update-onship-order.php" method="post" onsubmit="return onShipUpdate(this); return false">
                    <input type="hidden" name="order_id" value="${delivery.id}">
                    <input type="hidden" name="rider_name" value="<?= htmlspecialchars($riderFullName) ?>">
                    <input type="hidden" name="order_status" value="On-Ship">
                    <button type="submit" class="update-button">Update to On-Ship</button>
                </form>
            `;

            // Show the modal
            modal.style.display = "block";
        }

        // Function to close the modal
        function closeModal() {
            modal.style.display = "none";
        }
        function closeUpdateSuccessModal() {
            var modal = document.getElementById("updateSuccessModal");
            modal.style.display = "none";
             window.location.href = "Rider-Dashboard.php";
        }

         function onShipUpdate(form) {
            // AJAX request to update the order status
            fetch(form.action, {
                method: 'POST',
                body: new FormData(form)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show a success message in a modal
                    document.getElementById("updateSuccessMessage").innerText = data.message;
                    document.getElementById("updateSuccessModal").style.display = "block";
                    closeModal();
                } else {
                    // Display error message
                    alert("Error: " + data.message);
                }
            })
            .catch(error => {
                console.error("Error: ", error);
                alert("An error occurred while updating the order.");
            });

             return false;
        }

        // Close the modal if the user clicks outside of it
        window.onclick = function (event) {
            if (event.target == modal) {
                closeModal();
            }
               if (event.target == updateSuccessModal) {
                closeUpdateSuccessModal();
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            const container = document.querySelector('.barangay-buttons');

            let touchStartX = 0;
            let scrollLeft = 0;
            let isDragging = false;

            // Increase the drag speed factor for more responsive movement
            const DRAG_SPEED = 2;

            // Touch start event
            container.addEventListener('touchstart', (e) => {
                isDragging = true;
                touchStartX = e.touches[0].clientX - container.offsetLeft;
                scrollLeft = container.scrollLeft;
            });

            // Mouse down event for desktop
            container.addEventListener('mousedown', (e) => {
                isDragging = true;
                touchStartX = e.clientX - container.offsetLeft;
                scrollLeft = container.scrollLeft;
                container.classList.add('active'); // Add 'active' class on mousedown
            });

            // Touch move event
            container.addEventListener('touchmove', (e) => {
                if (!isDragging) return;
                e.preventDefault(); // Prevent text selection during scroll

                const touchX = e.touches[0].clientX - container.offsetLeft;
                // Multiply the distance by DRAG_SPEED
                const walk = (touchX - touchStartX) * DRAG_SPEED;
                container.scrollLeft = scrollLeft - walk;
            });

            // Mouse move event
            container.addEventListener('mousemove', (e) => {
                if (!isDragging) return;
                e.preventDefault();

                const touchX = e.clientX - container.offsetLeft;
                // Multiply the distance by DRAG_SPEED
                const walk = (touchX - touchStartX) * DRAG_SPEED;
                container.scrollLeft = scrollLeft - walk;
            });

            // Touch end and leave events
            const endScroll = () => {
                isDragging = false;
                container.classList.remove('active'); // Remove 'active' class on touchend and mouseup
            };

            container.addEventListener('touchend', endScroll);
            container.addEventListener('mouseleave', endScroll);
            container.addEventListener('mouseup', endScroll);

        });

        // Real-time updates using Server-Sent Events
        let eventSource = null;
        let isConnected = false;

        // Function to show notification
        function showNotification(message = 'New orders are ready to ship!') {
            const notification = document.getElementById('orderNotification');
            const notificationText = document.getElementById('notificationText');
            
            // Play notification sound
            const audio = new Audio('<?= $baseURL ?>uploads/Notify.mp3');
            audio.volume = 0.7; // Set volume to 70%
            audio.play().catch(error => {
                console.log('Audio playback failed:', error);
                // Audio will fail silently if user hasn't interacted with page yet
            });
            
            // Update the notification text
            notificationText.textContent = message;
            
            // Reset animation and show notification
            notification.style.animation = 'none';
            notification.style.display = 'block';
            notification.style.opacity = '0';
            
            // Trigger fade in animation
            setTimeout(() => {
                notification.style.animation = 'fadeIn 0.6s ease-out forwards';
            }, 10);
            
            // Hide notification after 4 seconds with fade out animation
            setTimeout(() => {
                notification.style.animation = 'fadeOut 0.6s ease-out forwards';
                setTimeout(() => {
                    notification.style.display = 'none';
                    notification.style.opacity = '0';
                }, 600);
            }, 4000);

            // Also push into bell dropdown
            try { addBellNotification(message); } catch (_) {}
        }

        // Function to update ready to ship count
        function updateReadyToShipCount(count) {
            const countElement = document.querySelector('.ready-to-ship-count');
            const container = document.querySelector('.ready-to-ship-container');
            if (countElement) {
                countElement.textContent = count;
            }
            if (container) {
                container.classList.add('real-time-update');
                setTimeout(() => {
                    container.classList.remove('real-time-update');
                }, 1000);
            }
        }

        // Function to update deliveries table
        function updateDeliveriesTable(deliveries) {
            console.log('updateDeliveriesTable called with:', deliveries);
            const tbody = document.querySelector('.delivery-table tbody');
            if (!tbody) {
                console.log('tbody not found!');
                return;
            }

            if (deliveries.length === 0) {
                tbody.innerHTML = '<tr><td colspan="3" style="text-align: center;">No current deliveries assigned.</td></tr>';
            } else {
                tbody.innerHTML = deliveries.map(delivery => `
                    <tr>
                        <td>${delivery.id}</td>
                        <td>${new Date(delivery.order_date).toLocaleString('en-US', {
                            year: 'numeric', month: '2-digit', day: '2-digit',
                            hour: 'numeric', minute: '2-digit', hour12: true
                        })}</td>
                        <td class="action-buttons">
                            <button onclick="openModal(${JSON.stringify(delivery).replace(/"/g, '&quot;')})">View Details</button>
                        </td>
                    </tr>
                `).join('');
            }
            console.log('Deliveries table updated successfully');
        }

        // Function to update barangay button counts
        function updateBarangayCounts() {
            // This would need to be implemented based on your specific requirements
            // For now, we'll just refresh the page when counts change
            location.reload();
        }

        // Function to connect to SSE
        function connectSSE() {
            if (eventSource) {
                eventSource.close();
            }

            const riderName = '<?= htmlspecialchars($riderFullName) ?>';
            const selectedBarangay = '<?= $selectedBarangayId ?: '' ?>';
            const url = `sse_rider_dashboard.php?rider_name=${encodeURIComponent(riderName)}${selectedBarangay ? '&barangay=' + selectedBarangay : ''}`;

            eventSource = new EventSource(url);

            eventSource.onopen = function(event) {
                console.log('SSE connection opened');
                isConnected = true;
            };

            eventSource.onmessage = function(event) {
                try {
                    const data = JSON.parse(event.data);
                    
                    switch(data.type) {
                        case 'connection_established':
                            console.log('SSE connection established for rider:', data.rider_name);
                            // Update with initial data
                            if (data.ready_to_ship_count !== undefined) {
                                updateReadyToShipCount(data.ready_to_ship_count);
                            }
                            if (data.deliveries !== undefined) {
                                updateDeliveriesTable(data.deliveries);
                            }
                            break;
                            
                        case 'ready_to_ship_update':
                            updateReadyToShipCount(data.count);
                            if (data.count > 0) {
                                const msg = `You have ${data.count} new orders ready to ship!`;
                                showNotification(msg);
                                addBellNotification(msg);
                            }
                            break;
                            
                        case 'deliveries_update':
                            console.log('Deliveries update received:', data.deliveries);
                            updateDeliveriesTable(data.deliveries);
                            break;
                            
                        case 'heartbeat':
                            console.log('SSE heartbeat received');
                            break;
                            
                        case 'error':
                            console.error('SSE error:', data.error);
                            showNotification('Connection error. Please refresh the page.');
                            break;
                    }
                } catch (e) {
                    console.error('Error parsing SSE data:', e);
                }
            };

            eventSource.onerror = function(event) {
                console.error('SSE connection error');
                isConnected = false;
                
                // Attempt to reconnect after 5 seconds
                setTimeout(() => {
                    if (!isConnected) {
                        console.log('Attempting to reconnect...');
                        connectSSE();
                    }
                }, 5000);
            };
        }

        // Function to disconnect SSE
        function disconnectSSE() {
            if (eventSource) {
                eventSource.close();
                eventSource = null;
                isConnected = false;
            }
        }

        // Initialize SSE connection when page loads
        document.addEventListener('DOMContentLoaded', function() {
            // One-time rider loader: show truck, reveal logo above, then fade out
            try {
                var overlay = document.getElementById('riderLoader');
                if (overlay && overlay.classList.contains('show')) {
                    var core = document.getElementById('riderCore');
                    // Reveal CJ logo after a short delay while truck animates
                    setTimeout(function(){ if (core) core.classList.add('showLogo'); }, 500);
                    // Then fade out overlay with zoom
                    setTimeout(function(){ if (core) core.classList.add('fading'); overlay.classList.add('fading'); setTimeout(function(){ overlay.classList.remove('show'); overlay.classList.remove('fading'); if (core) core.classList.remove('fading'); core.classList.remove('showLogo'); }, 700); }, 1400);
                }
            } catch(e) {}
            connectSSE();
            initializeOrderHistoryScroll();

            // Bell interactions
            try {
                const bell = document.getElementById('riderBell');
                const dropdown = document.getElementById('riderNotifDropdown');
                bell.addEventListener('click', function(e){
                    e.stopPropagation();
                    dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
                });
                document.addEventListener('click', function(){ dropdown.style.display = 'none'; });
            } catch(_) {}

            // Close banner button
            try {
                const closeBtn = document.getElementById('notifCloseBtn');
                const notification = document.getElementById('orderNotification');
                closeBtn.addEventListener('click', function(){
                    notification.style.animation = 'fadeOut 0.3s ease-out forwards';
                    setTimeout(() => { notification.style.display = 'none'; notification.style.opacity = '0'; }, 280);
                });
            } catch(_) {}
        });

        function addBellNotification(text) {
            const ul = document.getElementById('riderNotifList');
            if (!ul) return;
            const li = document.createElement('li');
            const timestamp = new Date().toLocaleString('en-US', { month:'short', day:'2-digit', hour:'numeric', minute:'2-digit' });
            li.innerHTML = `${text}<time>${timestamp}</time>`;
            const empty = ul.querySelector('.empty');
            if (empty) empty.remove();
            ul.prepend(li);
        }

        // Initialize order history scroll functionality
        function initializeOrderHistoryScroll() {
            const scrollableContainer = document.getElementById('orderHistoryScrollable');
            const scrollIndicator = document.getElementById('scrollIndicator');
            
            if (!scrollableContainer || !scrollIndicator) return;

            // Hide scroll indicator when user scrolls
            scrollableContainer.addEventListener('scroll', function() {
                const isAtBottom = this.scrollTop + this.clientHeight >= this.scrollHeight - 5;
                if (isAtBottom) {
                    scrollIndicator.style.opacity = '0';
                    setTimeout(() => {
                        scrollIndicator.style.display = 'none';
                    }, 300);
                } else {
                    scrollIndicator.style.display = 'block';
                    scrollIndicator.style.opacity = '1';
                }
            });

            // Show scroll indicator on hover
            scrollableContainer.addEventListener('mouseenter', function() {
                if (this.scrollHeight > this.clientHeight) {
                    scrollIndicator.style.display = 'block';
                    scrollIndicator.style.opacity = '1';
                }
            });

            // Hide scroll indicator when not hovering
            scrollableContainer.addEventListener('mouseleave', function() {
                const isAtBottom = this.scrollTop + this.clientHeight >= this.scrollHeight - 5;
                if (isAtBottom) {
                    scrollIndicator.style.opacity = '0';
                    setTimeout(() => {
                        scrollIndicator.style.display = 'none';
                    }, 300);
                }
            });
        }

        // Clean up on page unload
        window.addEventListener('beforeunload', function() {
            disconnectSSE();
        });
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
    <script src="js/script.js"></script>
</body>
</html>
<?php $conn->close(); ?>
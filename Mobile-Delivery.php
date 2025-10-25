<?php
session_start();
require 'dbconn.php'; // Ensure this file contains your database connection logic

// Dynamically determine the base URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$path = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');  // Get directory of the current script
$baseURL = $protocol . '://' . $host . $path . '/';

if (!isset($_SESSION['user_id'])) {
    header("Location: {$baseURL}signin.php"); // Redirect to login if user is not logged in
    exit();
}


$user_id = $_SESSION['user_id'];

// Fetch user data from the database, joining with the barangays table
$sql = "SELECT
            u.first_name,
            u.middle_name,
            u.last_name,
            u.email,
            u.purok,
            u.phone_number,
            b.barangay_name,
            u.barangay_id  -- Fetch barangay_id
        FROM
            users u
        INNER JOIN
            barangays b ON u.barangay_id = b.id
        WHERE
            u.id = ?";
$stmt = $conn->prepare($sql);

if ($stmt === false) {
    die("Prepare failed: " . $conn->error); // Handle the error appropriately
}

$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    die("User not found or multiple users with the same ID"); // Handle the error appropriately
}

$user = $result->fetch_assoc();

// Now you have the user's data in the $user array:
$fullname = trim($user['first_name'] . ' ' . (empty($user['middle_name']) ? '' : $user['middle_name'] . ' ') . $user['last_name']);
$email = $user['email'];
$selected_barangay_id = $user['barangay_id']; // Get existing barangay_id
$purok = $user['purok'];
$phone_number = $user['phone_number'];
$stmt->close();

// Detect where fare data is stored
$hasFareTable = false;
$barangaysHasFareColumn = false;
$barangaysHasDistanceColumn = false;

// Check if separate fare table exists
$checkFareTableSql = "SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'barangay_fares'";
$checkFareTable = $conn->query($checkFareTableSql);
if ($checkFareTable && ($row = $checkFareTable->fetch_assoc()) && (int)$row['cnt'] > 0) {
	$hasFareTable = true;
}

// Check if barangays table already contains fare/distance columns
$checkColumnsSql = "SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'barangays' AND COLUMN_NAME IN ('fare_amount', 'distance_km')";
$checkColumns = $conn->query($checkColumnsSql);
if ($checkColumns) {
	while ($col = $checkColumns->fetch_assoc()) {
		if ($col['COLUMN_NAME'] === 'fare_amount') { $barangaysHasFareColumn = true; }
		if ($col['COLUMN_NAME'] === 'distance_km') { $barangaysHasDistanceColumn = true; }
	}
}

// Build barangays query based on available schema - Include both local rider and staff fares
if ($hasFareTable) {
	// Prefer strict id join but fall back to name match to ensure complete display
	$sql_barangays = "SELECT 
					  b.id, 
					  b.barangay_name,
					  COALESCE(bf_id.fare_amount, bf_name.fare_amount, 0) AS fare_amount,
					  COALESCE(bf_id.staff_fare_amount, bf_name.staff_fare_amount, 0) AS staff_fare_amount,
					  COALESCE(bf_id.distance_km, bf_name.distance_km, 0) AS distance_km
					  FROM barangays b
					  LEFT JOIN barangay_fares bf_id ON bf_id.barangay_id = b.id
					  LEFT JOIN barangay_fares bf_name ON bf_name.barangay_name = b.barangay_name
					  ORDER BY b.barangay_name";
} else if ($barangaysHasFareColumn) {
	$distanceExpr = $barangaysHasDistanceColumn ? "COALESCE(b.distance_km, 0)" : "0";
	$sql_barangays = "SELECT b.id, b.barangay_name, 
					  COALESCE(b.fare_amount, 0) AS fare_amount, 
					  0 AS staff_fare_amount,
					  $distanceExpr AS distance_km
					  FROM barangays b
					  ORDER BY b.barangay_name";
} else {
	// No fare info available in DB; default to 0 to avoid fatal errors
	$sql_barangays = "SELECT b.id, b.barangay_name, 0 AS fare_amount, 0 AS staff_fare_amount, 0 AS distance_km FROM barangays b ORDER BY b.barangay_name";
}

$result_barangays = $conn->query($sql_barangays);

if ($result_barangays === false) {
    die("Query failed: " . $conn->error); // Handle the error appropriately
}

// Fetch cart items and calculate total and weight from the database
$total_amount = 0;
$total_weight = 0;
$sql_cart = "SELECT c.ProductID, c.Quantity, p.Price, p.Weight FROM cart c INNER JOIN products p ON c.ProductID = p.ProductID WHERE c.UserID = ?";
$stmt_cart = $conn->prepare($sql_cart);

if ($stmt_cart === false) {
    die("Prepare failed: " . $conn->error);
}

$stmt_cart->bind_param("i", $user_id);
$stmt_cart->execute();
$result_cart = $stmt_cart->get_result();

if ($result_cart->num_rows > 0) {
    while ($row = $result_cart->fetch_assoc()) {
        $product_id = $row['ProductID'];
        $quantity = $row['Quantity'];
        $price = $row['Price'];
        $weight = $row['Weight'];
        $total_amount += ($price * $quantity);
        $total_weight += ($weight * $quantity);
    }
}

$stmt_cart->close();

// Get initial delivery fee for selected barangay (default to local rider fare)
$initial_delivery_fee = 0;
$initial_staff_fare = 0;

if ($selected_barangay_id) {
	if ($hasFareTable) {
		// STRICT read from barangay_fares by barangay_id only
		$sql_fare = "SELECT 
					COALESCE(bf.fare_amount, 0) AS fare_amount,
					COALESCE(bf.staff_fare_amount, 0) AS staff_fare_amount
				  FROM barangay_fares bf
				  WHERE bf.barangay_id = ?
				  LIMIT 1";
		$stmt_fare = $conn->prepare($sql_fare);
		if ($stmt_fare) {
			$stmt_fare->bind_param("i", $selected_barangay_id);
			$stmt_fare->execute();
			$result_fare = $stmt_fare->get_result();
			if ($result_fare->num_rows > 0) {
				$fare_row = $result_fare->fetch_assoc();
				$initial_delivery_fee = (float)$fare_row['fare_amount'];
				$initial_staff_fare = (float)$fare_row['staff_fare_amount'];
			}
			$stmt_fare->close();
		}
	} else if ($barangaysHasFareColumn) {
		$sql_fare = "SELECT COALESCE(fare_amount, 0) AS fare_amount FROM barangays WHERE id = ?";
		$stmt_fare = $conn->prepare($sql_fare);
		if ($stmt_fare) {
			$stmt_fare->bind_param("i", $selected_barangay_id);
			$stmt_fare->execute();
			$result_fare = $stmt_fare->get_result();
			if ($result_fare->num_rows > 0) {
				$fare_row = $result_fare->fetch_assoc();
				$initial_delivery_fee = (float)$fare_row['fare_amount'];
				$initial_staff_fare = 0; // No staff fare in old system
			}
			$stmt_fare->close();
		}
	}
}

$local_delivery_available = ($total_weight >= 0.00 && $total_weight <= 14);
$staff_delivery_available = ($total_weight > 14);

// Auto-select default delivery method based on weight
$default_delivery_method = $local_delivery_available ? 'local' : ($staff_delivery_available ? 'staff' : 'pickup');
if ($default_delivery_method === 'staff') {
    // When staff is required, use staff fare as initial fee
    $initial_delivery_fee = $initial_staff_fare;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Details</title>
    <link rel="icon" type="image/png" href="<?= $baseURL ?>Image/logo.png">
    <link rel="stylesheet" href="<?= $baseURL ?>css/styles.css">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
        /* Your original CSS remains unchanged */
        .payment-options { display: flex; flex-direction: column; gap: 10px; }
        .payment-option { display: flex; align-items: center; padding: 15px; border: 1px solid #ccc; border-radius: 8px; cursor: pointer; transition: border-color 0.3s ease, box-shadow 0.3s ease; position: relative; }
        .payment-option input[type="radio"] { position: absolute; opacity: 0; width: 0; height: 0; }
        .payment-option .material-icons { font-size: 36px; margin-right: 15px; color: #007bff; }
        .payment-option .option-content { flex: 1; }
        .payment-option .option-content h4 { margin: 0; font-size: 1em; color: #333; }
        .payment-option .option-content p { margin: 0; font-size: 0.9em; color: #777; }
        .payment-option:hover { border-color: #007bff; }
        .payment-option.selected { border: 2px solid #0D6EFD; box-shadow: 0 4px 8px rgba(0, 123, 255, 0.2); }
        .pickup-delivery-option { border: 1px solid #ccc; padding: 10px; margin-bottom: 10px; border-radius: 5px; cursor: pointer; display: flex; align-items: center; box-sizing: border-box; transition: all 0.3s ease; }
        .pickup-delivery-option.disabled { opacity: 0.5; pointer-events: none; background-color: #eee; color: #999; }
        .pickup-delivery-option img { width: 50px; margin-right: 10px; }
        .pickup-delivery-option-content { flex: 1; }
        .pickup-delivery-option-content h4 { margin-top: 0; }
        .pickup-delivery-option.selected { border: 2px solid #007bff; }
        select { width: 100%; padding: 10px; margin-bottom: 10px; border: 1px solid #ccc; border-radius: 5px; box-sizing: border-box; font-size: 1em; font-family: sans-serif; -webkit-appearance: none; -moz-appearance: none; appearance: none; background-image: url('data:image/svg+xml;utf8,<svg fill="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M7 10l5 5 5-5z"/><path d="M0 0h24v24H0z" fill="none"/></svg>'); background-repeat: no-repeat; background-position-x: 95%; background-position-y: 50%; }
        /* Readonly-looking select (keeps value for scripts but user can't change) */
        .readonly-select { background-color: #f5f5f5; color: #666; border-color: #ddd; pointer-events: none; opacity: 1; background-image: none; }
        /* Readonly-looking input fields */
        .readonly-input { background-color: #f5f5f5; color: #666; border-color: #ddd; pointer-events: none; }
        .place-order-btn.disabled { opacity: 0.5; pointer-events: none; cursor: not-allowed; }
        .form-section label { display: block; margin-bottom: 5px; color: #333; font-weight: bold; }
        
        /* Disabled select styling */
        select:disabled {
            background-color: white;
            color: #333;
            cursor: not-allowed;
            opacity: 1;
            background-image: none; /* Remove the dropdown arrow */
        }
        
        /* Delivery fee and summary */
        .delivery-fee-info { background-color: #f8f9fa; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #007bff; }
        .total-price-section { background-color: #e8f5e8; padding: 20px; border-radius: 10px; margin: 20px 0; border: 2px solid #28a745; }
        .price-breakdown div { display: flex; justify-content: space-between; margin: 8px 0; padding: 5px 0; border-bottom: 1px solid #ddd; }
        .price-breakdown div:last-child { border-bottom: none; padding-top: 10px; margin-top: 10px; border-top: 2px solid #28a745; font-weight: bold; font-size: 1.1em; color: #28a745; }
        
        /* Free shipping banner styles */
        .free-shipping-banner {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: bold;
            font-size: 1.1em;
            box-shadow: 0 4px 8px rgba(40, 167, 69, 0.3);
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.02); }
            100% { transform: scale(1); }
        }
        
        .free-shipping-banner .banner-icon {
            font-size: 1.2em;
            margin-right: 8px;
        }
        
        .free-shipping-banner .banner-subtitle {
            font-size: 0.9em;
            margin-top: 5px;
            opacity: 0.9;
        }
        
        .free-shipping-note {
            text-align: center;
            margin-top: 15px;
            padding: 10px;
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            color: #856404;
            font-size: 0.9em;
            animation: fadeIn 0.5s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* COD Suspension Styles */
        .payment-option.suspended {
            opacity: 0.95;
            background-color: #fff5f5;
            border-color: #dc3545;
            border-width: 3px;
            cursor: not-allowed;
        }
        
        .cod-suspension-overlay {
            display: none; /* no full overlay; keep red glow only */
        }

        /* Fixed banner that appears above COD card */
        .cod-suspension-banner {
            position: absolute;
            left: 50%;
            bottom: 100%;
            transform: translateX(-50%);
            margin-bottom: 10px;
            width: 300px;
            max-width: 90vw;
            background-color: #ffffff;
            border: 2px solid #dc3545;
            border-radius: 8px;
            box-shadow: 0 6px 16px rgba(0,0,0,0.15);
            padding: 14px 16px;
            z-index: 20;
            display: none;
            text-align: center;
        }
        .cod-suspension-banner:after {
            content: "";
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            border-width: 8px;
            border-style: solid;
            border-color: #dc3545 transparent transparent transparent;
        }
        .cod-suspension-banner .banner-title {
            color: #dc3545;
            font-weight: 700;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
            text-transform: uppercase;
        }
        .cod-suspension-banner .banner-date {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            display: inline-block;
            padding: 4px 8px;
            color: #495057;
            font-weight: 600;
            margin: 6px 0 2px 0;
        }
        
        .suspension-content {
            text-align: center;
            padding: 15px;
            background-color: rgba(255, 255, 255, 0.95);
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }
        
        .suspension-content .material-icons {
            margin-bottom: 8px;
            font-size: 32px !important;
        }
        
        .suspension-content .suspension-title {
            font-size: 16px;
            font-weight: bold;
            color: #dc3545;
            margin: 8px 0;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .suspension-content .suspension-date {
            font-size: 14px;
            color: #495057;
            font-weight: 600;
            margin: 5px 0;
            background-color: #f8f9fa;
            padding: 5px 10px;
            border-radius: 4px;
            border: 1px solid #dee2e6;
        }
        
        /* Pulsing animation for suspended COD */
        .payment-option.suspended {
            animation: pulse-suspension 2s infinite;
        }
        
        @keyframes pulse-suspension {
            0% { 
                box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7);
                transform: scale(1);
            }
            50% { 
                box-shadow: 0 0 0 10px rgba(220, 53, 69, 0);
                transform: scale(1.02);
            }
            100% { 
                box-shadow: 0 0 0 0 rgba(220, 53, 69, 0);
                transform: scale(1);
            }
        }
        
        /* Suspension Modal Styles */
        .suspension-modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }
        
        .suspension-modal-content {
            background-color: white;
            margin: 15% auto;
            padding: 20px;
            border-radius: 10px;
            width: 90%;
            max-width: 400px;
            text-align: center;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
        }
        
        .suspension-modal .material-icons {
            font-size: 48px;
            color: #dc3545;
            margin-bottom: 15px;
        }
        
        .suspension-modal h3 {
            color: #dc3545;
            margin-bottom: 15px;
        }
        
        .suspension-modal p {
            color: #6c757d;
            margin-bottom: 20px;
        }
        
        .suspension-modal .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            margin: 0 5px;
        }
        .suspension-modal .actions { display:flex; justify-content:center; gap:10px; flex-wrap:wrap; margin-top:10px; }
        .suspension-modal .btn { min-width: 120px; }
        
        .suspension-modal .btn-primary {
            background-color: #007bff;
            color: white;
        }
        
        .suspension-modal .btn-secondary {
            background-color: #6c757d;
            color: white;
        }

        /* GCash Confirmation Modal - modern look with smooth transitions */
        .gcash-modal {
            position: fixed;
            inset: 0;
            z-index: 1001;
            background: rgba(0, 0, 0, 0.35);
            backdrop-filter: blur(2px);
            opacity: 0;
            visibility: hidden;
            transition: opacity 200ms ease, visibility 200ms ease;
            display: block; /* keep in DOM for transitions */
        }
        .gcash-modal.show { opacity: 1; visibility: visible; }
        .gcash-modal-content {
            background: linear-gradient(180deg, #ffffff 0%, #fafbff 100%);
            width: 92%;
            max-width: 440px;
            margin: 12% auto;
            padding: 22px 20px;
            border-radius: 14px;
            text-align: center;
            box-shadow: 0 18px 50px rgba(13, 110, 253, 0.18);
            transform: translateY(16px) scale(0.98);
            opacity: 0;
            transition: transform 260ms cubic-bezier(0.22, 1, 0.36, 1), opacity 260ms ease;
        }
        .gcash-modal.show .gcash-modal-content { transform: translateY(0) scale(1); opacity: 1; }
        .gcash-modal h3 { margin: 0 0 6px 0; color: #0D6EFD; letter-spacing: .3px; }
        .gcash-logo { margin: 8px 0 10px 0; display:inline-block; }
        .gcash-logo img { width: 140px; height: auto; object-fit: contain; filter: drop-shadow(0 2px 8px rgba(13,110,253,.15)); }
        .gcash-modal p { margin: 6px 0; color: #4a5568; }
        .gcash-modal .actions { margin-top: 16px; display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
        .gcash-modal .btn { padding: 10px 16px; border: none; border-radius: 10px; cursor: pointer; color: #ffffff; font-weight: 700; box-shadow: 0 6px 16px rgba(0,0,0,0.12); }
        .gcash-modal .btn-primary { background: linear-gradient(135deg, #0D6EFD, #5aa0ff); }
        .gcash-modal .btn-primary:hover { filter: brightness(1.05); }
        .gcash-modal .btn-secondary { background: #6c757d; }
        .gcash-modal .btn-secondary:hover { filter: brightness(1.05); }
        
        /* Modern Notification System */
        .notification-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 10000;
            max-width: 400px;
        }
        
        .notification {
            background: white;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12);
            margin-bottom: 12px;
            padding: 16px 20px;
            border-left: 4px solid #007bff;
            transform: translateX(100%);
            opacity: 0;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .notification.show {
            transform: translateX(0);
            opacity: 1;
        }
        
        .notification.success {
            border-left-color: #28a745;
            background: linear-gradient(135deg, #ffffff 0%, #f8fff9 100%);
        }
        
        .notification.error {
            border-left-color: #dc3545;
            background: linear-gradient(135deg, #ffffff 0%, #fff8f8 100%);
        }
        
        .notification.warning {
            border-left-color: #ffc107;
            background: linear-gradient(135deg, #ffffff 0%, #fffef8 100%);
        }
        
        .notification-header {
            display: flex;
            align-items: center;
            margin-bottom: 8px;
        }
        
        .notification-icon {
            width: 24px;
            height: 24px;
            margin-right: 12px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: bold;
            color: white;
        }
        
        .notification.success .notification-icon {
            background: #28a745;
        }
        
        .notification.error .notification-icon {
            background: #dc3545;
        }
        
        .notification.warning .notification-icon {
            background: #ffc107;
            color: #000;
        }
        
        .notification-title {
            font-weight: 600;
            color: #333;
            margin: 0;
            font-size: 16px;
        }
        
        .notification-message {
            color: #666;
            margin: 0;
            font-size: 14px;
            line-height: 1.4;
        }
        
        .notification-close {
            position: absolute;
            top: 8px;
            right: 8px;
            background: none;
            border: none;
            font-size: 18px;
            color: #999;
            cursor: pointer;
            padding: 4px;
            border-radius: 4px;
            transition: all 0.2s ease;
        }
        
        .notification-close:hover {
            background: #f5f5f5;
            color: #666;
        }
        
        .notification-progress {
            position: absolute;
            bottom: 0;
            left: 0;
            height: 3px;
            background: #007bff;
            border-radius: 0 0 12px 12px;
            transition: width linear;
        }
        
        .notification.success .notification-progress {
            background: #28a745;
        }
        
        .notification.error .notification-progress {
            background: #dc3545;
        }
        
        .notification.warning .notification-progress {
            background: #ffc107;
        }
        
        /* Tooltip for form validation */
        .tooltip {
            position: relative;
            display: inline-block;
        }
        
        .tooltip .tooltiptext {
            visibility: hidden;
            width: 200px;
            background-color: #dc3545;
            color: white;
            text-align: center;
            border-radius: 6px;
            padding: 8px 12px;
            position: absolute;
            z-index: 1;
            bottom: 125%;
            left: 50%;
            margin-left: -100px;
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 12px;
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
        border-color: #dc3545 !important;
        box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25) !important;
    }
    
    /* Appeal Success Modal */
    .appeal-success-modal {
        display: none;
        position: fixed;
        z-index: 1002;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.5);
        backdrop-filter: blur(2px);
    }
    
    .appeal-success-modal-content {
        background: linear-gradient(135deg, #ffffff 0%, #f8fff9 100%);
        margin: 15% auto;
        padding: 30px;
        border-radius: 15px;
        width: 90%;
        max-width: 400px;
        text-align: center;
        box-shadow: 0 20px 40px rgba(40, 167, 69, 0.2);
        border: 2px solid #28a745;
        animation: modalSlideIn 0.3s ease-out;
    }
    
    @keyframes modalSlideIn {
        from {
            transform: translateY(-50px) scale(0.9);
            opacity: 0;
        }
        to {
            transform: translateY(0) scale(1);
            opacity: 1;
        }
    }
    
    .appeal-success-modal .success-icon {
        font-size: 64px;
        color: #28a745;
        margin-bottom: 20px;
        animation: bounce 0.6s ease-in-out;
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
    
    .appeal-success-modal h3 {
        color: #28a745;
        margin-bottom: 15px;
        font-size: 24px;
        font-weight: bold;
    }
    
    .appeal-success-modal p {
        color: #666;
        margin-bottom: 25px;
        font-size: 16px;
        line-height: 1.5;
    }
    
    .appeal-success-modal .btn {
        padding: 12px 24px;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-size: 16px;
        font-weight: bold;
        margin: 0 5px;
        transition: all 0.3s ease;
    }
    
    .appeal-success-modal .btn-primary {
        background: linear-gradient(135deg, #28a745, #20c997);
        color: white;
        box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
    }
    
    .appeal-success-modal .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
    }
    
    /* Duplicate Appeal Confirmation Modal */
    .duplicate-appeal-modal {
        display: none;
        position: fixed;
        z-index: 1003;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.5);
        backdrop-filter: blur(2px);
    }
    
    .duplicate-appeal-modal-content {
        background: linear-gradient(135deg, #ffffff 0%, #fff8f0 100%);
        margin: 20% auto;
        padding: 30px;
        border-radius: 15px;
        width: 90%;
        max-width: 450px;
        text-align: center;
        box-shadow: 0 20px 40px rgba(255, 193, 7, 0.2);
        border: 2px solid #ffc107;
        animation: modalSlideIn 0.3s ease-out;
    }
    
    .duplicate-appeal-modal .warning-icon {
        font-size: 64px;
        color: #ffc107;
        margin-bottom: 20px;
        animation: pulse 1s infinite;
    }
    
    @keyframes pulse {
        0% { transform: scale(1); }
        50% { transform: scale(1.1); }
        100% { transform: scale(1); }
    }
    
    .duplicate-appeal-modal h3 {
        color: #ffc107;
        margin-bottom: 15px;
        font-size: 24px;
        font-weight: bold;
    }
    
    .duplicate-appeal-modal p {
        color: #666;
        margin-bottom: 25px;
        font-size: 16px;
        line-height: 1.5;
    }
    
    .duplicate-appeal-modal .btn {
        padding: 12px 24px;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-size: 16px;
        font-weight: bold;
        margin: 5px;
        transition: all 0.3s ease;
        min-width: 120px;
    }
    
    .duplicate-appeal-modal .btn-warning {
        background: linear-gradient(135deg, #ffc107, #ff8c00);
        color: white;
        box-shadow: 0 4px 15px rgba(255, 193, 7, 0.3);
    }
    
    .duplicate-appeal-modal .btn-warning:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(255, 193, 7, 0.4);
    }
    
    .duplicate-appeal-modal .btn-secondary {
        background: #6c757d;
        color: white;
    }
    
    .duplicate-appeal-modal .btn-secondary:hover {
        background: #5a6268;
        transform: translateY(-2px);
    }
    </style>
</head>
<body>
    <div class="app">
        <header class="cart-header" style="display: flex; align-items: center; justify-content: center; position: relative;">
            <a href="<?= $baseURL ?>Mobile-Cart.php" class="back-button" style="position: absolute; left: 0;"><span class="material-icons">arrow_back</span></a>
            <h1 style="margin: 0; color: #333;">Delivery Details</h1>
        </header>
        <div class="progress-bar">
            <div class="progress-step active">
                <span class="material-icons">shopping_cart</span>
                <span>Cart</span>
            </div>
            <div class="progress-line active"></div>
            <div class="progress-step current">
                <span class="material-icons">local_shipping</span>
                <span>Delivery</span>
            </div>
            <div class="progress-line"></div>
            <div class="progress-step">
                <span class="material-icons">check_circle</span>
                <span>Complete</span>
            </div>
        </div>

        <form id="deliveryForm" class="delivery-form" action="place_order.php" method="POST">
            <div class="form-section">
                <h3 style="text-align: center;">Logistic Shipping Address & Contact info</h3>
                
                <!-- THE ONLY CHANGE IS IN THE LINE BELOW -->
                <label for="Fullname">Name:</label>
                <input type="text" id="Fullname" name="fullname" value="<?php echo htmlspecialchars($fullname); ?>" required class="readonly-input" readonly>
                <!-- END OF CHANGE -->

                <label for="Email">Email:</label>
                <input type="text" id="Email" name="email" value="<?php echo htmlspecialchars($email); ?>" required class="readonly-input" readonly>
                <label for="Barangay">Barangay:</label>
                <select id="Barangay" name="barangay" class="readonly-select" aria-disabled="true" onchange="updateDeliveryFee()">
                    <option value="">Select Barangay</option>
                    <?php
                    if ($result_barangays->num_rows > 0) {
                        while ($barangay = $result_barangays->fetch_assoc()) {
                            $selected = ($barangay['id'] == $selected_barangay_id) ? 'selected' : '';
                            $fare_val = isset($barangay['fare_amount']) ? (float)$barangay['fare_amount'] : 0;
                            $dist_val = isset($barangay['distance_km']) ? (float)$barangay['distance_km'] : 0;
                            $fare_info = " - ₱" . number_format($fare_val, 2);
                            $distance_info = " (" . number_format($dist_val, 2) . " km)";
                            echo "<option value=\"" . htmlspecialchars($barangay['id']) . "\" 
                                  data-fare=\"" . htmlspecialchars($fare_val) . "\" 
                                  data-staff-fare=\"" . htmlspecialchars($barangay['staff_fare_amount']) . "\" 
                                  data-distance=\"" . htmlspecialchars($dist_val) . "\" 
                                  data-name=\"" . htmlspecialchars($barangay['barangay_name']) . "\" 
                                  $selected>" . htmlspecialchars($barangay['barangay_name']) . $fare_info . $distance_info . "</option>";
                        }
                    }
                    ?>
                </select>
                <label for="Purok">Purok:</label>
                <input type="text" id="Purok" name="purok" value="<?php echo htmlspecialchars($purok); ?>" required class="readonly-input" readonly>
                <label for="contactinfo">Phone Number:</label>
                <div style="display: flex; align-items: center; margin-bottom: 10px;">
                    <span style="padding: 10px; background-color: #eee; border: 1px solid #ccc; border-right: none; border-radius: 5px 0 0 5px;">+63</span>
                    <input type="tel" name="contactinfo" id="contactinfo" class="readonly-input" style="flex: 1; padding: 10px; border: 1px solid #ccc; border-radius: 0 5px 5px 0;" placeholder="9XXXXXXXXX" value="<?php echo htmlspecialchars($phone_number); ?>" required inputmode="numeric" pattern="\d{10}" maxlength="10" readonly>
                </div>
                <label for="home_description">Home Description:</label>
                <textarea id="home_description" name="home_description" rows="4" placeholder="Home Description (e.g., Near the basketball court, look for a blue gate)" style="width: 100%; padding: 10px; margin-bottom: 10px; border: 1px solid #ccc; border-radius: 5px; box-sizing: border-box; font-size: 1em; font-family: sans-serif;" required></textarea>

                <!-- Delivery Fee Display -->
                <div class="delivery-fee-info">
                    <h4 style="margin: 0 0 10px 0; color: #007bff;">Delivery Information</h4>
                    <div class="fee-details">
                        <p style="margin: 5px 0;"><strong>Selected Barangay:</strong> <span id="selectedBarangayName"><?php echo htmlspecialchars($user['barangay_name']); ?></span></p>
                        <p style="margin: 5px 0;"><strong>Distance:</strong> <span id="selectedDistance">N/A</span></p>
                        <p style="margin: 5px 0;"><strong>Local Rider Fee:</strong> ₱<span id="localRiderFee"><?php echo number_format($initial_delivery_fee, 2); ?></span></p>
                        <p style="margin: 5px 0;"><strong>Staff Delivery Fee:</strong> ₱<span id="staffDeliveryFee"><?php echo number_format($initial_staff_fare, 2); ?></span></p>
                        <p style="margin: 5px 0; font-size: 0.9em; color: #666;"><em>Select delivery method below to see final fee</em></p>
                    </div>
                </div>
            </div>
            
            <div class="form-section">
                <h3>Payment Method</h3>
                <div class="payment-options">
                    <label class="payment-option" data-payment="gcash">
                        <input type="radio" name="payment" value="gcash">
                        <span class="material-icons">account_balance_wallet</span>
                        <div class="option-content">
                            <h4>GCash</h4>
                            <p>Pay with your GCash wallet</p>
                        </div>
                    </label>
                    <label class="payment-option selected" data-payment="cod" id="codOption">
                        <input type="radio" name="payment" value="cod" checked>
                        <span class="material-icons">payments</span>
                        <div class="option-content">
                            <h4>Cash on Delivery</h4>
                            <p>Pay when you receive</p>
                        </div>
                        <!-- Anchored COD Suspension Banner (hidden by default) -->
                        <div class="cod-suspension-banner" id="codSuspensionBanner">
                            <div class="banner-title">COD Suspended</div>
                            <div class="banner-date" id="suspensionMessage"></div>
                            <div style="font-size: 12px; color: #6c757d; margin-top: 4px;">Please use GCash instead</div>
                        </div>
                    </label>
                </div>
            </div>

            <div class="form-section">
                <h3>Pick Up & Delivery Options</h3>
                 <div class="pickup-delivery-option" onclick="selectOption('pickup')" id="pickupOption" style="cursor: pointer;">
                    <img src="<?= $baseURL ?>uploads/smartparcel.gif" alt="Pick Up Order Logo">
                    <div class="pickup-delivery-option-content"><h4>Pick Up Order</h4><p>Pick up your order as soon as it is ready</p></div>
                </div>
                <div class="pickup-delivery-option <?php echo $local_delivery_available ? '' : 'disabled'; ?>" onclick="selectOption('local')" id="localOption" style="cursor: pointer;">
                    <img src="<?= $baseURL ?>uploads/motorcycle-riding.gif" alt="Local Rider Logo">
                    <div class="pickup-delivery-option-content"><h4>Local Rider Delivery</h4><p>Delivered by our local riders (0.00kg - 14kg)</p></div>
                </div>
                <div class="pickup-delivery-option <?php echo $staff_delivery_available ? '' : 'disabled'; ?>" onclick="selectOption('staff')" id="staffOption" style="cursor: pointer;">
                    <img src="<?= $baseURL ?>uploads/delivery.gif" alt="Staff Delivery Logo">
                    <div class="pickup-delivery-option-content"><h4>Staff Delivery</h4><p>Delivered by our staff (Over 14kg)</p></div>
                </div>
                <input type="hidden" id="delivery_method" name="delivery_method" value="<?php echo htmlspecialchars($default_delivery_method); ?>">
                
                <!-- Debug info -->
                <div style="margin-top: 10px; padding: 10px; background: #f8f9fa; border-radius: 5px; font-size: 12px; color: #666;">
                    <strong>Debug:</strong> Click any delivery option above to enable the Place Order button
                </div>
            </div>

            <!-- Total Price Display -->
            <div class="total-price-section">
                <h3 style="margin: 0 0 15px 0; color: #28a745; text-align: center;">Order Summary</h3>
                
                <!-- Free Shipping Banner Removed -->
                
                <div class="price-breakdown">
                    <div>
                        <span>Subtotal (Products):</span>
                        <span>₱<span id="subtotal"><?php echo number_format($total_amount, 2); ?></span></span>
                    </div>
                    <div>
                        <span>Delivery Fee:</span>
                        <span id="deliveryFeeDisplay">
                            ₱<span id="displayDeliveryFee"><?php echo number_format($initial_delivery_fee, 2); ?></span>
                        </span>
                    </div>
                    <div>
                        <span>Total Amount:</span>
                        <span>₱<span id="totalAmount"><?php echo number_format($total_amount + $initial_delivery_fee, 2); ?></span></span>
                    </div>
                </div>
                
                <!-- Free Shipping Note Removed -->
            </div>

            <input type="hidden" name="total_price" value="<?php echo htmlspecialchars($total_amount); ?>">
            <input type="hidden" name="total_weight" value="<?php echo htmlspecialchars($total_weight); ?>">
            <input type="hidden" name="delivery_fee" id="deliveryFeeInput" value="<?php echo htmlspecialchars($initial_delivery_fee); ?>">
            <input type="hidden" name="total_amount_with_delivery" id="totalAmountInput" value="<?php echo htmlspecialchars($total_amount + $initial_delivery_fee); ?>">

            <button type="submit" class="place-order-btn" id="placeOrderBtn">
                <span class="material-icons">lock_outline</span>
                Place Order
                 <span class="tooltiptext">Please select a delivery option!</span>
            </button>
        </form>
    </div>
    
    <!-- Modern Notification Container -->
    <div id="notificationContainer" class="notification-container"></div>
    
    <!-- Appeal Success Modal -->
    <div id="appealSuccessModal" class="appeal-success-modal">
        <div class="appeal-success-modal-content">
            <div class="success-icon">✓</div>
            <h3>Appeal Submitted Successfully!</h3>
            <p>Your appeal has been submitted and we will review it. We may lift your COD suspension earlier based on your explanation.</p>
            <button class="btn btn-primary" onclick="closeAppealSuccessModal()">Continue Shopping</button>
        </div>
    </div>
    
    <!-- Duplicate Appeal Confirmation Modal -->
    <div id="duplicateAppealModal" class="duplicate-appeal-modal">
        <div class="duplicate-appeal-modal-content">
            <div class="warning-icon">⚠</div>
            <h3>Appeal Already Submitted</h3>
            <p>You have already submitted an appeal for your COD suspension. Would you like to explain again?</p>
            <p><strong>If you continue, your previous appeal will be replaced with your current explanation.</strong></p>
            <div>
                <button class="btn btn-warning" onclick="confirmDuplicateAppeal()">Yes, Replace Previous Appeal</button>
                <button class="btn btn-secondary" onclick="closeDuplicateAppealModal()">Cancel</button>
            </div>
        </div>
    </div>
    
    <!-- GCash Confirmation Modal -->
    <div id="gcashConfirmModal" class="gcash-modal" aria-hidden="true">
      <div class="gcash-modal-content">
        <h3>Confirm GCash Payment</h3>
        <div class="gcash-logo" aria-hidden="true">
          <img src="<?= $baseURL ?>uploads/GCash_logo.png" alt="GCash Logo">
        </div>
        <p>Please double-check your order before proceeding.</p>
        <p><strong>This shop does not offer refunds.</strong></p>
        <p>Are you sure you want to proceed with GCash payment?</p>
        <div class="actions">
          <button type="button" id="proceedGCashBtn" class="btn btn-primary">Proceed with GCash</button>
          <button type="button" id="backToCartBtn" class="btn btn-secondary">Go Back to Cart</button>
        </div>
      </div>
    </div>
<script>
// Modern Notification System
function showNotification(type, title, message, duration = 5000) {
  console.log('showNotification called:', { type, title, message, duration }); // Debug log
  
  const container = document.getElementById('notificationContainer');
  if (!container) {
    console.error('Notification container not found!');
    return;
  }
  
  const notification = document.createElement('div');
  notification.className = `notification ${type}`;
  
  const icon = type === 'success' ? '✓' : type === 'error' ? '✕' : '⚠';
  
  notification.innerHTML = `
    <div class="notification-header">
      <div class="notification-icon">${icon}</div>
      <h4 class="notification-title">${title}</h4>
    </div>
    <p class="notification-message">${message}</p>
    <button class="notification-close" onclick="closeNotification(this)">&times;</button>
    <div class="notification-progress" style="width: 100%;"></div>
  `;
  
  container.appendChild(notification);
  console.log('Notification added to container'); // Debug log
  
  // Trigger animation
  setTimeout(() => {
    notification.classList.add('show');
    console.log('Notification show class added'); // Debug log
  }, 100);
  
  // Auto-remove after duration
  if (duration > 0) {
    // Animate progress bar
    const progressBar = notification.querySelector('.notification-progress');
    progressBar.style.transition = `width ${duration}ms linear`;
    progressBar.style.width = '0%';
    
    setTimeout(() => {
      closeNotification(notification.querySelector('.notification-close'));
    }, duration);
  }
}

function closeNotification(closeBtn) {
  const notification = closeBtn.closest('.notification');
  if (notification) {
    notification.classList.remove('show');
    setTimeout(() => notification.remove(), 300);
  }
}

// Tooltip system for form validation
function showTooltip(element, message) {
  // Remove existing tooltip
  const existingTooltip = element.querySelector('.tooltiptext');
  if (existingTooltip) {
    existingTooltip.remove();
  }
  
  // Add tooltip class to element
  element.classList.add('tooltip');
  
  // Create tooltip
  const tooltip = document.createElement('span');
  tooltip.className = 'tooltiptext';
  tooltip.textContent = message;
  element.appendChild(tooltip);
  
  // Show tooltip
  element.classList.add('show');
  
  // Hide tooltip after 3 seconds
  setTimeout(() => {
    element.classList.remove('show');
    setTimeout(() => {
      if (tooltip.parentNode) {
        tooltip.remove();
      }
    }, 300);
  }, 3000);
}

// Test notification system
function testNotification() {
  showNotification('success', 'Test Notification', 'This is a test notification to verify the system is working!', 3000);
}

// Appeal Success Modal Functions
function showAppealSuccessModal() {
  const modal = document.getElementById('appealSuccessModal');
  if (modal) {
    modal.style.display = 'block';
    console.log('Appeal success modal shown'); // Debug log
  }
}

function closeAppealSuccessModal() {
  const modal = document.getElementById('appealSuccessModal');
  if (modal) {
    modal.style.display = 'none';
    console.log('Appeal success modal closed'); // Debug log
  }
}

// Duplicate Appeal Modal Functions
function showDuplicateAppealModal() {
  const modal = document.getElementById('duplicateAppealModal');
  if (modal) {
    modal.style.display = 'block';
    console.log('Duplicate appeal modal shown'); // Debug log
  }
}

function closeDuplicateAppealModal() {
  const modal = document.getElementById('duplicateAppealModal');
  if (modal) {
    modal.style.display = 'none';
    console.log('Duplicate appeal modal closed'); // Debug log
  }
}

function confirmDuplicateAppeal() {
  closeDuplicateAppealModal();
  // Submit appeal with force_replace parameter
  submitCODAppeal(true);
}

// Strengthen validation for contact number and button state
function isValidPhMobile(num) {
  return /^\d{10}$/.test(num); // 10 digits without +63
}

document.addEventListener('DOMContentLoaded', function() {
  const form = document.getElementById('deliveryForm');
  const placeOrderBtn = document.getElementById('placeOrderBtn');
  const paymentOptions = document.querySelectorAll('.payment-option');
  const contactInput = document.getElementById('contactinfo');
  const homeDescInput = document.getElementById('home_description');
  // Initialize delivery fee and totals on load
  if (typeof updateDeliveryFee === 'function') {
    const deliveryMethodInput = document.getElementById('delivery_method');
    const method = (deliveryMethodInput && deliveryMethodInput.value) || '';
    const localOptionEl = document.getElementById('localOption');
    const staffOptionEl = document.getElementById('staffOption');
    const pickupOptionEl = document.getElementById('pickupOption');
    
    if (method === 'staff') {
      if (staffOptionEl) staffOptionEl.classList.add('selected');
      if (localOptionEl) localOptionEl.classList.remove('selected');
      if (pickupOptionEl) pickupOptionEl.classList.remove('selected');
      showHomeDescriptionField();
    } else if (method === 'local') {
      if (localOptionEl) localOptionEl.classList.add('selected');
      if (staffOptionEl) staffOptionEl.classList.remove('selected');
      if (pickupOptionEl) pickupOptionEl.classList.remove('selected');
      showHomeDescriptionField();
    } else if (method === 'pickup') {
      if (pickupOptionEl) pickupOptionEl.classList.add('selected');
      if (localOptionEl) localOptionEl.classList.remove('selected');
      if (staffOptionEl) staffOptionEl.classList.remove('selected');
      updatePaymentMethodForPickup();
      hideHomeDescriptionField();
    }
    
    updateDeliveryFee();
    // Ensure the Place Order button state reflects the default selection on load
    if (typeof updateButtonState === 'function') {
      updateButtonState();
    }
  }

  function validateContact() {
    const ok = isValidPhMobile(contactInput.value.trim());
    contactInput.setCustomValidity(ok ? '' : 'Enter a valid 10-digit mobile number');
    return ok;
  }

  contactInput.addEventListener('input', validateContact);

  paymentOptions.forEach(option => {
    option.addEventListener('click', function() {
      paymentOptions.forEach(opt => opt.classList.remove('selected'));
      this.classList.add('selected');
      this.querySelector('input[type="radio"]').checked = true;
    });
  });

  form.addEventListener('submit', function(e) {
    const paymentMethod = form.querySelector('input[name="payment"]:checked').value;

    // Always validate contact number
    if (!validateContact()) {
      e.preventDefault();
      showTooltip(contactInput, 'Please enter a valid 10-digit mobile number (e.g., 9XXXXXXXXX)');
      contactInput.classList.add('form-error');
      setTimeout(() => contactInput.classList.remove('form-error'), 3000);
      return;
    }

    // Validate home description (must not be empty for delivery orders only)
    const deliveryMethod = document.getElementById('delivery_method').value;
    if (deliveryMethod !== 'pickup' && (!homeDescInput || homeDescInput.value.trim() === '')) {
      e.preventDefault();
      showTooltip(homeDescInput, 'Please provide your Home Description so the rider can find your address');
      homeDescInput.classList.add('form-error');
      setTimeout(() => homeDescInput.classList.remove('form-error'), 3000);
      if (homeDescInput) { homeDescInput.focus(); }
      return;
    }

    // Check if COD is selected and user is suspended
    if (paymentMethod.toLowerCase() === 'cod') {
      // Check suspension status before submitting
      fetch('check_cod_suspension.php')
        .then(response => response.json())
        .then(data => {
          if (data.success && data.suspended) {
            e.preventDefault();
            showSuspensionModal(data.suspended_until_formatted, data.message);
            return;
          } else {
            // Not suspended, proceed with normal form submission
            return;
          }
        })
        .catch(error => {
          console.error('Error checking suspension:', error);
          // Continue with form submission if check fails
        });
    }

    if (paymentMethod.toLowerCase() === 'gcash') {
      e.preventDefault();
      openGCashConfirmModal();
    }
  });
});

function selectOption(option) {
  document.querySelectorAll('.pickup-delivery-option').forEach(el => el.classList.remove('selected'));
  
  // Find the clicked element and add selected class
  const clickedElement = document.querySelector(`[onclick="selectOption('${option}')"]`);
  if (clickedElement) {
    clickedElement.classList.add('selected');
  }
  
  document.getElementById('delivery_method').value = option;

  // Update payment method and form fields based on delivery option
  if (option === 'pickup') {
    // For pickup orders, change payment method to "Pay when pick up"
    updatePaymentMethodForPickup();
    
    // Hide home description field for pickup orders
    hideHomeDescriptionField();
    
    // Pickup has no delivery fee
    const subtotal = parseFloat((document.getElementById('subtotal')?.textContent || '0').replace(/,/g, '')) || 0;
    const deliveryFeeEl = document.getElementById('deliveryFee');
    if (deliveryFeeEl) {
      deliveryFeeEl.textContent = '0.00';
    }
    updateDeliveryFeeDisplay(0, subtotal);
  } else {
    // For delivery options, restore normal payment options
    restoreNormalPaymentOptions();
    
    // Show home description field for delivery orders
    showHomeDescriptionField();
    
    // Use the barangay fare for delivery
    updateDeliveryFee();
  }

  updateButtonState();
  // Ensure label updates to the chosen delivery method's fare
  if (typeof updateDeliveryFee === 'function') {
    updateDeliveryFee();
  }
}

function updatePaymentMethodForPickup() {
  const paymentOptions = document.querySelector('.payment-options');
  
  // Store original payment options if not already stored
  if (!window.originalPaymentOptions) {
    window.originalPaymentOptions = paymentOptions.innerHTML;
  }
  
  // Replace with pickup payment option
  paymentOptions.innerHTML = `
    <label class="payment-option selected" data-payment="pickup">
      <input type="radio" name="payment" value="pickup" checked>
      <span class="material-icons">store</span>
      <div class="option-content">
        <h4>Pay when pick up</h4>
        <p>Pay when you collect your order</p>
      </div>
    </label>
  `;
}

function restoreNormalPaymentOptions() {
  const paymentOptions = document.querySelector('.payment-options');
  
  // Restore original payment options
  if (window.originalPaymentOptions) {
    paymentOptions.innerHTML = window.originalPaymentOptions;
    
    // Re-add event listeners to restored payment options
    const restoredOptions = paymentOptions.querySelectorAll('.payment-option');
    restoredOptions.forEach(option => {
      option.addEventListener('click', function() {
        restoredOptions.forEach(opt => opt.classList.remove('selected'));
        this.classList.add('selected');
        this.querySelector('input[type="radio"]').checked = true;
      });
    });
    
    // Set COD as default selected
    const codOption = paymentOptions.querySelector('[data-payment="cod"]');
    if (codOption) {
      codOption.classList.add('selected');
      codOption.querySelector('input[type="radio"]').checked = true;
    }
  }
}

function hideHomeDescriptionField() {
  const homeDescLabel = document.querySelector('label[for="home_description"]');
  const homeDescField = document.getElementById('home_description');
  
  if (homeDescLabel) {
    homeDescLabel.style.display = 'none';
  }
  if (homeDescField) {
    homeDescField.style.display = 'none';
    // Remove required attribute for pickup orders
    homeDescField.removeAttribute('required');
    // Clear the field value
    homeDescField.value = 'Pickup Order - No home description needed';
  }
}

function showHomeDescriptionField() {
  const homeDescLabel = document.querySelector('label[for="home_description"]');
  const homeDescField = document.getElementById('home_description');
  
  if (homeDescLabel) {
    homeDescLabel.style.display = 'block';
  }
  if (homeDescField) {
    homeDescField.style.display = 'block';
    // Add required attribute back for delivery orders
    homeDescField.setAttribute('required', 'required');
    // Clear the placeholder value if it was set
    if (homeDescField.value === 'Pickup Order - No home description needed') {
      homeDescField.value = '';
    }
  }
}

function updateButtonState() {
  const deliveryMethod = document.getElementById('delivery_method').value;
  const placeOrderBtn = document.getElementById('placeOrderBtn');
  
  console.log('Delivery method selected:', deliveryMethod); // Debug log
  
  placeOrderBtn.disabled = (deliveryMethod === "");
  
  if (placeOrderBtn.disabled) {
    placeOrderBtn.classList.add('disabled');
    placeOrderBtn.innerHTML = '<span class="material-icons">lock_outline</span> Place Order <span class="tooltiptext">Please select a delivery option!</span>';
  } else {
    placeOrderBtn.classList.remove('disabled');
    placeOrderBtn.innerHTML = '<span class="material-icons">shopping_cart</span> Place Order';
  }
  
  console.log('Button disabled:', placeOrderBtn.disabled); // Debug log
}

function updateDeliveryFee() {
  const barangaySelect = document.getElementById('Barangay');
  const selectedOption = barangaySelect.options[barangaySelect.selectedIndex];
  const deliveryMethod = document.getElementById('delivery_method').value;

  if (selectedOption && selectedOption.value) {
    const localFare = parseFloat(selectedOption.getAttribute('data-fare')) || 0;
    const staffFare = parseFloat(selectedOption.getAttribute('data-staff-fare')) || 0;
    const distance = parseFloat(selectedOption.getAttribute('data-distance')) || 0;
    const barangayName = selectedOption.getAttribute('data-name') || '';

    // Determine which fare to use based on delivery method
    let fare = localFare; // Default to local rider fare
    if (deliveryMethod === 'staff') {
      fare = staffFare;
    } else if (deliveryMethod === 'local') {
      fare = localFare;
    } else if (deliveryMethod === 'pickup') {
      fare = 0;
    }

    // Update the selected option label so it reflects the active delivery method's fare
    try {
      const activeFare = fare;
      const label = `${barangayName} - ₱${activeFare.toFixed(2)} (${distance.toFixed(2)} km)`;
      selectedOption.text = label;
    } catch (e) {
      // no-op if text update fails
    }

    // Update delivery fee display
    const selectedBarangayNameEl = document.getElementById('selectedBarangayName');
    const selectedDistanceEl = document.getElementById('selectedDistance');
    const localRiderFeeEl = document.getElementById('localRiderFee');
    const staffDeliveryFeeEl = document.getElementById('staffDeliveryFee');
    
    if (selectedBarangayNameEl) selectedBarangayNameEl.textContent = barangayName;
    if (selectedDistanceEl) selectedDistanceEl.textContent = distance > 0 ? (distance.toFixed(2) + ' km') : 'N/A';
    if (localRiderFeeEl) localRiderFeeEl.textContent = localFare.toFixed(2);
    if (staffDeliveryFeeEl) staffDeliveryFeeEl.textContent = staffFare.toFixed(2);
    
    // Update the main delivery fee display (used for calculations)
    const deliveryFeeEl = document.getElementById('deliveryFee');
    if (deliveryFeeEl) {
      deliveryFeeEl.textContent = fare.toFixed(2);
    }

    // Calculate and update total amount (no free shipping logic)
    const subtotal = parseFloat((document.getElementById('subtotal')?.textContent || '0').replace(/,/g, '')) || 0;
    const finalDeliveryFee = fare;
    const totalAmount = subtotal + finalDeliveryFee;

    updateDeliveryFeeDisplay(finalDeliveryFee, subtotal);
  } else {
    // Reset values if no barangay selected
    const selectedBarangayNameEl = document.getElementById('selectedBarangayName');
    const selectedDistanceEl = document.getElementById('selectedDistance');
    const localRiderFeeEl = document.getElementById('localRiderFee');
    const staffDeliveryFeeEl = document.getElementById('staffDeliveryFee');
    
    if (selectedBarangayNameEl) selectedBarangayNameEl.textContent = 'N/A';
    if (selectedDistanceEl) selectedDistanceEl.textContent = 'N/A';
    if (localRiderFeeEl) localRiderFeeEl.textContent = '0.00';
    if (staffDeliveryFeeEl) staffDeliveryFeeEl.textContent = '0.00';
    
    const deliveryFeeEl = document.getElementById('deliveryFee');
    if (deliveryFeeEl) {
      deliveryFeeEl.textContent = '0.00';
    }

    const subtotal = parseFloat((document.getElementById('subtotal')?.textContent || '0').replace(/,/g, '')) || 0;
    updateDeliveryFeeDisplay(0, subtotal);
  }
}

function updateDeliveryFeeDisplay(deliveryFee, subtotal) {
  const subtotalAmount = subtotal || parseFloat((document.getElementById('subtotal')?.textContent || '0').replace(/,/g, '')) || 0;
  const finalDeliveryFee = deliveryFee;
  const totalAmount = subtotalAmount + finalDeliveryFee;

  // Update delivery fee display
  const deliveryFeeDisplay = document.getElementById('deliveryFeeDisplay');
  deliveryFeeDisplay.innerHTML = `₱<span id="displayDeliveryFee">${finalDeliveryFee.toFixed(2)}</span>`;

  // Update hidden inputs
  document.getElementById('deliveryFeeInput').value = finalDeliveryFee;
  document.getElementById('totalAmount').textContent = totalAmount.toFixed(2);
  document.getElementById('totalAmountInput').value = totalAmount.toFixed(2);
}

// COD Suspension Check and UI Management
document.addEventListener('DOMContentLoaded', function() {
  checkCODSuspension();
});

function checkCODSuspension() {
  fetch('check_cod_suspension.php')
    .then(response => response.json())
    .then(data => {
      if (data.success && data.suspended) {
        // User is suspended - show suspension UI
        showCODSuspension(data.suspended_until_formatted, data.message);
      }
    })
    .catch(error => {
      console.error('Error checking COD suspension:', error);
    });
}

function showCODSuspension(suspendedUntil, message) {
  const codOption = document.getElementById('codOption');
  const codRadio = codOption.querySelector('input[type="radio"]');
  const suspensionBanner = document.getElementById('codSuspensionBanner');
  const suspensionMessage = document.getElementById('suspensionMessage');
  
  // Update suspension message with more prominent format
  const dateObj = new Date(suspendedUntil);
  const formattedDate = dateObj.toLocaleDateString('en-US', {
    weekday: 'long',
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  });
  suspensionMessage.innerHTML = `<strong>Until:</strong><br>${formattedDate}`;
  
  // Show red glow only; banner will be toggled on hover/click
  
  // Mark COD option visually suspended and prevent selection
  codOption.classList.add('suspended');
  codOption.classList.remove('selected');
  codRadio.checked = false;
  
  // Select GCash instead
  const gcashOption = document.querySelector('input[value="gcash"]');
  if (gcashOption) {
    gcashOption.checked = true;
    gcashOption.closest('.payment-option').classList.add('selected');
  }
  
  // Hover/click to show anchored banner
  const showBanner = () => { suspensionBanner.style.display = 'block'; };
  const hideBanner = () => { suspensionBanner.style.display = 'none'; };
  codOption.addEventListener('mouseenter', showBanner);
  codOption.addEventListener('mouseleave', hideBanner);
  codOption.addEventListener('click', function(e) {
    e.preventDefault();
    showBanner();
    // Also show modal for clearer guidance on click
    showSuspensionModal(suspendedUntil, message);
  });
}

function showSuspensionModal(suspendedUntil, message) {
  // Create modal if it doesn't exist
  let modal = document.getElementById('suspensionModal');
  if (!modal) {
    modal = document.createElement('div');
    modal.id = 'suspensionModal';
    modal.className = 'suspension-modal';
    modal.innerHTML = `
      <div class="suspension-modal-content">
        <span class="material-icons">block</span>
        <h3>COD Payment Suspended</h3>
        <p>Your Cash on Delivery payment option is currently suspended until ${suspendedUntil}.</p>
        <p>Please use GCash for your payment method.</p>
        <div>
          <button class="btn btn-primary" onclick="useGCashInstead()">Use GCash</button>
          <button class="btn btn-secondary" onclick="closeSuspensionModal()">Cancel</button>
        </div>
      </div>
    `;
    document.body.appendChild(modal);
  }
  
  // Show modal
  modal.style.display = 'block';
  // Prefill any messaging
  const appealInput = document.getElementById('appealTextarea');
  if (appealInput) {
    appealInput.value = '';
  }
}

function closeSuspensionModal() {
  const modal = document.getElementById('suspensionModal');
  if (modal) {
    modal.style.display = 'none';
  }
}

function useGCashInstead() {
  // Switch to GCash payment method
  const gcashOption = document.querySelector('input[value="gcash"]');
  if (gcashOption) {
    gcashOption.checked = true;
    gcashOption.closest('.payment-option').classList.add('selected');
    
    // Remove selected class from COD option
    const codOption = document.getElementById('codOption');
    if (codOption) {
      codOption.classList.remove('selected');
    }
  }
  
  closeSuspensionModal();
}

// Submit COD appeal from modal
function submitCODAppeal(forceReplace = false) {
  const textarea = document.getElementById('appealTextarea');
  const message = (textarea && textarea.value.trim()) || '';
  if (message.length === 0) {
    showTooltip(textarea, 'Please write a short explanation for your appeal');
    textarea.classList.add('form-error');
    setTimeout(() => textarea.classList.remove('form-error'), 3000);
    return;
  }
  
  // Show loading state
  const submitBtn = document.querySelector('button[onclick="submitCODAppeal()"]');
  const originalText = submitBtn.innerHTML;
  submitBtn.innerHTML = 'Submitting...';
  submitBtn.disabled = true;
  
  const params = new URLSearchParams();
  params.append('appeal', message);
  if (forceReplace) {
    params.append('force_replace', '1');
  }
  
  console.log('Submitting appeal:', message, 'Force replace:', forceReplace); // Debug log
  
  fetch('submit_cod_appeal.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: params.toString()
  })
  .then(response => {
    console.log('Response status:', response.status); // Debug log
    return response.json();
  })
  .then(data => {
    console.log('Response data:', data); // Debug log
    if (data.success) {
      closeSuspensionModal();
      showAppealSuccessModal();
    } else if (data.has_existing_appeal) {
      // Show duplicate appeal confirmation modal
      showDuplicateAppealModal();
    } else {
      showNotification('error', 'Submission Failed', data.message || 'Failed to submit appeal.', 4000);
    }
  })
  .catch(error => {
    console.error('Appeal submission error:', error); // Debug log
    showNotification('error', 'Network Error', 'Network error while submitting appeal.', 4000);
  })
  .finally(() => {
    // Reset button state
    submitBtn.innerHTML = originalText;
    submitBtn.disabled = false;
  });
}

// Close modal when clicking outside
window.addEventListener('click', function(event) {
  const modal = document.getElementById('suspensionModal');
  if (event.target === modal) {
    closeSuspensionModal();
  }
});

// Manual button enable function for debugging
function enableButtonManually() {
  const placeOrderBtn = document.getElementById('placeOrderBtn');
  const deliveryMethodInput = document.getElementById('delivery_method');
  
  // Set delivery method to pickup if none selected
  if (!deliveryMethodInput.value) {
    deliveryMethodInput.value = 'pickup';
  }
  
  // Enable button
  placeOrderBtn.disabled = false;
  placeOrderBtn.classList.remove('disabled');
  placeOrderBtn.innerHTML = '<span class="material-icons">shopping_cart</span> Place Order';
  
  console.log('Button manually enabled');
}

// Simple function to force enable order placement
function forceEnableOrder() {
  // Set delivery method
  document.getElementById('delivery_method').value = 'pickup';
  
  // Enable button
  const btn = document.getElementById('placeOrderBtn');
  btn.disabled = false;
  btn.classList.remove('disabled');
  btn.innerHTML = '<span class="material-icons">shopping_cart</span> Place Order';
  
  // Submit form
  setTimeout(() => {
    document.getElementById('deliveryForm').submit();
  }, 500);
  
  console.log('Order form force-enabled and submitted');
}

// Add click event listeners to delivery options with better error handling
document.addEventListener('DOMContentLoaded', function() {
  const deliveryOptions = document.querySelectorAll('.pickup-delivery-option');
  
  deliveryOptions.forEach(option => {
    option.addEventListener('click', function(e) {
      console.log('Delivery option clicked:', this.id);
      
      // Remove selected class from all options
      deliveryOptions.forEach(opt => opt.classList.remove('selected'));
      
      // Add selected class to clicked option
      this.classList.add('selected');
      
      // Get the option type from onclick attribute
      const onclickAttr = this.getAttribute('onclick');
      const optionType = onclickAttr.match(/selectOption\('(\w+)'\)/)?.[1];
      
      if (optionType) {
        console.log('Setting delivery method to:', optionType);
        document.getElementById('delivery_method').value = optionType;
        updateButtonState();
      }
    });
  });
});

// GCash Confirmation Modal Logic
function openGCashConfirmModal() {
  const modal = document.getElementById('gcashConfirmModal');
  if (modal) { modal.classList.add('show'); modal.setAttribute('aria-hidden', 'false'); }
}

function closeGCashConfirmModal() {
  const modal = document.getElementById('gcashConfirmModal');
  if (modal) { modal.classList.remove('show'); modal.setAttribute('aria-hidden', 'true'); }
}

document.addEventListener('DOMContentLoaded', function() {
  const proceedBtn = document.getElementById('proceedGCashBtn');
  const backBtn = document.getElementById('backToCartBtn');
  const form = document.getElementById('deliveryForm');
  const placeOrderBtn = document.getElementById('placeOrderBtn');

  function createGCashPayment() {
    placeOrderBtn.disabled = true;
    placeOrderBtn.innerHTML = 'Creating secure payment...';

    const formData = new FormData(form);
    fetch('create_paymongo_payment.php', { method:'POST', body: formData })
      .then(res => res.json())
      .then(data => {
        if (data.success && data.checkout_url) {
          window.location.href = data.checkout_url;
        } else {
          showNotification('error', 'Payment Error', data.message || 'Unable to create payment.', 4000);
          placeOrderBtn.disabled = false;
          placeOrderBtn.innerHTML = '<span class="material-icons">lock_outline</span> Place Order';
        }
      })
      .catch((error) => {
        showNotification('error', 'Connection Error', 'A connection error occurred. Please try again.', 4000);
        placeOrderBtn.disabled = false;
        placeOrderBtn.innerHTML = '<span class="material-icons">lock_outline</span> Place Order';
      })
      .finally(() => { closeGCashConfirmModal(); });
  }

  if (proceedBtn) {
    proceedBtn.addEventListener('click', function() {
      createGCashPayment();
    });
  }
  if (backBtn) {
    backBtn.addEventListener('click', function() {
      window.location.href = 'Mobile-Cart.php';
    });
  }
  // Close modal when clicking outside content
  const modal = document.getElementById('gcashConfirmModal');
  if (modal) {
    modal.addEventListener('click', function(e) {
      if (e.target === modal) { closeGCashConfirmModal(); }
    });
  }
});
</script>

<!-- COD Suspension Modal -->
<div id="suspensionModal" class="suspension-modal">
  <div class="suspension-modal-content">
    <span class="material-icons">block</span>
    <h3>COD Payment Suspended</h3>
    <p id="modalSuspensionMessage">Your Cash on Delivery payment option is currently suspended.</p>
    <p>Please use GCash for your payment method.</p>
    <div style="text-align:left; margin:10px 0;">
      <label for="appealTextarea" style="display:block; font-weight:bold; color:#dc3545;">Appeal (optional)</label>
      <textarea id="appealTextarea" rows="4" style="width:100%; box-sizing:border-box; border:1px solid #ccc; border-radius:6px; padding:8px; transition: all 0.3s ease;" placeholder="Explain briefly why you couldn't receive the delivery on time (e.g., not available, provided wrong address, missed calls, emergency, etc.)"></textarea>
      <small style="color:#6c757d;">We'll review your appeal and may lift the suspension earlier.</small>
    </div>
    <div class="actions">
      <button class="btn btn-primary" onclick="submitCODAppeal()">Submit Appeal</button>
      <button class="btn btn-secondary" onclick="useGCashInstead()">Use GCash</button>
      <button class="btn btn-secondary" onclick="closeSuspensionModal()">Cancel</button>
    </div>
  </div>
</div>

</body>
</html>
<?php
$conn->close();
?>
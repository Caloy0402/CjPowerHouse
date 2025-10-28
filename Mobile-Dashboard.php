<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start(); // Start the session
}
require_once 'dbconn.php';



// Update session activity
require_once 'update_session_activity.php';

// Dynamically determine the base URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$path = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');  // Get directory of the current script
$baseURL = $protocol . '://' . $host . $path . '/';

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    // Redirect to login page or handle unauthorized access
    header("Location: {$baseURL}signin.php");  // Corrected path
    exit();
}

// Security check: Ensure only customers can access this page
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Customer') {
    // If user is staff, redirect to appropriate staff dashboard
    if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['Admin', 'Cashier', 'Rider', 'Mechanic'])) {
        switch ($_SESSION['role']) {
            case 'Admin':
                header("Location: Admin-Dashboard.php");
                break;
            case 'Cashier':
                header("Location: Cashier-Dashboard.php");
                break;
            case 'Rider':
                header("Location: Rider-Dashboard.php");
                break;
            case 'Mechanic':
                header("Location: Mechanic-Dashboard.php");
                break;
        }
        exit();
    }
    // If no valid role, redirect to appropriate login
    header("Location: {$baseURL}signin.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch recent order items with stock information
$sql_recent_items = "SELECT DISTINCT p.ProductID, p.ProductName, p.Price, p.ImagePath, p.Quantity
                     FROM orders o
                     JOIN order_items oi ON o.id = oi.order_id
                     JOIN products p ON oi.product_id = p.ProductID
                     WHERE o.user_id = ?
                     ORDER BY o.order_date DESC
                     LIMIT 10"; // Adjust limit as needed

$stmt_recent_items = $conn->prepare($sql_recent_items);
$stmt_recent_items->bind_param("i", $user_id);
$stmt_recent_items->execute();
$result_recent_items = $stmt_recent_items->get_result();

$recent_order_items = [];
while ($row = $result_recent_items->fetch_assoc()) {
    $recent_order_items[] = $row;
}
$stmt_recent_items->close();

// Fetch user data from the database
$sql = "SELECT u.*, b.barangay_name
        FROM users u
        LEFT JOIN barangays b ON u.barangay_id = b.id
        WHERE u.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

// Fetch products from the database
$sql = "SELECT ProductID, ProductName, Quantity, Price, category, ImagePath From products";
$result = $conn->query($sql);

$products = [];
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $product_id = $row['ProductID'];
        // Fetch ratings for this product
        $rating_sql = "SELECT rating FROM product_feedback WHERE product_id = ?";
        $rating_stmt = $conn->prepare($rating_sql);
        $rating_stmt->bind_param("i", $product_id);
        $rating_stmt->execute();
        $rating_result = $rating_stmt->get_result();
        $rating_sum = 0;
        $rating_count = 0;
        while ($rating_row = $rating_result->fetch_assoc()) {
            $rating_sum += (int)$rating_row['rating'];
            $rating_count++;
        }
        $average_rating = $rating_count ? round($rating_sum / $rating_count, 1) : 0;
        $row['average_rating'] = $average_rating;
        $row['rating_count'] = $rating_count;
        $products[] = $row;
        $rating_stmt->close();
    }
}

$targetDir = "uploads/profile_images/"; // Define the uploads directory for profile images

// Ensure the uploads directory exists
if (!is_dir($targetDir)) {
    mkdir($targetDir, 0777, true); // Create directory with full permissions
}

// Check if the form was submitted and the file input exists
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['profileImage'])) {
    if ($_FILES['profileImage']['error'] == 0) {
        $fileName = basename($_FILES['profileImage']['name']);
        $targetFile = $targetDir . $fileName;

        // Check if the file is an image
        $imageFileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));
        $allowedTypes = ['jpg', 'jpeg', 'png', 'gif'];
        if (in_array($imageFileType, $allowedTypes)) {
            // Move the uploaded file to the target directory
            if (move_uploaded_file($_FILES['profileImage']['tmp_name'], $targetFile)) {
                // Save the path to the database
                $imagePath = $targetFile;
                $updateSql = "UPDATE users SET ImagePath = ? WHERE id = ?";
                $updateStmt = $conn->prepare($updateSql);
                $updateStmt->bind_param("si", $imagePath, $user_id);
                $updateStmt->execute();
                echo "<script>alert('Profile image uploaded successfully.');</script>";
            } else {
                echo "<script>alert('Failed to move uploaded file.');</script>";
            }
        } else {
            echo "<script>alert('Invalid file type. Only JPG, JPEG, PNG, and GIF are allowed.');</script>";
        }
    } else {
        echo "<script>alert('Error uploading file.');</script>";
    }
}

// Fetch all barangays for the dropdown
$barangays = [];
$sql_barangays = "SELECT id, barangay_name FROM barangays";
$result_barangays = $conn->query($sql_barangays);
if ($result_barangays) {
    while ($row = $result_barangays->fetch_assoc()) {
        $barangays[] = $row;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CJ PowerHouse - Shop</title>
    <!-- Favicon for better browser compatibility -->
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="/image/logo.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/image/logo.png">
    <link rel="shortcut icon" href="/favicon.ico">
    <link rel="manifest" href="<?= $baseURL ?>manifest.json">
    <meta name="theme-color" content="#007bff">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="CJ PowerHouse">
    <link rel="apple-touch-icon" sizes="180x180" href="/image/logo.png">
    <link rel="stylesheet" href="<?= $baseURL ?>css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" integrity="sha512-9usAa10IRO0HhonpyAIVpjrylPvoDwiPUiKdWk5t3PyolY1cOd4DSE0Ga+ri4AuTroPR5aQvXU9xC6qOPnzFeg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
    <style>
        .header-profile-image {
            width: 40px; /* Adjust size as needed */
            height: 40px;
            border-radius: 50% !important; /* Force circular shape */
            object-fit: cover !important; /* Ensure image fills the circle */
            overflow: hidden; /* Clip image to circle */
        }
        
        /* Profile Avatar Change Photo Styling */
        .profile-avatar {
            position: relative;
            cursor: pointer;
            transition: transform 0.3s ease;
        }
        
        .profile-avatar:hover {
            transform: scale(1.02);
        }
        
        .profile-avatar-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.6);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
            border-radius: 10px;
            color: white;
            font-size: 0.9rem;
            text-align: center;
        }
        
        .profile-avatar:hover .profile-avatar-overlay {
            opacity: 1;
        }
        
        .profile-avatar-overlay .material-icons {
            font-size: 2rem;
            margin-bottom: 5px;
        }
        .header-greeting {
            font-size: 16px;
        }
        .header-logo-greeting {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            max-width: 100%;
            padding: 10px;
            box-sizing: border-box;
        }
        .header-logo-greeting img { /* Style for the logo */
            max-width: 100px; /* Smaller logo */
            height: auto;
            flex-shrink: 0;
        }
        .header-icons {
            display: flex;
            align-items: center;
            flex-shrink: 0;
            gap: 10px;
        }
        .header-icons span {
            margin-left: 0; /* No extra margin since we're using gap */
        }

        /* Adjusted Wishlist and Notifications Icon */
        .wishlist-header-btn,
        .header-icons span.material-icons {
            font-size: 24px; /* Consistent icon size */
            color: white;       /* changed to White */
        }

        .out-of-stock-stamp {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: contain; /* or object-fit: cover; depending on desired appearance */
            opacity: 0.7; /* Adjust transparency as needed */
            pointer-events: none; /* Make it non-interactive */
        }
        .product-image-container { /* Add this style */
            position: relative;      /* To position the stamp relative to the image */
        }
        .profile-greeting {
            display: flex;
            flex-direction: column; /* Stack items vertically */
            align-items: center;   /* Center items horizontally */
            margin-right: 20px;
            flex-shrink: 0;
            min-width: 50px;
        }
        .notification-icon {
            position: relative;
            cursor: pointer;
            transition: transform 0.2s ease;
        }
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background-color: #ff4444;
            color: white;
            border-radius: 50%;
            padding: 2px 6px;
            font-size: 12px;
            display: none;
        }
        /* Caution Banner Styles */
        .caution-banner {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            z-index: 999;
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
            animation: slideDown 0.5s ease-out;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .caution-banner:hover {
            background: linear-gradient(135deg, #c82333 0%, #bd2130 100%);
            transform: translateY(2px);
        }
        
        .caution-content {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            gap: 12px;
            max-width: 100%;
        }
        
        .caution-icon {
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
        }
        
        .caution-icon .material-icons {
            font-size: 24px;
            color: #ffc107;
            animation: pulse 2s infinite;
        }
        
        .caution-text {
            flex: 1;
            min-width: 0;
        }
        
        .caution-text h4 {
            margin: 0 0 4px 0;
            font-size: 16px;
            font-weight: bold;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .caution-text p {
            margin: 0;
            font-size: 13px;
            opacity: 0.9;
            line-height: 1.3;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .caution-close {
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            padding: 8px;
            border-radius: 50%;
            transition: background-color 0.2s;
            flex-shrink: 0;
        }
        
        .caution-close:hover {
            background-color: rgba(255, 255, 255, 0.2);
        }
        
        .caution-close .material-icons {
            font-size: 20px;
        }
        
        /* Auto-dismiss progress bar */
        .caution-progress {
            position: absolute;
            bottom: 0;
            left: 0;
            height: 3px;
            background: rgba(255, 255, 255, 0.3);
            animation: progressBar 8s linear forwards;
        }
        
        @keyframes progressBar {
            from { width: 100%; }
            to { width: 0%; }
        }
        
        @keyframes slideDown {
            from {
                transform: translateY(-100%);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.1);
            }
        }

        .notification-popup {
            display: none;
            position: fixed;
            top: 0;
            right: 0;
            width: 300px;
            height: 100%;
            background: white;
            box-shadow: -2px 0 5px rgba(0,0,0,0.2);
            z-index: 1000;
            transform: translateX(100%);
            transition: transform 0.3s ease-in-out;
        }
        .notification-popup.active {
            transform: translateX(0);
        }
        .notification-popup-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            border-bottom: 1px solid #eee;
        }
        .notification-popup-header h3 {
            margin: 0;
        }
        .notification-controls {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .close-notification {
            cursor: pointer;
        }
        .notification-items {
            padding: 15px;
            overflow-y: auto;
            max-height: calc(100% - 60px);
        }
        .notification-item {
            padding: 15px;
            border-bottom: 1px solid #eee;
            margin-bottom: 10px;
            background: #f9f9f9;
            border-radius: 8px;
        }
        .notification-items a.notification-item { display:block; text-decoration:none; color:inherit; }
        .notification-item h4 {
            margin: 0 0 10px 0;
            font-size: 16px;
            color: #333;
        }
        .notification-item p {
            margin: 5px 0;
            font-size: 14px;
            color: #666;
            line-height: 1.4;
        }
        .notification-item p strong {
            color: #333;
            font-weight: 600;
        }
        .notification-item .time {
            font-size: 12px;
            color: #999;
            margin-top: 8px;
            border-top: 1px solid #eee;
            padding-top: 8px;
        }
        .notification-popup-footer {
            padding: 10px;
            border-top: 1px solid #eee;
            text-align: center;
            background: #f8f9fa;
        }
        .notification-popup-footer .btn {
            font-size: 12px;
            padding: 6px 12px;
        }
        
        /* Settings Popup Styles */
        .settings-popup {
            display: none;
            position: fixed;
            top: 60px;
            left: 0;
            right: 0;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 480px;
            height: calc(100vh - 130px);
            margin: 0 auto;
            z-index: 1000;
            flex-direction: column;
            overflow-y: auto;
        }
        .settings-popup.active {
            display: flex;
        }
        .settings-popup-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            border-bottom: 1px solid #eee;
        }
        .settings-popup-header h3 {
            margin: 0;
        }
        .close-settings {
            cursor: pointer;
        }
        .settings-content {
            padding: 20px;
            overflow-y: auto;
            flex-grow: 1;
        }
        .pin-settings h4 {
            margin: 0 0 10px 0;
            color: #333;
        }
        .settings-description {
            color: #666;
            font-size: 14px;
            margin-bottom: 20px;
        }
        .toggle-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .toggle-label {
            font-weight: 500;
            color: #333;
        }
        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 24px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        input:checked + .slider {
            background-color: #4CAF50;
        }
        input:checked + .slider:before {
            transform: translateX(26px);
        }
        .pin-input-container, .pin-confirm-container {
            margin-bottom: 15px;
        }
        .pin-input-container label, .pin-confirm-container label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #333;
        }
        .pin-input-container input, .pin-confirm-container input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 18px;
            text-align: center;
            letter-spacing: 2px;
        }
        .pin-hint {
            color: #666;
            font-size: 12px;
            margin-top: 5px;
        }
        .pin-status {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 5px;
            margin-top: 15px;
        }
        .pin-status .material-icons {
            color: #666;
        }
        
        /* PIN Verification Modal Styles */
        #pinVerificationModal .modal-content {
            border-radius: 15px;
            border: none;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        
        #pinVerificationModal .modal-header {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            border-radius: 15px 15px 0 0;
            border-bottom: none;
        }
        
        #pinVerificationModal .modal-title {
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        #pinVerificationModal .btn-close {
            filter: invert(1);
        }
        
        #pinVerificationModal .modal-body {
            padding: 2rem;
        }
        
        #togglePinInput {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            transition: all 0.3s ease;
        }
        
        #togglePinInput:focus {
            border-color: #dc3545;
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
        }
        
        #confirmDisablePinBtn {
            border-radius: 8px;
            font-weight: 600;
            padding: 10px 20px;
        }
        
        #confirmDisablePinBtn:disabled {
            opacity: 0.6;
        }
        #toastContainer .toast-message {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            padding: 16px 20px;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(102, 126, 234, 0.3), 0 4px 16px rgba(0,0,0,0.1);
            font-size: 14px;
            position: relative;
            min-width: 200px;
            max-width: 280px;
            word-break: break-word;
            margin-bottom: 12px;
            margin-right: 0;
            border: 1px solid rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            font-weight: 500;
            line-height: 1.4;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @media (max-width: 600px) {
            #toastContainer {
                top: 10px;
                right: 10px;
                left: auto;
                align-items: center;
                padding: 0 10px;
                width: 280px;
                max-width: 90vw;
            }
            #toastContainer .toast-message {
                min-width: 0;
                max-width: 100%;
                width: 100%;
                font-size: 15px;
                padding: 18px 20px;
                border-radius: 20px;
                margin-bottom: 16px;
            }
        }
        .bottom-nav a.active .material-icons {
            color: #4CAF50 !important; /* Green */
        }
        /* Optional muted look for secondary items */
        .muted-link { opacity: 0.9; }
        /* Add this style for the status table in the modal */
        #seekHelpStatusView .table {
            width: 100%;
            table-layout: fixed;
        }
        #seekHelpStatusView .table td {
            width: 50%;
            vertical-align: top;
            padding: 8px 10px;
            word-break: break-word;
        }
        #seekHelpStatusView .table td:first-child {
            font-weight: bold;
            background: #fafbfc;
        }
        .mechanic-details-scroll {
            max-height: 80px;
            overflow-y: auto;
            padding-right: 4px;
        }
        .modern-rating {
            display: flex;
            align-items: center;
            gap: 2px; /* Reduced gap */
            font-size: 0.85em; /* Much smaller overall */
            margin-bottom: 2px;
            justify-content: center; /* Center the rating */
        }
        .modern-rating .rating-value {
            font-weight: bold;
            color: #ffb400;
            font-size: 0.9em; /* Smaller rating number */
            margin-right: 2px;
        }
        .modern-rating .stars {
            display: flex;
            align-items: center;
        }
        .modern-rating .stars i {
            color: #ffb400;
            font-size: 0.8em; /* Much smaller stars */
            margin-right: 1px;
        }
        .modern-rating .stars i.far {
            color: #e0e0e0;
        }
        .modern-rating .rating-count {
            color: #888;
            font-size: 0.8em; /* Smaller review count */
            margin-left: 2px;
        }
        /* Products Grid Container */
        .products {
            display: grid;
            grid-template-columns: repeat(2, 1fr); /* Keep 2 cards per row */
            gap: 16px; /* Increased gap to prevent overlapping */
            padding: 16px;
            padding-bottom: 100px; /* Space for bottom navigation */
            max-width: 100%;
            margin: 0 auto;
            box-sizing: border-box; /* Ensure padding is included in width */
        }

        /* Product Card - Wider and Better Mobile Layout */
        .product-card {
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 4px 18px 0 rgba(0,0,0,0.10), 0 1.5px 4px 0 rgba(0,0,0,0.08);
            padding: 16px 12px 14px 12px; /* Reduced padding for more content space */
            margin: 0; /* Remove margin since grid handles spacing */
            transition: box-shadow 0.18s, transform 0.18s;
            position: relative;
            width: 100%; /* Full width */
            min-height: 360px; /* Reduced height for better proportions */
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between;
            box-sizing: border-box; /* Ensure padding is included in width */
            overflow: hidden; /* Prevent content from overflowing */
        }
        .product-card .button-group {
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 6px; /* Reduced gap for more compact layout */
            margin-top: auto;
            padding-top: 6px; /* Reduced padding */
        }
        .product-card .add-to-cart-btn, .product-card .details-btn {
            width: 100%;
            border-radius: 8px; /* Slightly less rounded */
            margin-bottom: 0;
            padding: 10px 14px; /* Smaller padding for more compact look */
            font-size: 0.9em; /* Smaller font */
            font-weight: 500;
        }
        .product-card:hover, .product-card:active {
            box-shadow: 0 8px 28px 0 rgba(0,0,0,0.16), 0 3px 8px 0 rgba(0,0,0,0.10);
            transform: translateY(-2px) scale(1.02);
            z-index: 2;
        }

        /* Search Bar Styling */
        .search-bar {
            background: white;
            border-radius: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 12px 20px;
            margin: 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            position: relative;
            z-index: 10;
        }

        .search-bar input {
            border: none;
            outline: none;
            width: 100%;
            font-size: 16px;
            background: transparent;
        }

        .search-bar input::placeholder {
            color: #999;
        }

        .search-bar .material-icons {
            color: #666;
            font-size: 20px;
        }

        /* Responsive Design - Keep 2 columns but make cards wider */
        @media (min-width: 480px) {
            .products {
                grid-template-columns: repeat(2, 1fr); /* Always 2 columns */
                gap: 18px; /* Increased gap to prevent overlapping */
                padding: 18px;
            }
            
            .product-card {
                min-height: 350px; /* Slightly shorter for better proportions */
            }
        }

        /* Tablet and larger screens - Still 2 columns for better card width */
        @media (min-width: 768px) {
            .products {
                grid-template-columns: repeat(2, 1fr); /* Keep 2 columns for wider cards */
                gap: 24px; /* Larger gap for bigger screens */
                padding: 24px;
                max-width: 900px; /* Increased max-width for better proportions */
            }
            
            .product-card {
                min-height: 340px;
            }
        }
        .product-image-container img {
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.07);
            background: #f7f7f7;
            width: 100%;
            max-width: 120px; /* Smaller image for more compact layout */
            height: auto;
            margin-bottom: 8px; /* Reduced margin */
            object-fit: contain;
        }
        .product-card h3 {
            font-size: 1.0em; /* Smaller, more compact */
            font-weight: 600;
            margin: 8px 0 4px 0; /* Reduced margins */
            text-align: center;
            line-height: 1.2;
            color: #333;
        }
        .product-card .price {
            font-size: 1.1em; /* Slightly smaller but still prominent */
            font-weight: bold;
            color: #222;
            margin-bottom: 8px; /* Reduced margin */
            text-align: center;
        }
        .product-card .stock {
            font-size: 0.9em; /* Smaller, more compact */
            margin-bottom: 3px; /* Reduced margin */
            font-weight: 500;
            text-align: center;
        }
        .product-card .modern-rating {
            margin-bottom: 3px; /* Reduced margin */
        }
        .product-card .wishlist-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(255,255,255,0.85);
            border-radius: 50%;
            border: none;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
            padding: 6px;
            z-index: 3;
        }
        .recent-order-items {
            display: flex;
            gap: 18px;
            overflow-x: auto;
            padding-bottom: 8px;
            scroll-snap-type: x mandatory;
            scroll-behavior: smooth;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }
        .recent-order-items::-webkit-scrollbar {
            display: none;
        }
        .recent-order-item {
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 4px 18px 0 rgba(0,0,0,0.10), 0 1.5px 4px 0 rgba(0,0,0,0.08);
            padding: 18px 12px 16px 12px;
            min-width: 170px;
            min-height: 260px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between;
            text-align: center;
            scroll-snap-align: start;
            transition: box-shadow 0.18s, transform 0.18s;
        }
        .recent-order-item:hover, .recent-order-item:active {
            box-shadow: 0 8px 28px 0 rgba(0,0,0,0.16), 0 3px 8px 0 rgba(0,0,0,0.10);
            transform: translateY(-2px) scale(1.02);
            z-index: 2;
        }
        .recent-order-item img {
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.07);
            background: #f7f7f7;
            width: 100%;
            max-width: 90px;
            height: auto;
            margin-bottom: 10px;
        }
        .recent-order-item h3 {
            font-size: 1.05em;
            font-weight: 600;
            margin: 8px 0 4px 0;
            text-align: center;
        }
        .recent-order-item .price {
            font-size: 1.08em;
            font-weight: bold;
            color: #222;
            margin-bottom: 8px;
        }
        .recent-order-item .add-to-cart-btn {
            width: 100%;
            border-radius: 8px;
            margin-top: auto;
        }
        
        /* Disabled button styling for Order Again section */
        .recent-order-item .add-to-cart-btn.disabled,
        .recent-order-item .add-to-cart-btn:disabled {
            background: #ccc !important;
            color: #666 !important;
            cursor: not-allowed !important;
            opacity: 0.6 !important;
        }
        
        .recent-order-item .add-to-cart-btn.disabled:hover,
        .recent-order-item .add-to-cart-btn:disabled:hover {
            background: #ccc !important;
            transform: none !important;
            box-shadow: none !important;
        }
        
        /* Out of stock stamp for Order Again section */
        .recent-order-item .product-image-container {
            position: relative;
            display: inline-block;
        }
        
        .recent-order-item .out-of-stock-stamp {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-15deg);
            width: 80px;
            height: auto;
            z-index: 2;
            pointer-events: none;
        }
        /* Reactions styling */
        .feedback-image-wrap { position: relative; display: inline-block; }
        .feedback-image-wrap .reactions-bar {
            display: flex; gap: 8px; background: rgba(255,255,255,0.95);
            border-radius: 20px; padding: 6px 8px; box-shadow: 0 6px 16px rgba(0,0,0,0.15);
            margin-top: 8px;
        }
        .react-btn { border: none; background: transparent; cursor: pointer; font-size: 18px; line-height: 1; }
        .reactions-counts { margin-top: 6px; font-size: 14px; color: #333; display: flex; gap: 10px; flex-wrap: wrap; }
        .reactions-counts .rc { background: #f3f3f3; border-radius: 12px; padding: 2px 8px; }
    </style>
</head>
<body>
    <!-- Spinner Start -->
    <div id="spinner" class="show bg-dark position-fixed translate-middle w-100 vh-100 top-50 start-50 d-flex align-items-center justify-content-center">
        <img src="<?= $baseURL ?>img/Loading.gif" alt="Loading..." style="width: 200px; height: 200px;" />
    </div>
    <!-- Spinner End -->

    <!-- Example: Wrap main app in a responsive Bootstrap container -->
<!-- Restore original main app container -->
<div class="app">
    <header>
        <div class="header-logo-greeting">
            <div class="profile-greeting">
                <?php if (!empty($user['ImagePath'])): ?>
                    <img src="<?= $baseURL . $user['ImagePath'] ?>" alt="Profile" class="header-profile-image">
                <?php else: ?>
                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($user['first_name'] . ' ' . $user['last_name']) ?>&background=4CAF50&color=fff" alt="Profile" class="header-profile-image">
                <?php endif; ?>
                <span class="header-greeting">Hi, <?= htmlspecialchars($user['first_name']) ?></span>
            </div>
            <div class="header-icons">
                <img src="<?= $baseURL ?>uploads/Cjhouse.png" alt="Logo" class="app-logo" style="max-width:100px; margin-right:10px;">
                <span class="material-icons wishlist-header-btn">favorite_border</span>
                <div class="notification-icon">
                    <span class="material-icons">notifications</span>
                    <span class="notification-badge" id="notificationCount">0</span>
                </div>
            </div>
        </div>
    </header>
    <!-- Wishlist Popup -->
    <div class="wishlist-popup" id="wishlistPopup" style="display:none;">
        <div class="wishlist-popup-header">
            <h3>My Wishlist</h3>
            <span class="material-icons close-wishlist">close</span>
        </div>
        <div class="wishlist-items">
            <!-- Wishlist items will be added here dynamically -->
        </div>
    </div>

    <!-- Settings Popup -->
    <div class="settings-popup" id="settingsPopup" style="display:none;">
        <div class="settings-popup-header">
            <h3>Security Settings</h3>
            <span class="material-icons close-settings">close</span>
        </div>
        <div class="settings-content">
            <div class="pin-settings">
                <h4>PIN Code Protection</h4>
                <p class="settings-description">Secure your checkout process with a 4-digit PIN code.</p>
                
                <div class="pin-toggle-section">
                    <div class="toggle-container">
                        <label class="toggle-label">Enable PIN Protection</label>
                        <label class="switch">
                            <input type="checkbox" id="pinToggle">
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>
                
                <div class="pin-setup-section" id="pinSetupSection" style="display: none;">
                    <div class="pin-input-container">
                        <label for="pinInput">Set 4-Digit PIN:</label>
                        <input type="password" id="pinInput" maxlength="4" pattern="[0-9]{4}" placeholder="0000" inputmode="numeric">
                        <small class="pin-hint">Enter 4 digits only</small>
                    </div>
                    <div class="pin-confirm-container">
                        <label for="pinConfirm">Confirm PIN:</label>
                        <input type="password" id="pinConfirm" maxlength="4" pattern="[0-9]{4}" placeholder="0000" inputmode="numeric">
                    </div>
                    <button class="btn btn-primary" id="savePinBtn">Save PIN</button>
                </div>
                
                <div class="pin-status" id="pinStatus">
                    <span class="material-icons">lock_open</span>
                    <span>PIN Protection Disabled</span>
                </div>
            </div>
        </div>
    </div>

    <!-- PIN Verification Modal for Toggle -->
    <div class="modal fade" id="pinVerificationModal" tabindex="-1" aria-labelledby="pinVerificationModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="pinVerificationModalLabel">
                        <span class="material-icons" style="vertical-align: middle; margin-right: 8px;">security</span>
                        Verify PIN to Disable Protection
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <p class="text-muted">Enter your 4-digit PIN to disable PIN protection</p>
                    </div>
                    <div class="mb-3">
                        <label for="togglePinInput" class="form-label">PIN Code</label>
                        <input type="password" class="form-control form-control-lg text-center" id="togglePinInput" 
                               maxlength="4" pattern="[0-9]{4}" placeholder="0000" inputmode="numeric" 
                               style="font-size: 1.5rem; letter-spacing: 0.5rem;">
                    </div>
                    <div class="text-center">
                        <small class="text-muted">This action will disable PIN protection for your account</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="forgotPinBtn">
                        <span class="material-icons" style="vertical-align: middle; margin-right: 4px;">email</span>
                        Forgot PIN code?
                    </button>
                    <button type="button" class="btn btn-danger" id="confirmDisablePinBtn">
                        <span class="material-icons" style="vertical-align: middle; margin-right: 4px;">lock_open</span>
                        Disable PIN Protection
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Caution Banner for Declined Requests -->
    <div class="caution-banner" id="cautionBanner" style="display: none;">
        <div class="caution-content">
            <div class="caution-icon">
                <span class="material-icons">warning</span>
            </div>
            <div class="caution-text">
                <h4>Request Declined</h4>
                <p id="cautionMessage">Your help request has been declined. Tap to view details.</p>
            </div>
            <button class="caution-close" onclick="closeCautionBanner()">
                <span class="material-icons">close</span>
            </button>
        </div>
        <div class="caution-progress"></div>
    </div>

    <!-- Notification Popup -->
    <div class="notification-popup" id="notificationPopup">
        <div class="notification-popup-header">
            <h3>Notifications</h3>
            <div class="notification-controls">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="muteSoundBtn" title="Mute/Unmute Sound">
                    <span class="material-icons" id="muteIcon">volume_up</span>
                </button>
                <span class="material-icons close-notification">close</span>
            </div>
        </div>
        <div class="notification-items" id="notificationItems">
            <!-- Notifications will be added here dynamically -->
        </div>
        <div class="notification-popup-footer" style="padding: 10px; border-top: 1px solid #eee; text-align: center;">
           
           
        </div>
        
        <!-- Debug Panel -->
        <div id="debugPanel" style="display: none; padding: 15px; background: #f8f9fa; border-top: 1px solid #eee; font-size: 12px;">
            <h4 style="margin: 0 0 10px 0;">🔍 Debug Information</h4>
            <div style="margin-bottom: 10px;">
                <strong>SSE Status:</strong> <span id="sseStatus">Connecting...</span>
            </div>
            <div style="margin-bottom: 10px;">
                <strong>Last SSE Message:</strong> <span id="lastSseMessage">None</span>
            </div>
            <div style="margin-bottom: 10px;">
                <strong>Notification Count:</strong> <span id="debugNotificationCount">0</span>
            </div>
            <div style="margin-bottom: 10px;">
                <strong>Sound Muted:</strong> <span id="debugSoundMuted">No</span>
            </div>
            <div style="margin-bottom: 10px;">
                <strong>Audio Ready:</strong> <span id="debugAudioReady">Checking...</span>
            </div>
            <div style="margin-bottom: 10px;">
                <strong>Device Type:</strong> <span id="debugDeviceType">Checking...</span>
            </div>
            <div style="margin-bottom: 10px;">
                <strong>Audio Unlocked:</strong> <span id="debugAudioUnlocked">No</span>
            </div>
            <div style="margin-top: 15px; text-align: center;">
                <button type="button" class="btn btn-sm btn-outline-warning" id="reconnectSSEBtn" style="font-size: 11px; margin-right: 10px;">
                    🔄 Reconnect SSE
                </button>
                <button type="button" class="btn btn-sm btn-outline-info" id="clearDebugBtn" style="font-size: 11px;">
                    🧹 Clear Debug
                </button>
            </div>
        </div>
    </div>

    <!-- Sale Banner Container -->
    <div class="sale-banner-container">
        <!-- Banners -->
        <div class="sale-banner active" style="#">
            <!-- Optional Side Photo -->
            <img src="<?= $baseURL ?>uploads/shopping.gif" alt="Advertisement" class="side-image">
            <div class="sale-content">
                <h2>Quality Guaranty</h2>
                <p>PALIT NA!!</p>
                <p class="code">GENUINE PARTS</p>
            </div>
            <button class="btn">View</button>
        </div>

        <div class="sale-banner" style="#">
        <img src="<?= $baseURL ?>uploads/xrm125.png" alt="Advertisement" class="side-image">
            <div class="sale-content">
                <h2>For Sale XRM 125</h2>
                <p>For more Info</p>
                <p class="code">C'#:
                    09568706652</p>
            </div>
            <button class="btn">View</button>
        </div>

        <div class="sale-banner" style="#">
        <img src="<?= $baseURL ?>uploads/mechanic.gif" alt="Advertisement" class="side-image">
            <div class="sale-content">
                <h2>Bike Trouble?</h2>
                <p>Get Help by using</p>
                <p class="code">Seek help!!</p>
            </div>
            <button class="btn">View</button>
        </div>

        <!-- Navigation Dots -->
        <div class="banner-dots">
            <span class="dot active" data-index="0"></span>
            <span class="dot" data-index="1"></span>
            <span class="dot" data-index="2"></span>
        </div>
    </div>

    <!-- Recent Order Items Carousel -->
    <div class="recent-order-items-container">
        <h2>Order Again</h2>
        <div class="recent-order-items">
            <?php if (!empty($recent_order_items)): ?>
                <?php foreach ($recent_order_items as $item): ?>
                    <div class="recent-order-item">
                        <div class="product-image-container">
                            <img src="<?= $baseURL . 'uploads/' . htmlspecialchars($item['ImagePath']) ?>" alt="<?= htmlspecialchars($item['ProductName']) ?>">
                            <?php if ($item['Quantity'] == 0): ?>
                                <img src="<?= $baseURL ?>uploads/out-of-stock.png" class="out-of-stock-stamp">
                            <?php endif; ?>
                        </div>
                        <h3><?= htmlspecialchars($item['ProductName']) ?></h3>
                        <p class="price">₱<?= number_format($item['Price'], 2) ?></p>
                        <button class="add-to-cart-btn <?= $item['Quantity'] == 0 ? 'disabled' : '' ?>" 
                                data-product-id="<?= $item['ProductID'] ?>" 
                                <?= $item['Quantity'] == 0 ? 'disabled' : '' ?>>
                            <span class="material-icons">add_shopping_cart</span>
                            Add to Cart
                        </button>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No recent orders found.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Categories -->
    <div class="categories">
        <button class="category-btn active" data-category="all">All</button>
        <button class="category-btn" data-category="electrical components">Electronics</button>
        <button class="category-btn" data-category="tires">Tires</button>
        <button class="category-btn" data-category="oil">Oil</button>
        <button class="category-btn" data-category="batteries">Batteries</button>
        <button class="category-btn" data-category="accessories">Accessories & philux sprays</button>
        <button class="category-btn" data-category="engine components">Engine Components</button>
        <button class="category-btn" data-category="exhaust">Exhaust</button>
        <button class="category-btn" data-category="brakes">Brakes components</button>
        <button class="category-btn" data-category="fuel components">Fuel Components</button>
        <button class="category-btn" data-category="bolts">Bolts & Nuts</button>
        <button class="category-btn" data-category="motor chains">Motor Chains</button>
    </div>
     <!-- Search Bar -->
    <div class="search-bar">
        <input type="text" placeholder="Search products..." id="searchInput">
        <span class="material-icons">search</span>
    </div>
    <!-- Products Grid -->
    <div class="products">
        <?php foreach ($products as $product): ?>
            <div class="product-card" data-category="<?= strtolower($product['category']) ?>">
                <button class="wishlist-btn" data-product-id="<?= $product['ProductID'] ?>">
                    <span class="material-icons">favorite_border</span>
                </button>
                <div class="product-image-container">
                    <!-- Corrected Product Image -->
                    <img src="<?= $baseURL . 'uploads/' . $product['ImagePath'] ?>" alt="<?= htmlspecialchars($product['ProductName']) ?>">
                    <?php if ($product['Quantity'] == 0): ?>
                        <!-- Use the correct web-accessible path -->
                        <img src="<?= $baseURL ?>uploads/out-of-stock.png" class="out-of-stock-stamp">
                    <?php endif; ?>
                </div>
                <div class="stock">
                    <?= $product['Quantity'] > 0 ? "Stock: {$product['Quantity']}" : "<span class='text-danger'>Out of Stock</span>" ?>
                </div>
                <div class="rating">
                    <div class="modern-rating">
                        <span class="rating-value">
                            <?= number_format($product['rating_count'] > 0 ? $product['average_rating'] : 0, 1) ?>
                        </span>
                        <span class="stars">
                            <?php
                            $avg = $product['rating_count'] > 0 ? $product['average_rating'] : 0;
                            $fullStars = floor($avg);
                            $halfStar = ($avg - $fullStars) >= 0.25 && ($avg - $fullStars) < 0.75 ? 1 : 0;
                            $fullStars = ($avg - $fullStars) >= 0.75 ? $fullStars + 1 : $fullStars;
                            $emptyStars = 5 - $fullStars - $halfStar;
                            for ($i = 0; $i < $fullStars; $i++) echo '<i class="fas fa-star"></i>';
                            if ($halfStar) echo '<i class="fas fa-star-half-alt"></i>';
                            for ($i = 0; $i < $emptyStars; $i++) echo '<i class="far fa-star"></i>';
                            ?>
                        </span>
                        <span class="rating-count">(<?= $product['rating_count'] ?>)</span>
                    </div>
                </div>
                <h3><?= htmlspecialchars($product['ProductName']) ?></h3>
                <p class="price">₱<?= number_format($product['Price'], 2) ?></p>
                <div class="button-group">
                    <button class="add-to-cart-btn" data-product-id="<?= $product['ProductID'] ?>" <?= $product['Quantity'] == 0 ? 'disabled' : '' ?>>
                        <span class="material-icons">add_shopping_cart</span>
                        Add to Cart
                    </button>
                    <button class="details-btn" data-product-id="<?= $product['ProductID'] ?>">
                        <span class="material-icons">info</span>
                        Details
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Profile Popup -->
    <div class="profile-popup" id="profilePopup">
        <div class="profile-popup-header">
            <h3>Profile</h3>
            <span class="material-icons close-profile">close</span>
        </div>
        <div class="profile-content">
            <div class="profile-info">
                <div class="profile-avatar" id="profileAvatar">
                    <?php if (!empty($user['ImagePath'])): ?>
                        <img src="<?= $baseURL . $user['ImagePath'] ?>" alt="Profile" id="profileImage">
                    <?php else: ?>
                        <img src="https://ui-avatars.com/api/?name=<?= urlencode($user['first_name'] . ' ' . $user['last_name']) ?>&background=4CAF50&color=fff" alt="Profile" id="profileImage">
                    <?php endif; ?>
                    <div class="profile-avatar-overlay">
                        <span class="material-icons">camera_alt</span>
                        <span>Change Photo</span>
                    </div>
                    <input type="file" id="profileImageInput" accept="image/*" style="display: none;">
                </div>
                <h2><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></h2>
                <p class="email"><?= htmlspecialchars($user['email']) ?></p>
                <p class="phone">+(63)<?= htmlspecialchars($user['phone_number']) ?></p>
                <p class="address">
                    Bgry: <?= htmlspecialchars($user['barangay_name']) ?>, Purok <?= htmlspecialchars($user['purok']) ?>, Valencia City, Bukidnon
                </p>
            </div>
            <div class="profile-menu">
                <a href="<?= $baseURL ?>Mobile-Orders.php" class="menu-item">
                    <span class="material-icons">shopping_bag</span>
                    <span>My Orders</span>
                    <span class="material-icons">chevron_right</span>
                </a>
                <a href="#" class="menu-item" id="openWishlistFromProfile">
                    <span class="material-icons">favorite</span>
                    <span>Wishlist</span>
                    <span class="material-icons">chevron_right</span>
                </a>
                <a href="<?= $baseURL ?>Mobile-ShippingAddress.php" class="menu-item muted-link" title="Edit your shipping address">
                    <span class="material-icons">location_on</span>
                    <span>Shipping Address</span>
                    <span class="material-icons">chevron_right</span>
                </a>
                <a href="#" class="menu-item" data-bs-toggle="modal" data-bs-target="#helpCenterModal" title="Help Center">
                    <span class="material-icons">help_outline</span>
                    <span>Help Center</span>
                    <span class="material-icons">chevron_right</span>
                </a>
                <a href="#" class="menu-item" id="openSettingsFromProfile" title="Settings">
                    <span class="material-icons">settings</span>
                    <span>Settings</span>
                    <span class="material-icons">chevron_right</span>
                </a>
            </div>
            <button class="logout-button" onclick="window.location.href='<?= $baseURL ?>LandingPage.php'">
                <span class="material-icons">logout</span>
                <span>Logout</span>
            </button>
        </div>
    </div>

                   <!-- Bottom Navigation -->
       <nav class="bottom-nav">
           <a href="<?= $baseURL ?>Mobile-Dashboard.php" class="active">
               <span class="material-icons">home</span>
               <span>Home</span>
           </a>
           <a href="<?= $baseURL ?>Mobile-Orders.php">
               <span class="material-icons">history</span>
               <span>Orders</span>
           </a>
           <a href="<?= $baseURL ?>Mobile-Cart.php">
               <span class="material-icons">shopping_cart</span>
               <span>Cart</span>
           </a>
           <a href="#" data-bs-toggle="modal" data-bs-target="#emergencyReportModal">
               <span class="material-icons">sos</span>
               <span>Seek Help</span>
           </a>
           <a href="#" id="profileNavBtn">
               <span class="material-icons">person</span>
               <span>Profile</span>
           </a>
       </nav>

       <!-- Emergency Report Modal -->
       <div class="modal fade" id="emergencyReportModal" tabindex="-1" aria-labelledby="emergencyReportModalLabel" aria-hidden="true">
           <div class="modal-dialog modal-dialog-centered">
               <div class="modal-content">
                   <div class="modal-header">
                       <h5 class="modal-title" id="emergencyReportModalLabel">Report Emergency</h5>
                       <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                   </div>
                   <div class="modal-body">
                       <!-- Tab Switcher -->
                       <div class="seekhelp-tabs mb-3" style="display:flex;gap:8px;">
                           <button id="seekHelpTabBtn" class="btn btn-outline-primary active" type="button" style="flex:1;">Seek Help</button>
                           <button id="statusTabBtn" class="btn btn-outline-secondary" type="button" style="flex:1;">My Request Status</button>
                       </div>
                       <!-- Seek Help Form View -->
                       <div id="seekHelpFormView">
                           <form id="emergencyReportForm">
                               <div class="mb-3">
                                   <label for="name" class="form-label">Your Name</label>
                                   <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?>" required>
                               </div>
                               <div class="mb-3">
                                   <label for="email" class="form-label">Email</label>
                                   <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>
                               </div>
                               <div class="mb-3">
                                   <label for="contactInfo" class="form-label">Contact Number</label>
                                   <input type="tel" class="form-control" id="contactInfo" name="contactInfo" value="<?= htmlspecialchars($user['phone_number']) ?>" required>
                               </div>
                               <div class="mb-3">
                                   <label for="barangay" class="form-label">Barangay (Breakdown Location)</label>
                                   <select class="form-select" id="barangay" name="barangay" required>
                                       <option value="">Select Barangay</option>
                                       <?php foreach ($barangays as $barangay): ?>
                                           <option value="<?= $barangay['id'] ?>"><?= htmlspecialchars($barangay['barangay_name']) ?></option>
                                       <?php endforeach; ?>
                                   </select>
                               </div>
                               <div class="mb-3">
                                   <label for="bikeUnit" class="form-label">Bike Unit</label>
                                   <input type="text" class="form-control" id="bikeUnit" name="bikeUnit" placeholder="e.g., XRM 125, Click 150" required>
                               </div>
                               <div class="mb-3">
                                   <label for="plateNumber" class="form-label">Plate Number</label>
                                   <input type="text" class="form-control" id="plateNumber" name="plateNumber">
                               </div>
                               <div class="mb-3">
                                   <label for="problem" class="form-label">What seems to be the problem?</label>
                                   <textarea class="form-control" id="problem" name="problem" rows="3" required></textarea>
                               </div>
                               <div class="mb-3">
                                   <label for="location" class="form-label">Your Location / Landmark</label>
                                   <textarea class="form-control" id="location" name="location" rows="3" placeholder="Please provide specific details about your location" required></textarea>
                                   <button type="button" class="btn btn-outline-primary mt-2" id="pinLocationBtn" style="width:100%;">Pin My Location on Map</button>
                                   <div id="mapContainer" style="display:none; margin-top:10px;">
                                       <div id="leafletMap" style="width:100%;height:250px;border-radius:8px;"></div>
                                       <div class="text-muted" style="font-size:0.95em;">Drag the marker or use your current location.</div>
                                       <div class="row g-2 mt-2">
                                          <!-- Merged coordinate input field for copy-paste from Google Maps -->
                                          <div class="col-12">
                                              <label class="form-label" style="font-size:0.9em;">Coordinates (Lat, Lng)</label>
                                              <input type="text" id="mergedCoordsInput" class="form-control form-control-sm" placeholder="Paste coordinates like: 7.919867,125.092611">
                                              <small class="text-muted" style="font-size:0.75em;">Paste comma-separated coordinates from Google Maps</small>
                                          </div>
                                           <div class="col-6">
                                               <label class="form-label" style="font-size:0.9em;">Latitude</label>
                                               <input type="text" id="latDisplay" class="form-control form-control-sm" readonly>
                                           </div>
                                           <div class="col-6">
                                               <label class="form-label" style="font-size:0.9em;">Longitude</label>
                                               <input type="text" id="lngDisplay" class="form-control form-control-sm" readonly>
                                           </div>
                                           <div class="col-12 d-flex gap-2">
                                               <button type="button" id="copyCoordsBtn" class="btn btn-sm btn-secondary" style="flex:1;">Copy Coordinates</button>
                                               <a id="openInGmapsLink" href="#" target="_blank" class="btn btn-sm btn-success" style="flex:1;">Open in Google Maps</a>
                                           </div>
                                       </div>
                                   </div>
                                   <input type="hidden" id="latitude" name="latitude">
                                   <input type="hidden" id="longitude" name="longitude">
                               </div>
                               <button type="submit" class="btn btn-danger">Submit Emergency Report</button>
                           </form>
                       </div>
                       <!-- Status View (hidden by default) -->
                       <div id="seekHelpStatusView" style="display:none;">
                           <div class="alert alert-info">Your latest help request status will appear here.</div>
                       </div>
                   </div>
               </div>
           </div>
       </div>

    <!-- Product Details Modal -->
<div class="modal fade" id="productDetailsModal" tabindex="-1" aria-labelledby="productDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="productDetailsModalLabel">Product Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="product-details">
                    <img id="modalProductImage" src="" alt="Product Image" class="img-fluid">
                    <table class="table table-bordered">
                        <tr>
                            <th>Name</th>
                            <td id="modalProductName"></td>
                        </tr>
                        <tr>
                            <th>Price</th>
                            <td id="modalProductPrice"></td>
                        </tr>
                        <tr>
                            <th>Stock</th>
                            <td id="modalProductStock"></td>
                        </tr>
                    </table>
                </div>
                <div class="product-feedback">
                    <h4>Customer Review</h4>
                    <div id="feedbackList"></div>
                    <form id="feedbackForm">
                        <input type="hidden" name="product_id" id="productId">
                        <div class="mb-3">
                            <label for="feedbackComment" class="form-label">Your Comment</label>
                            <textarea class="form-control" name="comment" id="feedbackComment" rows="3" required></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="feedbackRating" class="form-label">Rating</label>
                            <div class="star-rating">
                                <span class="star" data-rating="1"><i class="fas fa-star"></i></span>
                                <span class="star" data-rating="2"><i class="fas fa-star"></i></span>
                                <span class="star" data-rating="3"><i class="fas fa-star"></i></span>
                                <span class="star" data-rating="4"><i class="fas fa-star"></i></span>
                                <span class="star" data-rating="5"><i class="fas fa-star"></i></span>
                                <input type="hidden" name="rating" id="feedbackRating" value="0" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="feedbackImage" class="form-label">Upload Image</label>
                            <input type="file" class="form-control" name="image" id="feedbackImage" accept="image/*">
                        </div>
                        <button type="submit" class="btn btn-primary">Submit Feedback</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

</div>
    <!-- Help Center Modal -->
    <div class="modal fade" id="helpCenterModal" tabindex="-1" aria-labelledby="helpCenterLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="helpCenterLabel">Help Center</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body" style="color:#333;">
            If you have any concern, please contact us on Facebook:
            <a href="https://www.facebook.com/cjspowerhouse" target="_blank" rel="noopener noreferrer">facebook.com/cjspowerhouse</a>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            <a class="btn btn-primary" href="https://www.facebook.com/cjspowerhouse" target="_blank" rel="noopener noreferrer">Open Facebook</a>
          </div>
        </div>
      </div>
    </div>
    <div id="toastContainer" style="position: fixed; top: 20px; right: 20px; z-index: 9999; width: 300px; max-width: 90vw;"></div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="<?= $baseURL ?>js/script.js"></script>
    <script>
        window.CURRENT_USER_ID = <?= (int)$user_id ?>;
    </script>
    <script>
            window.addEventListener('load', function () {
                setTimeout(function() {
                    document.getElementById("spinner").classList.remove("show");
                }, 1000);
            });
        </script>
    <script>
        // Notification System with SSE and Sound
        document.addEventListener('DOMContentLoaded', function() {
            const notificationIcon = document.querySelector('.notification-icon');
            const notificationPopup = document.getElementById('notificationPopup');
            const closeNotification = document.querySelector('.close-notification');
            const notificationCount = document.getElementById('notificationCount');
            const notificationItems = document.getElementById('notificationItems');
            const notificationSound = document.getElementById('notificationSound');
            
            let currentNotificationCount = 0;
            let eventSource = null;
            let lastNotificationTime = 0;
            const NOTIFICATION_COOLDOWN = 5000; // 5 seconds cooldown between notifications

            // Function to play notification sound with mobile support
            function playNotificationSound() {
                if (isMuted) {
                    return; // Don't play sound if muted
                }
                
                try {
                    // Check if we're on mobile
                    const isMobile = /iPhone|iPad|iPod|Android/i.test(navigator.userAgent);
                    
                    if (isMobile) {
                        // Mobile-specific audio handling
                        console.log('Mobile device detected, using mobile audio strategy');
                        
                        // Try to unlock audio context first
                        unlockAudioContext();
                        
                        // Use a different approach for mobile
                        const audio = document.getElementById('notificationSound');
                        if (audio) {
                            // Reset and play with mobile-optimized settings
                            audio.currentTime = 0;
                            audio.volume = 1.0;
                            
                            // Create a promise-based play function
                            const playPromise = audio.play();
                            
                            if (playPromise !== undefined) {
                                playPromise.then(() => {
                                    console.log('Mobile audio played successfully');
                                }).catch(error => {
                                    console.log('Mobile audio play failed:', error);
                                    // Fallback: try to show a visual notification instead
                                    showMobileNotificationFallback();
                                });
                            }
                        }
                    } else {
                        // Desktop audio handling
                        notificationSound.currentTime = 0;
                        notificationSound.play().catch(error => {
                            console.log('Desktop audio play failed:', error);
                        });
                    }
                    
                    // Add visual feedback - animate notification icon
                    if (notificationIcon) {
                        notificationIcon.style.transform = 'scale(1.2)';
                        notificationIcon.style.transition = 'transform 0.2s ease';
                        setTimeout(() => {
                            notificationIcon.style.transform = 'scale(1)';
                        }, 200);
                    }
                } catch (error) {
                    console.log('Error playing notification sound:', error);
                }
            }

            // Function to unlock audio context for mobile devices
            function unlockAudioContext() {
                try {
                    // Create a temporary audio context to unlock audio
                    const AudioContext = window.AudioContext || window.webkitAudioContext;
                    if (AudioContext) {
                        const audioContext = new AudioContext();
                        if (audioContext.state === 'suspended') {
                            audioContext.resume();
                        }
                    }
                } catch (error) {
                    console.log('Audio context unlock failed:', error);
                }
            }

            // Function to show mobile notification fallback
            function showMobileNotificationFallback() {
                // Show a more prominent visual notification for mobile
                const message = '🔔 New notification received!';
                showToast(message);
                
                // Add mobile-specific visual feedback
                if (notificationIcon) {
                    // More prominent animation for mobile
                    notificationIcon.style.transform = 'scale(1.3)';
                    notificationIcon.style.transition = 'transform 0.3s ease';
                    notificationIcon.style.boxShadow = '0 0 20px rgba(255, 0, 0, 0.5)';
                    
                    setTimeout(() => {
                        notificationIcon.style.transform = 'scale(1)';
                        notificationIcon.style.boxShadow = 'none';
                    }, 300);
                }
            }

            // Function to check for declined help requests
            function checkForDeclinedRequests() {
                fetch('get_latest_help_request.php')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.request && data.request.status === 'Declined') {
                            // Check if we've already shown this declined request
                            const declinedRequests = JSON.parse(sessionStorage.getItem('declinedRequests') || '[]');
                            const requestId = data.request.id || 'unknown';
                            
                            if (!declinedRequests.includes(requestId)) {
                                showCautionBanner(data.request);
                                // Mark this request as shown
                                declinedRequests.push(requestId);
                                sessionStorage.setItem('declinedRequests', JSON.stringify(declinedRequests));
                            }
                        }
                    })
                    .catch(error => console.error('Error checking for declined requests:', error));
            }

            // Function to show caution banner for declined requests
            function showCautionBanner(request) {
                const cautionBanner = document.getElementById('cautionBanner');
                const cautionMessage = document.getElementById('cautionMessage');
                
                if (cautionBanner && cautionMessage) {
                    let message = 'Your help request has been declined.';
                    if (request.decline_reason_text) {
                        message += ` Reason: ${request.decline_reason_text}`;
                    }
                    message += ' Tap to view details.';
                    
                    cautionMessage.textContent = message;
                    cautionBanner.style.display = 'block';
                    
                    // Make the banner clickable to open Report Emergency
                    cautionBanner.onclick = function() {
                        openReportEmergency();
                    };
                    
                    // Play a special sound for declined requests
                    playDeclineSound();
                    
                    // Auto-dismiss after 8 seconds
                    setTimeout(() => {
                        if (cautionBanner.style.display !== 'none') {
                            closeCautionBanner();
                        }
                    }, 8000);
                }
            }

            // Function to close caution banner
            function closeCautionBanner() {
                const cautionBanner = document.getElementById('cautionBanner');
                if (cautionBanner) {
                    cautionBanner.style.display = 'none';
                }
            }

            // Function to open Report Emergency modal
            function openReportEmergency() {
                const emergencyModal = new bootstrap.Modal(document.getElementById('emergencyReportModal'));
                const statusTabBtn = document.getElementById('statusTabBtn');
                
                // Open modal and switch to status tab
                emergencyModal.show();
                
                // Switch to status tab after modal opens
                setTimeout(() => {
                    if (statusTabBtn) {
                        statusTabBtn.click();
                    }
                }, 300);
                
                // Close caution banner
                closeCautionBanner();
            }

            // Function to play special sound for declined requests
            function playDeclineSound() {
                const audio = new Audio('<?= $baseURL ?>uploads/UserNotification.mp3');
                audio.volume = 0.8;
                audio.play().catch(error => {
                    console.log('Decline sound playback failed:', error);
                });
            }

            // Function to fetch notifications
            function fetchNotifications() {
                fetch('<?= $baseURL ?>get_notifications.php')
                    .then(response => response.json())
                    .then(data => {
                        // Update notification count
                        const count = data.notifications.length;
                        notificationCount.textContent = count;
                        notificationCount.style.display = count > 0 ? 'block' : 'none';

                        // Update notification items
                        notificationItems.innerHTML = '';
                        data.notifications.forEach(notification => {
                            const item = document.createElement('a');
                            item.className = 'notification-item';
                            item.href = '<?= $baseURL ?>Mobile-Orders.php?order_id=' + encodeURIComponent(notification.order_id || '');
                            item.innerHTML = `
                                <h4>Order #${notification.order_id} is now ${notification.order_status}. Prepare the amount of Total ₱${notification.total_with_delivery || '0.00'}</h4>
                                <p><strong>Items:</strong> ${notification.items || 'N/A'}</p>
                                <p class="time">${notification.order_date}</p>
                            `;
                            notificationItems.appendChild(item);
                        });
                    })
                    .catch(error => console.error('Error fetching notifications:', error));
            }

            // Initialize SSE connection for real-time notifications
            function initializeSSE() {
                if (eventSource) {
                    eventSource.close();
                }

                console.log('Initializing SSE connection for user:', <?= $user_id ?>);
                eventSource = new EventSource('<?= $baseURL ?>sse_mobile_notifications.php?user_id=<?= $user_id ?>');
                
                eventSource.onopen = function(event) {
                    console.log('SSE connection opened successfully');
                    updateDebugInfo();
                };
                
                eventSource.onmessage = function(event) {
                    try {
                        console.log('SSE message received:', event.data);
                        const data = JSON.parse(event.data);
                        
                        // Update last SSE message in debug panel
                        const lastSseMessageEl = document.getElementById('lastSseMessage');
                        if (lastSseMessageEl) {
                            lastSseMessageEl.textContent = JSON.stringify(data).substring(0, 100) + '...';
                        }
                        
                        if (data.type === 'new_notification') {
                            // Check cooldown to prevent spam notifications
                            const now = Date.now();
                            if (now - lastNotificationTime < NOTIFICATION_COOLDOWN) {
                                console.log('Notification skipped due to cooldown');
                                return;
                            }
                            lastNotificationTime = now;
                            
                            console.log('New notification received:', data);
                            // Play notification sound for new notifications
                            if (data.notification_count > currentNotificationCount) {
                                console.log('Playing notification sound - count increased from', currentNotificationCount, 'to', data.notification_count);
                                playNotificationSound();
                                
                                // Show toast notification
                                const message = `You have ${data.notification_count} new order updates!`;
                                showToast(message);
                                
                                // Update the notification count
                                currentNotificationCount = data.notification_count;
                                notificationCount.textContent = data.notification_count;
                                notificationCount.style.display = data.notification_count > 0 ? 'block' : 'none';
                                
                                // Refresh notifications
                                fetchNotifications();
                            }
                            
                            // Update debug info
                            updateDebugInfo();
                        } else if (data.type === 'help_request_declined') {
                            console.log('Help request declined:', data);
                            
                            // Check if we've already shown this declined request
                            const declinedRequests = JSON.parse(sessionStorage.getItem('declinedRequests') || '[]');
                            const requestId = data.request_id || 'unknown';
                            
                            if (!declinedRequests.includes(requestId)) {
                                // Show caution banner for declined request
                                const request = {
                                    id: requestId,
                                    status: 'Declined',
                                    decline_reason: data.decline_reason,
                                    decline_reason_text: data.decline_reason_text,
                                    declined_at: data.declined_at
                                };
                                showCautionBanner(request);
                                
                                // Mark this request as shown
                                declinedRequests.push(requestId);
                                sessionStorage.setItem('declinedRequests', JSON.stringify(declinedRequests));
                            }
                            
                            // Update debug info
                            updateDebugInfo();
                        } else if (data.type === 'heartbeat') {
                            console.log('SSE heartbeat received:', data);
                            // Update notification count from heartbeat
                            if (data.notification_count !== currentNotificationCount) {
                                currentNotificationCount = data.notification_count;
                                notificationCount.textContent = data.notification_count;
                                notificationCount.style.display = data.notification_count > 0 ? 'block' : 'none';
                            }
                        } else if (data.type === 'connection_established') {
                            console.log('SSE connection established successfully');
                            updateDebugInfo();
                        } else if (data.error) {
                            console.error('SSE error received:', data.error);
                            // Show error in debug panel
                            const lastSseMessageEl = document.getElementById('lastSseMessage');
                            if (lastSseMessageEl) {
                                lastSseMessageEl.textContent = 'ERROR: ' + data.error;
                                lastSseMessageEl.style.color = 'red';
                            }
                        }
                    } catch (error) {
                        console.error('Error parsing SSE data:', error);
                    }
                };

                eventSource.onerror = function(error) {
                    console.error('SSE connection error:', error);
                    // Reconnect after 15 seconds to reduce aggressive reconnection
                    setTimeout(initializeSSE, 15000);
                };
            }

            // Function to show toast notification
            function showToast(message) {
                const toast = createToast(message);
                const container = document.getElementById('toastContainer');
                container.appendChild(toast);
                
                // Remove toast after 5 seconds
                setTimeout(() => {
                    if (toast.parentNode) {
                        toast.remove();
                    }
                }, 5000);
            }

            // Toggle notification popup
            notificationIcon.addEventListener('click', function() {
                notificationPopup.style.display = 'block';
                setTimeout(() => {
                    notificationPopup.classList.add('active');
                }, 10);
                fetchNotifications();
            });

            // Close notification popup
            closeNotification.addEventListener('click', function() {
                notificationPopup.classList.remove('active');
                setTimeout(() => {
                    notificationPopup.style.display = 'none';
                }, 300);
            });

            // Test sound button
            const testSoundBtn = document.getElementById('testSoundBtn');
            if (testSoundBtn) {
                testSoundBtn.addEventListener('click', function() {
                    // Check if we're on mobile
                    const isMobile = /iPhone|iPad|iPod|Android/i.test(navigator.userAgent);
                    
                    if (isMobile) {
                        console.log('Mobile device detected, testing mobile audio...');
                        // For mobile, try to unlock audio first
                        unlockAudioContext();
                        
                        // Test mobile audio
                        
                        // Try to play sound with mobile handling
                        setTimeout(() => {
                            playNotificationSound();
                        }, 100);
                    } else {
                        // Desktop behavior
                        playNotificationSound();
                    }
                    
                    // Also simulate a notification count change to test the full system
                    const currentCount = parseInt(notificationCount.textContent) || 0;
                    const newCount = currentCount + 1;
                    notificationCount.textContent = newCount;
                    notificationCount.style.display = 'block';
                    currentNotificationCount = newCount;
                });
            }

            // Mute/Unmute sound button
            const muteSoundBtn = document.getElementById('muteSoundBtn');
            const muteIcon = document.getElementById('muteIcon');
            let isMuted = localStorage.getItem('notificationSoundMuted') === 'true';

            // Debug button
            const debugBtn = document.getElementById('debugBtn');
            const debugPanel = document.getElementById('debugPanel');
            if (debugBtn && debugPanel) {
                debugBtn.addEventListener('click', function() {
                    debugPanel.style.display = debugPanel.style.display === 'none' ? 'block' : 'none';
                    updateDebugInfo();
                });
            }

            // Debug panel buttons
            const reconnectSSEBtn = document.getElementById('reconnectSSEBtn');
            const clearDebugBtn = document.getElementById('clearDebugBtn');
            
            if (reconnectSSEBtn) {
                reconnectSSEBtn.addEventListener('click', function() {
                    console.log('Manual SSE reconnection requested');
                    if (eventSource) {
                        eventSource.close();
                    }
                    setTimeout(initializeSSE, 1000);
                });
            }
            
            if (clearDebugBtn) {
                clearDebugBtn.addEventListener('click', function() {
                    const lastSseMessageEl = document.getElementById('lastSseMessage');
                    if (lastSseMessageEl) {
                        lastSseMessageEl.textContent = 'None';
                        lastSseMessageEl.style.color = 'inherit';
                    }
                });
            }

            if (muteSoundBtn && muteIcon) {
                // Set initial state
                if (isMuted) {
                    muteIcon.textContent = 'volume_off';
                    muteSoundBtn.title = 'Unmute Sound';
                } else {
                    muteIcon.textContent = 'volume_up';
                    muteSoundBtn.title = 'Mute Sound';
                }

                muteSoundBtn.addEventListener('click', function() {
                    isMuted = !isMuted;
                    localStorage.setItem('notificationSoundMuted', isMuted);
                    
                    if (isMuted) {
                        muteIcon.textContent = 'volume_off';
                        muteSoundBtn.title = 'Unmute Sound';
                        showToast('Notification sound muted');
                    } else {
                        muteIcon.textContent = 'volume_up';
                        muteSoundBtn.title = 'Mute Sound';
                        showToast('Notification sound enabled');
                    }
                });
            }

            // Initial fetch of notifications
            fetchNotifications();
            checkForDeclinedRequests();
            
            // Check for declined requests periodically
            setInterval(checkForDeclinedRequests, 10000); // Check every 10 seconds

            // Function to update debug information
            function updateDebugInfo() {
                const sseStatusEl = document.getElementById('sseStatus');
                const lastSseMessageEl = document.getElementById('lastSseMessage');
                const debugNotificationCountEl = document.getElementById('debugNotificationCount');
                const debugSoundMutedEl = document.getElementById('debugSoundMuted');
                const debugAudioReadyEl = document.getElementById('debugAudioReady');
                
                if (sseStatusEl) {
                    if (eventSource) {
                        switch(eventSource.readyState) {
                            case 0: // CONNECTING
                                sseStatusEl.textContent = 'Connecting...';
                                sseStatusEl.style.color = 'orange';
                                break;
                            case 1: // OPEN
                                sseStatusEl.textContent = 'Connected';
                                sseStatusEl.style.color = 'green';
                                break;
                            case 2: // CLOSED
                                sseStatusEl.textContent = 'Disconnected';
                                sseStatusEl.style.color = 'red';
                                break;
                            default:
                                sseStatusEl.textContent = 'Unknown';
                                sseStatusEl.style.color = 'gray';
                        }
                    } else {
                        sseStatusEl.textContent = 'Not initialized';
                        sseStatusEl.style.color = 'gray';
                    }
                }
                
                if (debugNotificationCountEl) {
                    debugNotificationCountEl.textContent = currentNotificationCount;
                }
                
                if (debugSoundMutedEl) {
                    debugSoundMutedEl.textContent = isMuted ? 'Yes' : 'No';
                }
                
                if (debugAudioReadyEl) {
                    const audio = document.getElementById('notificationSound');
                    if (audio) {
                        switch(audio.readyState) {
                            case 0: // HAVE_NOTHING
                                debugAudioReadyEl.textContent = 'Loading...';
                                debugAudioReadyEl.style.color = 'orange';
                                break;
                            case 1: // HAVE_METADATA
                                debugAudioReadyEl.textContent = 'Metadata loaded';
                                debugAudioReadyEl.style.color = 'blue';
                                break;
                            case 2: // HAVE_CURRENT_DATA
                                debugAudioReadyEl.textContent = 'Ready';
                                debugAudioReadyEl.style.color = 'green';
                                break;
                            case 3: // HAVE_FUTURE_DATA
                                debugAudioReadyEl.textContent = 'Ready';
                                debugAudioReadyEl.style.color = 'green';
                                break;
                            case 4: // HAVE_ENOUGH_DATA
                                debugAudioReadyEl.textContent = 'Ready';
                                debugAudioReadyEl.style.color = 'green';
                                break;
                            default:
                                debugAudioReadyEl.textContent = 'Unknown';
                                debugAudioReadyEl.style.color = 'gray';
                        }
                    } else {
                        debugAudioReadyEl.textContent = 'Not found';
                        debugAudioReadyEl.style.color = 'red';
                    }
                }

                // Update device type
                const debugDeviceTypeEl = document.getElementById('debugDeviceType');
                if (debugDeviceTypeEl) {
                    const isMobile = /iPhone|iPad|iPod|Android/i.test(navigator.userAgent);
                    debugDeviceTypeEl.textContent = isMobile ? 'Mobile' : 'Desktop';
                    debugDeviceTypeEl.style.color = isMobile ? 'blue' : 'green';
                }

                // Update audio unlocked status
                const debugAudioUnlockedEl = document.getElementById('debugAudioUnlocked');
                if (debugAudioUnlockedEl) {
                    debugAudioUnlockedEl.textContent = audioUnlocked ? 'Yes' : 'No';
                    debugAudioUnlockedEl.style.color = audioUnlocked ? 'green' : 'red';
                }
            }

            // Initialize SSE connection
            initializeSSE();

            // Update debug info every 2 seconds
            setInterval(updateDebugInfo, 2000);

            // Mobile audio unlock on first user interaction
            let audioUnlocked = false;
            function unlockMobileAudio() {
                if (audioUnlocked) return;
                
                try {
                    const audio = document.getElementById('notificationSound');
                    if (audio) {
                        // Try to play and immediately pause to unlock audio
                        const playPromise = audio.play();
                        if (playPromise !== undefined) {
                            playPromise.then(() => {
                                audio.pause();
                                audio.currentTime = 0;
                                audioUnlocked = true;
                                console.log('Mobile audio unlocked successfully');
                            }).catch(error => {
                                console.log('Mobile audio unlock failed:', error);
                            });
                        }
                    }
                } catch (error) {
                    console.log('Mobile audio unlock error:', error);
                }
            }

            // Unlock audio on various user interactions
            document.addEventListener('touchstart', unlockMobileAudio, { once: true });
            document.addEventListener('click', unlockMobileAudio, { once: true });
            document.addEventListener('scroll', unlockMobileAudio, { once: true });

            // Cleanup on page unload
            window.addEventListener('beforeunload', function() {
                if (eventSource) {
                    eventSource.close();
                }
            });
        });
    </script>
    <script>
    function createToast(message) {
        const toast = document.createElement('div');
        toast.style.background = 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)';
        toast.style.color = '#fff';
        toast.style.padding = '16px 20px';
        toast.style.borderRadius = '16px';
        toast.style.boxShadow = '0 8px 32px rgba(102, 126, 234, 0.3), 0 4px 16px rgba(0,0,0,0.1)';
        toast.style.fontSize = '14px';
        toast.style.marginBottom = '12px';
        toast.style.position = 'relative';
        toast.style.minWidth = '200px';
        toast.style.maxWidth = '280px';
        toast.style.width = '100%';
        toast.style.border = '1px solid rgba(255, 255, 255, 0.1)';
        toast.style.backdropFilter = 'blur(10px)';
        toast.style.fontWeight = '500';
        toast.style.lineHeight = '1.4';
        toast.style.transition = 'all 0.3s cubic-bezier(0.4, 0, 0.2, 1)';

        // X button
        const closeBtn = document.createElement('span');
        closeBtn.textContent = '×';
        closeBtn.style.position = 'absolute';
        closeBtn.style.top = '8px';
        closeBtn.style.right = '12px';
        closeBtn.style.cursor = 'pointer';
        closeBtn.style.fontSize = '18px';
        closeBtn.style.fontWeight = 'bold';
        closeBtn.style.opacity = '0.8';
        closeBtn.style.transition = 'opacity 0.2s ease';
        closeBtn.onmouseover = () => closeBtn.style.opacity = '1';
        closeBtn.onmouseout = () => closeBtn.style.opacity = '0.8';
        closeBtn.onclick = () => {
            toast.remove();
        };

        toast.appendChild(closeBtn);

        // Message
        const msg = document.createElement('span');
        msg.textContent = message;
        toast.appendChild(msg);

        return toast;
    }

    function showToastsWithDelay(notifications) {
        const container = document.getElementById('toastContainer');
        container.innerHTML = '';
        let index = 0;

        function showNext() {
            if (index >= notifications.length) return;
            const notification = notifications[index];
            const orderLabel = notification.order_id ? ` #${notification.order_id}` : '';
            const message = `Order${orderLabel} is now On-Ship. Prepare the amount of Total ₱${notification.total_with_delivery}`;
            const toast = createToast(message);
            container.appendChild(toast);
            index++;
            setTimeout(showNext, 600); // 600ms delay between each toast
        }

        if (notifications.length > 0) {
            showNext();
        }
    }

    function fetchAndShowToasts() {
        fetch('<?= $baseURL ?>get_notifications.php')
            .then(response => response.json())
            .then(data => {
                const notifications = data.notifications || [];
                if (notifications.length > 0) {
                    showToastsWithDelay(notifications);
                } else {
                    document.getElementById('toastContainer').innerHTML = '';
                }
            });
    }

    document.addEventListener('DOMContentLoaded', function() {
        fetchAndShowToasts();
    });
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const navLinks = document.querySelectorAll('.bottom-nav a');
            const profileNavBtn = document.getElementById('profileNavBtn');

            profileNavBtn.addEventListener('click', function(e) {
                e.preventDefault(); // Prevent default link behavior
                navLinks.forEach(link => link.classList.remove('active'));
                profileNavBtn.classList.add('active');
                // Optionally, show the profile popup here if needed
                document.getElementById('profilePopup').style.display = 'block';
            });

            // If you want to close the profile popup and remove the active state:
            document.querySelector('.close-profile').addEventListener('click', function() {
                document.getElementById('profilePopup').style.display = 'none';
                profileNavBtn.classList.remove('active');
                // Optionally, set Home as active again:
                // document.querySelector('.bottom-nav a[href$="Mobile-Dashboard.php"]').classList.add('active');
            });
        });
    </script>
    <script>
document.addEventListener('DOMContentLoaded', function() {
    // Profile popup logic
    const navLinks = document.querySelectorAll('.bottom-nav a');
    const profileNavBtn = document.getElementById('profileNavBtn');
    const profilePopup = document.getElementById('profilePopup');
    const closeProfileBtn = document.querySelector('.close-profile');

    // Wishlist popup logic
    const wishlistPopup = document.getElementById('wishlistPopup');
    const openWishlistFromProfile = document.getElementById('openWishlistFromProfile');
    const closeWishlistBtn = document.querySelector('.close-wishlist');

    let wishlistOpenedFromProfile = false;

    // Open profile popup
    if (profileNavBtn && profilePopup && closeProfileBtn) {
        profileNavBtn.addEventListener('click', function(e) {
            e.preventDefault();
            navLinks.forEach(link => link.classList.remove('active'));
            profileNavBtn.classList.add('active');
            profilePopup.style.display = 'block';
        });
        closeProfileBtn.addEventListener('click', function() {
            profilePopup.style.display = 'none';
            profileNavBtn.classList.remove('active');
            // Set Home as active again
            const homeNavBtn = document.querySelector('.bottom-nav a[href$="Mobile-Dashboard.php"]');
            if (homeNavBtn) homeNavBtn.classList.add('active');
        });
    }

    // Open wishlist from profile
    if (openWishlistFromProfile && wishlistPopup && profilePopup) {
        openWishlistFromProfile.addEventListener('click', function(e) {
            e.preventDefault();
            profilePopup.style.display = 'none';
            wishlistPopup.style.display = 'block';
            wishlistOpenedFromProfile = true;
        });
    }

    // Open wishlist from header icon
    const wishlistHeaderBtn = document.querySelector('.wishlist-header-btn');
    if (wishlistHeaderBtn && wishlistPopup) {
        wishlistHeaderBtn.addEventListener('click', function(e) {
            e.preventDefault();
            wishlistPopup.style.display = 'block';
            wishlistOpenedFromProfile = false;
        });
    }

    // Profile picture change functionality
    const profileAvatar = document.getElementById('profileAvatar');
    const profileImageInput = document.getElementById('profileImageInput');
    const profileImage = document.getElementById('profileImage');
    const headerProfileImage = document.querySelector('.header-profile-image');

    if (profileAvatar && profileImageInput) {
        profileAvatar.addEventListener('click', function() {
            profileImageInput.click();
        });

        profileImageInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                // Validate file type
                if (!file.type.startsWith('image/')) {
                    alert('Please select a valid image file.');
                    return;
                }

                // Validate file size (max 5MB)
                if (file.size > 5 * 1024 * 1024) {
                    alert('Image size should be less than 5MB.');
                    return;
                }

                // Create FormData for file upload
                const formData = new FormData();
                formData.append('profile_image', file);

                // Show loading state
                const originalSrc = profileImage.src;
                profileImage.style.opacity = '0.5';
                
                // Upload the image
                fetch('update_profile_picture.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update profile images
                        profileImage.src = data.image_url + '?t=' + Date.now();
                        if (headerProfileImage) {
                            headerProfileImage.src = data.image_url + '?t=' + Date.now();
                        }
                        
                        // Show success message
                        showModernNotification('Profile picture updated successfully!', 'success');
                    } else {
                        showModernNotification('Failed to update profile picture: ' + (data.message || 'Unknown error'), 'error');
                        profileImage.src = originalSrc;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showModernNotification('An error occurred while updating the profile picture.', 'error');
                    profileImage.src = originalSrc;
                })
                .finally(() => {
                    profileImage.style.opacity = '1';
                });
            }
        });
    }

    // Modern Notification Function
    function showModernNotification(message, type = 'info') {
        // Remove any existing notifications
        const existingNotifications = document.querySelectorAll('.modern-notification');
        existingNotifications.forEach(notification => notification.remove());

        // Create notification element
        const notification = document.createElement('div');
        notification.className = `modern-notification modern-notification-${type}`;
        
        // Set icon based on type
        let icon = 'info';
        if (type === 'success') icon = 'check_circle';
        if (type === 'error') icon = 'error';
        if (type === 'warning') icon = 'warning';
        
        notification.innerHTML = `
            <div class="notification-content">
                <div class="notification-icon">
                    <span class="material-icons">${icon}</span>
                </div>
                <div class="notification-message">${message}</div>
                <button class="notification-close" onclick="this.parentElement.parentElement.remove()">
                    <span class="material-icons">close</span>
                </button>
            </div>
            <div class="notification-progress"></div>
        `;

        // Add styles
        const style = document.createElement('style');
        style.textContent = `
            .modern-notification {
                position: fixed;
                top: 20px;
                right: 20px;
                background: white;
                border-radius: 12px;
                box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12);
                z-index: 10000;
                min-width: 300px;
                max-width: 400px;
                overflow: hidden;
                animation: slideInRight 0.4s ease-out;
                border-left: 4px solid #667eea;
            }
            
            .modern-notification-success {
                border-left-color: #4CAF50;
            }
            
            .modern-notification-error {
                border-left-color: #f44336;
            }
            
            .modern-notification-warning {
                border-left-color: #ff9800;
            }
            
            .notification-content {
                display: flex;
                align-items: center;
                padding: 16px 20px;
                gap: 12px;
            }
            
            .notification-icon {
                width: 24px;
                height: 24px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
            }
            
            .modern-notification .notification-icon {
                background: #667eea;
                color: white;
            }
            
            .modern-notification-success .notification-icon {
                background: #4CAF50;
                color: white;
            }
            
            .modern-notification-error .notification-icon {
                background: #f44336;
                color: white;
            }
            
            .modern-notification-warning .notification-icon {
                background: #ff9800;
                color: white;
            }
            
            .notification-icon .material-icons {
                font-size: 16px;
            }
            
            .notification-message {
                flex: 1;
                color: #333;
                font-size: 14px;
                font-weight: 500;
                line-height: 1.4;
            }
            
            .notification-close {
                background: none;
                border: none;
                color: #999;
                cursor: pointer;
                padding: 4px;
                border-radius: 4px;
                transition: all 0.2s ease;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .notification-close:hover {
                background: #f5f5f5;
                color: #666;
            }
            
            .notification-close .material-icons {
                font-size: 18px;
            }
            
            .notification-progress {
                height: 3px;
                background: #667eea;
                width: 100%;
                animation: progressBar 4s linear forwards;
            }
            
            .modern-notification-success .notification-progress {
                background: #4CAF50;
            }
            
            .modern-notification-error .notification-progress {
                background: #f44336;
            }
            
            .modern-notification-warning .notification-progress {
                background: #ff9800;
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
            
            @keyframes progressBar {
                from {
                    width: 100%;
                }
                to {
                    width: 0%;
                }
            }
            
            /* Mobile responsiveness */
            @media (max-width: 768px) {
                .modern-notification {
                    top: 10px;
                    right: 10px;
                    left: 10px;
                    min-width: auto;
                    max-width: none;
                }
                
                .notification-content {
                    padding: 14px 16px;
                    gap: 10px;
                }
                
                .notification-message {
                    font-size: 13px;
                }
                
                .notification-icon {
                    width: 20px;
                    height: 20px;
                }
                
                .notification-icon .material-icons {
                    font-size: 14px;
                }
                
                .notification-close .material-icons {
                    font-size: 16px;
                }
            }
            
            @media (max-width: 480px) {
                .modern-notification {
                    top: 5px;
                    right: 5px;
                    left: 5px;
                }
                
                .notification-content {
                    padding: 12px 14px;
                    gap: 8px;
                }
                
                .notification-message {
                    font-size: 12px;
                }
            }
        `;
        
        // Add styles to head if not already added
        if (!document.querySelector('#modern-notification-styles')) {
            style.id = 'modern-notification-styles';
            document.head.appendChild(style);
        }
        
        // Add notification to body
        document.body.appendChild(notification);
        
        // Auto remove after 4 seconds
        setTimeout(() => {
            if (notification.parentNode) {
                notification.style.animation = 'slideOutRight 0.3s ease-in forwards';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.remove();
                    }
                }, 300);
            }
        }, 4000);
        
        // Add slide out animation
        const slideOutStyle = document.createElement('style');
        slideOutStyle.textContent = `
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
        if (!document.querySelector('#slide-out-styles')) {
            slideOutStyle.id = 'slide-out-styles';
            document.head.appendChild(slideOutStyle);
        }
    }

    // Settings popup logic
    const settingsPopup = document.getElementById('settingsPopup');
    const settingsHeaderBtn = document.querySelector('.settings-header-btn');
    const openSettingsFromProfile = document.getElementById('openSettingsFromProfile');
    const closeSettingsBtn = document.querySelector('.close-settings');
    
    console.log('Settings elements found:', { settingsPopup, closeSettingsBtn });
    
    if (closeSettingsBtn) {
        closeSettingsBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            console.log('Close button clicked');
            if (settingsPopup) {
                settingsPopup.style.display = 'none';
                settingsPopup.classList.remove('active');
                console.log('Settings popup closed');
            }
        });
        
        // Also add a simple onclick as backup
        closeSettingsBtn.onclick = function(e) {
            e.preventDefault();
            e.stopPropagation();
            console.log('Close button onclick triggered');
            if (settingsPopup) {
                settingsPopup.style.display = 'none';
                settingsPopup.classList.remove('active');
                console.log('Settings popup closed via onclick');
            }
        };
    }
    
    if (settingsHeaderBtn && settingsPopup) {
        settingsHeaderBtn.addEventListener('click', function(e) {
            e.preventDefault();
            settingsPopup.style.display = 'flex';
            settingsPopup.classList.add('active');
            loadPinStatus();
        });
    }
    
    // Settings from profile menu
    if (openSettingsFromProfile && settingsPopup) {
        openSettingsFromProfile.addEventListener('click', function(e) {
            e.preventDefault();
            profilePopup.style.display = 'none';
            settingsPopup.style.display = 'flex';
            settingsPopup.classList.add('active');
            loadPinStatus();
        });
    }

    // Close wishlist popup
    if (closeWishlistBtn && wishlistPopup && profilePopup) {
        closeWishlistBtn.addEventListener('click', function() {
            wishlistPopup.style.display = 'none';
            if (wishlistOpenedFromProfile) {
                profilePopup.style.display = 'block';
            }
        });
    }
});
    </script>
    <script>
        // Emergency Report Form Handling
        document.addEventListener('DOMContentLoaded', function() {
            const emergencyReportForm = document.getElementById('emergencyReportForm');
            
            if (emergencyReportForm) {
                emergencyReportForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    // Require pinned coordinates
                    var latVal = (document.getElementById('latitude')?.value || '').trim();
                    var lngVal = (document.getElementById('longitude')?.value || '').trim();
                    if (!latVal || !lngVal) {
                        if (typeof showFancyAlert === 'function') {
                            showFancyAlert('warning', 'Please pin your exact location on the map before submitting.');
                        } else {
                            alert('Please pin your exact location on the map before submitting.');
                        }
                        return;
                    }

                    // Get form data
                    const formData = new FormData(emergencyReportForm);
                    
                    // Submit the form via AJAX
                    fetch('<?= $baseURL ?>submit_help_request.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Show modern success banner/toast instead of native alert
                            if (typeof showFancyAlert === 'function') {
                                showFancyAlert('success', data.message || 'Help request submitted successfully!');
                            } else if (typeof showToast === 'function') {
                                showToast(data.message || 'Help request submitted successfully!');
                            } else {
                                alert(data.message || 'Help request submitted successfully!');
                            }
                            // Close the modal
                            const modal = bootstrap.Modal.getInstance(document.getElementById('emergencyReportModal'));
                            if (modal) {
                                modal.hide();
                            }
                            // Reset the form
                            emergencyReportForm.reset();
                        } else {
                            // Show error message
                            alert('Error: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('An error occurred while submitting your request. Please try again.');
                    });
                });
            }
        });
    </script>
    <!-- Leaflet.js CSS & JS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
<!-- Leaflet Control Geocoder for search box -->
<link rel="stylesheet" href="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.css" />
<script src="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    let leafletMap, leafletMarker;
    const pinBtn = document.getElementById('pinLocationBtn');
    const mapContainer = document.getElementById('mapContainer');
    const latInput = document.getElementById('latitude');
    const lngInput = document.getElementById('longitude');
    const latDisplay = document.getElementById('latDisplay');
    const lngDisplay = document.getElementById('lngDisplay');
    const copyCoordsBtn = document.getElementById('copyCoordsBtn');
    const openInGmapsLink = document.getElementById('openInGmapsLink');
    // Add a warning message element
    let geoWarning = document.createElement('div');
    geoWarning.style.color = 'red';
    geoWarning.style.fontSize = '0.95em';
    geoWarning.style.marginTop = '6px';
    geoWarning.style.display = 'none';
    geoWarning.textContent = 'Could not detect your current location. Drag the marker to your breakdown spot.';
    mapContainer.appendChild(geoWarning);

    pinBtn.addEventListener('click', function(e) {
        e.preventDefault();
        mapContainer.style.display = 'block';
        if (!leafletMap) {
            // Default to Valencia City, Bukidnon (center of city)
            const defaultLat = 7.9061, defaultLng = 125.0897;
            leafletMap = L.map('leafletMap').setView([defaultLat, defaultLng], 15);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(leafletMap);
            leafletMarker = L.marker([defaultLat, defaultLng], {draggable:true}).addTo(leafletMap);
            latInput.value = defaultLat;
            lngInput.value = defaultLng;
            if (latDisplay) latDisplay.value = defaultLat.toFixed(6);
            if (lngDisplay) lngDisplay.value = defaultLng.toFixed(6);
            if (openInGmapsLink) openInGmapsLink.href = `https://www.google.com/maps?q=${defaultLat},${defaultLng}`;
            leafletMarker.on('dragend', function(e) {
                const pos = leafletMarker.getLatLng();
                latInput.value = pos.lat;
                lngInput.value = pos.lng;
                if (latDisplay) latDisplay.value = pos.lat.toFixed(6);
                if (lngDisplay) lngDisplay.value = pos.lng.toFixed(6);
                if (openInGmapsLink) openInGmapsLink.href = `https://www.google.com/maps?q=${pos.lat},${pos.lng}`;
            });
            // Add search box using Leaflet Control Geocoder
            if (typeof L.Control.Geocoder !== 'undefined') {
                const geocoder = L.Control.geocoder({
                    defaultMarkGeocode: false,
                    placeholder: 'Search for a place...'
                })
                .on('markgeocode', function(e) {
                    const center = e.geocode.center;
                    leafletMap.setView(center, 17);
                    leafletMarker.setLatLng(center);
                    latInput.value = center.lat;
                    lngInput.value = center.lng;
                })
                .addTo(leafletMap);
            }
            // Try to use browser geolocation
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(function(pos) {
                    const userLat = pos.coords.latitude, userLng = pos.coords.longitude;
                    leafletMap.setView([userLat, userLng], 16);
                    leafletMarker.setLatLng([userLat, userLng]);
                    latInput.value = userLat;
                    lngInput.value = userLng;
                    if (latDisplay) latDisplay.value = userLat.toFixed(6);
                    if (lngDisplay) lngDisplay.value = userLng.toFixed(6);
                    if (openInGmapsLink) openInGmapsLink.href = `https://www.google.com/maps?q=${userLat},${userLng}`;
                    geoWarning.style.display = 'none';
                }, function(error) {
                    // If user denies or geolocation fails, show warning and keep default
                    geoWarning.style.display = 'block';
                }, {timeout: 5000});
            } else {
                geoWarning.style.display = 'block';
            }
        }
    });

    // Copy coordinates to clipboard for easy sharing
    if (copyCoordsBtn && latDisplay && lngDisplay) {
        copyCoordsBtn.addEventListener('click', function() {
            const text = `${latDisplay.value}, ${lngDisplay.value}`.trim();
            if (!text || text === ',') return;
            navigator.clipboard.writeText(text).then(() => {
                alert('Coordinates copied: ' + text);
            }).catch(() => {
                // Fallback
                const ta = document.createElement('textarea');
                ta.value = text; document.body.appendChild(ta); ta.select();
                try { document.execCommand('copy'); alert('Coordinates copied: ' + text); } catch(e) {}
                document.body.removeChild(ta);
            });
        });
    }
    
    // Handle merged coordinate input for paste from Google Maps
    const mergedCoordsInput = document.getElementById('mergedCoordsInput');
    if (mergedCoordsInput && latDisplay && lngDisplay && latInput && lngInput) {
        mergedCoordsInput.addEventListener('input', function(e) {
            const value = e.target.value.trim();
            if (value === '') return;
            
            // Try to parse "lat,lng" format
            const parts = value.split(',').map(p => p.trim()).filter(p => p !== '');
            
            if (parts.length === 2) {
                const lat = parseFloat(parts[0]);
                const lng = parseFloat(parts[1]);
                
                if (!isNaN(lat) && !isNaN(lng) && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180) {
                    // Update all coordinate displays
                    latDisplay.value = lat.toFixed(6);
                    lngDisplay.value = lng.toFixed(6);
                    latInput.value = lat;
                    lngInput.value = lng;
                    
                    // Update marker on map if it exists
                    if (typeof leafletMap !== 'undefined' && typeof leafletMarker !== 'undefined') {
                        leafletMarker.setLatLng([lat, lng]);
                        leafletMap.setView([lat, lng], 15);
                    }
                    
                    // Update Google Maps link
                    const openInGmapsLink = document.getElementById('openInGmapsLink');
                    if (openInGmapsLink) {
                        openInGmapsLink.href = `https://www.google.com/maps?q=${lat},${lng}`;
                    }
                    
                    // Clear the merged input after parsing
                    setTimeout(() => {
                        e.target.value = '';
                    }, 500);
                }
            }
        });
        
        // Allow paste event
        mergedCoordsInput.addEventListener('paste', function(e) {
            setTimeout(() => {
                const value = e.target.value.trim();
                if (value) {
                    const parts = value.split(',').map(p => p.trim()).filter(p => p !== '');
                    
                    if (parts.length === 2) {
                        const lat = parseFloat(parts[0]);
                        const lng = parseFloat(parts[1]);
                        
                        if (!isNaN(lat) && !isNaN(lng) && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180) {
                            // Update all coordinate displays
                            latDisplay.value = lat.toFixed(6);
                            lngDisplay.value = lng.toFixed(6);
                            latInput.value = lat;
                            lngInput.value = lng;
                            
                            // Update marker on map if it exists
                            if (typeof leafletMap !== 'undefined' && typeof leafletMarker !== 'undefined') {
                                leafletMarker.setLatLng([lat, lng]);
                                leafletMap.setView([lat, lng], 15);
                            }
                            
                            // Update Google Maps link
                            const openInGmapsLink = document.getElementById('openInGmapsLink');
                            if (openInGmapsLink) {
                                openInGmapsLink.href = `https://www.google.com/maps?q=${lat},${lng}`;
                            }
                            
                            // Clear the input
                            e.target.value = '';
                        }
                    }
                }
            }, 10);
        });
    }
});
</script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Tab switching logic for Seek Help modal
    const seekHelpTabBtn = document.getElementById('seekHelpTabBtn');
    const statusTabBtn = document.getElementById('statusTabBtn');
    const seekHelpFormView = document.getElementById('seekHelpFormView');
    const seekHelpStatusView = document.getElementById('seekHelpStatusView');
    if (seekHelpTabBtn && statusTabBtn && seekHelpFormView && seekHelpStatusView) {
        seekHelpTabBtn.addEventListener('click', function() {
            seekHelpTabBtn.classList.add('active');
            seekHelpTabBtn.classList.remove('btn-outline-primary');
            seekHelpTabBtn.classList.add('btn-primary');
            statusTabBtn.classList.remove('active');
            statusTabBtn.classList.remove('btn-primary');
            statusTabBtn.classList.add('btn-outline-secondary');
            seekHelpFormView.style.display = '';
            seekHelpStatusView.style.display = 'none';
        });
        statusTabBtn.addEventListener('click', function() {
            statusTabBtn.classList.add('active');
            statusTabBtn.classList.remove('btn-outline-secondary');
            statusTabBtn.classList.add('btn-primary');
            seekHelpTabBtn.classList.remove('active');
            seekHelpTabBtn.classList.remove('btn-primary');
            seekHelpTabBtn.classList.add('btn-outline-primary');
            seekHelpFormView.style.display = 'none';
            seekHelpStatusView.style.display = '';
                        // Fetch latest help request via AJAX
                        fetch('get_latest_help_request.php')
                            .then(response => response.json())
                            .then(data => {
                                if (data.success && data.request) {
                                    let html = '<div id="statusContent"><table class="table table-bordered">';
                                    
                                    // Status with special styling for declined requests
                                    if (data.request.status === 'Declined') {
                                        html += `<tr><td style=\"font-weight:bold;\">Status:</td><td><span class=\"badge bg-danger\">${data.request.status}</span></td></tr>`;
                                    } else {
                                        html += `<tr><td style=\"font-weight:bold;\">Status:</td><td>${data.request.status}</td></tr>`;
                                    }
                                    
                                    html += `<tr><td style=\"font-weight:bold;\">Bike:</td><td>${data.request.bike_unit}</td></tr>`;
                                    html += `<tr><td style=\"font-weight:bold;\">Problem:</td><td>${data.request.problem_description}</td></tr>`;
                                    html += `<tr><td style=\"font-weight:bold;\">Barangay (Breakdown Location):</td><td>${data.request.barangay_name}</td></tr>`;
                                    html += `<tr><td style=\"font-weight:bold;\">Your Location / Landmark:</td><td>${data.request.location}</td></tr>`;
                                    
                                    // Show decline reason if request was declined
                                    if (data.request.status === 'Declined' && data.request.decline_reason_text) {
                                        html += `<tr><td style=\"font-weight:bold; color: #dc3545;\">Decline Reason:</td><td style=\"color: #dc3545;\">${data.request.decline_reason_text}</td></tr>`;
                                        if (data.request.declined_at) {
                                            const declinedDate = new Date(data.request.declined_at).toLocaleString();
                                            html += `<tr><td style=\"font-weight:bold;\">Declined At:</td><td>${declinedDate}</td></tr>`;
                                        }
                                        html += '';
                                    }
                                    
                                    if (data.request.mechanic_id) {
                            html += `<tr><td style=\"font-weight:bold;\">Mechanic:</td><td><div class=\"mechanic-details-scroll\">`;
                            if (data.request.mechanic_name) {
                                html += `${data.request.mechanic_name}<br>`;
                            }
                            if (data.request.mechanic_image) {
                                html += `<img src='${data.request.mechanic_image}' alt='Mechanic' style='width:48px;height:48px;border-radius:50%;object-fit:cover;margin-top:4px;'><br>`;
                            }
                            if (data.request.mechanic_phone) {
                                html += `<span style=\"font-size:0.97em;\"><b>Phone:</b> <a href='tel:${data.request.mechanic_phone}'>${data.request.mechanic_phone}</a></span><br>`;
                            }
                            if (data.request.mechanic_email) {
                                html += `<span style=\"font-size:0.97em;\"><b>Email:</b> <a href='mailto:${data.request.mechanic_email}'>${data.request.mechanic_email}</a></span><br>`;
                            }
                            if (data.request.mechanic_home_address) {
                                html += `<span style=\"font-size:0.97em;\"><b>Address:</b> ${data.request.mechanic_home_address}</span><br>`;
                            }
                            if (data.request.mechanic_plate) {
                                html += `<span style=\"font-size:0.97em;\"><b>Plate Number:</b> ${data.request.mechanic_plate}</span><br>`;
                            }
                            if (data.request.mechanic_motor_type) {
                                html += `<span style=\"font-size:0.97em;\"><b>Motor Type:</b> ${data.request.mechanic_motor_type}</span><br>`;
                            }
                            if (data.request.mechanic_specialization) {
                                html += `<span style=\"font-size:0.97em;\"><b>Specialization:</b> ${data.request.mechanic_specialization}</span>`;
                            }
                            html += `</div></td></tr>`;
                        } else {
                            html += `<tr><td style=\"font-weight:bold;\">Mechanic:</td><td><em>Not assigned yet</em></td></tr>`;
                        }
                        html += '</table>';
                        // If declined, add an OK button below the table to delete/acknowledge
                        if (data.request.status === 'Declined') {
                            html += '<div class="d-grid"><button id="ackDeclinedBtn" class="btn btn-primary">OK</button></div>';
                        }
                        html += '</div>';
                        document.getElementById('seekHelpStatusView').innerHTML = html;
                        // Wire up acknowledgment button if present
                        const ackBtn = document.getElementById('ackDeclinedBtn');
                        if (ackBtn) {
                            ackBtn.addEventListener('click', function(){
                                fetch('delete_declined_help_request.php', { method: 'POST' })
                                    .then(r => r.json())
                                    .then(res => {
                                        if (res.success) {
                                            document.getElementById('seekHelpStatusView').innerHTML = '<div class="alert alert-success">Declined request removed.</div>';
                                            // Clear any cached declined IDs so banner/sound won\'t repeat
                                            try { sessionStorage.removeItem('declinedRequests'); } catch(e) {}
                                            // Optionally switch back to Seek Help tab
                                            if (typeof seekHelpTabBtn !== 'undefined') { seekHelpTabBtn.click(); }
                                        } else {
                                            document.getElementById('seekHelpStatusView').innerHTML += '<div class="alert alert-danger mt-2">'+(res.message||'Failed to remove request')+'</div>';
                                        }
                                    })
                                    .catch(() => {
                                        document.getElementById('seekHelpStatusView').innerHTML += '<div class="alert alert-danger mt-2">Failed to remove request.</div>';
                                    });
                            });
                        }
                    } else {
                        document.getElementById('seekHelpStatusView').innerHTML = '<div class="alert alert-info">No recent help request found.</div>';
                    }
                })
                .catch(() => {
                    document.getElementById('seekHelpStatusView').innerHTML = '<div class="alert alert-danger">Failed to load request status.</div>';
                });
        });
    }
});

// Fancy Alert Function for Mobile Dashboard
function showFancyAlert(type, message) {
    const existing = document.getElementById('md-toast');
    if (existing) existing.remove();
    
    const colors = { 
        success: '#198754', 
        warning: '#ffc107', 
        error: '#dc3545', 
        info: '#0d6efd' 
    };
    
    const toast = document.createElement('div');
    toast.id = 'md-toast';
    toast.style.position = 'fixed';
    toast.style.top = '70px';
    toast.style.left = '50%';
    toast.style.transform = 'translateX(-50%)';
    toast.style.background = colors[type] || '#333';
    toast.style.color = '#fff';
    toast.style.padding = '12px 16px';
    toast.style.borderRadius = '8px';
    toast.style.boxShadow = '0 4px 12px rgba(0,0,0,0.3)';
    toast.style.fontSize = '14px';
    toast.style.zIndex = '9999';
    toast.style.maxWidth = '300px';
    toast.style.width = '280px';
    toast.style.wordWrap = 'break-word';
    toast.style.textAlign = 'center';
    toast.textContent = message;
    
    document.body.appendChild(toast);
    
    // Auto remove after 4 seconds
    setTimeout(() => {
        if (toast.parentNode) {
            toast.style.transition = 'opacity 0.3s ease';
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 300);
        }
    }, 4000);
}

// PIN Management Functions
function loadPinStatus() {
    fetch('pin_management.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_status'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const pinToggle = document.getElementById('pinToggle');
            const pinSetupSection = document.getElementById('pinSetupSection');
            const pinStatus = document.getElementById('pinStatus');
            
            pinToggle.checked = data.pin_enabled;
            
            if (data.pin_enabled) {
                pinSetupSection.style.display = 'none';
                pinStatus.innerHTML = '<span class="material-icons">lock</span><span>PIN Protection Active</span>';
            } else {
                pinSetupSection.style.display = 'block';
                pinStatus.innerHTML = '<span class="material-icons">lock_open</span><span>PIN Protection Disabled</span>';
            }
        }
    })
    .catch(error => console.error('Error loading PIN status:', error));
}

// PIN Toggle Handler
document.addEventListener('DOMContentLoaded', function() {
    const pinToggle = document.getElementById('pinToggle');
    const pinSetupSection = document.getElementById('pinSetupSection');
    const savePinBtn = document.getElementById('savePinBtn');
    const pinVerificationModal = new bootstrap.Modal(document.getElementById('pinVerificationModal'));
    const togglePinInput = document.getElementById('togglePinInput');
    const confirmDisablePinBtn = document.getElementById('confirmDisablePinBtn');
    
    if (pinToggle) {
        pinToggle.addEventListener('change', function() {
            const enabled = this.checked;
            
            // If enabling PIN protection, check if PIN is set first
            if (enabled) {
                // First check if PIN is already set
                fetch('pin_management.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=get_status'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Check if PIN exists in database
                        fetch('pin_management.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `action=toggle_pin&enabled=${enabled}`
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                pinSetupSection.style.display = 'none'; // Hide setup section since PIN is already set
                                loadPinStatus();
                                showFancyAlert('success', data.message);
                            } else {
                                pinToggle.checked = false; // Revert toggle
                                showFancyAlert('error', data.message);
                                // If PIN doesn't exist, show the setup section
                                pinSetupSection.style.display = 'block';
                            }
                        })
                        .catch(error => {
                            pinToggle.checked = false; // Revert toggle
                            showFancyAlert('error', 'Network error');
                        });
                    }
                })
                .catch(error => {
                    pinToggle.checked = false; // Revert toggle
                    showFancyAlert('error', 'Network error');
                });
            } else {
                // If disabling PIN protection, show verification modal
                pinToggle.checked = true; // Revert toggle first
                togglePinInput.value = ''; // Clear input
                pinVerificationModal.show();
                setTimeout(() => togglePinInput.focus(), 300); // Focus after modal animation
            }
        });
    }
    
    // Handle PIN verification for disabling protection
    if (confirmDisablePinBtn) {
        confirmDisablePinBtn.addEventListener('click', function() {
            const pin = togglePinInput.value;
            
            if (pin.length !== 4 || !/^\d{4}$/.test(pin)) {
                showFancyAlert('error', 'Please enter a valid 4-digit PIN');
                togglePinInput.focus();
                return;
            }
            
            // Disable button to prevent double clicks
            confirmDisablePinBtn.disabled = true;
            confirmDisablePinBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Verifying...';
            
            fetch('pin_management.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=secure_toggle_pin&enabled=false&pin=${pin}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    pinVerificationModal.hide();
                    pinToggle.checked = false; // Update toggle state
                    pinSetupSection.style.display = 'none';
                    document.getElementById('pinInput').value = '';
                    document.getElementById('pinConfirm').value = '';
                    loadPinStatus();
                    showFancyAlert('success', data.message);
                } else {
                    showFancyAlert('error', data.message);
                    togglePinInput.focus();
                }
            })
            .catch(error => {
                showFancyAlert('error', 'Network error');
                togglePinInput.focus();
            })
            .finally(() => {
                // Re-enable button
                confirmDisablePinBtn.disabled = false;
                confirmDisablePinBtn.innerHTML = '<span class="material-icons" style="vertical-align: middle; margin-right: 4px;">lock_open</span>Disable PIN Protection';
            });
        });
    }
    
    // Handle Enter key in PIN input
    if (togglePinInput) {
        togglePinInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                confirmDisablePinBtn.click();
            }
        });
    }
    
    // Handle Forgot PIN button
    const forgotPinBtn = document.getElementById('forgotPinBtn');
    if (forgotPinBtn) {
        forgotPinBtn.addEventListener('click', function() {
            // Disable button to prevent double clicks
            forgotPinBtn.disabled = true;
            forgotPinBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Sending...';
            
            fetch('pin_management.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=send_pin_email'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showFancyAlert('success', data.message);
                    // Close the modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('pinVerificationModal'));
                    modal.hide();
                } else {
                    showFancyAlert('error', data.message);
                }
            })
            .catch(error => {
                showFancyAlert('error', 'Network error. Please try again.');
            })
            .finally(() => {
                // Re-enable button
                forgotPinBtn.disabled = false;
                forgotPinBtn.innerHTML = '<span class="material-icons" style="vertical-align: middle; margin-right: 4px;">email</span>Forgot PIN code?';
            });
        });
    }
    
    // Save PIN Handler
    if (savePinBtn) {
        savePinBtn.addEventListener('click', function() {
            const pin = document.getElementById('pinInput').value;
            const confirmPin = document.getElementById('pinConfirm').value;
            
            // Check if PIN is empty or not 4 digits
            if (!pin || pin.length !== 4 || !/^\d{4}$/.test(pin)) {
                showFancyAlert('error', 'PIN must be exactly 4 digits');
                document.getElementById('pinInput').focus();
                return;
            }
            
            // Check if confirmation PIN is empty or not 4 digits
            if (!confirmPin || confirmPin.length !== 4 || !/^\d{4}$/.test(confirmPin)) {
                showFancyAlert('error', 'PIN confirmation must be exactly 4 digits');
                document.getElementById('pinConfirm').focus();
                return;
            }
            
            if (pin !== confirmPin) {
                showFancyAlert('error', 'PIN confirmation does not match');
                document.getElementById('pinConfirm').focus();
                return;
            }
            
            // Disable button during save
            savePinBtn.disabled = true;
            savePinBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';
            
            fetch('pin_management.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=set_pin&pin=${pin}&confirm_pin=${confirmPin}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showFancyAlert('success', data.message);
                    document.getElementById('pinInput').value = '';
                    document.getElementById('pinConfirm').value = '';
                    loadPinStatus();
                    // Auto-enable PIN protection after successful PIN setup
                    setTimeout(() => {
                        document.getElementById('pinToggle').checked = true;
                        pinSetupSection.style.display = 'none';
                    }, 1000);
                } else {
                    showFancyAlert('error', data.message);
                }
            })
            .catch(error => {
                showFancyAlert('error', 'Network error');
            })
            .finally(() => {
                // Re-enable button
                savePinBtn.disabled = false;
                savePinBtn.innerHTML = 'Save PIN';
            });
        });
    }
});
</script>

    <!-- Toast Container -->
    <div id="toastContainer" style="position: fixed; top: 20px; right: 20px; z-index: 9999; width: 300px; max-width: 90vw;"></div>

    <!-- Audio element for notification sound -->
    <audio id="notificationSound" preload="auto" playsinline webkit-playsinline>
        <source src="<?= $baseURL ?>uploads/UserNotification.mp3" type="audio/mpeg">
        Your browser does not support the audio element.
    </audio>
    
    <!-- Mobile audio unlock button (hidden by default) -->
    <button id="mobileAudioUnlock" style="display: none; position: fixed; top: -100px; left: -100px; width: 1px; height: 1px; opacity: 0; pointer-events: none;">Unlock Audio</button>

    <script>
    // Real-time stock and rating updates via SSE
    document.addEventListener('DOMContentLoaded', function() {
        try {
            const es = new EventSource('<?= $baseURL ?>sse_products.php');

            function updateProductCard(prod) {
                const detailsBtn = document.querySelector('.details-btn[data-product-id="' + prod.ProductID + '"]');
                const card = detailsBtn ? detailsBtn.closest('.product-card') : null;
                if (!card) return;
                // Update stock text and button state
                const stockDiv = card.querySelector('.stock');
                if (stockDiv) {
                    stockDiv.innerHTML = (parseInt(prod.Quantity) > 0) ? ('Stock: ' + prod.Quantity) : ("<span class='text-danger'>Out of Stock</span>");
                }
                const addBtn = card.querySelector('.add-to-cart-btn');
                if (addBtn) {
                    if (parseInt(prod.Quantity) > 0) { addBtn.removeAttribute('disabled'); } else { addBtn.setAttribute('disabled', ''); }
                }
                // Out of stock stamp overlay
                const imgContainer = card.querySelector('.product-image-container');
                if (imgContainer) {
                    let stamp = imgContainer.querySelector('.out-of-stock-stamp');
                    if (parseInt(prod.Quantity) === 0) {
                        if (!stamp) {
                            stamp = document.createElement('img');
                            stamp.className = 'out-of-stock-stamp';
                            stamp.src = '<?= $baseURL ?>uploads/out-of-stock.png';
                            imgContainer.appendChild(stamp);
                        }
                    } else if (stamp) {
                        stamp.remove();
                    }
                }
                // Update rating UI
                const ratingWrap = card.querySelector('.modern-rating');
                if (ratingWrap) {
                    const valueEl = ratingWrap.querySelector('.rating-value');
                    const starsEl = ratingWrap.querySelector('.stars');
                    const countEl = ratingWrap.querySelector('.rating-count');
                    const avg = parseFloat(prod.average_rating || 0);
                    const count = parseInt(prod.rating_count || 0);
                    if (valueEl) valueEl.textContent = (count > 0 ? avg : 0).toFixed(1);
                    if (countEl) countEl.textContent = '(' + count + ')';
                    if (starsEl) {
                        let full = Math.floor(avg + 0.0001);
                        let half = (avg - full) >= 0.25 && (avg - full) < 0.75 ? 1 : 0;
                        full = (avg - full) >= 0.75 ? full + 1 : full;
                        const empty = Math.max(0, 5 - full - half);
                        let html = '';
                        for (let i = 0; i < full; i++) html += '<i class="fas fa-star"></i>';
                        if (half) html += '<i class="fas fa-star-half-alt"></i>';
                        for (let i = 0; i < empty; i++) html += '<i class="far fa-star"></i>';
                        starsEl.innerHTML = html;
                    }
                }
            }

            es.onmessage = function(ev) {
                try {
                    const data = JSON.parse(ev.data);
                    if (data.type === 'products_snapshot') {
                        (data.products || []).forEach(updateProductCard);
                    } else if (data.type === 'products_update') {
                        (data.changes || []).forEach(updateProductCard);
                    }
                } catch (e) { console.log('SSE parse error', e); }
            };

            es.onerror = function() {
                console.log('SSE products connection error - attempting reconnection');
                try { es.close(); } catch (e) {}
                // Reconnect after 10 seconds instead of refreshing the whole page
                setTimeout(function(){ 
                    try {
                        const newEs = new EventSource('<?= $baseURL ?>sse_products.php');
                        newEs.onmessage = es.onmessage;
                        newEs.onerror = es.onerror;
                        es = newEs;
                    } catch (e) {
                        console.log('SSE reconnection failed', e);
                    }
                }, 10000);
            };
        } catch (e) { console.log('SSE init failed', e); }
    });
    </script>

    <!-- Install App Banner -->
    <div id="installAppBanner" class="install-app-banner">
        <div class="banner-content">
            <div class="app-icon">
                <i class="fas fa-mobile-alt"></i>
            </div>
            <div class="banner-text">
                <h4>Install CJ PowerHouse App</h4>
                <p>Install this app on your device for a better experience and quick access.</p>
                <div class="banner-actions">
                    <button class="install-btn" onclick="installApp()">
                        <i class="fas fa-download"></i>
                        Install
                    </button>
                    <button class="later-btn" onclick="hideBanner()">Maybe Later</button>
                    <button class="dont-show-btn" onclick="dontShowAgain()">Don't Show Again</button>
                </div>
            </div>
        </div>
        <button class="close-btn" onclick="hideBanner()">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <style>
        .install-app-banner {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%) translateY(100%);
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            z-index: 1000;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            max-width: 500px;
            width: calc(100% - 40px);
            margin: 0 auto;
            height: auto;
            min-height: auto;
        }

        .install-app-banner.show {
            transform: translateX(-50%) translateY(0);
        }

        .banner-content {
            display: flex;
            align-items: flex-start;
            width: 100%;
            gap: 16px;
        }

        .app-icon {
            width: 48px;
            height: 48px;
            background: #007bff;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            flex-shrink: 0;
            margin-top: 4px;
        }

        .banner-text {
            flex: 1;
            min-width: 0;
        }

        .banner-text h4 {
            margin: 0 0 6px 0;
            font-size: 16px;
            font-weight: 700;
            color: #333;
        }

        .banner-text p {
            margin: 0 0 12px 0;
            font-size: 13px;
            color: #666;
            line-height: 1.4;
        }

        .banner-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .install-btn {
            background: #007bff;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 10px 16px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s ease;
        }

        .install-btn:hover {
            background: #0056b3;
            transform: translateY(-1px);
        }

        .later-btn {
            background: white;
            color: #666;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 10px 16px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .later-btn:hover {
            background: #f8f9fa;
            border-color: #ccc;
        }

        .dont-show-btn {
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 10px 16px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .dont-show-btn:hover {
            background: #5a6268;
        }

        .close-btn {
            position: absolute;
            top: 12px;
            right: 12px;
            background: none;
            border: none;
            color: #999;
            font-size: 16px;
            cursor: pointer;
            padding: 6px;
            border-radius: 8px;
            transition: all 0.3s ease;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .close-btn:hover {
            background: #f8f9fa;
            color: #666;
        }

        @media (max-width: 768px) {
            .install-app-banner {
                bottom: 16px;
                width: calc(100% - 32px);
                padding: 16px;
            }
            
            .banner-content {
                gap: 12px;
            }

            .app-icon {
                width: 44px;
                height: 44px;
                font-size: 18px;
            }

            .banner-text h4 {
                font-size: 15px;
            }

            .banner-text p {
                font-size: 12px;
            }

            .install-btn, .later-btn, .dont-show-btn {
                padding: 8px 12px;
                font-size: 12px;
            }

            .banner-actions {
                gap: 6px;
            }
        }

        @media (max-width: 480px) {
            .install-app-banner {
                bottom: 12px;
                width: calc(100% - 24px);
                padding: 12px;
            }
            
            .banner-actions {
                flex-direction: column;
                gap: 6px;
            }
            
            .install-btn, .later-btn, .dont-show-btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>

    <script>
        // Show banner only after user scrolls or after 10 seconds
        let bannerShown = false;
        
        function showBannerIfNeeded() {
            if (bannerShown) return;
            const banner = document.getElementById('installAppBanner');
            if (banner && !localStorage.getItem('appBannerPermanentlyDismissed')) {
                banner.classList.add('show');
                bannerShown = true;
            }
        }
        
        // Show after 10 seconds OR when user scrolls
        setTimeout(showBannerIfNeeded, 10000);
        
        // Show when user scrolls down
        let scrollTimeout;
        window.addEventListener('scroll', () => {
            if (window.scrollY > 100) {
                clearTimeout(scrollTimeout);
                scrollTimeout = setTimeout(showBannerIfNeeded, 1000);
            }
        });

        function installApp() {
            // Check if PWA is already installed
            if (window.matchMedia('(display-mode: standalone)').matches) {
                alert('App is already installed!');
                hideBanner();
                return;
            }

            // Check if it's iOS
            if (/iPad|iPhone|iPod/.test(navigator.userAgent)) {
                alert('To install this app on iOS:\n1. Tap the Share button at the bottom\n2. Scroll down and tap "Add to Home Screen"\n3. Tap "Add" in the top right');
            } else if (/Android/.test(navigator.userAgent)) {
                // For Android - trigger PWA install prompt
                if (window.deferredPrompt) {
                    window.deferredPrompt.prompt();
                    window.deferredPrompt.userChoice.then((choiceResult) => {
                        if (choiceResult.outcome === 'accepted') {
                            console.log('User accepted the install prompt');
                            alert('App installed successfully!');
                        } else {
                            console.log('User dismissed the install prompt');
                        }
                        window.deferredPrompt = null;
                    });
                } else {
                    alert('App installation started! Follow your browser\'s prompts to complete the installation.');
                }
            } else {
                // For desktop browsers - trigger PWA install prompt
                if (window.deferredPrompt) {
                    window.deferredPrompt.prompt();
                    window.deferredPrompt.userChoice.then((choiceResult) => {
                        if (choiceResult.outcome === 'accepted') {
                            console.log('User accepted the install prompt');
                            alert('App installed successfully! Check your desktop or start menu.');
                        } else {
                            console.log('User dismissed the install prompt');
                        }
                        window.deferredPrompt = null;
                    });
                } else {
                    alert('App installation started! Look for the install icon in your browser\'s address bar.');
                }
            }
            hideBanner();
        }

        function hideBanner() {
            const banner = document.getElementById('installAppBanner');
            banner.classList.remove('show');
            // Only hide temporarily, don't set permanent dismissal
        }

        function dontShowAgain() {
            const banner = document.getElementById('installAppBanner');
            banner.classList.remove('show');
            // Set permanent dismissal
            localStorage.setItem('appBannerPermanentlyDismissed', 'true');
        }

        // Register service worker
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('<?= $baseURL ?>sw.js')
                    .then((registration) => {
                        console.log('SW registered: ', registration);
                    })
                    .catch((registrationError) => {
                        console.log('SW registration failed: ', registrationError);
                    });
            });
        }

        // Listen for PWA install prompt
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            window.deferredPrompt = e;
            console.log('PWA install prompt available');
        });

        // Listen for app installed event
        window.addEventListener('appinstalled', (evt) => {
            console.log('PWA was installed');
            alert('App installed successfully!');
        });
    </script>
</body>
</html>
</body>
</html>
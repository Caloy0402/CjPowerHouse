<?php
session_start();
require_once 'dbconn.php';

// Dynamically determine the base URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$path = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$baseURL = $protocol . '://' . $host . $path . '/';

// Check if the user is logged in and has the 'Mechanic' role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Mechanic') {
    header("Location: {$baseURL}signin.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$mechanic_id = $user_id; // Alias for clarity
$message = '';
$error = '';

// --- ACTION HANDLING (POST for updates, GET for navigation) ---

// Handle POST requests for updating status (Complete, Cancel)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($request_id > 0 && ($action === 'complete' || $action === 'cancel')) {
        $new_status = ($action === 'complete') ? 'Completed' : 'Cancelled';
        
        $update_sql = "UPDATE help_requests SET status = ?, updated_at = NOW() WHERE id = ? AND mechanic_id = ?";
        $stmt = $conn->prepare($update_sql);
        if ($stmt) {
            $stmt->bind_param("sii", $new_status, $request_id, $mechanic_id);
            if ($stmt->execute()) {
                $_SESSION['message'] = "Request #" . $request_id . " has been marked as " . $new_status . ".";
            } else {
                $_SESSION['error'] = "Error updating request: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $_SESSION['error'] = "Error preparing update statement: " . $conn->error;
        }
        // Redirect to the main transaction page to show the updated list
        header("Location: {$baseURL}Mechanic-Transaction.php");
        exit();
    }
}

// Handle GET requests (Accepting a new request from dashboard, or Viewing details)
$action = isset($_GET['action']) ? $_GET['action'] : '';
$request_id = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;

// Action: Accept a request from the dashboard
if ($action === 'accept' && $request_id > 0) {
    $update_sql = "UPDATE help_requests SET status = 'In Progress', mechanic_id = ?, accepted_at = NOW() WHERE id = ? AND status = 'Pending'";
    $stmt = $conn->prepare($update_sql);
    if ($stmt) {
        $stmt->bind_param("ii", $mechanic_id, $request_id);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $_SESSION['message'] = "Request #" . $request_id . " accepted! It is now in your active list.";
        } else {
            $_SESSION['error'] = "Could not accept request. It might have been taken by another mechanic.";
        }
        $stmt->close();
    } else {
        $_SESSION['error'] = "Error preparing accept statement: " . $conn->error;
    }
    // Redirect to the transaction page to see the newly added active request
    header("Location: {$baseURL}Mechanic-Transaction.php");
    exit();
}

// Check for session messages
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}

// --- DATA FETCHING for page display ---

$request_details = null;
$active_requests = [];

// Action: View a specific request's details
if ($action === 'view' && $request_id > 0) {
    $sql_request = "SELECT hr.*, u.first_name AS requestor_first_name, u.last_name AS requestor_last_name, u.phone_number, u.purok, u.ImagePath AS requestor_image, bb.barangay_name AS breakdown_barangay
                    FROM help_requests hr
                    JOIN users u ON hr.user_id = u.id
                    LEFT JOIN barangays bb ON hr.breakdown_barangay_id = bb.id
                    WHERE hr.id = ? AND hr.mechanic_id = ?";
    $stmt = $conn->prepare($sql_request);
    if ($stmt) {
        $stmt->bind_param("ii", $request_id, $mechanic_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $request_details = $result->fetch_assoc();
        $stmt->close();
    }
} else {
    // Default View: Fetch all active ('In Progress') requests for this mechanic
    $sql_active = "SELECT hr.*, u.first_name AS requestor_first_name, u.last_name AS requestor_last_name
                   FROM help_requests hr
                   JOIN users u ON hr.user_id = u.id
                   WHERE hr.mechanic_id = ? AND hr.status = 'In Progress'
                   ORDER BY hr.accepted_at DESC";
    $stmt = $conn->prepare($sql_active);
    if ($stmt) {
        $stmt->bind_param("i", $mechanic_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $active_requests[] = $row;
        }
        $stmt->close();
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mechanic Transactions</title>
    <link rel="icon" type="image/png" href="<?= $baseURL ?>image/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= $baseURL ?>css/mechanic-responsive.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
        :root {
            --primary-color: #530707;
            --accent-color: #ffc107;
            --light-gray: #f4f5f7;
            --dark-text: #333;
            --green: #28a745;
            --red: #dc3545;
        }
        body, html {
            margin: 0; padding: 0; background-color: var(--light-gray);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            padding-bottom: 85px; /* Crucial: Make space for bottom nav */
        }
        .page-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, #8B0000 50%, #A52A2A 100%);
            color: white; 
            padding: 30px 20px;
            text-align: center; 
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(83, 7, 7, 0.3);
        }
        
        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="white" opacity="0.1"/><circle cx="75" cy="75" r="1" fill="white" opacity="0.1"/><circle cx="50" cy="10" r="0.5" fill="white" opacity="0.1"/><circle cx="10" cy="60" r="0.5" fill="white" opacity="0.1"/><circle cx="90" cy="40" r="0.5" fill="white" opacity="0.1"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            opacity: 0.3;
        }
        
        .page-header-content {
            position: relative;
            z-index: 2;
        }
        
        .page-title { 
            font-size: 2.2em; 
            font-weight: 700; 
            margin: 0 0 8px 0;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
            letter-spacing: -0.5px;
        }
        
        .page-subtitle { 
            font-size: 1.1em; 
            opacity: 0.95; 
            margin: 0;
            font-weight: 300;
            letter-spacing: 0.3px;
        }
        
        .header-icon {
            font-size: 2.5em;
            margin-bottom: 10px;
            display: block;
            opacity: 0.9;
        }
        
        /* Responsive banner adjustments */
        @media (max-width: 576px) {
            .page-header {
                padding: 25px 15px;
                margin-bottom: 1.5rem;
            }
            .page-title {
                font-size: 1.8em;
            }
            .page-subtitle {
                font-size: 1em;
            }
            .header-icon {
                font-size: 2.2em;
            }
        }
        
        @media (min-width: 768px) {
            .page-header {
                padding: 40px 30px;
            }
            .page-title {
                font-size: 2.5em;
            }
            .page-subtitle {
                font-size: 1.2em;
            }
            .header-icon {
                font-size: 3em;
            }
        }
        .container { 
            max-width: 100%; 
            margin: 0 auto; 
            padding: 0 1rem; 
        }
        
        /* Responsive container */
        @media (min-width: 576px) {
            .container { max-width: 540px; }
        }
        
        @media (min-width: 768px) {
            .container { max-width: 720px; }
        }
        
        @media (min-width: 992px) {
            .container { max-width: 960px; }
        }
        
        @media (min-width: 1200px) {
            .container { max-width: 1140px; }
        }
        
        .alert { padding: 1rem; margin-bottom: 1rem; border-radius: 8px; border: 1px solid transparent; }
        .alert-success { color: #0f5132; background-color: #d1e7dd; border-color: #badbcc; }
        .alert-danger { color: #842029; background-color: #f8d7da; border-color: #f5c2c7; }

        .request-card {
            background-color: #fff; 
            border: 1px solid #e0e0e0;
            border-radius: 10px; 
            padding: 15px; 
            margin-bottom: 1rem;
            box-shadow: 0 2px 5px rgba(0,0,0,0.07);
        }
        
        /* Responsive request cards */
        @media (min-width: 768px) {
            .request-card {
                padding: 20px;
            }
        }
        .request-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .request-title { font-weight: bold; color: var(--dark-text); font-size: 1.2em; }
        .request-table { width: 100%; }
        .request-table td { padding: 4px 0; }
        .request-table td:first-child { font-weight: bold; width: 120px; }
        .btn-action {
            display: inline-flex; 
            align-items: center; 
            justify-content: center;
            text-decoration: none; 
            color: white; 
            border: none; 
            padding: 10px 18px;
            border-radius: 6px; 
            cursor: pointer; 
            font-size: 0.9rem;
            transition: background-color 0.2s;
            min-width: 120px;
        }
        
        /* Responsive buttons */
        @media (max-width: 576px) {
            .btn-action {
                width: 100%;
                margin-bottom: 8px;
                padding: 12px 18px;
            }
        }
        
        @media (min-width: 768px) {
            .btn-action {
                padding: 12px 20px;
                font-size: 1rem;
            }
        }
        .btn-view { background-color: #007bff; }
        .btn-complete { background-color: var(--green); }
        .btn-cancel { background-color: var(--red); }
        .btn-back { background-color: #6c757d; }
        .btn-action .material-icons { font-size: 16px; margin-right: 6px; }

        .map-container { 
            height: 300px; 
            width: 100%; 
            border-radius: 8px; 
            overflow: hidden; 
            margin: 1.5rem 0; 
            background-color: #e9e9e9; 
        }
        
        /* Responsive map container */
        @media (max-width: 576px) {
            .map-container {
                height: 250px;
                margin: 1rem 0;
            }
        }
        
        @media (min-width: 768px) {
            .map-container {
                height: 400px;
            }
        }
        
        @media (min-width: 992px) {
            .map-container {
                height: 500px;
            }
        }
        .empty-state { text-align: center; padding: 50px 20px; color: #666; }
        .empty-state .material-icons { font-size: 48px; margin-bottom: 1rem; }

        /* FIXED: Correct styles for the bottom navigation bar */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--primary-color);
            display: flex;
            justify-content: space-around;
            padding: 8px 5px;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.15);
            z-index: 1000;
        }
        
        .bottom-nav a {
            text-decoration: none;
            color: #ddd;
            display: flex;
            flex-direction: column;
            align-items: center;
            font-size: 0.75rem;
            flex: 1;
            text-align: center;
            padding: 8px 4px;
            transition: color 0.3s ease;
        }
        
        .bottom-nav a.active {
            color: var(--accent-color);
        }
        
        .bottom-nav .material-icons {
            font-size: 1.6rem;
            margin-bottom: 2px;
        }
        
        /* Responsive bottom navigation */
        @media (max-width: 576px) {
            .bottom-nav {
                padding: 6px 2px;
            }
            .bottom-nav a {
                font-size: 0.7rem;
                padding: 6px 2px;
            }
            .bottom-nav .material-icons {
                font-size: 1.4rem;
            }
        }
        
        @media (min-width: 768px) {
            .bottom-nav {
                padding: 12px 8px;
            }
            .bottom-nav a {
                font-size: 0.8rem;
                padding: 10px 6px;
            }
            .bottom-nav .material-icons {
                font-size: 1.8rem;
            }
        }
        
        /* Modern Custom Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(5px);
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease;
        }
        
        .modal-overlay.show {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 16px;
            padding: 0;
            max-width: 400px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            transform: scale(0.9);
            animation: slideUp 0.3s ease forwards;
            overflow: hidden;
        }
        
        .modal-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, #8B0000 100%);
            color: white;
            padding: 20px 24px;
            text-align: center;
            position: relative;
        }
        
        .modal-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="modalGrain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="white" opacity="0.1"/><circle cx="75" cy="75" r="1" fill="white" opacity="0.1"/><circle cx="50" cy="10" r="0.5" fill="white" opacity="0.1"/><circle cx="10" cy="60" r="0.5" fill="white" opacity="0.1"/><circle cx="90" cy="40" r="0.5" fill="white" opacity="0.1"/></pattern></defs><rect width="100" height="100" fill="url(%23modalGrain)"/></svg>');
            opacity: 0.3;
        }
        
        .modal-icon {
            font-size: 3rem;
            margin-bottom: 10px;
            display: block;
            position: relative;
            z-index: 2;
        }
        
        .modal-title {
            font-size: 1.4rem;
            font-weight: 600;
            margin: 0;
            position: relative;
            z-index: 2;
        }
        
        .modal-body {
            padding: 24px;
            text-align: center;
        }
        
        .modal-message {
            font-size: 1rem;
            color: #555;
            margin: 0 0 24px 0;
            line-height: 1.5;
        }
        
        .modal-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
        }
        
        .modal-btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            min-width: 100px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .modal-btn-cancel {
            background: #f8f9fa;
            color: #6c757d;
            border: 1px solid #dee2e6;
        }
        
        .modal-btn-cancel:hover {
            background: #e9ecef;
            transform: translateY(-1px);
        }
        
        .modal-btn-confirm {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        }
        
        .modal-btn-confirm:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(40, 167, 69, 0.4);
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideUp {
            from { 
                transform: scale(0.9) translateY(20px);
                opacity: 0;
            }
            to { 
                transform: scale(1) translateY(0);
                opacity: 1;
            }
        }
        
        /* Responsive modal */
        @media (max-width: 576px) {
            .modal-content {
                width: 95%;
                margin: 20px;
            }
            .modal-header {
                padding: 16px 20px;
            }
            .modal-body {
                padding: 20px;
            }
            .modal-actions {
                flex-direction: column;
            }
            .modal-btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>

    <!-- Modern Confirmation Modal -->
    <div id="confirmModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <i class="material-icons modal-icon">help_outline</i>
                <h3 class="modal-title">Confirm Action</h3>
            </div>
            <div class="modal-body">
                <p class="modal-message" id="modalMessage">Are you sure you want to mark this request as COMPLETED?</p>
                <div class="modal-actions">
                    <button type="button" class="modal-btn modal-btn-cancel" onclick="closeModal()">
                        <i class="material-icons">close</i>
                        Cancel
                    </button>
                    <button type="button" class="modal-btn modal-btn-confirm" id="confirmButton">
                        <i class="material-icons">check</i>
                        Confirm
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php if ($action === 'view' && $request_details): ?>
        <!-- == DETAILED VIEW FOR A SINGLE REQUEST == -->
        <div class="page-header">
            <div class="page-header-content">
                <i class="material-icons header-icon">assignment</i>
                <h1 class="page-title">Request Details</h1>
                <p class="page-subtitle">Help Request #<?= $request_details['id'] ?></p>
            </div>
        </div>
        <div class="container">
            <div class="request-card">
                <!-- Customer Info Section -->
                <div style="display: flex; align-items: center; gap: 18px; margin-bottom: 1em; border-bottom: 1px solid #eee; padding-bottom: 0.7em;">
                    <?php
                    $imgPath = !empty($request_details['requestor_image']) ? $baseURL . htmlspecialchars($request_details['requestor_image']) :
                        'https://ui-avatars.com/api/?name=' . urlencode($request_details['requestor_first_name'] . ' ' . $request_details['requestor_last_name']) . '&background=4CAF50&color=fff';
                    ?>
                    <img src="<?= $imgPath ?>" alt="Customer" style="width:60px;height:60px;border-radius:50%;object-fit:cover;border:2px solid #eee;">
                    <div>
                        <div style="font-weight:bold;font-size:1.1em;"> <?= htmlspecialchars($request_details['requestor_first_name'] . ' ' . $request_details['requestor_last_name']) ?> </div>
                        <div><a href="tel:<?= htmlspecialchars($request_details['contact_info']) ?>" style="color:#530707;"> <?= htmlspecialchars($request_details['contact_info']) ?> </a></div>
                    </div>
                </div>
                <!-- Breakdown/Request Details Section -->
                <table class="request-table">
                    <tr><td>Barangay (Breakdown Location):</td><td><?= htmlspecialchars($request_details['breakdown_barangay']) ?></td></tr>
                    <tr><td>Bike Unit:</td><td><?= htmlspecialchars($request_details['bike_unit']) ?></td></tr>
                    <tr><td>Plate Number:</td><td><?= !empty($request_details['plate_number']) ? htmlspecialchars($request_details['plate_number']) : '-' ?></td></tr>
                    <tr><td>What seems to be the problem?</td><td><?= htmlspecialchars($request_details['problem_description']) ?></td></tr>
                    <tr><td>Your Location / Landmark:</td><td><?= htmlspecialchars($request_details['location']) ?></td></tr>
                    <tr><td>Requested:</td><td><?= date('M d, Y, g:i A', strtotime($request_details['accepted_at'])) ?></td></tr>
                </table>
                <!-- Live Map Tracker and actions remain unchanged -->

                <!-- Live Map Tracker -->
                <div class="map-container" id="liveMap"></div>
                <div id="etaDisplay" style="font-weight:bold; margin-top:8px;"></div>
                <div id="map-error" style="display:none; text-align:center; color: var(--red);">Could not load map. Please check your connection.</div>

                <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 1rem;">
                    <form method="POST" action="<?= $baseURL ?>Mechanic-Transaction.php" id="completeForm">
                        <input type="hidden" name="request_id" value="<?= $request_details['id'] ?>">
                        <input type="hidden" name="action" value="complete">
                        <button type="button" class="btn-action btn-complete" onclick="showConfirmModal()"><i class="material-icons">check_circle</i>Mark as Completed</button>
                    </form>
                    <?php
                    // Generate Google Maps link using coordinates if available
                    $lat = isset($request_details['latitude']) ? $request_details['latitude'] : null;
                    $lng = isset($request_details['longitude']) ? $request_details['longitude'] : null;
                    if ($lat && $lng) {
                        $mapsUrl = "https://www.google.com/maps/search/?api=1&query={$lat},{$lng}";
                    } else {
                        $address = urlencode($request_details['location'] . ', ' . $request_details['breakdown_barangay'] . ', Valencia City, Bukidnon');
                        $mapsUrl = "https://www.google.com/maps/search/?api=1&query={$address}";
                    }
                    ?>
                    <a href="<?= $mapsUrl ?>" target="_blank" class="btn-action btn-view" style="text-decoration: none;">
                        <i class="material-icons">navigation</i>Open in Maps
                    </a>
                </div>
            </div>
            <a href="<?= $baseURL ?>Mechanic-Transaction.php" class="btn-action btn-back mt-3"><i class="material-icons">arrow_back</i>Back to Active List</a>
        </div>

        <!-- Leaflet.js and OpenRouteService for routing -->
        <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
        <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
        <script src="https://unpkg.com/leaflet-rotatedmarker/leaflet.rotatedMarker.js"></script>
        <script src="https://unpkg.com/leaflet-polylineoffset/leaflet.polylineoffset.js"></script>
        <script src="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.js"></script>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Customer's breakdown location (from help request)
            const customerLat = <?= isset($request_details['latitude']) ? floatval($request_details['latitude']) : '7.9061' ?>;
            const customerLng = <?= isset($request_details['longitude']) ? floatval($request_details['longitude']) : '125.0897' ?>;
            let mechanicMarker, routeLine, headingArrow, etaDisplay = document.getElementById('etaDisplay');
            let lastPosition = null;
            let map = L.map('liveMap').setView([customerLat, customerLng], 15);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(map);
            // Customer pin
            const customerIcon = L.icon({
                iconUrl: 'https://cdn-icons-png.flaticon.com/512/684/684908.png',
                iconSize: [32, 32],
                iconAnchor: [16, 32],
            });
            L.marker([customerLat, customerLng], {icon: customerIcon}).addTo(map).bindPopup('Breakdown Location');
            // Mechanic icon (arrow)
            const mechanicIcon = L.icon({
                iconUrl: 'https://cdn-icons-png.flaticon.com/512/684/684908.png', // You can use a custom arrow icon
                iconSize: [32, 32],
                iconAnchor: [16, 16],
            });
            // Live geolocation tracking
            function updateMechanicPosition(position) {
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;
                if (!mechanicMarker) {
                    mechanicMarker = L.marker([lat, lng], {icon: mechanicIcon, rotationAngle: 0}).addTo(map).bindPopup('Your Location');
                } else {
                    mechanicMarker.setLatLng([lat, lng]);
                }
                // Draw/update route line
                if (routeLine) map.removeLayer(routeLine);
                routeLine = L.polyline([[lat, lng], [customerLat, customerLng]], {color: 'blue', weight: 5, opacity: 0.7}).addTo(map);
                // Calculate heading (bearing)
                let angle = 0;
                if (lastPosition) {
                    const dx = lng - lastPosition.lng;
                    const dy = lat - lastPosition.lat;
                    angle = Math.atan2(dx, dy) * 180 / Math.PI;
                    mechanicMarker.setRotationAngle(angle);
                }
                lastPosition = {lat, lng};
                // Calculate ETA (simple straight-line, 30km/h)
                const R = 6371; // km
                const dLat = (customerLat-lat) * Math.PI/180;
                const dLng = (customerLng-lng) * Math.PI/180;
                const a = Math.sin(dLat/2) * Math.sin(dLat/2) + Math.cos(lat*Math.PI/180) * Math.cos(customerLat*Math.PI/180) * Math.sin(dLng/2) * Math.sin(dLng/2);
                const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
                const distance = R * c; // in km
                const speed = 30; // km/h
                const eta = distance > 0.05 ? Math.round((distance/speed)*60) : 1;
                etaDisplay.textContent = `Estimated arrival: ${eta} minute(s)`;
            }
            if (navigator.geolocation) {
                navigator.geolocation.watchPosition(updateMechanicPosition, function(err) {
                    document.getElementById('map-error').style.display = 'block';
                }, {enableHighAccuracy:true, maximumAge:0, timeout:10000});
            } else {
                document.getElementById('map-error').style.display = 'block';
            }
        });
        </script>
        
        <!-- Modal JavaScript -->
        <script>
        function showConfirmModal() {
            document.getElementById('confirmModal').classList.add('show');
        }
        
        function closeModal() {
            document.getElementById('confirmModal').classList.remove('show');
        }
        
        function confirmAction() {
            document.getElementById('completeForm').submit();
        }
        
        // Close modal when clicking outside
        document.getElementById('confirmModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
        
        // Set up confirm button
        document.getElementById('confirmButton').addEventListener('click', confirmAction);
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });
        </script>
    <?php else: ?>
        <!-- == LIST VIEW OF ALL ACTIVE REQUESTS == -->
        <div class="page-header">
            <div class="page-header-content">
                <i class="material-icons header-icon">build_circle</i>
                <h1 class="page-title">Active Requests</h1>
                <p class="page-subtitle">Manage your current help requests</p>
            </div>
        </div>
        <div class="container">
            <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <?php if (empty($active_requests)): ?>
                <div class="empty-state">
                    <i class="material-icons">build_circle</i>
                    <p>No active requests at the moment.</p>
                    <p style="font-size: 0.9em;">Check the dashboard for new jobs.</p>
                </div>
            <?php else: ?>
                <?php foreach ($active_requests as $request): ?>
                    <div class="request-card">
                        <div class="request-header">
                            <div class="request-title">Request #<?= $request['id'] ?></div>
                        </div>
                        <p><strong>Customer:</strong> <?= htmlspecialchars($request['requestor_first_name'] . ' ' . $request['requestor_last_name']) ?></p>
                        <a href="<?= $baseURL ?>Mechanic-Transaction.php?action=view&request_id=<?= $request['id'] ?>" class="btn-action btn-view">
                            <i class="material-icons">visibility</i> View Details & Map
                        </a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <!-- Bottom Navigation -->
    <nav class="bottom-nav">
        <a href="<?= $baseURL ?>Mechanic-Dashboard.php">
            <span class="material-icons">dashboard</span>
            <span>Dashboard</span>
        </a>
        <a href="<?= $baseURL ?>Mechanic-Transaction.php" class="active">
            <span class="material-icons">history</span>
            <span>Transactions</span>
        </a>
        <a href="<?= $baseURL ?>Mechanic-Profile.php">
            <span class="material-icons">person</span>
            <span>Profile</span>
        </a>
    </nav>
</body>
</html>
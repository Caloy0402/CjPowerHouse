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
$error = null;
$info = null;

// Initialize variables
$user = null;
$pending_requests = [];
$request_history = [];
$barangays = [];
$mechanicFullName = '';
$allBarangayPendingCount = 0;
$barangayCounts = [];

// Initialize all statement variables to null
$stmt = null;
$stmt_requests = null;
$stmt_request_history = null;

// Fetch mechanic data from the database
$sql = "SELECT * FROM mechanics WHERE id = ?";
$stmt = $conn->prepare($sql);
if ($stmt === false) {
    $error = "Error preparing statement: " . $conn->error;
} else {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    if (!$user) {
        $error = "Mechanic not found!";
    } else {
        $mechanicFullName = $user['first_name'] . ' ' . $user['last_name'];
    }
}

// Initialize the selected barangay ID
$selectedBarangayId = isset($_GET['barangay']) ? (int)$_GET['barangay'] : null;

// Construct SQL query for pending help requests based on filter
$sql_requests = "SELECT hr.*, u.first_name AS requestor_first_name, u.last_name AS requestor_last_name, u.phone_number, u.purok, u.ImagePath AS requestor_image, b.barangay_name AS home_barangay, bb.barangay_name AS breakdown_barangay
                    FROM help_requests hr
                    JOIN users u ON hr.user_id = u.id
                    LEFT JOIN barangays b ON u.barangay_id = b.id
                    LEFT JOIN barangays bb ON hr.breakdown_barangay_id = bb.id
                    WHERE hr.status = 'Pending'";
if ($selectedBarangayId) {
    $sql_requests .= " AND hr.breakdown_barangay_id = ?";
}
$sql_requests .= " ORDER BY hr.created_at DESC";

$stmt_requests = $conn->prepare($sql_requests);
if ($stmt_requests === false) {
    $error = "Error preparing requests statement: " . $conn->error;
} else {
    if ($selectedBarangayId) {
        $stmt_requests->bind_param("i", $selectedBarangayId);
    }
    $stmt_requests->execute();
    $result_requests = $stmt_requests->get_result();
    while ($row = $result_requests->fetch_assoc()) {
        $pending_requests[] = $row;
    }
}

// Fetch recent request history for the mechanic
$sql_history = "SELECT hr.*, u.first_name AS requestor_first_name, u.last_name AS requestor_last_name,
                       COALESCE(hr.updated_at, hr.created_at) AS activity_time
                FROM help_requests hr
                JOIN users u ON hr.user_id = u.id
                WHERE hr.mechanic_id = ?
                  AND hr.status IN ('Completed', 'Cancelled')
                  AND DATE(COALESCE(hr.updated_at, hr.created_at)) = CURDATE()
                ORDER BY activity_time DESC";
$stmt_request_history = $conn->prepare($sql_history);
if ($stmt_request_history === false) {
    $error = $error ? $error : "Error preparing history statement: " . $conn->error;
} else {
    $stmt_request_history->bind_param("i", $user_id);
    $stmt_request_history->execute();
    $result_history = $stmt_request_history->get_result();
    while ($row = $result_history->fetch_assoc()) {
        $request_history[] = $row;
    }
}

// Function to count pending help requests
function getPendingHelpRequestCount($conn, $barangayId = null) {
    $sql = "SELECT COUNT(*) FROM help_requests WHERE status = 'Pending'";
    if ($barangayId) { $sql .= " AND breakdown_barangay_id = ?"; }
    $stmt_count = $conn->prepare($sql);
    if ($stmt_count === false) { return 0; }
    $count = 0;
    if ($barangayId) { $stmt_count->bind_param("i", $barangayId); }
    $stmt_count->execute();
    $stmt_count->bind_result($count);
    $stmt_count->fetch();
    $stmt_count->close();
    return $count;
}

// Get the total count of all pending requests for the main display
$pendingRequestCount = getPendingHelpRequestCount($conn, null);

// Fetch all barangays for filter buttons
$sql_barangays = "SELECT id, barangay_name FROM barangays";
$result_barangays = $conn->query($sql_barangays);
if ($result_barangays) {
    while ($row = $result_barangays->fetch_assoc()) {
        $barangays[] = $row;
    }
}

// Pre-calculate counts for all barangay buttons
$allBarangayPendingCount = $pendingRequestCount; // Already fetched
foreach ($barangays as $barangay) {
    $barangayCounts[$barangay['id']] = getPendingHelpRequestCount($conn, $barangay['id']);
}

// --- ALL DATABASE OPERATIONS ARE DONE. NOW CLOSE EVERYTHING. ---
if ($stmt instanceof mysqli_stmt) { $stmt->close(); }
if ($stmt_requests instanceof mysqli_stmt) { $stmt_requests->close(); }
if ($stmt_request_history instanceof mysqli_stmt) { $stmt_request_history->close(); }
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mechanic Dashboard</title>
    <!-- Favicon for better browser compatibility -->
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="/image/logo.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/image/logo.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/image/logo.png">
    <link rel="shortcut icon" href="/favicon.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="<?= $baseURL ?>css/mechanic-responsive.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
    <script src="<?= $baseURL ?>js/notification-sound.js"></script>
    <style>
        :root {
            --primary-color: #530707;
            --accent-color: #ffc107;
            --light-gray: #f4f5f7;
            --dark-text: #333;
        }
        body, html {
            margin: 0; padding: 0; background-color: var(--light-gray);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            padding-bottom: 85px;
        }
        body.no-scroll { overflow: hidden; }

        .header {
            background-color: var(--primary-color); 
            color: white; 
            padding: 10px 15px;
            display: flex; 
            align-items: center; 
            justify-content: space-between;
        }
        
        .header-left { 
            display: flex; 
            align-items: center; 
            flex: 1;
            min-width: 0; /* Allow text to truncate */
        }
        
        .profile-icon {
            width: 45px; 
            height: 45px; 
            border-radius: 50%; 
            margin-right: 12px;
            object-fit: cover; 
            border: 2px solid #fff;
            flex-shrink: 0;
        }
        
        .powerhouse-logo { 
            width: 70px; 
            height: 50px; 
            cursor: pointer; 
            flex-shrink: 0;
            object-fit: contain;
            background: white;
            border-radius: 4px;
            padding: 2px;
        }
        
        .header-welcome { 
            font-size: 1.1em; 
            font-weight: bold; 
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* Responsive header */
        @media (max-width: 576px) {
            .header {
                padding: 8px 12px;
            }
            .profile-icon {
                width: 40px;
                height: 40px;
                margin-right: 8px;
            }
            .powerhouse-logo {
                width: 60px;
                height: 40px;
            }
            .header-welcome {
                font-size: 1em;
            }
        }
        
        @media (min-width: 768px) {
            .header {
                padding: 15px 20px;
            }
            .profile-icon {
                width: 50px;
                height: 50px;
            }
            .powerhouse-logo {
                width: 80px;
                height: 60px;
            }
            .header-welcome {
                font-size: 1.2em;
            }
        }
        
        .dashboard-panel-container {
            display: flex; justify-content: center; padding: 0 1rem;
        }
        .dashboard-panel {
            width: 100%; 
            max-width: 100%; 
            margin-top: 1rem; 
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08); 
            padding: 18px 22px;
        }
        
        /* Responsive breakpoints */
        @media (min-width: 576px) {
            .dashboard-panel {
                max-width: 540px;
            }
        }
        
        @media (min-width: 768px) {
            .dashboard-panel {
                max-width: 720px;
                padding: 24px 28px;
            }
        }
        
        @media (min-width: 992px) {
            .dashboard-panel {
                max-width: 960px;
            }
        }
        
        @media (min-width: 1200px) {
            .dashboard-panel {
                max-width: 1140px;
            }
        }
        .dashboard-panel.orange {
            background: #ff9800; color: #fff; text-align: center;
            font-weight: bold; font-size: 1.2em;
        }
        .dashboard-panel.maroon {
            background: #7a0d0d; color: #fff; font-size: 1em; margin-top: 1rem;
        }

        .barangay-buttons-container {
            max-width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch;
            padding: 0.5rem 1rem; margin-top: 1rem;
        }
        .barangay-buttons { display: flex; flex-wrap: nowrap; gap: 0.75rem; }
        .barangay-buttons-container::-webkit-scrollbar { height: 4px; }
        .barangay-buttons-container::-webkit-scrollbar-thumb { background: #ccc; border-radius: 2px; }
        .barangay-btn {
            background-color: #fff; border: 1px solid #dee2e6;
            border-radius: 20px; padding: 0.5rem 1rem; font-size: 0.9rem;
            white-space: nowrap; transition: all 0.2s ease; flex-shrink: 0; cursor: pointer;
        }
        .barangay-btn.active { background-color: var(--primary-color); color: white; border-color: var(--primary-color); }

        .content-container { 
            padding: 0 1rem; 
            max-width: 100%; 
            margin: 1rem auto; 
        }
        
        /* Responsive content container */
        @media (min-width: 576px) {
            .content-container { max-width: 540px; }
        }
        
        @media (min-width: 768px) {
            .content-container { max-width: 720px; }
        }
        
        @media (min-width: 992px) {
            .content-container { max-width: 960px; }
        }
        
        @media (min-width: 1200px) {
            .content-container { max-width: 1140px; }
        }
        
        .section-title { margin-bottom: 1.2rem; color: var(--dark-text); font-weight: bold; }
        
        /* Scrollable requests container */
        #requestsContainer {
            max-height: 500px;
            overflow-y: auto;
            overflow-x: hidden;
            padding-right: 8px;
            margin-right: -8px;
        }
        
        /* Custom scrollbar styling */
        #requestsContainer::-webkit-scrollbar {
            width: 8px;
        }
        
        #requestsContainer::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        #requestsContainer::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, var(--primary-color) 0%, #8B0000 100%);
            border-radius: 10px;
            transition: background 0.3s ease;
        }
        
        #requestsContainer::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #8B0000 0%, var(--primary-color) 100%);
        }
        
        /* Firefox scrollbar styling */
        #requestsContainer {
            scrollbar-width: thin;
            scrollbar-color: var(--primary-color) #f1f1f1;
        }
        
        .request-item, .history-item {
            background-color: #fff; 
            border: 1px solid #e0e0e0;
            border-radius: 10px; 
            padding: 15px; 
            margin-bottom: 1rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        /* Responsive request items */
        @media (min-width: 768px) {
            .request-item, .history-item {
                padding: 20px;
            }
        }
        
        /* Responsive scrollbar adjustments */
        @media (max-width: 576px) {
            #requestsContainer {
                max-height: 400px;
            }
            
            #requestsContainer::-webkit-scrollbar {
                width: 6px;
            }
        }
        
        @media (min-width: 768px) {
            #requestsContainer {
                max-height: 600px;
            }
            
            #requestsContainer::-webkit-scrollbar {
                width: 10px;
            }
        }
        
        @media (min-width: 992px) {
            #requestsContainer {
                max-height: 700px;
            }
        }
        .request-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .request-title { font-weight: bold; color: var(--dark-text); }
        .request-status { padding: 4px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: bold; }
        .status-pending { background-color: #fff3cd; color: #856404; }
        .request-detail { margin-bottom: 6px; font-size: 0.9rem; line-height: 1.4; }
        .request-detail strong { color: #495057; }
        .request-actions { 
            display: flex; 
            gap: 10px; 
            margin-top: 15px; 
            flex-wrap: wrap; 
        }
        
        /* Responsive request actions */
        @media (max-width: 576px) {
            .request-actions {
                flex-direction: column;
                gap: 8px;
            }
            .btn-action {
                width: 100%;
                justify-content: center;
            }
        }
        
        @media (min-width: 768px) {
            .request-actions {
                justify-content: center;
            }
        }
        
        .btn-action {
            color: white; 
            border: none; 
            padding: 8px 14px;
            border-radius: 6px; 
            cursor: pointer; 
            font-size: 0.9rem;
            display: inline-flex; 
            align-items: center; 
            justify-content: center;
            transition: background-color 0.2s;
            min-width: 120px;
        }
        
        @media (min-width: 768px) {
            .btn-action {
                padding: 10px 18px;
                font-size: 1rem;
            }
        }
        .btn-action .material-icons { font-size: 16px; margin-right: 6px; }
        .btn-accept { background-color: #28a745; }
        .btn-accept:hover { background-color: #218838; }
        .btn-decline { background-color: #dc3545; }
        .btn-decline:hover { background-color: #c82333; }
        .btn-view-details { background-color: #007bff; }
        .btn-view-details:hover { background-color: #0056b3; }

        .history-customer { font-weight: bold; color: var(--dark-text); }
        .history-status { font-size: 0.8rem; font-weight: bold; }
        .history-status.completed { color: #28a745; }
        .history-status.cancelled { color: #dc3545; }
        .history-date { color: #888; font-size: 0.8rem; }
        
        .sidebar {
            position: fixed; top: 0; left: 0; width: 260px; height: 100%;
            background: var(--primary-color); color: #fff; z-index: 3000;
            transform: translateX(-100%); transition: transform 0.3s ease;
        }
        .sidebar.active { transform: translateX(0); }
        .sidebar-header { display: flex; align-items: center; padding: 18px; border-bottom: 1px solid #ffffff22; }
        .sidebar-logo { 
            width: 50px; 
            height: 40px; 
            margin-right: auto; 
            object-fit: contain;
            background: white;
            border-radius: 4px;
            padding: 2px;
        }
        .close-btn { font-size: 2rem; cursor: pointer; color: #fff; background: none; border: none; }
        .sidebar-links { list-style: none; padding: 0; margin: 30px 0; }
        .sidebar-links a { color: #fff; text-decoration: none; font-size: 1.1rem; display: flex; align-items: center; padding: 12px 24px; transition: background 0.2s; }
        .sidebar-links a:hover { background: #ffffff1a; }
        .sidebar-links i.material-icons { margin-right: 16px; }
        .sidebar-overlay {
            position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(0,0,0,0.5); z-index: 2999;
            opacity: 0; visibility: hidden; transition: opacity 0.3s ease;
        }
        .sidebar-overlay.active { opacity: 1; visibility: visible; }
        .hamburger-menu { cursor: pointer; padding: 8px; margin-right: 5px; border-radius: 50%; }

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
        
        .bottom-nav a.active { color: var(--accent-color); }
        .bottom-nav .material-icons { font-size: 1.6rem; margin-bottom: 2px; }
        
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

        .modal-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.6); display: none; justify-content: center; align-items: center; z-index: 4000;
        }
        .modal-content {
            background: #fff; 
            padding: 25px; 
            border-radius: 12px; 
            width: 90%;
            max-width: 500px; 
            box-shadow: 0 5px 15px rgba(0,0,0,0.3); 
            position: relative;
            margin: 20px auto;
        }
        
        /* Responsive modal */
        @media (max-width: 576px) {
            .modal-content {
                width: 95%;
                padding: 20px;
                margin: 10px auto;
            }
        }
        
        @media (min-width: 768px) {
            .modal-content {
                width: 80%;
                max-width: 600px;
                padding: 30px;
            }
        }
        
        @media (min-width: 992px) {
            .modal-content {
                max-width: 700px;
            }
        }
        .modal-close-btn {
            position: absolute; top: 10px; right: 10px; font-size: 1.8rem;
            font-weight: bold; color: #888; cursor: pointer; border: none; background: none;
        }
        .modal-title { font-size: 1.4em; font-weight: bold; margin-bottom: 1rem; color: var(--dark-text); }
        .modal-body .request-detail { font-size: 1rem; margin-bottom: 8px; }
        .map-placeholder {
            height: 150px; background-color: #e9e9e9; border-radius: 8px; display: flex;
            justify-content: center; align-items: center; color: #999; text-align: center; margin-top: 1rem;
        }
        .badge-count {
            display: inline-block;
            min-width: 22px;
            padding: 2px 7px;
            font-size: 0.85em;
            font-weight: bold;
            color: #fff;
            background: #dc3545;
            border-radius: 12px;
            margin-left: 7px;
            vertical-align: middle;
        }
        .notification-bell {
            position: relative;
            cursor: pointer;
            font-size: 2rem;
            color: #fff;
            transition: color 0.2s;
            display: flex;
            align-items: center;
        }
        .notification-bell:hover {
            color: var(--accent-color);
        }
        .notif-badge {
            position: absolute;
            top: -4px;
            right: -4px;
            background: #dc3545;
            color: #fff;
            border-radius: 50%;
            font-size: 0.75rem;      /* Smaller font size */
            padding: 1px 5px;        /* Less padding */
            min-width: 16px;
            height: 16px;
            line-height: 14px;
            text-align: center;
            font-weight: bold;
            box-shadow: 0 1px 4px rgba(0,0,0,0.15);
            z-index: 2;
            display: inline-block;
        }
        .notif-dropdown {
            display: none;
            position: absolute;
            right: 0;
            top: 40px;
            background: #fff;
            color: #333;
            min-width: 320px;
            max-width: 400px;
            max-height: 400px;
            overflow-y: auto;
            box-shadow: 0 4px 16px rgba(0,0,0,0.18);
            border-radius: 8px;
            z-index: 5000;
        }
        .notif-dropdown-header {
            font-weight: bold;
            padding: 12px 16px;
            border-bottom: 1px solid #eee;
            background: #f8f9fa;
        }
        .notif-item {
            padding: 10px 16px;
            border-bottom: 1px solid #f1f1f1;
            cursor: pointer;
            transition: background 0.15s;
        }
        .notif-item:hover {
            background: #f5f5f5;
        }
        .notif-empty {
            padding: 18px 16px;
            color: #888;
            text-align: center;
        }

        /* Notification Sound Controls */
        .sound-controls {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .sound-controls button {
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            padding: 4px;
            border-radius: 4px;
            transition: all 0.2s ease;
        }
        
        .sound-controls button:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: scale(1.1);
        }
        
        .sound-controls button:active {
            transform: scale(0.95);
        }
        
        .sound-controls .material-icons {
            font-size: 20px;
        }

        /* Decline Modal Styles */
        .decline-reasons {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .reason-option {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            overflow: hidden;
            transition: all 0.2s ease;
        }
        
        .reason-option:hover {
            border-color: #dc3545;
            box-shadow: 0 2px 8px rgba(220, 53, 69, 0.1);
        }
        
        .reason-option input[type="radio"] {
            display: none;
        }
        
        .reason-option input[type="radio"]:checked + label {
            background-color: #dc3545;
            color: white;
        }
        
        .reason-option input[type="radio"]:checked + label .material-icons {
            color: white;
        }
        
        .reason-option label {
            display: flex;
            align-items: center;
            padding: 15px;
            cursor: pointer;
            background-color: #f8f9fa;
            transition: all 0.2s ease;
            margin: 0;
        }
        
        .reason-option label .material-icons {
            font-size: 24px;
            margin-right: 15px;
            color: #dc3545;
        }
        
        .reason-option label div {
            flex: 1;
        }
        
        .reason-option label div strong {
            display: block;
            font-size: 16px;
            margin-bottom: 4px;
        }
        
        .reason-option label div span {
            display: block;
            font-size: 14px;
            opacity: 0.8;
        }

        /* Real-time update animations */
        .dashboard-panel.real-time-update {
            animation: pulse 1s ease-in-out;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        /* Panel slide-down animation */
        .panel-slide-down {
            animation: slideDown 0.8s ease-out forwards;
        }
        
        @keyframes slideDown {
            0% {
                transform: translateY(-20px);
                opacity: 0;
            }
            100% {
                transform: translateY(0);
                opacity: 1;
            }
        }

        /* Request card fade-in animation */
        .request-fade-in {
            animation: fadeInUp 0.6s ease-out forwards;
        }
        
        @keyframes fadeInUp {
            0% {
                transform: translateY(30px);
                opacity: 0;
            }
            100% {
                transform: translateY(0);
                opacity: 1;
            }
        }

        /* Smooth transition for request container */
        #requestsContainer {
            transition: all 0.3s ease;
        }

        /* New request highlight animation */
        .new-request-highlight {
            animation: highlightNew 2s ease-out forwards;
        }
        
        @keyframes highlightNew {
            0% {
                background-color: #fff3cd;
                box-shadow: 0 0 20px rgba(255, 193, 7, 0.5);
            }
            100% {
                background-color: white;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }
        }

        /* Connection status indicator */
        .connection-status {
            position: fixed;
            top: 10px;
            left: 10px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            z-index: 1000;
        }
        .connection-status.connected {
            background-color: #28a745;
        }
        .connection-status.disconnected {
            background-color: #dc3545;
        }

        /* Notification styles */
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

        /* One-time CJ Powerhouse Mechanic Loader */
        .mechanic-loader-overlay {
            position: fixed;
            inset: 0;
            background: radial-gradient(1200px 600px at 50% -20%, #7a0d0d 0%, #3a0303 60%, #1a0101 100%);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }
        .mechanic-loader-overlay.show { display: flex; }
        .mechanic-loader {
            width: 180px;
            height: 180px;
            position: relative;
            filter: drop-shadow(0 12px 24px rgba(0,0,0,0.35));
        }
        .mechanic-loader .ring {
            position: absolute;
            inset: 0;
            border-radius: 50%;
            border: 6px solid rgba(255,255,255,0.08);
            box-sizing: border-box;
        }
        .mechanic-loader .gear {
            position: absolute;
            top: 50%;
            left: 50%;
            width: 120px;
            height: 120px;
            transform: translate(-50%, -50%);
            border-radius: 50%;
            background: conic-gradient(from 0deg, #ffc107, #ffdd57);
            -webkit-mask: radial-gradient(circle 44px at 50% 50%, transparent 56px, black 57px),
                         url('<?= $baseURL ?>img/gear-mask.svg') center/contain no-repeat;
                    mask: radial-gradient(circle 44px at 50% 50%, transparent 56px, black 57px),
                         url('<?= $baseURL ?>img/gear-mask.svg') center/contain no-repeat;
            animation: spin 2.4s linear infinite;
        }
        .mechanic-loader .logo {
            position: absolute;
            top: 50%; left: 50%; transform: translate(-50%,-50%);
            width: 76px; height: 76px; border-radius: 50%;
            background: #fff url('<?= $baseURL ?>image/logo.png') center/70% no-repeat;
            box-shadow: 0 0 0 6px rgba(255,255,255,0.08) inset;
        }
        /* Hide tick ring to remove the pointed wedge effect */
        .mechanic-loader .ticks { display: none; }
        .mechanic-loader .bolt {
            position: absolute; top: 50%; left: 50%; width: 6px; height: 6px;
            background: #ffc107; border-radius: 50%; transform: translate(-50%, -50%);
            box-shadow: 0 0 18px 6px rgba(255,193,7,0.35);
        }
        .mechanic-loader .text {
            position: absolute; top: calc(100% + 18px); left: 50%; transform: translateX(-50%);
            font-weight: 700; letter-spacing: 0.08em; color: #ffd65a;
            text-shadow: 0 2px 8px rgba(0,0,0,0.35);
        }
        @keyframes spin { to { transform: translate(-50%, -50%) rotate(360deg); } }

        /* Smooth fade-out + logo zoom */
        .mechanic-loader-overlay { opacity: 1; transition: opacity 0.6s ease; }
        .mechanic-loader-overlay.fading { opacity: 0; }
        .mechanic-loader .logo { transition: transform 0.6s ease; }
        .mechanic-loader.fading .logo { transform: translate(-50%, -50%) scale(1.2); }
    </style>
</head>
<body>
    <?php
    $__showMechanicLoader = false;
    if (isset($_SESSION['show_mechanic_loader']) && $_SESSION['show_mechanic_loader'] === true) {
        $__showMechanicLoader = true;
        unset($_SESSION['show_mechanic_loader']);
    } else if (isset($_SESSION['mechanic_login_time'])) {
        // Fallback: if just logged in within last 5 seconds, show loader
        if (time() - (int)$_SESSION['mechanic_login_time'] <= 5) {
            $__showMechanicLoader = true;
        }
        unset($_SESSION['mechanic_login_time']);
    }
    ?>
    <div id="mechanicLoader" class="mechanic-loader-overlay<?= $__showMechanicLoader ? ' show' : '' ?>">
        <div class="mechanic-loader">
            <div class="ring"></div>
            <div class="ticks"></div>
            <div class="gear"></div>
            <div class="logo"></div>
            <div class="bolt"></div>
            <div class="text">CJ Powerhouse • Mechanic</div>
        </div>
    </div>
    <!-- Connection Status Indicator -->
    <div id="connectionStatus" class="connection-status disconnected"></div>
    
    <!-- Notification -->
    <div id="requestNotification" class="notification">
        <div class="notification-content">
            <img src="<?= $baseURL ?>uploads/mechanic.gif" alt="New Request" class="notification-icon">
            <span id="notificationText">New help request available!</span>
        </div>
    </div>

    <div id="sidebar" class="sidebar">
        <div class="sidebar-header">
            <img src="<?= $baseURL ?>image/logo.png" alt="CJ Powerhouse Logo" class="sidebar-logo" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
            <div style="display:none; color:white; font-weight:bold; font-size:14px;">CJ Powerhouse</div>
            <button class="close-btn" onclick="toggleSidebar()">×</button>
        </div>
        <ul class="sidebar-links">
            <li><a href="<?= $baseURL ?>Mechanic-Profile.php"><i class="material-icons">person</i> Profile</a></li>
            <li><a href="#" onclick="logout(); return false;"><i class="material-icons">logout</i> Sign out</a></li>
        </ul>
    </div>
    <div id="sidebarOverlay" class="sidebar-overlay" onclick="toggleSidebar()"></div>

    <div class="header">
        <div class="header-left">
            <div class="hamburger-menu" onclick="toggleSidebar()"><i class="material-icons">menu</i></div>
            <?php if (!empty($user['ImagePath'])): ?>
                <img src="<?= $baseURL . htmlspecialchars($user['ImagePath']) ?>" alt="Profile" class="profile-icon">
            <?php else: ?>
                <img src="https://ui-avatars.com/api/?name=<?= urlencode($mechanicFullName) ?>&size=45&background=random" alt="Profile" class="profile-icon">
            <?php endif; ?>
            <div>
                <div class="header-welcome">Welcome, <?= htmlspecialchars($mechanicFullName) ?>!</div>
                <div style="font-size: 0.9em; opacity: 0.8;">Mechanic Dashboard</div>
            </div>
        </div>
        <div style="display: flex; align-items: center; gap: 18px;">
          
            
            <span class="notification-bell" onclick="toggleNotifDropdown(event)">
                <i class="material-icons">notifications</i>
                <span id="notificationBadge" class="notif-badge" style="<?= $pendingRequestCount > 0 ? '' : 'display: none;' ?>"><?= $pendingRequestCount ?></span>
            </span>
        </div>
    </div>

    <div id="notifDropdown" class="notif-dropdown">
        <div class="notif-dropdown-header">Pending Requests (<?= $pendingRequestCount ?>)</div>
        <?php if (empty($pending_requests)): ?>
            <div class="notif-empty">No pending requests.</div>
        <?php else: ?>
            <?php foreach ($pending_requests as $req): ?>
                <div class="notif-item" onclick="viewRequestDetailsById(<?= $req['id'] ?>)">
                    <div><strong><?= htmlspecialchars($req['requestor_first_name'] . ' ' . $req['requestor_last_name']) ?></strong></div>
                    <div style="font-size:0.9em;"><?= htmlspecialchars($req['problem_description']) ?></div>
                    <div style="font-size:0.8em; color:#888;"><?= date('M d, Y g:i A', strtotime($req['created_at'])) ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="dashboard-panel-container">
        <div class="dashboard-panel orange" id="pendingRequestsPanel">
            <div id="pendingRequestsCount"><?= $pendingRequestCount ?></div>
            <div style="font-size: 0.9em; font-weight: normal; margin-top: 4px;">Across all areas</div>
        </div>
    </div>
    
    <?php if ($error): ?>
    <div class="dashboard-panel-container">
        <div class="dashboard-panel maroon"><strong>Error:</strong> <?= htmlspecialchars($error) ?></div>
    </div>
    <?php endif; ?>

    <div class="barangay-buttons-container">
        <div class="barangay-buttons">
            <button class="barangay-btn <?= !$selectedBarangayId ? 'active' : '' ?>" onclick="window.location.href='<?= $baseURL ?>Mechanic-Dashboard.php'">
                All Areas
                <span id="allAreasBadge" class="badge-count" style="<?= $allBarangayPendingCount > 0 ? '' : 'display: none;' ?>"><?= $allBarangayPendingCount ?></span>
            </button>
            <?php foreach ($barangays as $barangay): ?>
                <button class="barangay-btn <?= $selectedBarangayId == $barangay['id'] ? 'active' : '' ?>" onclick="window.location.href='<?= $baseURL ?>Mechanic-Dashboard.php?barangay=<?= $barangay['id'] ?>'">
                    <?= htmlspecialchars($barangay['barangay_name']) ?>
                    <span id="barangayBadge<?= $barangay['id'] ?>" class="badge-count" style="<?= ($barangayCounts[$barangay['id']] ?? 0) > 0 ? '' : 'display: none;' ?>"><?= $barangayCounts[$barangay['id']] ?? 0 ?></span>
                </button>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="content-container">
        <h3 class="section-title">Pending Help Requests</h3>
        <div id="requestsContainer">
        <?php if (empty($pending_requests)): ?>
            <div class="text-center" style="padding: 40px 20px; color: #666; background: #fff; border-radius: 8px;">
                <i class="material-icons" style="font-size: 48px; margin-bottom: 10px;">build_circle</i>
                <p style="font-size: 1.1em; color:#555;">No pending help requests in this area.</p>
                <p>Check back later or select a different area from the filter above.</p>
            </div>
        <?php else: ?>
            <?php foreach ($pending_requests as $request): ?>
                <div class="request-item" 
                     data-id="<?= $request['id'] ?>"
                     data-name="<?= htmlspecialchars($request['requestor_first_name'] . ' ' . $request['requestor_last_name']) ?>"
                     data-bike="<?= htmlspecialchars($request['bike_unit']) ?>"
                     data-problem="<?= htmlspecialchars($request['problem_description']) ?>"
                     data-location="<?= htmlspecialchars($request['location']) ?>"
                     data-contact="<?= htmlspecialchars($request['contact_info']) ?>"
                     data-barangay="<?= htmlspecialchars($request['breakdown_barangay']) ?>"
                     data-plate="<?= htmlspecialchars($request['plate_number'] ?? '') ?>"
                     data-lat="<?= htmlspecialchars($request['latitude'] ?? '') ?>"
                     data-lng="<?= htmlspecialchars($request['longitude'] ?? '') ?>"
                     data-requested="<?= date('M d, Y g:i A', strtotime($request['created_at'])) ?>">
                    
                    <div class="request-header" style="display: flex; flex-direction: column; align-items: center; gap: 8px; text-align: center;">
                        <?php
                        $imgPath = !empty($request['requestor_image']) ? $baseURL . htmlspecialchars($request['requestor_image']) :
                            'https://ui-avatars.com/api/?name=' . urlencode($request['requestor_first_name'] . ' ' . $request['requestor_last_name']) . '&background=4CAF50&color=fff';
                        ?>
                        <img src="<?= $imgPath ?>" alt="Customer" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid #eee;">
                        <div class="request-title" style="font-weight:bold; font-size:1.1em;"><?= htmlspecialchars($request['requestor_first_name'] . ' ' . $request['requestor_last_name']) ?></div>
                        <span class="request-status status-pending" style="align-self:center;">Pending</span>
                    </div>
                    <div class="request-details">
                        <table class="table table-bordered" style="margin-bottom:0; background:#fff;">
                            <tr><td style="font-weight:bold; width:180px;">Bike:</td><td><?= htmlspecialchars($request['bike_unit']) ?></td></tr>
                            <tr><td style="font-weight:bold;">Problem:</td><td><?= htmlspecialchars($request['problem_description']) ?></td></tr>
                            <tr><td style="font-weight:bold;">Barangay (Breakdown Location):</td><td><?= htmlspecialchars($request['breakdown_barangay']) ?></td></tr>
                            <tr><td style="font-weight:bold;">Your Location / Landmark:</td><td><?= htmlspecialchars($request['location']) ?></td></tr>
                            <?php if (!empty($request['latitude']) && !empty($request['longitude'])): ?>
                            <tr><td style="font-weight:bold;">Coordinates:</td><td><?= htmlspecialchars(number_format((float)$request['latitude'], 6)) ?>, <?= htmlspecialchars(number_format((float)$request['longitude'], 6)) ?></td></tr>
                            <?php endif; ?>
                        </table>
                    </div>
                    <div class="request-actions" style="justify-content:center;">
                        <button class="btn-action btn-accept" onclick="acceptRequest(<?= $request['id'] ?>)"><i class="material-icons">check</i>Accept</button>
                        <button class="btn-action btn-decline" onclick="showDeclineModal(<?= $request['id'] ?>)"><i class="material-icons">close</i>Decline</button>
                        <button class="btn-action btn-view-details" onclick="viewRequestDetails(this)"><i class="material-icons">map</i>View Details</button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        </div>
        
        <div class="recent-history" style="margin-top: 2rem;">
            <h3 class="section-title">History Logs (Today)</h3>
            <?php if (empty($request_history)): ?>
                <div class="text-center" style="padding: 20px; color: #666; background-color: #fff; border-radius: 8px;">
                    <p>No recent transaction history found.</p>
                </div>
            <?php else: ?>
                <?php foreach ($request_history as $history): ?>
                    <div class="history-item">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="history-customer"><?= htmlspecialchars($history['requestor_first_name'] . ' ' . $history['requestor_last_name']) ?></div>
                            <div class="history-status <?= strtolower($history['status']) ?>"><?= htmlspecialchars($history['status']) ?></div>
                        </div>
                        <div class="history-date mt-1"><?= date('M d, Y, g:i A', strtotime($history['activity_time'])) ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div id="detailsModal" class="modal-overlay">
        <div class="modal-content">
            <button id="modalCloseBtn" class="modal-close-btn">×</button>
            <h2 id="modalTitle" class="modal-title">Request Details</h2>
            <div id="modalBody" class="modal-body">
                <!-- Customer Info Section -->
                <div style="margin-bottom: 1em; border-bottom: 1px solid #eee; padding-bottom: 0.7em;">
                    <div class="request-detail"><strong>Customer:</strong> <span id="modalName"></span></div>
                    <div class="request-detail"><strong>Contact:</strong> <span id="modalContact"></span></div>
                </div>
                <!-- Breakdown/Request Details Section -->
                <div style="margin-bottom: 1em;">
                    <div class="request-detail"><strong>Barangay (Breakdown Location):</strong> <span id="modalBarangay"></span></div>
                    <div class="request-detail"><strong>Bike Unit:</strong> <span id="modalBike"></span></div>
                    <div class="request-detail"><strong>Plate Number:</strong> <span id="modalPlate"></span></div>
                    <div class="request-detail"><strong>What seems to be the problem?</strong> <span id="modalProblem"></span></div>
                    <div class="request-detail"><strong>Your Location / Landmark:</strong> <span id="modalLocation"></span></div>
                    <div class="request-detail" id="modalCoordsWrap" style="display:none;"><strong>Coordinates:</strong> <span id="modalCoords"></span></div>
                    <div class="request-detail"><strong>Requested:</strong> <span id="modalRequested"></span></div>
                </div>
                <div id="modalLeaflet" style="height: 200px; border-radius:8px; background:#e9e9e9; margin-top:1rem; display:none;"></div>
                <div class="request-actions" style="justify-content: space-between;">
                    <button id="copyCoordsBtn" class="btn-action btn-view-details" style="background:#6c757d;"><i class="material-icons">content_copy</i>Copy Coords</button>
                    <a id="modalMapLink" href="#" target="_blank" class="btn-action btn-view-details" style="text-decoration: none;">
                        <i class="material-icons">navigation</i>Open in Maps
                    </a>
                </div>
            </div>
        </div>
    </div>

    <nav class="bottom-nav">
        <a href="<?= $baseURL ?>Mechanic-Dashboard.php" class="active"><span class="material-icons">dashboard</span><span>Dashboard</span></a>
        <a href="<?= $baseURL ?>Mechanic-Transaction.php"><span class="material-icons">history</span><span>Transactions</span></a>
        <a href="<?= $baseURL ?>Mechanic-Profile.php"><span class="material-icons">person</span><span>Profile</span></a>
    </nav>
    
    <!-- Accept Confirmation Modal -->
    <div class="modal fade" id="acceptConfirmModal" tabindex="-1" aria-labelledby="acceptConfirmLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border:none;border-radius:14px;overflow:hidden;">
                <div class="modal-header" style="background:linear-gradient(135deg,#a30808,#7a0d0d);color:#fff; justify-content:center;">
                    <h5 class="modal-title" id="acceptConfirmLabel" style="font-weight:700; letter-spacing:0.3px; color:#fff;">Accept Help Request</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="padding:1.2rem 1.2rem;">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <span class="material-icons" style="color:#0d6efd;">help_outline</span>
                        <div>Are you sure you want to accept this help request?</div>
                    </div>
                </div>
                <div class="modal-footer" style="border-top:none;">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" id="confirmAcceptBtn" class="btn btn-primary">Accept</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Decline Reason Modal -->
    <div id="declineModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3><i class="material-icons">cancel</i> Decline Help Request</h3>
                <span class="close" onclick="closeDeclineModal()">&times;</span>
            </div>
            <div class="modal-body">
                <p style="margin-bottom: 20px; color: #666;">Please select a reason for declining this help request. The customer will be notified of your decision.</p>
                
                <div class="decline-reasons">
                    <div class="reason-option">
                        <input type="radio" id="reason_distance" name="declineReason" value="distance">
                        <label for="reason_distance">
                            <i class="material-icons">location_off</i>
                            <div>
                                <strong>Too Far Away</strong>
                                <span>Location is outside my service area</span>
                            </div>
                        </label>
                    </div>
                    
                    <div class="reason-option">
                        <input type="radio" id="reason_equipment" name="declineReason" value="equipment">
                        <label for="reason_equipment">
                            <i class="material-icons">build</i>
                            <div>
                                <strong>Missing Equipment</strong>
                                <span>Don't have required tools/parts</span>
                            </div>
                        </label>
                    </div>
                    
                    <div class="reason-option">
                        <input type="radio" id="reason_expertise" name="declineReason" value="expertise">
                        <label for="reason_expertise">
                            <i class="material-icons">school</i>
                            <div>
                                <strong>Not My Expertise</strong>
                                <span>Problem requires specialized knowledge</span>
                            </div>
                        </label>
                    </div>
                    
                    <div class="reason-option">
                        <input type="radio" id="reason_schedule" name="declineReason" value="schedule">
                        <label for="reason_schedule">
                            <i class="material-icons">schedule</i>
                            <div>
                                <strong>Schedule Conflict</strong>
                                <span>Already committed to other requests</span>
                            </div>
                        </label>
                    </div>
                    
                    <div class="reason-option">
                        <input type="radio" id="reason_weather" name="declineReason" value="weather">
                        <label for="reason_weather">
                            <i class="material-icons">cloud</i>
                            <div>
                                <strong>Weather Conditions</strong>
                                <span>Unsafe weather conditions</span>
                            </div>
                        </label>
                    </div>
                    
                    <div class="reason-option">
                        <input type="radio" id="reason_other" name="declineReason" value="other">
                        <label for="reason_other">
                            <i class="material-icons">more_horiz</i>
                            <div>
                                <strong>Other Reason</strong>
                                <span>Custom reason (specify below)</span>
                            </div>
                        </label>
                    </div>
                </div>
                
                <div id="customReasonContainer" style="display: none; margin-top: 15px;">
                    <label for="customReason" style="font-weight: bold; margin-bottom: 8px; display: block;">Custom Reason:</label>
                    <textarea id="customReason" placeholder="Please specify your reason for declining..." 
                             style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; resize: vertical; min-height: 80px;"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-action btn-view-details" onclick="closeDeclineModal()" style="background: #6c757d;">
                    <i class="material-icons">arrow_back</i>Cancel
                </button>
                <button class="btn-action btn-decline" onclick="submitDeclineRequest()">
                    <i class="material-icons">send</i>Submit Decline
                </button>
            </div>
        </div>
    </div>
    
    <script>
    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('active');
        document.getElementById('sidebarOverlay').classList.toggle('active');
        document.body.classList.toggle('no-scroll', document.getElementById('sidebar').classList.contains('active'));
    }

    // Modern confirm modal for accepting a request
    let _pendingAcceptId = null;
    function acceptRequest(requestId) {
        _pendingAcceptId = requestId;
        const m = new bootstrap.Modal(document.getElementById('acceptConfirmModal'));
        m.show();
    }

    const modal = document.getElementById('detailsModal');
    const modalCloseBtn = document.getElementById('modalCloseBtn');

    function viewRequestDetails(button) {
        const requestItem = button.closest('.request-item');
        const data = requestItem.dataset;
        document.getElementById('modalName').textContent = data.name;
        document.getElementById('modalContact').innerHTML = `<a href="tel:${data.contact}">${data.contact}</a>`;
        document.getElementById('modalBarangay').textContent = data.barangay;
        document.getElementById('modalBike').textContent = data.bike;
        document.getElementById('modalPlate').textContent = data.plate && data.plate.trim() !== '' ? data.plate : '-';
        document.getElementById('modalProblem').textContent = data.problem;
        document.getElementById('modalLocation').textContent = data.location;
        document.getElementById('modalRequested').textContent = data.requested;
        const lat = parseFloat(data.lat);
        const lng = parseFloat(data.lng);
        const hasCoords = !isNaN(lat) && !isNaN(lng);
        const coordsWrap = document.getElementById('modalCoordsWrap');
        const coordsSpan = document.getElementById('modalCoords');
        if (hasCoords) {
            coordsWrap.style.display = '';
            coordsSpan.textContent = `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
            // Force map center to exact coords and zoom level
            const coordStr = `${lat},${lng}`;
            document.getElementById('modalMapLink').href = `https://www.google.com/maps?q=${coordStr}&ll=${coordStr}&z=18`;
            // Show small Leaflet preview map
            try {
                document.getElementById('modalLeaflet').style.display = 'block';
                if (!window._modalMap) {
                    window._modalMap = L.map('modalLeaflet');
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap contributors' }).addTo(window._modalMap);
                }
                window._modalMap.setView([lat, lng], 17);
                if (window._modalMarker) { window._modalMarker.setLatLng([lat, lng]); } else { window._modalMarker = L.marker([lat, lng]).addTo(window._modalMap); }
                setTimeout(() => { try { window._modalMap.invalidateSize(); } catch(e) {} }, 200);
            } catch(e) { /* ignore map errors */ }
        } else {
            coordsWrap.style.display = 'none';
            const mapQuery = encodeURIComponent(`${data.location}, ${data.barangay}`);
            document.getElementById('modalMapLink').href = `https://www.google.com/maps/search/?api=1&query=${mapQuery}`;
        }
        // Set up copy coordinates functionality
        document.getElementById('copyCoordsBtn').onclick = function() {
            if (hasCoords) {
                const coordsText = `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
                navigator.clipboard.writeText(coordsText).then(function() {
                    // Show success feedback
                    const btn = document.getElementById('copyCoordsBtn');
                    const originalText = btn.innerHTML;
                    btn.innerHTML = '<i class="material-icons">check</i>Copied!';
                    btn.style.background = '#28a745';
                    setTimeout(() => {
                        btn.innerHTML = originalText;
                        btn.style.background = '#6c757d';
                    }, 2000);
                }).catch(function(err) {
                    console.error('Could not copy text: ', err);
                    alert('Failed to copy coordinates. Please copy manually: ' + coordsText);
                });
            } else {
                alert('No coordinates available for this request');
            }
        };
        
        modal.style.display = 'flex';
        document.body.classList.add('no-scroll');
    }

    modalCloseBtn.onclick = function() {
        modal.style.display = 'none';
        document.body.classList.remove('no-scroll');
    }
    window.onclick = function(event) {
        if (event.target == modal) {
            modal.style.display = 'none';
            document.body.classList.remove('no-scroll');
        }
    }

    function showNotifications() {
        alert('Show notifications here!');
    }

    // Example: Set notification count (replace with real data)
    function setNotificationCount(count) {
        const badge = document.getElementById('notificationBadge');
        if (badge) {
            if (count > 0) {
                badge.textContent = count;
                badge.style.display = 'inline-block';
            } else {
                badge.style.display = 'none';
            }
        }
    }
    // Example: Set to 3 notifications
    setNotificationCount(3);

    function toggleNotifDropdown(event) {
        event.stopPropagation();
        const dropdown = document.getElementById('notifDropdown');
        dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
    }

    // Confirm Accept flow
    document.getElementById('confirmAcceptBtn')?.addEventListener('click', function() {
        if (_pendingAcceptId) {
            window.location.href = `<?= $baseURL ?>Mechanic-Transaction.php?action=accept&request_id=${_pendingAcceptId}`;
        }
    });

    // Hide dropdown when clicking outside
    document.addEventListener('click', function(event) {
        const dropdown = document.getElementById('notifDropdown');
        if (dropdown && dropdown.style.display === 'block') {
            dropdown.style.display = 'none';
        }
    });

    // Optional: Show request details modal when clicking a notification
    function viewRequestDetailsById(requestId) {
        // Find the request item in the DOM and trigger the modal logic
        const requestItem = document.querySelector(`.request-item[data-id='${requestId}']`);
        if (requestItem) {
            // Reuse your existing modal logic
            viewRequestDetails(requestItem.querySelector('.btn-view-details'));
        }
    }

    // Real-time updates using Server-Sent Events
    let eventSource = null;
    let isConnected = false;

    // Initialize notification sound system
    let notificationSound = null;
    
    // Function to show notification
    function showNotification(message = 'New help request available!') {
        const notification = document.getElementById('requestNotification');
        const notificationText = document.getElementById('notificationText');
        
        // Play notification sound using advanced system
        if (notificationSound && !notificationSound.getMuted()) {
            notificationSound.play();
        }
        
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
    }
    
    // Function to toggle notification mute
    function toggleNotificationMute() {
        if (notificationSound) {
            const isMuted = notificationSound.toggleMute();
            
            // Show toast notification
            notificationSound.showToast(
                isMuted ? 'Notifications muted' : 'Notifications unmuted',
                'info'
            );
        }
    }
    
    // Function to test notification sound
    function testNotificationSound() {
        if (notificationSound) {
            const played = notificationSound.testSound();
            if (!played) {
                notificationSound.showToast('Sound is muted', 'warning');
            } else {
                notificationSound.showToast('Test sound played', 'success');
            }
        }
    }

    // Decline Modal Functions
    let currentDeclineRequestId = null;

    function showDeclineModal(requestId) {
        currentDeclineRequestId = requestId;
        const modal = document.getElementById('declineModal');
        modal.style.display = 'block';
        
        // Reset form
        document.querySelectorAll('input[name="declineReason"]').forEach(radio => {
            radio.checked = false;
        });
        document.getElementById('customReasonContainer').style.display = 'none';
        document.getElementById('customReason').value = '';
        
        // Add event listener for "Other" reason
        document.getElementById('reason_other').addEventListener('change', function() {
            if (this.checked) {
                document.getElementById('customReasonContainer').style.display = 'block';
            } else {
                document.getElementById('customReasonContainer').style.display = 'none';
            }
        });
    }

    function closeDeclineModal() {
        const modal = document.getElementById('declineModal');
        modal.style.display = 'none';
        currentDeclineRequestId = null;
    }

    // Lightweight tooltip helper using Bootstrap's Tooltip
    function showInlineTip(targetEl, message) {
        try {
            if (!targetEl) return;
            // Ensure target is a DOM element
            const el = (typeof targetEl === 'string') ? document.querySelector(targetEl) : targetEl;
            if (!el) return;
            // Set title dynamically
            el.setAttribute('data-bs-toggle', 'tooltip');
            el.setAttribute('data-bs-placement', 'top');
            el.setAttribute('data-bs-title', message);
            // Dispose existing tooltip if any
            if (el._bootstrapTooltipInstance) {
                el._bootstrapTooltipInstance.dispose();
            }
            const tip = new bootstrap.Tooltip(el, { trigger: 'manual' });
            el._bootstrapTooltipInstance = tip;
            tip.show();
            setTimeout(() => { try { tip.hide(); tip.dispose(); } catch(e) {} }, 2200);
        } catch (_) { /* noop */ }
    }

    function submitDeclineRequest() {
        if (!currentDeclineRequestId) {
            showInlineTip(document.querySelector('#declineModal .btn-decline') || '#declineModal', 'No request selected for decline');
            return;
        }

        const selectedReason = document.querySelector('input[name="declineReason"]:checked');
        if (!selectedReason) {
            showInlineTip(document.querySelector('#declineModal .decline-reasons') || '#declineModal', 'Please select a reason for declining');
            return;
        }

        let reason = selectedReason.value;
        let reasonText = selectedReason.nextElementSibling.querySelector('strong').textContent;
        
        // If "Other" reason is selected, get custom text
        if (reason === 'other') {
            const customReason = document.getElementById('customReason').value.trim();
            if (!customReason) {
                showInlineTip('#customReason', 'Please specify your custom reason');
                return;
            }
            reasonText = customReason;
        }

        // Show loading state
        const submitBtn = document.querySelector('#declineModal .btn-decline');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="material-icons">hourglass_empty</i>Processing...';
        submitBtn.disabled = true;

        // Submit decline request
        fetch('decline_help_request.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                request_id: currentDeclineRequestId,
                reason: reason,
                reason_text: reasonText
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Show success notification
                if (notificationSound) {
                    notificationSound.showToast('Request declined successfully', 'success');
                }
                
                // Close modal
                closeDeclineModal();
                
                // Remove the declined request from the UI
                const requestElement = document.querySelector(`[data-id="${currentDeclineRequestId}"]`);
                if (requestElement) {
                    requestElement.style.animation = 'fadeOut 0.5s ease-out forwards';
                    setTimeout(() => {
                        requestElement.remove();
                        // Update counts
                        updatePendingCount(document.querySelectorAll('.request-item').length);
                    }, 500);
                }
                
                // Refresh the requests list
                setTimeout(() => {
                    location.reload();
                }, 1000);
            } else {
                alert('Error declining request: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error declining request. Please try again.');
        })
        .finally(() => {
            // Reset button
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        });
    }

    // Function to update pending count
    function updatePendingCount(count) {
        const countElement = document.getElementById('pendingRequestsCount');
        const panel = document.getElementById('pendingRequestsPanel');
        const badge = document.getElementById('notificationBadge');
        
        if (countElement) {
            countElement.textContent = count;
        }
        
        if (badge) {
            if (count > 0) {
                badge.textContent = count;
                badge.style.display = 'inline-block';
            } else {
                badge.style.display = 'none';
            }
        }
        
        if (panel) {
            panel.classList.add('real-time-update');
            setTimeout(() => {
                panel.classList.remove('real-time-update');
            }, 1000);
        }
    }

    // Function to update requests list
    function updateRequestsList(requests) {
        console.log('updateRequestsList called with:', requests);
        const container = document.getElementById('requestsContainer');
        if (!container) {
            console.log('requestsContainer not found!');
            return;
        }

        // Store previous request IDs to detect new ones
        const previousRequestIds = new Set();
        const existingItems = container.querySelectorAll('.request-item');
        existingItems.forEach(item => {
            const requestId = item.getAttribute('data-id');
            if (requestId) {
                previousRequestIds.add(requestId);
            }
        });

        // Check if there are new requests
        const hasNewRequests = requests.some(request => !previousRequestIds.has(request.id.toString()));
        
        // If there are new requests, trigger panel slide-down animation
        if (hasNewRequests) {
            const panel = document.getElementById('pendingRequestsPanel');
            if (panel) {
                panel.classList.add('panel-slide-down');
                setTimeout(() => {
                    panel.classList.remove('panel-slide-down');
                }, 800);
            }
        }

        if (requests.length === 0) {
            container.innerHTML = `
                <div class="text-center" style="padding: 40px 20px; color: #666; background: #fff; border-radius: 8px;">
                    <i class="material-icons" style="font-size: 48px; margin-bottom: 10px;">build_circle</i>
                    <p style="font-size: 1.1em; color:#555;">No pending help requests in this area.</p>
                    <p>Check back later or select a different area from the filter above.</p>
                </div>
            `;
        } else {
            container.innerHTML = requests.map(request => {
                const imgPath = request.requestor_image && request.requestor_image.trim() !== '' 
                    ? '<?= $baseURL ?>' + request.requestor_image 
                    : 'https://ui-avatars.com/api/?name=' + encodeURIComponent(request.requestor_first_name + ' ' + request.requestor_last_name) + '&background=4CAF50&color=fff';
                
                const isNewRequest = !previousRequestIds.has(request.id.toString());
                const animationClass = isNewRequest ? 'request-fade-in new-request-highlight' : '';
                
                return `
                    <div class="request-item ${animationClass}" 
                         data-id="${request.id}"
                         data-name="${request.requestor_first_name} ${request.requestor_last_name}"
                         data-bike="${request.bike_unit}"
                         data-problem="${request.problem_description}"
                         data-location="${request.location}"
                         data-contact="${request.contact_info}"
                         data-barangay="${request.breakdown_barangay || 'Unknown'}"
                         data-plate="${request.plate_number || ''}"
                         data-lat="${request.latitude || ''}"
                         data-lng="${request.longitude || ''}"
                         data-requested="${new Date(request.created_at).toLocaleString('en-US', {
                             year: 'numeric', month: 'short', day: 'numeric',
                             hour: 'numeric', minute: '2-digit', hour12: true
                         })}">
                        
                        <div class="request-header" style="display: flex; flex-direction: column; align-items: center; gap: 8px; text-align: center;">
                            <img src="${imgPath}" alt="Customer" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid #eee;">
                            <div class="request-title" style="font-weight:bold; font-size:1.1em;">${request.requestor_first_name} ${request.requestor_last_name}</div>
                            <span class="request-status status-pending" style="align-self:center;">Pending</span>
                        </div>
                        <div class="request-details">
                            <table class="table table-bordered" style="margin-bottom:0; background:#fff;">
                                <tr><td style="font-weight:bold; width:180px;">Bike:</td><td>${request.bike_unit}</td></tr>
                                <tr><td style="font-weight:bold;">Problem:</td><td>${request.problem_description}</td></tr>
                                <tr><td style="font-weight:bold;">Barangay (Breakdown Location):</td><td>${request.breakdown_barangay || 'Unknown'}</td></tr>
                                <tr><td style="font-weight:bold;">Your Location / Landmark:</td><td>${request.location}</td></tr>
                                ${request.latitude && request.longitude ? 
                                    `<tr><td style="font-weight:bold;">Coordinates:</td><td>${parseFloat(request.latitude).toFixed(6)}, ${parseFloat(request.longitude).toFixed(6)}</td></tr>` : 
                                    ''
                                }
                            </table>
                        </div>
                        <div class="request-actions" style="justify-content:center;">
                            <button class="btn-action btn-accept" onclick="acceptRequest(${request.id})"><i class="material-icons">check</i>Accept</button>
                            <button class="btn-action btn-decline" onclick="showDeclineModal(${request.id})"><i class="material-icons">close</i>Decline</button>
                            <button class="btn-action btn-view-details" onclick="viewRequestDetails(this)"><i class="material-icons">map</i>View Details</button>
                        </div>
                    </div>
                `;
            }).join('');
        }
        
        // Remove animation classes after animation completes
        setTimeout(() => {
            const animatedItems = container.querySelectorAll('.request-fade-in');
            animatedItems.forEach(item => {
                item.classList.remove('request-fade-in');
            });
        }, 600);
        
        // Remove highlight animation after it completes
        setTimeout(() => {
            const highlightedItems = container.querySelectorAll('.new-request-highlight');
            highlightedItems.forEach(item => {
                item.classList.remove('new-request-highlight');
            });
        }, 2000);
        
        console.log('Requests list updated successfully');
    }

    // Function to update barangay counts
    function updateBarangayCounts(barangayCounts) {
        // Update "All Areas" count
        const allAreasBadge = document.getElementById('allAreasBadge');
        const totalCount = Object.values(barangayCounts).reduce((sum, item) => sum + item.count, 0);
        
        if (allAreasBadge) {
            if (totalCount > 0) {
                allAreasBadge.textContent = totalCount;
                allAreasBadge.style.display = 'inline-block';
            } else {
                allAreasBadge.style.display = 'none';
            }
        }
        
        // Update individual barangay counts
        Object.keys(barangayCounts).forEach(barangayId => {
            const badge = document.getElementById(`barangayBadge${barangayId}`);
            if (badge) {
                const count = barangayCounts[barangayId].count;
                if (count > 0) {
                    badge.textContent = count;
                    badge.style.display = 'inline-block';
                } else {
                    badge.style.display = 'none';
                }
            }
        });
    }

    // Function to connect to SSE
    function connectSSE() {
        if (eventSource) {
            eventSource.close();
        }

        const mechanicId = <?= $user_id ?>;
        const selectedBarangay = <?= $selectedBarangayId ?: 'null' ?>;
        const url = `sse_mechanic_dashboard.php?mechanic_id=${mechanicId}${selectedBarangay ? '&barangay=' + selectedBarangay : ''}`;

        eventSource = new EventSource(url);

        eventSource.onopen = function(event) {
            console.log('SSE connection opened');
            isConnected = true;
            document.getElementById('connectionStatus').className = 'connection-status connected';
        };

        eventSource.onmessage = function(event) {
            try {
                const data = JSON.parse(event.data);
                
                switch(data.type) {
                    case 'connection_established':
                        console.log('SSE connection established for mechanic:', data.mechanic_id);
                        // Update with initial data
                        if (data.pending_count !== undefined) {
                            updatePendingCount(data.pending_count);
                        }
                        if (data.requests !== undefined) {
                            updateRequestsList(data.requests);
                        }
                        if (data.barangay_counts !== undefined) {
                            updateBarangayCounts(data.barangay_counts);
                        }
                        break;
                        
                    case 'pending_count_update':
                        updatePendingCount(data.count);
                        if (data.count > 0) {
                            let notificationMsg = `You have ${data.count} new help requests!`;
                            if (data.estimated_time && data.estimated_date) {
                                notificationMsg += ` Estimated response time: ${data.estimated_date} at ${data.estimated_time}`;
                            }
                            showNotification(notificationMsg);
                        }
                        break;
                        
                    case 'requests_update':
                        console.log('Requests update received:', data.requests);
                        updateRequestsList(data.requests);
                        break;
                        
                    case 'barangay_counts_update':
                        console.log('Barangay counts update received:', data.barangay_counts);
                        updateBarangayCounts(data.barangay_counts);
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
            document.getElementById('connectionStatus').className = 'connection-status disconnected';
            
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
            document.getElementById('connectionStatus').className = 'connection-status disconnected';
        }
    }

    // Initialize SSE connection when page loads
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize notification sound system
        notificationSound = new NotificationSound({
            soundFile: '<?= $baseURL ?>uploads/Notify.mp3',
            volume: 0.7,
            enableMute: true,
            enableTest: true,
            storageKey: 'mechanicNotificationSoundSettings'
        });
        
        // Initialize notification sound without UI elements
        
        // Hide one-time mechanic loader after a short delay
        try {
            var overlay = document.getElementById('mechanicLoader');
            if (overlay && overlay.classList.contains('show')) {
                console.log('Mechanic loader visible: showing overlay');
                // Trigger smooth fade and subtle logo zoom
                var core = overlay.querySelector('.mechanic-loader');
                setTimeout(function(){
                    if (core) core.classList.add('fading');
                    overlay.classList.add('fading');
                    setTimeout(function(){ overlay.classList.remove('show'); overlay.classList.remove('fading'); if (core) core.classList.remove('fading'); console.log('Mechanic loader hidden'); }, 650);
                }, 700);
            } else {
                console.log('Mechanic loader not shown');
            }
        } catch(e) { try { console.warn('Loader init error', e); } catch(_) {} }

        // Initialize SSE connection
        connectSSE();
    });

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
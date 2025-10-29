<?php
// Prevent any output before SSE
error_reporting(0);
ini_set('display_errors', 0);

// Set proper headers for SSE
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header("Access-Control-Allow-Origin: *");
header("X-Accel-Buffering: no"); // Disable nginx buffering

// Simple error handling
try {
    require 'dbconn.php';
    
    // Test database connection
    if ($conn->connect_error) {
        throw new Exception("Database connection failed");
    }
} catch (Exception $e) {
    // Send error event and exit
    echo "data: " . json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]) . "\n\n";
    ob_flush();
    flush();
    exit;
}

function sendEvent($data) {
    echo "data: " . json_encode($data) . "\n\n";
    ob_flush();
    flush();
}

// Get mechanic ID from query parameter
$mechanic_id = isset($_GET['mechanic_id']) ? (int)$_GET['mechanic_id'] : 0;
$selected_barangay = isset($_GET['barangay']) ? (int)$_GET['barangay'] : null;

if (!$mechanic_id) {
    sendEvent(['error' => 'Mechanic ID required']);
    exit;
}

// Function to get pending help request count
function getPendingHelpRequestCount($conn, $barangayId = null) {
    $sql = "SELECT COUNT(*) FROM help_requests WHERE status = 'Pending'";
    if ($barangayId) {
        $sql .= " AND breakdown_barangay_id = ?";
    }

    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        error_log("Prepare failed: " . $conn->error);
        return 0;
    }

    if ($barangayId) {
        $stmt->bind_param("i", $barangayId);
    }

    $stmt->execute();
    $count = 0;
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return $count;
}

// Function to get pending help requests
function getPendingHelpRequests($conn, $barangayId = null) {
    $sql = "SELECT hr.*, u.first_name AS requestor_first_name, u.last_name AS requestor_last_name, u.phone_number, u.purok, u.ImagePath AS requestor_image, b.barangay_name AS home_barangay, bb.barangay_name AS breakdown_barangay
            FROM help_requests hr
            JOIN users u ON hr.user_id = u.id
            LEFT JOIN barangays b ON u.barangay_id = b.id
            LEFT JOIN barangays bb ON hr.breakdown_barangay_id = bb.id
            WHERE hr.status = 'Pending'";
    
    if ($barangayId) {
        $sql .= " AND hr.breakdown_barangay_id = ?";
    }
    
    $sql .= " ORDER BY hr.created_at DESC";

    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        return [];
    }

    if ($barangayId) {
        $stmt->bind_param("i", $barangayId);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $requests = [];

    while ($row = $result->fetch_assoc()) {
        $requests[] = $row;
    }

    $stmt->close();
    return $requests;
}

// Function to get barangay counts
function getBarangayCounts($conn) {
    $sql = "SELECT b.id, b.barangay_name, COUNT(hr.id) as request_count
            FROM barangays b
            LEFT JOIN help_requests hr ON b.id = hr.breakdown_barangay_id AND hr.status = 'Pending'
            GROUP BY b.id, b.barangay_name
            ORDER BY b.barangay_name";
    
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        return [];
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $counts = [];

    while ($row = $result->fetch_assoc()) {
        $counts[$row['id']] = [
            'barangay_name' => $row['barangay_name'],
            'count' => (int)$row['request_count']
        ];
    }

    $stmt->close();
    return $counts;
}

// Send initial connection success message with current data
$initial_pending_count = getPendingHelpRequestCount($conn, $selected_barangay);
$initial_requests = getPendingHelpRequests($conn, $selected_barangay);
$initial_barangay_counts = getBarangayCounts($conn);

sendEvent([
    "type" => "connection_established",
    "mechanic_id" => $mechanic_id,
    "selected_barangay" => $selected_barangay,
    "pending_count" => $initial_pending_count,
    "requests" => $initial_requests,
    "barangay_counts" => $initial_barangay_counts,
    "timestamp" => date('Y-m-d H:i:s')
]);

// Main loop
$counter = 0;
$last_pending_count = $initial_pending_count;
$last_requests_hash = md5(json_encode($initial_requests));
$last_barangay_counts_hash = md5(json_encode($initial_barangay_counts));

while ($counter < 1000) { // Limit to prevent infinite loops
    try {
        // Check if client is still connected
        if (connection_aborted()) {
            break;
        }
        
        // Get current pending count
        $current_pending_count = getPendingHelpRequestCount($conn, $selected_barangay);
        
        // Get current requests
        $current_requests = getPendingHelpRequests($conn, $selected_barangay);
        $current_requests_hash = md5(json_encode($current_requests));
        
        // Get current barangay counts
        $current_barangay_counts = getBarangayCounts($conn);
        $current_barangay_counts_hash = md5(json_encode($current_barangay_counts));
        
        // Check if pending count changed
        if ($current_pending_count != $last_pending_count) {
            // Calculate estimated time within the day (end of today)
            $now = new DateTime();
            $endOfDay = new DateTime();
            $endOfDay->setTime(23, 59, 59);
            $estimatedTime = $endOfDay->format('g:i A');
            $estimatedDate = $endOfDay->format('F j, Y');
            
            sendEvent([
                "type" => "pending_count_update",
                "count" => $current_pending_count,
                "timestamp" => date('Y-m-d H:i:s'),
                "estimated_time" => $estimatedTime,
                "estimated_date" => $estimatedDate
            ]);
            $last_pending_count = $current_pending_count;
        }
        
        // Check if requests changed
        if ($current_requests_hash != $last_requests_hash) {
            error_log("Requests changed for mechanic $mechanic_id. Count: " . count($current_requests));
            sendEvent([
                "type" => "requests_update",
                "requests" => $current_requests,
                "count" => count($current_requests),
                "timestamp" => date('Y-m-d H:i:s')
            ]);
            $last_requests_hash = $current_requests_hash;
        }
        
        // Check if barangay counts changed
        if ($current_barangay_counts_hash != $last_barangay_counts_hash) {
            sendEvent([
                "type" => "barangay_counts_update",
                "barangay_counts" => $current_barangay_counts,
                "timestamp" => date('Y-m-d H:i:s')
            ]);
            $last_barangay_counts_hash = $current_barangay_counts_hash;
        }
        
        // Send heartbeat every 6 iterations (30 seconds with 5-second sleep)
        if ($counter % 6 == 0) {
            sendEvent([
                "type" => "heartbeat",
                "pending_count" => $current_pending_count,
                "requests_count" => count($current_requests),
                "timestamp" => date('Y-m-d H:i:s')
            ]);
        }
        
        sleep(5); // Check every 5 seconds
        $counter++;
        
    } catch (Exception $e) {
        // Send error and break
        sendEvent([
            "type" => "error",
            "error" => "Connection error: " . $e->getMessage(),
            "timestamp" => date('Y-m-d H:i:s')
        ]);
        break;
    }
}

$conn->close();
?>

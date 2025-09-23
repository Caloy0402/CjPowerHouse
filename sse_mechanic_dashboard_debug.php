<?php
// Debug version with error reporting enabled
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set proper headers for SSE
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header("Access-Control-Allow-Origin: *");
header("X-Accel-Buffering: no");

function sendEvent($data) {
    echo "data: " . json_encode($data) . "\n\n";
    ob_flush();
    flush();
}

// Get mechanic ID from query parameter
$mechanic_id = isset($_GET['mechanic_id']) ? (int)$_GET['mechanic_id'] : 0;
$selected_barangay = isset($_GET['barangay']) ? (int)$_GET['barangay'] : null;

sendEvent(['debug' => 'SSE endpoint started', 'mechanic_id' => $mechanic_id, 'barangay' => $selected_barangay]);

if (!$mechanic_id) {
    sendEvent(['error' => 'Mechanic ID required']);
    exit;
}

try {
    require 'dbconn.php';
    sendEvent(['debug' => 'Database connection file loaded']);
    
    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }
    sendEvent(['debug' => 'Database connected successfully']);
    
    // Test a simple query
    $test_sql = "SELECT COUNT(*) as count FROM help_requests WHERE status = 'Pending'";
    $result = $conn->query($test_sql);
    
    if ($result) {
        $row = $result->fetch_assoc();
        sendEvent(['debug' => 'Test query successful', 'pending_count' => $row['count']]);
    } else {
        sendEvent(['debug' => 'Test query failed', 'error' => $conn->error]);
    }
    
    // Send initial data
    sendEvent([
        "type" => "connection_established",
        "mechanic_id" => $mechanic_id,
        "selected_barangay" => $selected_barangay,
        "pending_count" => $row['count'] ?? 0,
        "timestamp" => date('Y-m-d H:i:s')
    ]);
    
    // Send a few test events
    for ($i = 1; $i <= 3; $i++) {
        sleep(2);
        sendEvent([
            "type" => "test",
            "message" => "Test event $i",
            "timestamp" => date('Y-m-d H:i:s')
        ]);
    }
    
    sendEvent(['debug' => 'SSE test completed successfully']);
    
} catch (Exception $e) {
    sendEvent(['error' => 'Exception occurred: ' . $e->getMessage()]);
}

$conn->close();
?>

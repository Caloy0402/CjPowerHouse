<?php
// Prevent any output before SSE
error_reporting(0);
ini_set('display_errors', 0);

// Set proper headers for SSE
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header("Access-Control-Allow-Origin: *");

// Simple error handling
try {
    require 'dbconn.php';
    
    // Test database connection
    if ($conn->connect_error) {
        throw new Exception("Database connection failed");
    }
} catch (Exception $e) {
    // Send error event and exit
    echo "data: " . json_encode(['error' => 'Database connection failed']) . "\n\n";
    ob_flush();
    flush();
    exit;
}

function sendEvent($data) {
    echo "data: " . json_encode($data) . "\n\n";
    ob_flush();
    flush();
}

// Simple loop with error handling
$counter = 0;
while ($counter < 100) { // Limit to prevent infinite loops
    try {
        // Check if client is still connected
        if (connection_aborted()) {
            break;
        }
        
        // Simple notification count
        $total = 0;
        
        // Safe queries with error handling
        $queries = [
            "SELECT COUNT(*) as count FROM orders WHERE order_status = 'pending'",
            "SELECT COUNT(*) as count From Products WHERE Quantity <= 10",
            "SELECT COUNT(*) as count FROM orders WHERE payment_method = 'COD' AND order_status = 'Pending'"
        ];
        
        foreach ($queries as $query) {
            $result = $conn->query($query);
            if ($result) {
                $count = $result->fetch_assoc()['count'];
                $total += $count;
            }
        }
        
        // Send heartbeat
        sendEvent([
            "heartbeat" => true, 
            "notification_count" => $total,
            "timestamp" => date('Y-m-d H:i:s')
        ]);
        
        sleep(10); // Check every 10 seconds
        $counter++;
        
    } catch (Exception $e) {
        // Send error and break
        sendEvent(["error" => "Connection error"]);
        break;
    }
}

$conn->close();
?>

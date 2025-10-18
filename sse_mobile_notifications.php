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

// Get user ID from query parameter
$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

if (!$user_id) {
    sendEvent(['error' => 'User ID required']);
    exit;
}

// Send initial connection success message
sendEvent([
    "type" => "connection_established",
    "user_id" => $user_id,
    "timestamp" => date('Y-m-d H:i:s')
]);

// Simple loop with error handling
$counter = 0;
$last_notification_count = 0;

while ($counter < 1000) { // Limit to prevent infinite loops
    try {
        // Check if client is still connected
        if (connection_aborted()) {
            break;
        }
        
        // Get current notification count for this user
        $sql = "SELECT COUNT(*) as count FROM orders WHERE user_id = ? AND order_status IN ('On-Ship', 'Ready to Ship', 'Delivered')";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Failed to prepare statement: " . $conn->error);
        }
        
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $current_count = 0;
        
        if ($result && $row = $result->fetch_assoc()) {
            $current_count = $row['count'];
        }
        $stmt->close();
        
        // Check for declined help requests (only send once per decline)
        static $last_declined_request_id = null;
        $decline_sql = "SELECT id, status, decline_reason, decline_reason_text, declined_at FROM help_requests WHERE user_id = ? AND status = 'Declined' ORDER BY declined_at DESC LIMIT 1";
        $decline_stmt = $conn->prepare($decline_sql);
        if ($decline_stmt) {
            $decline_stmt->bind_param("i", $user_id);
            $decline_stmt->execute();
            $decline_result = $decline_stmt->get_result();
            
            if ($decline_result && $decline_row = $decline_result->fetch_assoc()) {
                // Only send if this is a different declined request than the last one
                if ($last_declined_request_id !== $decline_row['id']) {
                    // Check if this is a new decline (within last 60 seconds)
                    $declined_time = strtotime($decline_row['declined_at']);
                    $current_time = time();
                    
                    if (($current_time - $declined_time) <= 60) {
                        sendEvent([
                            "type" => "help_request_declined",
                            "request_id" => $decline_row['id'],
                            "decline_reason" => $decline_row['decline_reason'],
                            "decline_reason_text" => $decline_row['decline_reason_text'],
                            "declined_at" => $decline_row['declined_at'],
                            "timestamp" => date('Y-m-d H:i:s')
                        ]);
                        $last_declined_request_id = $decline_row['id'];
                    }
                }
            }
            $decline_stmt->close();
        }
        
        // Check if there are new notifications
        if ($current_count > $last_notification_count) {
            try {
                // Get the latest notifications - using only columns that exist
                $sql = "SELECT o.id as order_id, o.order_status, o.order_date, o.delivery_fee,
                               GROUP_CONCAT(p.ProductName SEPARATOR ', ') as items
                        FROM orders o
                        LEFT JOIN order_items oi ON o.id = oi.order_id
                        LEFT JOIN products p ON oi.product_id = p.ProductID
                        WHERE o.user_id = ? AND o.order_status IN ('On-Ship', 'Ready to Ship', 'Delivered')
                        GROUP BY o.id
                        ORDER BY o.order_date DESC
                        LIMIT 5";
                
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception("Failed to prepare notification statement: " . $conn->error);
                }
                
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                $notifications = [];
                while ($row = $result->fetch_assoc()) {
                    $notifications[] = $row;
                }
                $stmt->close();
                
                // Send notification event
                sendEvent([
                    "type" => "new_notification",
                    "notification_count" => $current_count,
                    "notifications" => $notifications,
                    "timestamp" => date('Y-m-d H:i:s')
                ]);
                
                $last_notification_count = $current_count;
                
            } catch (Exception $e) {
                // Log the error and send it to the client
                sendEvent([
                    "type" => "error",
                    "error" => "Notification query failed: " . $e->getMessage(),
                    "timestamp" => date('Y-m-d H:i:s')
                ]);
            }
        }
        
        // Send heartbeat every 12 iterations (60 seconds with 5-second sleep)
        if ($counter % 12 == 0) {
            sendEvent([
                "type" => "heartbeat", 
                "notification_count" => $current_count,
                "timestamp" => date('Y-m-d H:i:s')
            ]);
        }
        
        sleep(5); // Check every 5 seconds for more responsiveness
        $counter++;
        
    } catch (Exception $e) {
        // Send error and break
        sendEvent([
            "error" => "Connection error: " . $e->getMessage(),
            "timestamp" => date('Y-m-d H:i:s')
        ]);
        break;
    }
}

$conn->close();
?>

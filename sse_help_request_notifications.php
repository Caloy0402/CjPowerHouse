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
$last_help_request_status = null;

while ($counter < 1000) { // Limit to prevent infinite loops
    try {
        // Check if client is still connected
        if (connection_aborted()) {
            break;
        }
        
        // Get current help request status for this user
        $sql = "SELECT hr.*, bb.barangay_name, m.first_name AS mechanic_first_name, m.last_name AS mechanic_last_name
                FROM help_requests hr
                LEFT JOIN barangays bb ON hr.breakdown_barangay_id = bb.id
                LEFT JOIN mechanics m ON hr.mechanic_id = m.id
                WHERE hr.user_id = ?
                ORDER BY hr.created_at DESC
                LIMIT 1";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Failed to prepare statement: " . $conn->error);
        }
        
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $current_help_request = null;
        
        if ($result && $row = $result->fetch_assoc()) {
            $current_help_request = $row;
        }
        $stmt->close();
        
        // Check for status changes
        if ($current_help_request) {
            $current_status = $current_help_request['status'];
            
            // If status changed, send notification
            if ($last_help_request_status !== null && $last_help_request_status !== $current_status) {
                $notification_message = "";
                $notification_type = "";
                
                switch ($current_status) {
                    case 'In Progress':
                        $notification_message = "Your help request has been accepted! A mechanic is on the way.";
                        $notification_type = "request_accepted";
                        break;
                    case 'Completed':
                        $notification_message = "Your help request has been completed successfully!";
                        $notification_type = "request_completed";
                        break;
                    case 'Declined':
                        $decline_reason = $current_help_request['decline_reason_text'] ?? 'No reason provided';
                        $notification_message = "Your help request has been declined. Reason: " . $decline_reason;
                        $notification_type = "request_declined";
                        break;
                    case 'Cancelled':
                        $notification_message = "Your help request has been cancelled.";
                        $notification_type = "request_cancelled";
                        break;
                }
                
                if ($notification_message) {
                    sendEvent([
                        "type" => "help_request_update",
                        "status" => $current_status,
                        "message" => $notification_message,
                        "notification_type" => $notification_type,
                        "help_request" => $current_help_request,
                        "timestamp" => date('Y-m-d H:i:s')
                    ]);
                }
            }
            
            $last_help_request_status = $current_status;
        }
        
        // Get notification count
        $sql = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = FALSE";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $current_count = 0;
            
            if ($result && $row = $result->fetch_assoc()) {
                $current_count = $row['count'];
            }
            $stmt->close();
            
            // Check if there are new notifications
            if ($current_count > $last_notification_count) {
                // Get the latest notifications
                $sql = "SELECT * FROM notifications 
                        WHERE user_id = ? 
                        ORDER BY created_at DESC 
                        LIMIT 5";
                
                $stmt = $conn->prepare($sql);
                if ($stmt) {
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
                }
            }
        }
        
        // Send heartbeat every 30 seconds
        if ($counter % 6 == 0) {
            sendEvent([
                "type" => "heartbeat",
                "timestamp" => date('Y-m-d H:i:s'),
                "user_id" => $user_id
            ]);
        }
        
        // Sleep for 5 seconds before next check
        sleep(5);
        $counter++;
        
    } catch (Exception $e) {
        // Send error event
        sendEvent([
            "type" => "error",
            "error" => $e->getMessage(),
            "timestamp" => date('Y-m-d H:i:s')
        ]);
        
        // Sleep before retrying
        sleep(10);
        $counter++;
    }
}

// Send connection closed event
sendEvent([
    "type" => "connection_closed",
    "timestamp" => date('Y-m-d H:i:s')
]);

$conn->close();
?>

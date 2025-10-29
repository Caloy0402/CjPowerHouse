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
$last_order_statuses = [];

while ($counter < 1000) { // Limit to prevent infinite loops
    try {
        // Check if client is still connected
        if (connection_aborted()) {
            break;
        }
        
        // Get current orders for this user
        $sql = "SELECT o.id, o.order_status, o.order_date, o.total_price, o.delivery_fee, o.total_amount_with_delivery,
                       o.total_weight, o.delivery_method, o.payment_method,
                       t.transaction_number,
                       GROUP_CONCAT(p.ProductName SEPARATOR ', ') as items
                FROM orders o
                JOIN transactions t ON o.id = t.order_id
                LEFT JOIN order_items oi ON o.id = oi.order_id
                LEFT JOIN products p ON oi.product_id = p.ProductID
                WHERE o.user_id = ?
                GROUP BY o.id
                ORDER BY o.order_date DESC";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Failed to prepare statement: " . $conn->error);
        }
        
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $current_orders = [];
        while ($row = $result->fetch_assoc()) {
            $current_orders[$row['id']] = $row;
        }
        $stmt->close();
        
        // Check for status changes
        foreach ($current_orders as $order_id => $order) {
            $current_status = $order['order_status'];
            $last_status = isset($last_order_statuses[$order_id]) ? $last_order_statuses[$order_id] : null;
            
            // If status changed, send notification
            if ($last_status && $last_status !== $current_status) {
                // Determine notification message based on status change
                $status_messages = [
                    'Pending' => 'Your order is now pending',
                    'Processing' => 'Your order is being processed',
                    'Ready to Ship' => 'Your order is ready to ship',
                    'On-Ship' => 'Your order is on its way',
                    'Completed' => 'Your order has been completed',
                    'Returned' => 'Your order has been returned',
                    'Canceled' => 'Your order has been canceled'
                ];
                
                // Special handling for pickup orders
                if ($order['delivery_method'] === 'pickup' && $current_status === 'Ready to Ship') {
                    $message = 'Your pickup order is ready for collection!';
                } else {
                    $message = isset($status_messages[$current_status]) ? $status_messages[$current_status] : "Your order status has been updated to: " . $current_status;
                }
                
                // Calculate estimated delivery date and time for "Ready to Ship" and "On-Ship" statuses
                $estimatedTime = null;
                $estimatedDate = null;
                
                if ($current_status === 'Ready to Ship' || $current_status === 'On-Ship') {
                    $now = new DateTime();
                    // Estimate delivery within the same day by end of day (11:59 PM)
                    $endOfDay = new DateTime();
                    $endOfDay->setTime(23, 59, 59);
                    $estimatedTime = $endOfDay->format('g:i A');
                    $estimatedDate = $endOfDay->format('F j, Y');
                }
                
                sendEvent([
                    "type" => "order_status_update",
                    "order_id" => $order_id,
                    "old_status" => $last_status,
                    "new_status" => $current_status,
                    "message" => $message,
                    "order_data" => $order,
                    "timestamp" => date('Y-m-d H:i:s'),
                    "estimated_time" => $estimatedTime,
                    "estimated_date" => $estimatedDate
                ]);
            }
        }
        
        // Update last known statuses
        $last_order_statuses = [];
        foreach ($current_orders as $order_id => $order) {
            $last_order_statuses[$order_id] = $order['order_status'];
        }
        
        // Send heartbeat every 12 iterations (60 seconds with 5-second sleep)
        if ($counter % 12 == 0) {
            sendEvent([
                "type" => "heartbeat", 
                "order_count" => count($current_orders),
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

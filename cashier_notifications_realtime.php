<?php
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Cache-Control');

// Prevent timeout
set_time_limit(0);

require 'dbconn.php';

// Start session (read-only) and immediately release the lock to avoid blocking other requests
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo "event: error\n";
    echo "data: " . json_encode(['error' => 'Not authenticated']) . "\n\n";
    exit;
}

// Capture the user id we need, then release the session lock so long-running SSE doesn't block AJAX
$sse_user_id = (int)$_SESSION['user_id'];
session_write_close();

function getCashierNotifications($conn, $user_id) {
    // Fetch pending orders that need cashier attention (COD, GCASH, and Pickup)
    $sql = "SELECT
                o.id AS order_id,
                o.transaction_id,
                t.transaction_number,
                o.order_status,
                o.order_date,
                o.total_price,
                o.payment_method,
                o.user_id,
                o.delivery_method,
                o.rider_name,
                o.rider_motor_type,
                o.rider_plate_number,
                o.delivery_fee,
                o.total_amount_with_delivery,
                u.barangay_id,
                bf.fare_amount,
                bf.staff_fare_amount,
                GROUP_CONCAT(CONCAT(p.ProductName, ' (', oi.quantity, ')') SEPARATOR ', ') AS items
            FROM orders o
            LEFT JOIN transactions t ON o.id = t.order_id
            LEFT JOIN order_items oi ON o.id = oi.order_id
            LEFT JOIN products p ON oi.product_id = p.ProductID
            LEFT JOIN users u ON o.user_id = u.id
            LEFT JOIN barangay_fares bf ON u.barangay_id = bf.barangay_id
            WHERE (
                -- COD orders (pending)
                (o.payment_method = 'COD' AND LOWER(o.order_status) = 'pending')
                OR
                -- GCASH orders (pending payment or processing)
                (o.payment_method = 'GCASH' AND o.order_status IN ('Pending Payment','Processing'))
                OR
                -- Pickup orders (pending or ready to ship)
                (o.delivery_method = 'pickup' AND o.order_status IN ('Pending', 'Ready to Ship'))
            )
            GROUP BY o.id
            ORDER BY o.order_date DESC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $notifications = [];
    while ($row = $result->fetch_assoc()) {
        // Compute delivery fee and total with delivery with fallbacks
        $subtotal = (float)$row['total_price'];
        $deliveryFee = 0.0;

        // For pickup orders, delivery fee is usually 0
        if ($row['delivery_method'] === 'pickup') {
            $deliveryFee = 0.0;
        } else {
            if (!empty($row['delivery_fee']) && (float)$row['delivery_fee'] > 0) {
                $deliveryFee = (float)$row['delivery_fee'];
            } else {
                // Use appropriate fare based on delivery method
                if ($row['delivery_method'] === 'staff' && !empty($row['staff_fare_amount'])) {
                    $deliveryFee = (float)$row['staff_fare_amount'];
                } else if (!empty($row['fare_amount'])) {
                    $deliveryFee = (float)$row['fare_amount'];
                }
            }
        }
        
        $totalWithDelivery = $subtotal + $deliveryFee;
        if (!empty($row['total_amount_with_delivery']) && (float)$row['total_amount_with_delivery'] > 0) {
            $totalWithDelivery = (float)$row['total_amount_with_delivery'];
        }

        $orderNumber = !empty($row['transaction_number']) ? $row['transaction_number'] : (!empty($row['transaction_id']) ? $row['transaction_id'] : $row['order_id']);
        $notifications[] = [
            'order_id' => $row['order_id'],
            'transaction_number' => $orderNumber,
            'order_status' => $row['order_status'],
            'order_date' => date('M d, Y h:i A', strtotime($row['order_date'])),
            'total_price' => number_format($subtotal, 2),
            'delivery_fee' => number_format($deliveryFee, 2),
            'total_with_delivery' => number_format($totalWithDelivery, 2),
            'payment_method' => $row['payment_method'],
            'delivery_method' => $row['delivery_method'],
            'rider_name' => $row['rider_name'],
            'rider_motor_type' => $row['rider_motor_type'],
            'rider_plate_number' => $row['rider_plate_number'],
            'items' => $row['items']
        ];
    }

    $stmt->close();
    return $notifications;
}

function getNotificationCount($conn, $user_id) {
    try {
        $count = 0;
        // Check if notifications table exists
        $result = $conn->query("SHOW TABLES LIKE 'notifications'");
        if ($result->num_rows > 0) {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->bind_result($count);
            $stmt->fetch();
            $stmt->close();
            return (int)$count;
        } else {
            return 0; // Table doesn't exist
        }
    } catch (Exception $e) {
        return 0; // Return 0 on any error
    }
}

// Store previous notifications to detect changes
$previous_notifications = [];
$previous_notification_count = 0;

// Keep connection alive and send updates
while (true) {
    // Check if connection is still alive
    if (connection_aborted()) {
        break;
    }

    try {
        // Get current notifications
        $current_notifications = getCashierNotifications($conn, $sse_user_id);
        $current_notification_count = count($current_notifications);

        // Check for new orders that need cashier attention
        if ($previous_notifications !== null) {
            $notifications_changed = false;

            // Check if count changed
            if ($current_notification_count != $previous_notification_count) {
                $notifications_changed = true;
            }

            // Check if any new orders were added
            if (!$notifications_changed && $current_notification_count > 0) {
                $current_ids = array_column($current_notifications, 'order_id');
                $previous_ids = array_column($previous_notifications, 'order_id');

                $new_orders = array_diff($current_ids, $previous_ids);
                if (!empty($new_orders)) {
                    $notifications_changed = true;
                }
            }

            if ($notifications_changed) {
                $new_orders_count = $current_notification_count - $previous_notification_count;

                echo "event: cashier_notification_update\n";
                echo "data: " . json_encode([
                    'count' => $current_notification_count,
                    'notifications' => $current_notifications,
                    'new_orders' => $new_orders_count,
                    'new_order_data' => $new_orders_count > 0 ? array_slice($current_notifications, 0, $new_orders_count) : []
                ]) . "\n\n";
                ob_flush();
                flush();
            }
        }

        // Store current notifications for next iteration
        $previous_notifications = $current_notifications;
        $previous_notification_count = $current_notification_count;

        // Send heartbeat every 30 seconds
        echo "event: heartbeat\n";
        echo "data: " . json_encode(['timestamp' => time()]) . "\n\n";
        ob_flush();
        flush();

    } catch (Exception $e) {
        echo "event: error\n";
        echo "data: " . json_encode(['error' => $e->getMessage()]) . "\n\n";
        ob_flush();
        flush();
    }

    // Wait 3 seconds before next check (faster for better responsiveness)
    sleep(3);
}

$conn->close();
?>

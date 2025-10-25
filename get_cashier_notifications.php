<?php
session_start();
require_once 'dbconn.php';

// Ensure JSON responses
header('Content-Type: application/json');

// Check if user is logged in and is a cashier
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch pending orders with details for the cashier
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
            -- delivery fee columns if present
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

// Prepare and execute safely with clear error feedback
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Query prepare failed', 'details' => $conn->error]);
    exit();
}

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['error' => 'Query execution failed', 'details' => $stmt->error]);
    exit();
}

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
        // Always calculate delivery fee - removed free shipping logic
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
$conn->close();

// Return notifications as JSON
echo json_encode(['notifications' => $notifications]);
?>
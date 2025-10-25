<?php
session_start();
require 'dbconn.php';

header('Content-Type: application/json');

if (!isset($_GET['order_id']) || empty($_GET['order_id'])) {
    echo json_encode(['success' => false, 'message' => 'Order ID is required']);
    exit;
}

$orderId = (int)$_GET['order_id'];

// Get order details - Fixed query based on actual DB schema
$sql = "SELECT o.id, o.user_id, o.total_price, o.order_status, o.order_date, o.payment_method,
               t.transaction_number,
               u.first_name, u.last_name, u.phone_number, u.email, u.ImagePath
        FROM orders o
        LEFT JOIN transactions t ON o.id = t.order_id
        JOIN users u ON o.user_id = u.id
        WHERE o.id = ? AND o.delivery_method = 'pickup'";

// Debug: Log the query and parameters
error_log("Pickup order query: " . $sql);
error_log("Order ID parameter: " . $orderId);

$stmt = $conn->prepare($sql);
if ($stmt === false) {
    echo json_encode(['success' => false, 'message' => 'Database prepare failed: ' . $conn->error]);
    exit;
}

$stmt->bind_param("i", $orderId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Try without delivery_method filter to see if the order exists
    $fallback_sql = "SELECT o.id, o.user_id, o.total_price, o.order_status, o.order_date, o.payment_method, o.delivery_method,
                           t.transaction_number,
                           u.first_name, u.last_name, u.phone_number, u.email, u.ImagePath
                    FROM orders o
                    LEFT JOIN transactions t ON o.id = t.order_id
                    JOIN users u ON o.user_id = u.id
                    WHERE o.id = ?";
    
    $fallback_stmt = $conn->prepare($fallback_sql);
    if ($fallback_stmt) {
        $fallback_stmt->bind_param("i", $orderId);
        $fallback_stmt->execute();
        $fallback_result = $fallback_stmt->get_result();
        
        if ($fallback_result->num_rows > 0) {
            $fallback_order = $fallback_result->fetch_assoc();
            error_log("Order found but with different delivery_method: " . ($fallback_order['delivery_method'] ?: 'NULL'));
            echo json_encode(['success' => false, 'message' => 'Order found but not a pickup order. Delivery method: ' . ($fallback_order['delivery_method'] ?: 'NULL')]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Order not found']);
        }
        $fallback_stmt->close();
    } else {
        echo json_encode(['success' => false, 'message' => 'Order not found']);
    }
    exit;
}

$order = $result->fetch_assoc();

// Debug: Log the retrieved order data
error_log("Retrieved order data: " . json_encode($order));

// Debug: Check if customer data is missing
if (empty($order['first_name']) || empty($order['last_name'])) {
    error_log("Customer data missing for order ID: " . $orderId);
    error_log("User ID from order: " . ($order['user_id'] ?? 'NULL'));
    
    // Try to get user data separately if join failed
    if (!empty($order['user_id'])) {
        $user_sql = "SELECT first_name, last_name, phone_number, email, ImagePath FROM users WHERE id = ?";
        $user_stmt = $conn->prepare($user_sql);
        if ($user_stmt) {
            $user_stmt->bind_param("i", $order['user_id']);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            if ($user_result->num_rows > 0) {
                $user_data = $user_result->fetch_assoc();
                error_log("Found user data separately: " . json_encode($user_data));
                // Merge user data into order data
                $order = array_merge($order, $user_data);
            } else {
                error_log("No user found with ID: " . $order['user_id']);
            }
            $user_stmt->close();
        }
    }
}

// Get order items
$sql_items = "SELECT oi.product_id, oi.quantity, oi.price,
                     p.ProductName, p.ImagePath as product_image
              FROM order_items oi
              JOIN products p ON oi.product_id = p.ProductID
              WHERE oi.order_id = ?";

$stmt_items = $conn->prepare($sql_items);
$stmt_items->bind_param("i", $orderId);
$stmt_items->execute();
$result_items = $stmt_items->get_result();

$items = [];
while ($item = $result_items->fetch_assoc()) {
    $imagePath = $item['product_image'] ?: '';
    if (!empty($imagePath) && strpos($imagePath, 'uploads/') !== 0) {
        $imagePath = 'uploads/' . ltrim($imagePath, '/');
    }
    $items[] = [
        'name' => $item['ProductName'],
        'quantity' => $item['quantity'],
        'price' => $item['price'],
        'product_image' => $imagePath ?: 'img/shifter.png'
    ];
}

// Debug: Log final order data before response
error_log("Final order data for response: " . json_encode($order));
error_log("Transaction number: " . ($order['transaction_number'] ?: 'NULL'));
error_log("Customer name: " . trim(($order['first_name'] ?: '') . ' ' . ($order['last_name'] ?: '')));

// If transaction number is missing, try to get it separately
if (empty($order['transaction_number'])) {
    $trans_sql = "SELECT transaction_number FROM transactions WHERE order_id = ?";
    $trans_stmt = $conn->prepare($trans_sql);
    if ($trans_stmt) {
        $trans_stmt->bind_param("i", $orderId);
        $trans_stmt->execute();
        $trans_result = $trans_stmt->get_result();
        if ($trans_result->num_rows > 0) {
            $trans_data = $trans_result->fetch_assoc();
            $order['transaction_number'] = $trans_data['transaction_number'];
            error_log("Found transaction number separately: " . $trans_data['transaction_number']);
        } else {
            error_log("No transaction found for order ID: " . $orderId);
        }
        $trans_stmt->close();
    }
}

// Prepare response
$response = [
    'success' => true,
    'order' => [
        'id' => $order['id'],
        'transaction_number' => $order['transaction_number'] ?: 'N/A',
        'order_date' => date('M d, Y g:i A', strtotime($order['order_date'])),
        'customer_name' => trim(($order['first_name'] ?: '') . ' ' . ($order['last_name'] ?: '')) ?: 'Unknown Customer',
        'customer_phone' => $order['phone_number'] ?: 'N/A',
        'customer_email' => $order['email'] ?: 'N/A',
        'customer_image' => $order['ImagePath'] ?: 'img/user.jpg',
        'payment_method' => $order['payment_method'] ?: 'pickup',
        'total_price' => $order['total_price'] ?: 0,
        'order_status' => $order['order_status'] ?: 'Pending',
        'items' => $items
    ]
];


echo json_encode($response);

$stmt->close();
$stmt_items->close();
$conn->close();
?>

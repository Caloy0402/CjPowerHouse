<?php
// Ultra-simple API to avoid timeouts
session_start();
require 'dbconn.php';

header('Content-Type: application/json');

if (!isset($_GET['order_id']) || empty($_GET['order_id'])) {
    echo json_encode(['success' => false, 'message' => 'Order ID is required']);
    exit;
}

$orderId = (int)$_GET['order_id'];

// Simple query with minimal joins
$sql = "SELECT o.id, o.total_price, o.order_status, o.order_date, o.payment_method,
               u.first_name, u.last_name, u.ImagePath
        FROM orders o
        JOIN users u ON o.user_id = u.id
        WHERE o.id = ? AND o.delivery_method = 'pickup'";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database prepare failed']);
    exit;
}

$stmt->bind_param("i", $orderId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Order not found']);
    exit;
}

$order = $result->fetch_assoc();
$stmt->close();

// Get transaction number separately (simpler query)
$trans_sql = "SELECT transaction_number FROM transactions WHERE order_id = ? LIMIT 1";
$trans_stmt = $conn->prepare($trans_sql);
$transaction_number = 'N/A';
if ($trans_stmt) {
    $trans_stmt->bind_param("i", $orderId);
    $trans_stmt->execute();
    $trans_result = $trans_stmt->get_result();
    if ($trans_result->num_rows > 0) {
        $trans_data = $trans_result->fetch_assoc();
        $transaction_number = $trans_data['transaction_number'];
    }
    $trans_stmt->close();
}

// Get order items (simplified)
$items_sql = "SELECT oi.quantity, oi.price, p.ProductName
              FROM order_items oi
              JOIN products p ON oi.product_id = p.ProductID
              WHERE oi.order_id = ?";

$items_stmt = $conn->prepare($items_sql);
$items = [];
if ($items_stmt) {
    $items_stmt->bind_param("i", $orderId);
    $items_stmt->execute();
    $items_result = $items_stmt->get_result();
    
    while ($item = $items_result->fetch_assoc()) {
        $items[] = [
            'name' => $item['ProductName'],
            'quantity' => $item['quantity'],
            'price' => $item['price']
        ];
    }
    $items_stmt->close();
}

// Prepare response
$response = [
    'success' => true,
    'order' => [
        'id' => $order['id'],
        'transaction_number' => $transaction_number,
        'order_date' => date('M d, Y g:i A', strtotime($order['order_date'])),
        'customer_name' => trim($order['first_name'] . ' ' . $order['last_name']),
        'customer_image' => $order['ImagePath'] ?: 'img/user.jpg',
        'payment_method' => $order['payment_method'] ?: 'pickup',
        'total_price' => $order['total_price'],
        'order_status' => $order['order_status'],
        'items' => $items
    ]
];

echo json_encode($response);
$conn->close();
?>

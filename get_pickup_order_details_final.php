<?php
session_start();
require 'dbconn.php';

header('Content-Type: application/json');

if (!isset($_GET['order_id']) || empty($_GET['order_id'])) {
    echo json_encode(['success' => false, 'message' => 'Order ID is required']);
    exit;
}

$orderId = (int)$_GET['order_id'];

try {
    // Get order details
    $sql = "SELECT o.id, o.user_id, o.total_price, o.order_status, o.order_date, o.payment_method,
                   t.transaction_number,
                   u.first_name, u.last_name, u.ImagePath
            FROM orders o
            LEFT JOIN transactions t ON o.id = t.order_id
            JOIN users u ON o.user_id = u.id
            WHERE o.id = ? AND o.delivery_method = 'pickup'";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Database prepare failed: ' . $conn->error);
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

    // Get order items
    $sql_items = "SELECT oi.product_id, oi.quantity, oi.price,
                         p.ProductName, p.ImagePath as product_image
                  FROM order_items oi
                  JOIN products p ON oi.product_id = p.ProductID
                  WHERE oi.order_id = ?";

    $stmt_items = $conn->prepare($sql_items);
    if (!$stmt_items) {
        throw new Exception('Items prepare failed: ' . $conn->error);
    }

    $stmt_items->bind_param("i", $orderId);
    $stmt_items->execute();
    $result_items = $stmt_items->get_result();

    $items = [];
    while ($item = $result_items->fetch_assoc()) {
        $img = $item['product_image'] ?: '';
        if (!empty($img) && strpos($img, 'uploads/') !== 0) {
            $img = 'uploads/' . ltrim($img, '/');
        }
        $items[] = [
            'name' => $item['ProductName'],
            'quantity' => $item['quantity'],
            'price' => $item['price'],
            'product_image' => $img ?: 'img/shifter.png'
        ];
    }
    $stmt_items->close();

    // Prepare response
    $response = [
        'success' => true,
        'order' => [
            'id' => $order['id'],
            'transaction_number' => $order['transaction_number'] ?: 'N/A',
            'order_date' => date('M d, Y g:i A', strtotime($order['order_date'])),
            'customer_name' => trim(($order['first_name'] ?: '') . ' ' . ($order['last_name'] ?: '')) ?: 'Unknown Customer',
            'customer_image' => $order['ImagePath'] ?: 'img/user.jpg',
            'payment_method' => $order['payment_method'] ?: 'pickup',
            'total_price' => $order['total_price'] ?: 0,
            'order_status' => $order['order_status'] ?: 'Pending',
            'items' => $items
        ]
    ];

    echo json_encode($response);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
?>

<?php
require_once 'dbconn.php';

header('Content-Type: application/json');

$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;

error_log("fetch_return_items.php called with order_id: " . $orderId . ", GET params: " . json_encode($_GET));

if ($orderId <= 0) {
    error_log("Invalid order ID received: " . $orderId);
    echo json_encode(['items' => [], 'error' => 'Invalid order ID', 'received_id' => $orderId, 'get_params' => $_GET]);
    exit;
}

try {
    // Check if order exists first
    $orderCheck = $conn->prepare("SELECT id FROM orders WHERE id = ?");
    $orderCheck->bind_param('i', $orderId);
    $orderCheck->execute();
    if ($orderCheck->get_result()->num_rows === 0) {
        echo json_encode(['items' => [], 'error' => 'Order not found']);
        exit;
    }
    $orderCheck->close();

    // Simple query to get order items - use column names that work based on search results
    $sql = "SELECT oi.product_id, p.ProductName, oi.quantity, oi.price, p.ImagePath AS image
            FROM order_items oi
            JOIN products p ON p.ProductID = oi.product_id
            WHERE oi.order_id = ?";

    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        echo json_encode(['items' => [], 'error' => 'Prepare failed: ' . $conn->error]);
        exit;
    }

    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $result = $stmt->get_result();

    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = [
            'product_id' => (int)$row['product_id'],
            'product_name' => $row['ProductName'] ?? 'Unknown Product',
            'quantity' => (int)$row['quantity'],
            'price' => (float)$row['price'],
            'image' => $row['image'] ?? 'img/default-product.jpg'
        ];
    }
    $stmt->close();

    // Return the items or indicate no items found
    if (count($items) === 0) {
        echo json_encode(['items' => [], 'error' => 'No items found for this order']);
    } else {
        echo json_encode(['items' => $items, 'count' => count($items)]);
    }
} catch (Exception $e) {
    echo json_encode(['items' => [], 'error' => 'Exception: ' . $e->getMessage()]);
}
exit;
?>



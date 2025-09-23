<?php
require_once 'dbconn.php';

header('Content-Type: application/json');

$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
if ($orderId <= 0) {
    echo json_encode(['items' => []]);
    exit;
}

$sql = "SELECT oi.product_id, p.ProductName AS product_name, oi.quantity, oi.price
        FROM order_items oi
        LEFT JOIN products p ON p.ProductID = oi.product_id
        WHERE oi.order_id = ?";

$stmt = $conn->prepare($sql);
if ($stmt === false) {
    echo json_encode(['items' => []]);
    exit;
}
$stmt->bind_param('i', $orderId);
$stmt->execute();
$result = $stmt->get_result();

$items = [];
while ($row = $result->fetch_assoc()) {
    $items[] = [
        'product_id' => (int)$row['product_id'],
        'product_name' => $row['product_name'] ?? 'Unknown',
        'quantity' => (int)$row['quantity'],
        'price' => (float)$row['price']
    ];
}
$stmt->close();

echo json_encode(['items' => $items]);
exit;
?>



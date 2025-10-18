<?php
require_once 'dbconn.php';

header('Content-Type: application/json');

try {
    $response = [];

    // Check orders table
    $orders_result = $conn->query("SELECT COUNT(*) as count FROM orders WHERE payment_method = 'GCASH' AND order_status = 'On-Ship'");
    if ($orders_result) {
        $response['gcash_onship_orders'] = $orders_result->fetch_assoc()['count'];
    }

    // Check order_items table
    $items_result = $conn->query("SELECT COUNT(*) as count FROM order_items");
    if ($items_result) {
        $response['total_order_items'] = $items_result->fetch_assoc()['count'];
    }

    // Get sample order IDs
    $sample_orders = $conn->query("SELECT id FROM orders WHERE payment_method = 'GCASH' AND order_status = 'On-Ship' LIMIT 3");
    if ($sample_orders) {
        $response['sample_order_ids'] = [];
        while ($row = $sample_orders->fetch_assoc()) {
            $response['sample_order_ids'][] = $row['id'];
        }
    }

    // Check products table columns
    $products_columns = $conn->query("DESCRIBE products");
    if ($products_columns) {
        $response['products_columns'] = [];
        while ($row = $products_columns->fetch_assoc()) {
            $response['products_columns'][] = $row['Field'];
        }
    }

    // Check order_items table columns
    $order_items_columns = $conn->query("DESCRIBE order_items");
    if ($order_items_columns) {
        $response['order_items_columns'] = [];
        while ($row = $order_items_columns->fetch_assoc()) {
            $response['order_items_columns'][] = $row['Field'];
        }
    }

    echo json_encode($response, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}

$conn->close();
?>

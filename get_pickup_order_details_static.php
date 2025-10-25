<?php
// Minimal API that returns static data to test if the issue is with database queries
header('Content-Type: application/json');

$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;

// Return static test data
$response = [
    'success' => true,
    'order' => [
        'id' => $orderId,
        'transaction_number' => 'TEST-' . $orderId,
        'order_date' => 'Jan 01, 2024 12:00 PM',
        'customer_name' => 'Test Customer',
        'customer_image' => 'img/default.jpg',
        'payment_method' => 'pickup',
        'total_price' => 100.00,
        'order_status' => 'Pending',
        'items' => [
            [
                'name' => 'Test Product',
                'quantity' => 1,
                'price' => 100.00
            ]
        ]
    ]
];

echo json_encode($response);
?>

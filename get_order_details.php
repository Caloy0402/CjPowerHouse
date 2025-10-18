<?php
// Start the session to access session variables
session_start();

// Include the database connection
require_once 'dbconn.php';

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Check if order_id is provided
if (!isset($_GET['order_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Order ID is required']);
    exit;
}

$order_id = (int)$_GET['order_id'];

$conn = new mysqli($servername, $username, $password, $dbname);

// Check if the connection is successful
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Simple check if delivery fee columns exist by trying to select them
$ordersHasDeliveryCols = false;
try {
    $testQuery = "SELECT delivery_fee, total_amount_with_delivery FROM orders LIMIT 1";
    $conn->query($testQuery);
    $ordersHasDeliveryCols = true;
} catch (Exception $e) {
    // Columns don't exist, use fallback
    $ordersHasDeliveryCols = false;
}

$selectDeliveryCols = $ordersHasDeliveryCols
    ? ", o.delivery_fee, o.total_amount_with_delivery"
    : ", 0 AS delivery_fee, o.total_price AS total_amount_with_delivery";

// Always join barangay_fares and compute effective fee/total from fare when order fields are empty
$selectFareFallback = ", 
    CASE 
        WHEN o.delivery_method = 'staff' THEN COALESCE(NULLIF(o.delivery_fee, 0), bf.staff_fare_amount, 0)
        ELSE COALESCE(NULLIF(o.delivery_fee, 0), bf.fare_amount, 0) 
    END AS delivery_fee_effective,
    CASE WHEN (o.total_amount_with_delivery IS NULL OR o.total_amount_with_delivery = 0)
         THEN (o.total_price + 
            CASE 
                WHEN o.delivery_method = 'staff' THEN COALESCE(NULLIF(o.delivery_fee, 0), bf.staff_fare_amount, 0)
                ELSE COALESCE(NULLIF(o.delivery_fee, 0), bf.fare_amount, 0) 
            END)
         ELSE o.total_amount_with_delivery END AS total_with_delivery_effective";

// Fetch order details with delivery fee calculation
$sql = "SELECT o.*, u.first_name, u.last_name, u.barangay_id, u.purok, b.barangay_name,
               bf.fare_amount AS barangay_fare, bf.staff_fare_amount AS barangay_staff_fare, o.payment_method
               $selectDeliveryCols $selectFareFallback
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        LEFT JOIN barangays b ON u.barangay_id = b.id
        LEFT JOIN barangay_fares bf ON b.id = bf.barangay_id
        WHERE o.id = ?";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Database prepare failed: ' . $conn->error]);
    exit;
}

$stmt->bind_param("i", $order_id);
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['error' => 'Database execute failed: ' . $stmt->error]);
    exit;
}

$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['error' => 'Order not found with ID: ' . $order_id]);
    exit;
}

$order = $result->fetch_assoc();

// Fetch order items
$sql = "SELECT oi.*, p.ProductName as product_name, p.Price as product_price, p.image
        FROM order_items oi
        JOIN products p ON oi.product_id = p.ProductID
        WHERE oi.order_id = ?";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Order items prepare failed: ' . $conn->error]);
    exit;
}

$stmt->bind_param("i", $order_id);
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['error' => 'Order items execute failed: ' . $stmt->error]);
    exit;
}

$items_result = $stmt->get_result();

$order_items = [];
while ($item = $items_result->fetch_assoc()) {
    $order_items[] = $item;
}

$conn->close();

// Prepare response
$response = [
    'order' => $order,
    'items' => $order_items
];

header('Content-Type: application/json');
echo json_encode($response);
?> 
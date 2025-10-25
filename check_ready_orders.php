<?php
session_start();
require_once 'dbconn.php';

// Check if user is logged in and is a rider
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Rider') {
    echo json_encode(['error' => 'Not logged in or not a rider']);
    exit;
}

// Get rider's full name
$sql = "SELECT CONCAT(first_name, ' ', last_name) as full_name FROM riders WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$rider = $result->fetch_assoc();

if (!$rider) {
    echo json_encode(['error' => 'Rider not found']);
    exit;
}

// Query to check for ready to ship orders for this rider
$sql = "SELECT COUNT(*) as order_count 
        FROM orders o 
        WHERE o.rider_name = ? 
        AND o.order_status = 'Ready to Ship'";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $rider['full_name']);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

// Return JSON response
echo json_encode([
    'hasOrders' => $row['order_count'] > 0,
    'count' => $row['order_count']
]);

$stmt->close();
$conn->close();
?>
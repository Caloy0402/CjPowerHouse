<?php
session_start();
require_once 'dbconn.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit();
}

if (!isset($_GET['cartID']) || !isset($_GET['action'])) {
    echo json_encode(['success' => false, 'message' => 'Missing cartID or action']);
    exit();
}

$cartID = $_GET['cartID'];
$action = $_GET['action'];
$user_id = $_SESSION['user_id'];

if ($action !== 'increase' && $action !== 'decrease') {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit();
}

// Get current quantity
$sql = "SELECT Quantity FROM cart WHERE CartID = ? AND UserID = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $cartID, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Cart item not found']);
    exit();
}

$row = $result->fetch_assoc();
$quantity = $row['Quantity'];

// Adjust quantity
if ($action === 'increase') {
    $newQuantity = $quantity + 1;
} else {
    $newQuantity = $quantity - 1;
    if ($newQuantity < 1) {
        $newQuantity = 1;  // Prevent quantity from going below 1
    }
}

// Update quantity in database
$sql = "UPDATE cart SET Quantity = ? WHERE CartID = ? AND UserID = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iii", $newQuantity, $cartID, $user_id);  // Make sure parameters match the number of placeholders
$stmt->execute();

if ($stmt->affected_rows > 0) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update quantity']);
}

$conn->close();

?>
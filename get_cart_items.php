<?php
session_start();
require_once 'dbconn.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];

$sql = "SELECT c.*, p.ProductName, p.Price, p.ImagePath, p.Weight
        FROM cart c 
        JOIN products p ON c.ProductID = p.ProductID 
        WHERE c.UserID = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$cartItems = [];
while ($row = $result->fetch_assoc()) {
    $cartItems[] = $row;
}

echo json_encode(['success' => true, 'cartItems' => $cartItems]);

// Move the connection close to here, after the JSON is encoded and sent
$conn->close();
?>
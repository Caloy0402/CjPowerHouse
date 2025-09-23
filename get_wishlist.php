<?php
require_once 'dbconn.php';
session_start();

$user_id = 1; // Replace this with the logged-in user's ID

// Get wishlist items with product details
$sql = "SELECT p.ProductID, p.ProductName, p.Price, p.ImagePath 
        FROM wishlist w
        JOIN products p ON w.product_id = p.ProductID
        WHERE w.user_id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$items = [];
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    echo json_encode(["success" => true, "items" => $items]);
} else {
    echo json_encode(["success" => true, "items" => []]);
}

$conn->close();
?>
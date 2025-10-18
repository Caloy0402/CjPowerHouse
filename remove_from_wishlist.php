<?php
require_once 'dbconn.php';
session_start();

$user_id = 1; // Replace this with the logged-in user's ID

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['product_id'])) {
    $product_id = intval($_POST['product_id']);

    // Remove from wishlist
    $sql = "DELETE FROM wishlist WHERE user_id = ? AND product_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $user_id, $product_id);

    if ($stmt->execute()) {
        echo json_encode(["success" => true]);
    } else {
        echo json_encode(["success" => false, "message" => "Failed to remove item"]);
    }
} else {
    echo json_encode(["success" => false, "message" => "Invalid request"]);
}

$conn->close();
?>
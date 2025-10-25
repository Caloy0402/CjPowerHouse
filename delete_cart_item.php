<?php
session_start();
require_once 'dbconn.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit();
}

// Check if cartID is provided via GET or POST
if (!isset($_GET['cartID']) && !isset($_POST['cartID'])) {
    echo json_encode(['success' => false, 'message' => 'Missing cart ID']);
    exit();
}

$cartID = isset($_GET['cartID']) ? $_GET['cartID'] : $_POST['cartID'];

$sql = "DELETE FROM cart WHERE CartID = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $cartID);
$stmt->execute();

if ($stmt->affected_rows > 0) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to delete item']);
}

$conn->close();
?>
<?php
session_start();
require_once 'dbconn.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];

$sql = "SELECT SUM(Quantity) as count FROM cart WHERE UserID = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

echo json_encode(['success' => true, 'count' => $row['count'] ?? 0]);

$conn->close();
?>
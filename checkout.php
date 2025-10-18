<?php
include 'dbconn.php';
$user_id = 1;

$query = "UPDATE orders SET order_status = 'Processing' WHERE user_id = ? AND order_status = 'Pending'";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();

echo "<script>alert('Order placed successfully!'); window.location.href='Mobile-Dashboard.php';</script>";
?>

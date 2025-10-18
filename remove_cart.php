<?php
include 'dbconn.php';
$data = json_decode(file_get_contents("php://input"), true);
$item_id = $data['item_id'];

$query = "DELETE FROM order_items WHERE id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $item_id);
$stmt->execute();
echo json_encode(["status" => "success"]);
?>

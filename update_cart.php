<?php
include 'dbconn.php';
$data = json_decode(file_get_contents("php://input"), true);
$item_id = $data['item_id'];
$change = $data['change'];

$query = "UPDATE order_items SET quantity = quantity + ? WHERE id = ? AND quantity + ? > 0";
$stmt = $conn->prepare($query);
$stmt->bind_param("iii", $change, $item_id, $change);
$stmt->execute();
echo json_encode(["status" => "success"]);
?>

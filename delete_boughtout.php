<?php
include 'db_connection.php'; // Include your database connection

$data = json_decode(file_get_contents("php://input"), true);

if (!empty($data['product_ids'])) {
    $ids = implode(',', array_map('intval', $data['product_ids']));
    $sql = "DELETE FROM boughtoutproducts WHERE product_id IN ($ids)";
    if ($conn->query($sql)) {
        echo json_encode(["success" => true]);
    } else {
        echo json_encode(["success" => false]);
    }
} else {
    echo json_encode(["success" => false]);
}
?>

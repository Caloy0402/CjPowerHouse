<?php
require_once 'dbconn.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Validate required fields
    if (!isset($_POST['product_id'], $_POST['quantity'], $_POST['price'])) {
        echo json_encode(["status" => "error", "message" => "Missing required fields."]);
        exit;
    }

    $product_id = intval($_POST['product_id']); // Ensure it's an integer
    $quantity = floatval($_POST['quantity']);   // Ensure it's a number
    $price = floatval($_POST['price']);         // Ensure it's a decimal number

    $sql = "UPDATE boughtoutproducts SET quantity = ?, price = ? WHERE product_id = ?";
    $stmt = $conn->prepare($sql);

    if ($stmt) {
        $stmt->bind_param("ddi", $quantity, $price, $product_id);
        if ($stmt->execute()) {
            echo json_encode(["status" => "success", "message" => "Product updated successfully!"]);
        } else {
            echo json_encode(["status" => "error", "message" => "Error updating product."]);
        }
        $stmt->close();
    } else {
        echo json_encode(["status" => "error", "message" => "Database error."]);
    }

    $conn->close();
}
?>

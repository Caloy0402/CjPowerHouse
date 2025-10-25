<?php
require_once 'dbconn.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Validate required fields
    if (!isset($_POST['product_id'], $_POST['quantity'], $_POST['price'])) {
        echo "Required information is missing. Please ensure all fields are completed.";
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
            echo "Product inventory has been successfully updated and restocked.";
        } else {
            echo "Unable to update product inventory. Please try again.";
        }
        $stmt->close();
    } else {
        echo "System error occurred. Please contact technical support.";
    }

    $conn->close();
}
?>

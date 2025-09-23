<?php
// Include the database connection file
include 'dbconn.php';

// Get the data from the request
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (isset($data['products']) && is_array($data['products'])) {
    // Prepare SQL statement to insert products into the boughtoutproducts table
    $insertStmt = $conn->prepare("INSERT INTO boughtoutproducts 
        (product_id, product_name, category, brand, motor_type, quantity, price, weight, image_path)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

    if (!$insertStmt) {
        die(json_encode(['success' => false, 'message' => 'SQL prepare failed: ' . $conn->error]));
    }

    // Prepare SQL statement to delete the product from the products table
    $deleteStmt = $conn->prepare("DELETE FROM products WHERE ProductID = ?");

    if (!$deleteStmt) {
        die(json_encode(['success' => false, 'message' => 'SQL prepare failed: ' . $conn->error]));
    }

    // Loop through each product and process it
    foreach ($data['products'] as $product) {
        // Insert into boughtoutproducts
        $price = floatval($product['price']);
        $insertStmt->bind_param("issssidds",
            $product['id'], 
            $product['name'], 
            $product['category'], 
            $product['brand'], 
            $product['motortype'], 
            $product['quantity'], 
            $price, 
            $product['weight'],
            $product['img']
        );

        if (!$insertStmt->execute()) {
            echo json_encode(['success' => false, 'message' => 'Insert failed: ' . $insertStmt->error]);
            exit;
        }

        // Delete from products
        $deleteStmt->bind_param("i", $product['id']);
        if (!$deleteStmt->execute()) {
            echo json_encode(['success' => false, 'message' => 'Delete failed: ' . $deleteStmt->error]);
            exit;
        }
    }

    // Close statements
    $insertStmt->close();
    $deleteStmt->close();

    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid data format']);
}

$conn->close();
?>
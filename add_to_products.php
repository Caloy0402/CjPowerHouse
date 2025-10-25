<?php
// Include the database connection file
include 'dbconn.php';

// Get the data from the request
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Debugging: Log full received JSON data
file_put_contents('debug_received_data.txt', print_r($data, true));

if (isset($data['products']) && is_array($data['products'])) {
    // Prepare SQL statement to insert products back into the products table
    $insertStmt = $conn->prepare("INSERT INTO products 
        (ProductID, ProductName, Category, Brand, MotorType, Quantity, Price, Weight, ImagePath) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

    if (!$insertStmt) {
        die("System error occurred. Please contact technical support.");
    }

    // Prepare SQL statement to delete the product from the boughtoutproducts table
    $deleteStmt = $conn->prepare("DELETE FROM boughtoutproducts WHERE product_id = ?");

    if (!$deleteStmt) {
        die("System error occurred. Please contact technical support.");
    }

    // Loop through each product and process it
    foreach ($data['products'] as $product) {
        $imagePath = 'default.png'; // Default image

        // Fetch ImagePath from boughtoutproducts if available
        $fetchImageStmt = $conn->prepare("SELECT image_path FROM boughtoutproducts WHERE product_id = ?");
        $fetchImageStmt->bind_param("i", $product['product_id']);
        $fetchImageStmt->execute();
        $fetchImageStmt->bind_result($fetchedImagePath);
        
        if ($fetchImageStmt->fetch() && !empty($fetchedImagePath)) {
            $imagePath = $fetchedImagePath; // Use the existing image if found
        }

        $fetchImageStmt->close();

        // Debugging: Log product ID and resolved ImagePath
        file_put_contents('debug_received_data.txt', "Product ID: {$product['product_id']}, ImagePath: $imagePath\n", FILE_APPEND);

        // Check if the product already exists
        $checkStmt = $conn->prepare("SELECT Quantity From Products WHERE ProductID = ?");
        $checkStmt->bind_param("i", $product['product_id']);
        $checkStmt->execute();
        $checkStmt->store_result();

        if ($checkStmt->num_rows > 0) {
            // Product exists, update the quantity
            $updateStmt = $conn->prepare("UPDATE products SET Quantity = Quantity + ? WHERE ProductID = ?");
            $updateStmt->bind_param("ii", $product['quantity'], $product['product_id']);

            if (!$updateStmt->execute()) {
                echo "Unable to update product inventory. Please try again.";
                exit;
            }

            $updateStmt->close();
        } else {
            // Product does not exist, insert it
         $cleanPrice = floatval(str_replace([',','₱',' '], '', $product['price']));
         $insertStmt->bind_param("issssidss",
                $product['product_id'],
                $product['product_name'],
                $product['category'],
                $product['brand'],
                $product['motor_type'],
                $product['quantity'],
                $cleanPrice,
                $product['Weight'],
                $imagePath
            );

        if (!$insertStmt->execute()) {
            echo "Unable to add product to inventory. Please try again.";
            exit;
        }

       }

        // Delete from boughtoutproducts
        $deleteStmt->bind_param("i", $product['product_id']);
        if (!$deleteStmt->execute()) {
            echo "Unable to remove product from bought-out list. Please try again.";
            exit;
        }
    }

    // Close statements
    $insertStmt->close();
    $deleteStmt->close();

    echo "Selected products have been successfully moved back to inventory.";
} else {
    echo "Invalid data format received. Please try again.";
}

// Close connection
$conn->close();
?>
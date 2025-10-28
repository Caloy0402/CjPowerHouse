<?php
require 'dbconn.php';

// Start the session to access session variables
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

// Get order ID from GET parameter
$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;

if ($orderId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order ID']);
    exit;
}

try {
    // Query to get order items - use correct column names based on database schema
    $sql = "SELECT oi.product_id, oi.quantity, oi.price, p.ProductName AS product_name, p.ImagePath AS product_image
            FROM order_items oi
            JOIN products p ON oi.product_id = p.ProductID
            WHERE oi.order_id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $result = $stmt->get_result();

    $items = [];
    while ($row = $result->fetch_assoc()) {
        // Ensure image path has the uploads/ prefix
        $imagePath = $row['product_image'] ?: '';
        if (!empty($imagePath) && strpos($imagePath, 'uploads/') !== 0 && strpos($imagePath, 'http') !== 0) {
            $imagePath = 'uploads/' . ltrim($imagePath, '/');
        }
        
        // Log the raw data for debugging
        error_log("Order item data: product_id=" . $row['product_id'] . 
                  ", quantity=" . $row['quantity'] . 
                  ", price=" . $row['price'] . 
                  ", name=" . $row['product_name'] . 
                  ", image=" . $imagePath);
        
        $items[] = [
            'product_id' => (int)$row['product_id'],
            'product_name' => $row['product_name'],
            'quantity' => (int)$row['quantity'],
            'price' => (float)$row['price'],
            'product_image' => $imagePath
        ];
    }
    
    $stmt->close();
    
    // Log the final response
    error_log("Final items response: " . json_encode($items));
    
    echo json_encode([
        'success' => true,
        'items' => $items
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching order items: ' . $e->getMessage()
    ]);
}

$conn->close();
?>

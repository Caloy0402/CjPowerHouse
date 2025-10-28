<?php
require 'dbconn.php';

// Start the session to access session variables
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

// Get data from POST request
$orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;

if ($orderId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order ID']);
    exit;
}

try {
    // Get order items with product details
    $sql = "SELECT 
                oi.product_id,
                oi.quantity as ordered_quantity,
                p.ProductName as product_name,
                p.Quantity as available_stock,
                p.ImagePath as product_image
            FROM order_items oi
            JOIN products p ON oi.product_id = p.ProductID
            WHERE oi.order_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $insufficientItems = [];
    $hasInsufficientStock = false;
    
    while ($row = $result->fetch_assoc()) {
        $orderedQty = (int)$row['ordered_quantity'];
        $availableStock = (int)$row['available_stock'];
        
        if ($orderedQty > $availableStock) {
            $hasInsufficientStock = true;
            $insufficientItems[] = [
                'product_id' => $row['product_id'],
                'product_name' => $row['product_name'],
                'ordered_quantity' => $orderedQty,
                'available_stock' => $availableStock,
                'shortage' => $orderedQty - $availableStock,
                'product_image' => $row['product_image']
            ];
        }
    }
    
    $stmt->close();
    
    if ($hasInsufficientStock) {
        echo json_encode([
            'success' => false,
            'has_insufficient_stock' => true,
            'message' => 'Insufficient stock for some items',
            'insufficient_items' => $insufficientItems
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'has_insufficient_stock' => false,
            'message' => 'All items have sufficient stock'
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error checking stock: ' . $e->getMessage()
    ]);
}

$conn->close();
?>


<?php
header('Content-Type: application/json');
require_once 'dbconn.php';

$category = $_GET['category'] ?? '';

if (empty($category)) {
    echo json_encode([
        'success' => false,
        'message' => 'Category parameter is required'
    ]);
    exit();
}

try {
    // Fetch products by category
    $stmt = $conn->prepare("
        SELECT 
            ProductName,
            Quantity,
            Price,
            ImagePath
        From Products 
        WHERE Category = ? 
        ORDER BY Quantity DESC
    ");
    
    $stmt->bind_param("s", $category);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $products = [];
    while ($row = $result->fetch_assoc()) {
        $products[] = [
            'ProductName' => $row['ProductName'],
            'Quantity' => (int)$row['Quantity'],
            'Price' => (float)$row['Price'],
            'ImagePath' => $row['ImagePath']
        ];
    }
    
    $stmt->close();
    
    if (empty($products)) {
        echo json_encode([
            'success' => false,
            'message' => 'No products found in category: ' . htmlspecialchars($category)
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'category' => $category,
            'products' => $products,
            'total_products' => count($products)
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>

<?php
require 'dbconn.php';

echo "<h1>Comprehensive Database Test</h1>";

// Test 1: Check if database connection works
echo "<h2>1. Database Connection Test</h2>";
if ($conn) {
    echo "<p style='color: green;'>✓ Database connection successful</p>";
} else {
    echo "<p style='color: red;'>✗ Database connection failed</p>";
    exit;
}

// Test 2: Check if orders table exists and has data
echo "<h2>2. Orders Table Test</h2>";
$orders_count = $conn->query("SELECT COUNT(*) as count FROM orders")->fetch_assoc()['count'];
echo "<p>Total orders in database: " . $orders_count . "</p>";

$pickup_count = $conn->query("SELECT COUNT(*) as count FROM orders WHERE delivery_method = 'pickup'")->fetch_assoc()['count'];
echo "<p>Pickup orders: " . $pickup_count . "</p>";

// Test 3: Check specific order IDs
echo "<h2>3. Specific Order Test</h2>";
$test_orders = [377, 378, 379];
foreach ($test_orders as $order_id) {
    echo "<h3>Order ID: " . $order_id . "</h3>";
    
    $sql = "SELECT o.id, o.user_id, o.total_price, o.order_status, o.order_date, o.payment_method, o.delivery_method,
                   t.transaction_number,
                   u.first_name, u.last_name, u.ImagePath
            FROM orders o
            LEFT JOIN transactions t ON o.id = t.order_id
            JOIN users u ON o.user_id = u.id
            WHERE o.id = ?";
    
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $order = $result->fetch_assoc();
            echo "<div style='background: #e8f5e8; padding: 10px; margin: 5px; border-radius: 5px;'>";
            echo "<strong>Order Found:</strong><br>";
            echo "ID: " . $order['id'] . "<br>";
            echo "User ID: " . $order['user_id'] . "<br>";
            echo "Customer: " . $order['first_name'] . " " . $order['last_name'] . "<br>";
            echo "Delivery Method: " . ($order['delivery_method'] ?: 'NULL') . "<br>";
            echo "Transaction Number: " . ($order['transaction_number'] ?: 'NULL') . "<br>";
            echo "Total Price: " . $order['total_price'] . "<br>";
            echo "Order Status: " . $order['order_status'] . "<br>";
            echo "</div>";
            
            // Check if it's a pickup order
            if ($order['delivery_method'] === 'pickup') {
                echo "<p style='color: green;'>✓ This is a pickup order</p>";
                
                // Test the items
                $items_sql = "SELECT oi.product_id, oi.quantity, oi.price,
                                     p.ProductName, p.ImagePath as product_image
                              FROM order_items oi
                              JOIN products p ON oi.product_id = p.ProductID
                              WHERE oi.order_id = ?";
                
                $items_stmt = $conn->prepare($items_sql);
                if ($items_stmt) {
                    $items_stmt->bind_param("i", $order_id);
                    $items_stmt->execute();
                    $items_result = $items_stmt->get_result();
                    
                    echo "<strong>Order Items:</strong><br>";
                    if ($items_result->num_rows > 0) {
                        while ($item = $items_result->fetch_assoc()) {
                            echo "- " . $item['ProductName'] . " (Qty: " . $item['quantity'] . ", Price: ₱" . $item['price'] . ")<br>";
                        }
                    } else {
                        echo "<p style='color: red;'>No items found for this order</p>";
                    }
                    $items_stmt->close();
                }
            } else {
                echo "<p style='color: orange;'>⚠ This is NOT a pickup order (delivery_method: " . $order['delivery_method'] . ")</p>";
            }
        } else {
            echo "<p style='color: red;'>✗ Order not found</p>";
        }
        $stmt->close();
    } else {
        echo "<p style='color: red;'>✗ Failed to prepare statement</p>";
    }
}

// Test 4: Check users table
echo "<h2>4. Users Table Test</h2>";
$users_count = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];
echo "<p>Total users: " . $users_count . "</p>";

// Test 5: Check transactions table
echo "<h2>5. Transactions Table Test</h2>";
$transactions_count = $conn->query("SELECT COUNT(*) as count FROM transactions")->fetch_assoc()['count'];
echo "<p>Total transactions: " . $transactions_count . "</p>";

// Test 6: Check order_items table
echo "<h2>6. Order Items Table Test</h2>";
$order_items_count = $conn->query("SELECT COUNT(*) as count FROM order_items")->fetch_assoc()['count'];
echo "<p>Total order items: " . $order_items_count . "</p>";

$conn->close();
?>

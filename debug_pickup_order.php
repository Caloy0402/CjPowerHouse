<?php
require 'dbconn.php';

echo "<h1>Pickup Order Debug Tool</h1>";

// First, let's check what delivery_method values exist
echo "<h2>1. Checking delivery_method values in orders table:</h2>";
$delivery_methods_sql = "SELECT DISTINCT delivery_method, COUNT(*) as count FROM orders GROUP BY delivery_method";
$delivery_result = $conn->query($delivery_methods_sql);

echo "<table border='1'>";
echo "<tr><th>Delivery Method</th><th>Count</th></tr>";
while ($row = $delivery_result->fetch_assoc()) {
    echo "<tr><td>" . ($row['delivery_method'] ?: 'NULL') . "</td><td>" . $row['count'] . "</td></tr>";
}
echo "</table>";

// Get a sample pickup order ID
echo "<h2>2. Sample Pickup Orders:</h2>";
$sql = "SELECT o.id, o.user_id, o.total_price, o.order_status, o.order_date, o.payment_method, o.delivery_method,
               t.transaction_number,
               u.first_name, u.last_name, u.phone_number, u.email, u.ImagePath
        FROM orders o
        LEFT JOIN transactions t ON o.id = t.order_id
        JOIN users u ON o.user_id = u.id
        WHERE o.delivery_method = 'pickup' AND o.order_status != 'Cancelled' AND o.order_status != 'Completed'
        LIMIT 3";

$result = $conn->query($sql);

if ($result->num_rows > 0) {
    echo "<table border='1'>";
    echo "<tr><th>Order ID</th><th>User ID</th><th>Customer Name</th><th>Transaction Number</th><th>Total Price</th><th>Status</th><th>Delivery Method</th></tr>";
    
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . $row['user_id'] . "</td>";
        echo "<td>" . ($row['first_name'] . ' ' . $row['last_name']) . "</td>";
        echo "<td>" . ($row['transaction_number'] ?: 'NULL') . "</td>";
        echo "<td>" . $row['total_price'] . "</td>";
        echo "<td>" . $row['order_status'] . "</td>";
        echo "<td>" . ($row['delivery_method'] ?: 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Reset the result pointer
    $result->data_seek(0);
    $order = $result->fetch_assoc();
    
    echo "<h2>3. Testing APIs with order ID: " . $order['id'] . "</h2>";
    
    // Test simple API first
    echo "<h3>Testing Simple API:</h3>";
    echo "<p><a href='test_simple_api.php?order_id=" . $order['id'] . "' target='_blank'>Test Simple API</a></p>";
    
    $simple_url = "test_simple_api.php?order_id=" . $order['id'];
    $simple_response = @file_get_contents($simple_url);
    if ($simple_response) {
        echo "<pre>" . htmlspecialchars($simple_response) . "</pre>";
    } else {
        echo "<p style='color: red;'>Simple API failed to load</p>";
    }
    
    // Test simplified pickup API
    echo "<h3>Testing Simplified Pickup API:</h3>";
    echo "<p><a href='get_pickup_order_details_simple.php?order_id=" . $order['id'] . "' target='_blank'>Test Simplified Pickup API</a></p>";
    
    $simple_pickup_url = "get_pickup_order_details_simple.php?order_id=" . $order['id'];
    $simple_pickup_response = @file_get_contents($simple_pickup_url);
    if ($simple_pickup_response) {
        echo "<pre>" . htmlspecialchars($simple_pickup_response) . "</pre>";
    } else {
        echo "<p style='color: red;'>Simplified Pickup API failed to load</p>";
    }
    
    // Test original API
    echo "<h3>Testing Original API:</h3>";
    echo "<p><a href='get_pickup_order_details.php?order_id=" . $order['id'] . "' target='_blank'>Test Original API</a></p>";
    
    $original_url = "get_pickup_order_details.php?order_id=" . $order['id'];
    $original_response = @file_get_contents($original_url);
    if ($original_response) {
        echo "<pre>" . htmlspecialchars($original_response) . "</pre>";
    } else {
        echo "<p style='color: red;'>Original API failed to load</p>";
    }
    
} else {
    echo "<h2>No pickup orders found in database</h2>";
    
    // Check if there are any orders at all
    $all_orders_sql = "SELECT COUNT(*) as count FROM orders WHERE delivery_method = 'pickup'";
    $all_result = $conn->query($all_orders_sql);
    $all_count = $all_result->fetch_assoc()['count'];
    
    echo "<p>Total pickup orders in database: " . $all_count . "</p>";
    
    if ($all_count > 0) {
        echo "<h3>All pickup orders (including cancelled/completed):</h3>";
        $all_orders_sql = "SELECT o.id, o.order_status, o.delivery_method, u.first_name, u.last_name 
                          FROM orders o 
                          LEFT JOIN users u ON o.user_id = u.id 
                          WHERE o.delivery_method = 'pickup' 
                          LIMIT 5";
        $all_result = $conn->query($all_orders_sql);
        
        echo "<table border='1'>";
        echo "<tr><th>Order ID</th><th>Status</th><th>Delivery Method</th><th>Customer Name</th></tr>";
        while ($row = $all_result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row['id'] . "</td>";
            echo "<td>" . $row['order_status'] . "</td>";
            echo "<td>" . $row['delivery_method'] . "</td>";
            echo "<td>" . ($row['first_name'] . ' ' . $row['last_name']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
}

// Check if there are any orders without delivery_method set
echo "<h2>4. Orders with NULL delivery_method:</h2>";
$null_delivery_sql = "SELECT COUNT(*) as count FROM orders WHERE delivery_method IS NULL";
$null_result = $conn->query($null_delivery_sql);
$null_count = $null_result->fetch_assoc()['count'];
echo "<p>Orders with NULL delivery_method: " . $null_count . "</p>";

$conn->close();
?>

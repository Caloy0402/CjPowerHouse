<?php
// Ultra-simple test to check if PHP is working at all
echo "PHP is working!<br>";
echo "Current time: " . date('Y-m-d H:i:s') . "<br>";

// Test database connection
require 'dbconn.php';
if ($conn) {
    echo "Database connection: OK<br>";
} else {
    echo "Database connection: FAILED<br>";
    exit;
}

// Test simple query
$result = $conn->query("SELECT COUNT(*) as count FROM orders");
if ($result) {
    $row = $result->fetch_assoc();
    echo "Orders in database: " . $row['count'] . "<br>";
} else {
    echo "Query failed: " . $conn->error . "<br>";
}

// Test specific order
$order_id = 377;
$stmt = $conn->prepare("SELECT id FROM orders WHERE id = ?");
if ($stmt) {
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        echo "Order " . $order_id . " exists<br>";
    } else {
        echo "Order " . $order_id . " NOT found<br>";
    }
    $stmt->close();
} else {
    echo "Prepare failed: " . $conn->error . "<br>";
}

$conn->close();
echo "Test completed successfully!";
?>

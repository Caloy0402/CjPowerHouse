<?php
// Simple test API to check if basic functionality works
session_start();
require 'dbconn.php';

header('Content-Type: application/json');

// Simple test response
echo json_encode([
    'success' => true,
    'message' => 'API is working',
    'order_id_received' => $_GET['order_id'] ?? 'none',
    'database_connected' => $conn ? 'yes' : 'no'
]);

$conn->close();
?>

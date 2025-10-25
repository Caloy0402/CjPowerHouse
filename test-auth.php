<?php
// Test authentication
error_reporting(0);
ini_set('display_errors', 0);

while (ob_get_level()) {
    ob_end_clean();
}

session_start();
require 'dbconn.php';

header('Content-Type: application/json; charset=utf-8');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in', 'session_data' => $_SESSION]);
    exit();
}

// Check if user has cashier privileges
$user_check_sql = "SELECT role FROM cjusers WHERE id = ?";
$user_check_stmt = $conn->prepare($user_check_sql);
if ($user_check_stmt) {
    $user_check_stmt->bind_param("i", $_SESSION['user_id']);
    $user_check_stmt->execute();
    $user_result = $user_check_stmt->get_result();
    $user_data = $user_result->fetch_assoc();
    $user_check_stmt->close();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Authentication successful',
        'user_id' => $_SESSION['user_id'],
        'user_role' => $user_data['role'] ?? 'unknown'
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
$conn->close();
?>

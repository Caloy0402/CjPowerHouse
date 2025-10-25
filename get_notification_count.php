<?php
require 'dbconn.php';

// Start session
session_start();

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    // Check if notifications table exists
    $result = $conn->query("SHOW TABLES LIKE 'notifications'");
    if ($result->num_rows > 0) {
        // Get notification count for the user
        $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();
    } else {
        // Table doesn't exist, return 0
        $count = 0;
    }
    
    echo json_encode([
        'success' => true,
        'count' => $count
    ]);
    
} catch (Exception $e) {
    // If there's any error, just return 0 notifications
    echo json_encode([
        'success' => true,
        'count' => 0
    ]);
}

$conn->close();
?>

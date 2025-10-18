<?php
session_start();
header('Content-Type: application/json');
require_once 'dbconn.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success'=>false, 'message'=>'Not logged in']);
    exit;
}

$userId = $_SESSION['user_id'];

// Remove only the latest declined request for the user
$sql = "DELETE FROM help_requests 
        WHERE id = (
            SELECT id FROM (
                SELECT id FROM help_requests 
                WHERE user_id = ? AND status = 'Declined' 
                ORDER BY created_at DESC LIMIT 1
            ) AS t
        )";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['success'=>false, 'message'=>'Prepare failed']);
    exit;
}
$stmt->bind_param('i', $userId);
$ok = $stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

if ($ok && $affected > 0) {
    echo json_encode(['success'=>true]);
} else {
    echo json_encode(['success'=>false, 'message'=>'No declined request to remove']);
}

$conn->close();
?>




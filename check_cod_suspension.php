<?php
session_start();
require_once 'dbconn.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit;
}

$user_id = $_SESSION['user_id'];

// Ensure required columns exist
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS cod_suspended TINYINT(1) DEFAULT 0");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS cod_suspended_until DATETIME NULL");

// Check if user is currently suspended
$stmt = $conn->prepare("SELECT cod_suspended, cod_suspended_until FROM users WHERE id = ?");
if ($stmt) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->bind_result($cod_suspended, $cod_suspended_until);
    $stmt->fetch();
    $stmt->close();
    
    // Check if currently suspended and suspension hasn't expired
    $is_suspended = $cod_suspended && $cod_suspended_until && strtotime($cod_suspended_until) > time();
    
    if ($is_suspended) {
        echo json_encode([
            'success' => true,
            'suspended' => true,
            'suspended_until' => $cod_suspended_until,
            'suspended_until_formatted' => date('Y-m-d h:i A', strtotime($cod_suspended_until)),
            'message' => 'Your COD option is currently suspended until ' . date('Y-m-d h:i A', strtotime($cod_suspended_until)) . '. Please use GCASH.'
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'suspended' => false,
            'message' => 'COD is available'
        ]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>

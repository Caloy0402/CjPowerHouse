<?php
session_start();
require_once 'dbconn.php';

// Only admins
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

// Get the last check timestamp from request
$lastCheck = isset($_GET['last_check']) ? $_GET['last_check'] : date('Y-m-d H:i:s', strtotime('-1 minute'));

// Check for any new or updated records since last check
$sql = "SELECT COUNT(*) as changes FROM staff_logs 
        WHERE (created_at > ? OR updated_at > ?) 
        AND role <> 'Admin'";

$stmt = $conn->prepare($sql);
$hasChanges = false;
$newRecords = 0;

if ($stmt) {
    $stmt->bind_param('ss', $lastCheck, $lastCheck);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $newRecords = $row['changes'];
    $hasChanges = $newRecords > 0;
    $stmt->close();
}

// Get current online staff count
$onlineSql = "SELECT role, COUNT(*) as count FROM staff_logs 
              WHERE action = 'login' AND time_out IS NULL 
              AND role <> 'Admin' 
              GROUP BY role";
$onlineStmt = $conn->prepare($onlineSql);
$onlineStaff = [];

if ($onlineStmt) {
    $onlineStmt->execute();
    $result = $onlineStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $onlineStaff[$row['role']] = $row['count'];
    }
    $onlineStmt->close();
}

echo json_encode([
    'success' => true,
    'has_changes' => $hasChanges,
    'new_records' => $newRecords,
    'online_staff' => $onlineStaff,
    'current_time' => date('Y-m-d H:i:s'),
    'last_check' => $lastCheck
]);

$conn->close();
?>

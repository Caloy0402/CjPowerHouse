<?php
session_start();
require_once 'dbconn.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$staffId = (int)$_SESSION['user_id'];
$role = ucfirst(strtolower($_SESSION['role']));

if ($role !== 'Cashier') {
    echo json_encode(['role' => $role, 'is_cashier' => false]);
    exit;
}

// Get latest open staff log for this cashier
$sql = "SELECT id, time_in, TIMESTAMPDIFF(MINUTE, time_in, NOW()) AS elapsed_minutes
        FROM staff_logs
        WHERE staff_id = ? AND role = 'Cashier' AND time_out IS NULL
        ORDER BY time_in DESC LIMIT 1";

$status = [
    'is_cashier' => true,
    'has_session' => false,
    'minutes' => 0,
    'required_minutes' => 480,
    'met_requirement' => false,
    'missing_minutes' => 0,
    'time_in' => null,
    'time_out' => date('Y-m-d H:i:s'),
    'staff_id' => $staffId
];

if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param('i', $staffId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $status['has_session'] = true;
        $status['minutes'] = (int)$row['elapsed_minutes'];
        $status['time_in'] = $row['time_in'];
        $status['met_requirement'] = $status['minutes'] >= $status['required_minutes'];
        $status['missing_minutes'] = max(0, $status['required_minutes'] - $status['minutes']);
        $status['log_id'] = (int)$row['id'];
    }
    $stmt->close();
}

echo json_encode($status);
exit;
?>

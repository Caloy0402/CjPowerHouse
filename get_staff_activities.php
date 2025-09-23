<?php
// Returns recent activities for a staff member within a date range as JSON
header('Content-Type: application/json');
require_once 'dbconn.php';

$staffId = isset($_GET['staff_id']) ? (int)$_GET['staff_id'] : 0;
$role = isset($_GET['role']) ? $_GET['role'] : '';
$from = isset($_GET['from']) ? $_GET['from'] : date('Y-m-d', strtotime('-7 days'));
$to = isset($_GET['to']) ? $_GET['to'] : date('Y-m-d');

if ($staffId <= 0 || $role === '') {
    echo json_encode([]);
    exit;
}

// Ensure table exists (safe-guard)
$conn->query("CREATE TABLE IF NOT EXISTS staff_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  staff_id INT NOT NULL,
  role VARCHAR(20) NOT NULL,
  action VARCHAR(20) NOT NULL,
  activity TEXT NULL,
  time_in DATETIME NOT NULL,
  time_out DATETIME DEFAULT NULL,
  duty_duration_minutes INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_staff_role (staff_id, role),
  INDEX idx_time_in (time_in)
)");

$sql = "SELECT id, action, activity, time_in, time_out, duty_duration_minutes
        FROM staff_logs
        WHERE staff_id = ? AND role = ? AND DATE(time_in) BETWEEN ? AND ?
        ORDER BY time_in DESC
        LIMIT 200";

$rows = [];
if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param('isss', $staffId, $role, $from, $to);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) { $rows[] = $r; }
    $stmt->close();
}

echo json_encode($rows);
exit;
?>



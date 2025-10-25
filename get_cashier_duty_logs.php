<?php
session_start();
require_once 'dbconn.php';
header('Content-Type: application/json');

$from = isset($_GET['from']) ? $_GET['from'] : date('Y-m-d');
$to   = isset($_GET['to'])   ? $_GET['to']   : date('Y-m-d');
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['perPage']) ? max(1, (int)$_GET['perPage']) : 5;
$offset = ($page - 1) * $perPage;

$rows = [];

$sql = "SELECT l.id, l.staff_id, l.time_in, l.time_out, l.duty_duration_minutes,
               cj.first_name, cj.last_name
        FROM staff_logs l
        LEFT JOIN cjusers cj ON cj.id = l.staff_id
        WHERE l.role = 'Cashier' AND l.time_out IS NOT NULL
          AND DATE(l.time_in) BETWEEN ? AND ?
        ORDER BY l.time_out DESC
        LIMIT ? OFFSET ?";

if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param('ssii', $from, $to, $perPage, $offset);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $minutes = (int)($r['duty_duration_minutes'] ?? 0);
        $rows[] = [
            'cashier' => trim(($r['first_name'] ?? '').' '.($r['last_name'] ?? '')),
            'time_in' => date('Y/m/d h:i A', strtotime($r['time_in'])),
            'time_out' => date('Y/m/d h:i A', strtotime($r['time_out'])),
            'duration' => sprintf('%02dh %02dm', floor($minutes/60), $minutes%60),
            'from_dt' => $r['time_in'],
            'to_dt' => $r['time_out']
        ];
    }
    $stmt->close();
}

$totalRows = 0;
if ($c = $conn->prepare("SELECT COUNT(*) AS cnt FROM staff_logs l WHERE l.role='Cashier' AND l.time_out IS NOT NULL AND DATE(l.time_in) BETWEEN ? AND ?")) {
    $c->bind_param('ss', $from, $to);
    $c->execute();
    $rr = $c->get_result();
    if ($row = $rr->fetch_assoc()) { $totalRows = (int)$row['cnt']; }
    $c->close();
}

echo json_encode([
    'rows' => $rows,
    'page' => $page,
    'perPage' => $perPage,
    'totalRows' => $totalRows,
    'totalPages' => max(1, (int)ceil($totalRows / $perPage))
]);
exit;
?>

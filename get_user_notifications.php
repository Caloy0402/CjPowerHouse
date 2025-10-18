<?php
header('Content-Type: application/json');
require 'dbconn.php';

// Ensure column exists
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS cod_failed_attempts INT DEFAULT 0");

$data = [ 'total_count' => 0, 'notifications' => [] ];

$q = "SELECT id, first_name, last_name, ImagePath, cod_failed_attempts
      FROM users
      WHERE cod_failed_attempts >= 2
        AND NOT (cod_suspended = 1 AND cod_suspended_until IS NOT NULL AND cod_suspended_until > NOW())
      ORDER BY cod_failed_attempts DESC, id DESC";

if ($res = $conn->query($q)) {
    while ($row = $res->fetch_assoc()) {
        $fullName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        $attempts = (int)$row['cod_failed_attempts'];
        $data['notifications'][] = [
            'type' => 'user_cod_failed',
            'title' => 'User COD Failure',
            'message' => $fullName . ' failed to receive COD ' . $attempts . ' times',
            'count' => $attempts,
            'icon' => 'fa-user-times',
            'color' => 'text-danger',
            'user_id' => (int)$row['id'],
            'image_path' => $row['ImagePath'] ?? '',
            'name' => $fullName,
        ];
    }
    $res->free();
}

$data['total_count'] = count($data['notifications']);

echo json_encode($data);
?>



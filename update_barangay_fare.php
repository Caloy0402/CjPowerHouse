<?php
// Simple maintenance endpoint to update/view barangay fares by barangay name
// Usage examples:
//   View current values:  update_barangay_fare.php?name=Lumbo
//   Update local fare:    update_barangay_fare.php?name=Lumbo&fare=54
//   Update both fares:    update_barangay_fare.php?name=Lumbo&fare=54&staff=140

require_once __DIR__ . '/dbconn.php';

header('Content-Type: text/html; charset=utf-8');

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$name  = isset($_GET['name']) ? trim($_GET['name']) : '';
$fare  = isset($_GET['fare']) ? $_GET['fare'] : null;         // local rider fare
$staff = isset($_GET['staff']) ? $_GET['staff'] : null;       // staff delivery fare

if ($name === '') {
    echo '<h3>Provide a barangay name with ?name=...</h3>';
    exit;
}

// 1) Find barangay id by exact name
$sqlFind = 'SELECT id, barangay_name FROM barangays WHERE barangay_name = ? LIMIT 1';
$stmt    = $conn->prepare($sqlFind);
if (!$stmt) { die('Prepare failed: ' . h($conn->error)); }
$stmt->bind_param('s', $name);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    echo '<p style="color:red">Barangay not found: ' . h($name) . '</p>';
    exit;
}

$barangay = $res->fetch_assoc();
$barangayId = (int)$barangay['id'];
$stmt->close();

// 2) Ensure a single fare row exists for this barangay_id
$sqlFare = 'SELECT id, fare_amount, staff_fare_amount, distance_km FROM barangay_fares WHERE barangay_id = ? ORDER BY id DESC';
$stmtFare = $conn->prepare($sqlFare);
if (!$stmtFare) { die('Prepare failed: ' . h($conn->error)); }
$stmtFare->bind_param('i', $barangayId);
$stmtFare->execute();
$resFare = $stmtFare->get_result();

// Optional cleanup for duplicates: keep newest, remove older rows
if ($resFare->num_rows > 1) {
    $first = $resFare->fetch_assoc(); // keep newest (highest id due to ORDER BY id DESC)
    while ($row = $resFare->fetch_assoc()) {
        $conn->query('DELETE FROM barangay_fares WHERE id=' . (int)$row['id']);
    }
    // Requery to get the remaining row
    $stmtFare->close();
    $stmtFare = $conn->prepare($sqlFare);
    $stmtFare->bind_param('i', $barangayId);
    $stmtFare->execute();
    $resFare = $stmtFare->get_result();
}

$existing = $resFare->fetch_assoc();
$stmtFare->close();

// If only viewing
if ($fare === null && $staff === null) {
    echo '<h3>Current Fare for ' . h($barangay['barangay_name']) . '</h3>';
    if ($existing) {
        echo '<p>Local Rider Fare: ₱' . h(number_format((float)$existing['fare_amount'], 2)) . '</p>';
        echo '<p>Staff Fare: ₱' . h(number_format((float)$existing['staff_fare_amount'], 2)) . '</p>';
        echo '<p>Distance: ' . h($existing['distance_km']) . ' km</p>';
    } else {
        echo '<p>No fare row yet for this barangay.</p>';
    }
    exit;
}

// 3) Apply updates (create or update)
$newFare  = ($fare  !== null) ? (float)$fare  : ($existing ? (float)$existing['fare_amount'] : 0.0);
$newStaff = ($staff !== null) ? (float)$staff : ($existing ? (float)$existing['staff_fare_amount'] : 0.0);

if ($existing) {
    $sqlUpdate = 'UPDATE barangay_fares SET fare_amount = ?, staff_fare_amount = ? WHERE barangay_id = ?';
    $stmtUp = $conn->prepare($sqlUpdate);
    if (!$stmtUp) { die('Prepare failed: ' . h($conn->error)); }
    $stmtUp->bind_param('ddi', $newFare, $newStaff, $barangayId);
    $ok = $stmtUp->execute();
    $stmtUp->close();
    if (!$ok) { die('<p style="color:red">Update failed: ' . h($conn->error) . '</p>'); }
} else {
    $sqlIns = 'INSERT INTO barangay_fares (barangay_id, barangay_name, fare_amount, staff_fare_amount, distance_km) VALUES (?, ?, ?, ?, 0)';
    $stmtIns = $conn->prepare($sqlIns);
    if (!$stmtIns) { die('Prepare failed: ' . h($conn->error)); }
    $stmtIns->bind_param('isdd', $barangayId, $barangay['barangay_name'], $newFare, $newStaff);
    $ok = $stmtIns->execute();
    $stmtIns->close();
    if (!$ok) { die('<p style="color:red">Insert failed: ' . h($conn->error) . '</p>'); }
}

echo '<h3>Updated Fare for ' . h($barangay['barangay_name']) . '</h3>';
echo '<p>Local Rider Fare: ₱' . h(number_format($newFare, 2)) . '</p>';
echo '<p>Staff Fare: ₱' . h(number_format($newStaff, 2)) . '</p>';
echo '<p>Note: The checkout page reads from barangay_fares by barangay_id. This ensures the correct row is updated.</p>';

$conn->close();
?>



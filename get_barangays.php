<?php
require_once 'dbconn.php';

// Fetch all barangays from the database
$sql = "SELECT id, barangay_name FROM barangays ORDER BY barangay_name";
$result = $conn->query($sql);

$barangays = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $barangays[] = [
            'id' => $row['id'],
            'barangay_name' => $row['barangay_name']
        ];
    }
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode($barangays);

$conn->close();
?>
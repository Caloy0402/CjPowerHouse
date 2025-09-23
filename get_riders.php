<?php
require 'dbconn.php';

// Fetch all riders from the riders table with their current delivery status
$sql = "SELECT r.id, r.first_name, r.last_name, r.phone_number, r.MotorType, r.PlateNumber, r.barangay_id, r.purok,
               CASE 
                   WHEN EXISTS (
                       SELECT 1 FROM orders o 
                       WHERE o.rider_name = CONCAT(r.first_name, ' ', r.last_name) 
                       AND o.order_status = 'On-Ship'
                   ) THEN 'OnDelivery'
                   ELSE 'Available'
               END AS status
        FROM riders r 
        ORDER BY r.first_name, r.last_name";

$result = $conn->query($sql);
$riders = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $riders[] = [
            'id' => $row['id'],
            'name' => $row['first_name'] . ' ' . $row['last_name'],
            'phone' => $row['phone_number'],
            'motor_type' => $row['MotorType'],
            'plate_number' => $row['PlateNumber'],
            'barangay_id' => $row['barangay_id'],
            'purok' => $row['purok'],
            'status' => $row['status']
        ];
    }
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode($riders);

$conn->close();
?>

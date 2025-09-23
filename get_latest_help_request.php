<?php
session_start();
header('Content-Type: application/json');
require_once 'dbconn.php';
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success'=>false, 'message'=>'Not logged in']);
    exit();
}
$user_id = $_SESSION['user_id'];
$sql = "SELECT hr.*, bb.barangay_name, m.first_name AS mechanic_first_name, m.last_name AS mechanic_last_name, m.ImagePath AS mechanic_image, m.phone_number AS mechanic_phone, m.home_address AS mechanic_home_address, m.email AS mechanic_email, m.PlateNumber AS mechanic_plate, m.MotorType AS mechanic_motor_type, m.specialization AS mechanic_specialization
        FROM help_requests hr
        LEFT JOIN barangays bb ON hr.breakdown_barangay_id = bb.id
        LEFT JOIN mechanics m ON hr.mechanic_id = m.id
        WHERE hr.user_id = ?
        ORDER BY hr.created_at DESC LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $mechanic_name = ($row['mechanic_first_name'] && $row['mechanic_last_name']) ? $row['mechanic_first_name'].' '.$row['mechanic_last_name'] : null;
    $mechanic_image = $row['mechanic_image'] ? $row['mechanic_image'] : null;
    if ($mechanic_image && strpos($mechanic_image, 'http') !== 0) {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $path = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
        $baseURL = $protocol . '://' . $host . $path . '/';
        $mechanic_image = $baseURL . $mechanic_image;
    }
    echo json_encode([
        'success'=>true,
        'request'=>[
            'status'=>$row['status'],
            'bike_unit'=>$row['bike_unit'],
            'problem_description'=>$row['problem_description'],
            'barangay_name'=>$row['barangay_name'],
            'location'=>$row['location'],
            'mechanic_id'=>$row['mechanic_id'],
            'mechanic_name'=>$mechanic_name,
            'mechanic_image'=>$mechanic_image,
            'mechanic_phone'=>$row['mechanic_phone'] ?? null,
            'mechanic_home_address'=>$row['mechanic_home_address'] ?? null,
            'mechanic_email'=>$row['mechanic_email'] ?? null,
            'mechanic_plate'=>$row['mechanic_plate'] ?? null,
            'mechanic_motor_type'=>$row['mechanic_motor_type'] ?? null,
            'mechanic_specialization'=>$row['mechanic_specialization'] ?? null,
            'decline_reason'=>$row['decline_reason'] ?? null,
            'decline_reason_text'=>$row['decline_reason_text'] ?? null,
            'declined_at'=>$row['declined_at'] ?? null
        ]
    ]);
} else {
    echo json_encode(['success'=>true, 'request'=>null]);
}
$conn->close(); 
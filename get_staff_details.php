<?php
session_start();
require_once 'dbconn.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit();
}

// Get parameters
$staff_id = isset($_GET['staff_id']) ? (int)$_GET['staff_id'] : 0;
$staff_type = isset($_GET['staff_type']) ? trim($_GET['staff_type']) : '';

if (!$staff_id || !$staff_type) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit();
}

try {
    $staff_data = null;
    
    switch ($staff_type) {
        case 'cashier':
            $sql = "SELECT id, first_name, last_name, middle_name, email, profile_image, 
                           home_address, contact_info, motor_type, plate_number,
                           login_time, logout_time,
                           CASE 
                               WHEN login_time IS NOT NULL AND (logout_time IS NULL OR login_time > logout_time) 
                               THEN 1 
                               ELSE 0 
                           END AS is_online
                    FROM cjusers 
                    WHERE id = ? AND role = 'Cashier'";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $staff_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $staff_data = $result->fetch_assoc();
            $stmt->close();
            break;
            
        case 'mechanic':
            $sql = "SELECT id, first_name, last_name, middle_name, email, ImagePath as profile_image,
                           home_address, phone_number, MotorType, PlateNumber, specialization
                    FROM mechanics 
                    WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $staff_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $staff_data = $result->fetch_assoc();
            $stmt->close();
            break;
            
        case 'rider':
            $sql = "SELECT r.id, r.first_name, r.last_name, r.middle_name, r.email, r.ImagePath as profile_image,
                           r.phone_number, r.MotorType, r.PlateNumber, r.barangay_id, r.purok,
                           b.barangay_name
                    FROM riders r
                    LEFT JOIN barangays b ON r.barangay_id = b.id
                    WHERE r.id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $staff_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $staff_data = $result->fetch_assoc();
            $stmt->close();
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid staff type']);
            exit();
    }
    
    if ($staff_data) {
        // Format dates for cashiers only (they have login_time/logout_time)
        if ($staff_type === 'cashier') {
            if ($staff_data['login_time']) {
                $staff_data['last_login_at'] = date('Y-m-d H:i:s', strtotime($staff_data['login_time']));
            }
            if ($staff_data['logout_time']) {
                $staff_data['last_logout_at'] = date('Y-m-d H:i:s', strtotime($staff_data['logout_time']));
            }
        } else {
            // For mechanics and riders, set default values since they don't have login tracking
            $staff_data['last_login_at'] = null;
            $staff_data['last_logout_at'] = null;
            $staff_data['is_online'] = false;
        }
        
        echo json_encode([
            'success' => true,
            'staff' => $staff_data
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Staff member not found']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>

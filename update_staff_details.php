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
$staff_id = isset($_POST['staff_id']) ? (int)$_POST['staff_id'] : 0;
$staff_type = isset($_POST['staff_type']) ? trim($_POST['staff_type']) : '';
$first_name = trim($_POST['first_name'] ?? '');
$last_name = trim($_POST['last_name'] ?? '');
$middle_name = trim($_POST['middle_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = trim($_POST['password'] ?? '');
$phone_number = trim($_POST['phone_number'] ?? '');
$home_address = trim($_POST['home_address'] ?? '');
$motor_type = trim($_POST['motor_type'] ?? '');
$plate_number = trim($_POST['plate_number'] ?? '');
$specialization = trim($_POST['specialization'] ?? '');
$barangay_id = isset($_POST['barangay_id']) ? (int)$_POST['barangay_id'] : null;
$purok = trim($_POST['purok'] ?? '');

if (!$staff_id || !$staff_type || !$first_name || !$last_name || !$email) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

try {
    // Handle profile image upload
    $profile_image_path = null;
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == 0) {
        $image_name = $_FILES['profile_image']['name'];
        $image_tmp_name = $_FILES['profile_image']['tmp_name'];
        $image_ext = strtolower(pathinfo($image_name, PATHINFO_EXTENSION));
        
        $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
        
        if (in_array($image_ext, $allowed_ext)) {
            $new_image_name = uniqid() . '.' . $image_ext;
            $upload_dir = 'uploads/';
            $upload_path = $upload_dir . $new_image_name;
            
            if (move_uploaded_file($image_tmp_name, $upload_path)) {
                $profile_image_path = $new_image_name;
            }
        }
    }
    
    $success = false;
    $message = '';
    
    switch ($staff_type) {
        case 'cashier':
            // Update cashier in cjusers table
            $update_fields = "first_name = ?, last_name = ?, middle_name = ?, email = ?, home_address = ?, contact_info = ?, motor_type = ?, plate_number = ?";
            $params = [$first_name, $last_name, $middle_name, $email, $home_address, $phone_number, $motor_type, $plate_number];
            $param_types = "ssssssss";
            
            if ($profile_image_path) {
                $update_fields .= ", profile_image = ?";
                $params[] = $profile_image_path;
                $param_types .= "s";
            }
            
            if (!empty($password)) {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $update_fields .= ", password = ?";
                $params[] = $hashed_password;
                $param_types .= "s";
            }
            
            $params[] = $staff_id;
            $param_types .= "i";
            
            $sql = "UPDATE cjusers SET $update_fields WHERE id = ? AND role = 'Cashier'";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($param_types, ...$params);
            
            if ($stmt->execute()) {
                $success = true;
                $message = 'Cashier details updated successfully';
            } else {
                $message = 'Failed to update cashier details';
            }
            $stmt->close();
            break;
            
        case 'mechanic':
            // Update mechanic in mechanics table
            $update_fields = "first_name = ?, last_name = ?, middle_name = ?, email = ?, home_address = ?, phone_number = ?, MotorType = ?, PlateNumber = ?, specialization = ?";
            $params = [$first_name, $last_name, $middle_name, $email, $home_address, $phone_number, $motor_type, $plate_number, $specialization];
            $param_types = "sssssssss";
            
            if ($profile_image_path) {
                $update_fields .= ", ImagePath = ?";
                $params[] = $profile_image_path;
                $param_types .= "s";
            }
            
            if (!empty($password)) {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $update_fields .= ", password = ?";
                $params[] = $hashed_password;
                $param_types .= "s";
            }
            
            $params[] = $staff_id;
            $param_types .= "i";
            
            $sql = "UPDATE mechanics SET $update_fields WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($param_types, ...$params);
            
            if ($stmt->execute()) {
                $success = true;
                $message = 'Mechanic details updated successfully';
            } else {
                $message = 'Failed to update mechanic details';
            }
            $stmt->close();
            break;
            
        case 'rider':
            // Update rider in riders table
            $update_fields = "first_name = ?, last_name = ?, middle_name = ?, email = ?, phone_number = ?, MotorType = ?, PlateNumber = ?, barangay_id = ?, purok = ?";
            $params = [$first_name, $last_name, $middle_name, $email, $phone_number, $motor_type, $plate_number, $barangay_id, $purok];
            $param_types = "sssssssis";
            
            if ($profile_image_path) {
                $update_fields .= ", ImagePath = ?";
                $params[] = $profile_image_path;
                $param_types .= "s";
            }
            
            if (!empty($password)) {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $update_fields .= ", password = ?";
                $params[] = $hashed_password;
                $param_types .= "s";
            }
            
            $params[] = $staff_id;
            $param_types .= "i";
            
            $sql = "UPDATE riders SET $update_fields WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($param_types, ...$params);
            
            if ($stmt->execute()) {
                $success = true;
                $message = 'Rider details updated successfully';
            } else {
                $message = 'Failed to update rider details';
            }
            $stmt->close();
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid staff type']);
            exit();
    }
    
    echo json_encode([
        'success' => $success,
        'message' => $message
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>

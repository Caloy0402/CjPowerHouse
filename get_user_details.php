<?php
session_start();
require_once 'dbconn.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!isset($_GET['user_id']) || !is_numeric($_GET['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
    exit();
}

$user_id = (int)$_GET['user_id'];

try {
    // Fetch user details from users table with barangay name
    $sql = "SELECT u.id, u.first_name, u.last_name, u.middle_name, u.email, u.phone_number, u.ImagePath, 
                   u.barangay_id, u.purok, u.email_verified, u.last_login_at, u.last_logout_at, 
                   u.created_at, u.cod_suspended, u.cod_suspended_until, u.cod_failed_attempts,
                   b.barangay_name
            FROM users u
            LEFT JOIN barangays b ON u.barangay_id = b.id
            WHERE u.id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user_data = $result->fetch_assoc();
        
        // Format the response
        echo json_encode([
            'success' => true,
            'user' => [
                'id' => $user_data['id'],
                'first_name' => $user_data['first_name'],
                'last_name' => $user_data['last_name'],
                'middle_name' => $user_data['middle_name'],
                'email' => $user_data['email'],
                'phone_number' => $user_data['phone_number'],
                'ImagePath' => $user_data['ImagePath'],
                'barangay_id' => $user_data['barangay_id'],
                'barangay_name' => $user_data['barangay_name'],
                'purok' => $user_data['purok'],
                'email_verified' => $user_data['email_verified'],
                'last_login_at' => $user_data['last_login_at'],
                'last_logout_at' => $user_data['last_logout_at'],
                'created_at' => $user_data['created_at'],
                'cod_suspended' => $user_data['cod_suspended'],
                'cod_suspended_until' => $user_data['cod_suspended_until'],
                'cod_failed_attempts' => $user_data['cod_failed_attempts']
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'User not found']);
    }
    
    $stmt->close();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

$conn->close();
?>

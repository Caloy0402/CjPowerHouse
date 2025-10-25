<?php
session_start();
require_once 'dbconn.php';

header('Content-Type: application/json');

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'] ?? '';
    $logId = $_POST['log_id'] ?? 0;
    $userId = $_POST['user_id'] ?? 0;
    $userRole = $_POST['user_role'] ?? '';
    $userFullName = $_POST['user_full_name'] ?? '';
    
    if ($action === 'resume' && $logId > 0 && $userId > 0) {
        // Set session variables
        $_SESSION['user_id'] = $userId;
        $_SESSION['full_name'] = $userFullName;
        $_SESSION['role'] = $userRole;
        $_SESSION['resumed_session'] = true;
        $_SESSION['log_id'] = $logId;
        
        // Determine redirect URL based on role
        $redirectUrl = '';
        switch ($userRole) {
            case 'Cashier':
                $redirectUrl = 'Cashier-Dashboard.php';
                break;
            case 'Rider':
                $redirectUrl = 'Rider-Dashboard.php';
                break;
            case 'Mechanic':
                $redirectUrl = 'Mechanic-Dashboard.php';
                break;
            default:
                $redirectUrl = 'signin.php';
        }
        
        $response = [
            'status' => 'success',
            'message' => 'Session resumed successfully!',
            'redirectUrl' => $redirectUrl
        ];
        
    } elseif ($action === 'start_new' && $userId > 0) {
        // Close the existing session and start a new one
        if ($logId > 0) {
            $updateStmt = $conn->prepare("UPDATE staff_logs SET time_out = NOW(), duty_duration_minutes = TIMESTAMPDIFF(MINUTE, time_in, NOW()) WHERE id = ?");
            if ($updateStmt) {
                $updateStmt->bind_param("i", $logId);
                $updateStmt->execute();
                $updateStmt->close();
            }
        }
        
        // Create new session log
        $insertStmt = $conn->prepare("INSERT INTO staff_logs (staff_id, role, action, time_in) VALUES (?, ?, 'login', NOW())");
        if ($insertStmt) {
            $insertStmt->bind_param("is", $userId, $userRole);
            $insertStmt->execute();
            $insertStmt->close();
        }
        
        // Set session variables
        $_SESSION['user_id'] = $userId;
        $_SESSION['full_name'] = $userFullName;
        $_SESSION['role'] = $userRole;
        $_SESSION['resumed_session'] = false;
        
        // Determine redirect URL based on role
        $redirectUrl = '';
        switch ($userRole) {
            case 'Cashier':
                $redirectUrl = 'Cashier-Dashboard.php';
                break;
            case 'Rider':
                $redirectUrl = 'Rider-Dashboard.php';
                break;
            case 'Mechanic':
                $redirectUrl = 'Mechanic-Dashboard.php';
                break;
            default:
                $redirectUrl = 'signin.php';
        }
        
        $response = [
            'status' => 'success',
            'message' => 'New session started successfully!',
            'redirectUrl' => $redirectUrl
        ];
        
    } else {
        $response = [
            'status' => 'error',
            'message' => 'Invalid parameters provided.'
        ];
    }
    
    echo json_encode($response);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
}
?>

<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'dbconn.php';

header('Content-Type: application/json');

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'] ?? '';
    $user_id = $_POST['user_id'] ?? 0;
    $existing_session_id = $_POST['existing_session_id'] ?? '';
    
    if ($action && $user_id > 0) {
        if ($action === 'continue_existing') {
            // Deactivate all other sessions for this user
            $deactivateStmt = $conn->prepare("UPDATE user_sessions SET is_active = FALSE WHERE user_id = ? AND session_id != ?");
            if ($deactivateStmt) {
                $deactivateStmt->bind_param("is", $user_id, $existing_session_id);
                $deactivateStmt->execute();
                $deactivateStmt->close();
            }
            
            // Get user data
            $userStmt = $conn->prepare("SELECT id, first_name, middle_name, last_name, email, ImagePath FROM users WHERE id = ?");
            if ($userStmt) {
                $userStmt->bind_param("i", $user_id);
                $userStmt->execute();
                $userResult = $userStmt->get_result();
                
                if ($userResult->num_rows == 1) {
                    $user = $userResult->fetch_assoc();
                    
                    // Set session variables
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['full_name'] = trim($user['first_name'] . " " . ($user['middle_name'] ? $user['middle_name'] . " " : "") . $user['last_name']);
                    $_SESSION['role'] = "Customer";
                    $_SESSION['profile_image'] = $user['ImagePath'] ?? 'Image/default-avatar.png';
                    
                    $response = [
                        'status' => 'success',
                        'message' => 'Continuing with existing session...',
                        'redirectUrl' => 'Mobile-Dashboard.php'
                    ];
                } else {
                    $response = [
                        'status' => 'error',
                        'message' => 'User not found.'
                    ];
                }
                $userStmt->close();
            } else {
                $response = [
                    'status' => 'error',
                    'message' => 'Database error.'
                ];
            }
            
        } elseif ($action === 'start_new') {
            // Deactivate all existing sessions for this user
            $deactivateStmt = $conn->prepare("UPDATE user_sessions SET is_active = FALSE WHERE user_id = ?");
            if ($deactivateStmt) {
                $deactivateStmt->bind_param("i", $user_id);
                $deactivateStmt->execute();
                $deactivateStmt->close();
            }
            
            // Create new session
            $session_id = session_id();
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            
            $insertStmt = $conn->prepare("INSERT INTO user_sessions (user_id, session_id, ip_address, user_agent, login_time, last_activity, is_active) VALUES (?, ?, ?, ?, NOW(), NOW(), ?)");
            if ($insertStmt) {
                $is_active = true;
                $insertStmt->bind_param("isssb", $user_id, $session_id, $ip_address, $user_agent, $is_active);
                $insertStmt->execute();
                $insertStmt->close();
            }
            
            // Update last_login_at in users table
            $updateStmt = $conn->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?");
            if ($updateStmt) {
                $updateStmt->bind_param("i", $user_id);
                $updateStmt->execute();
                $updateStmt->close();
            }
            
            // Get user data
            $userStmt = $conn->prepare("SELECT id, first_name, middle_name, last_name, email, ImagePath FROM users WHERE id = ?");
            if ($userStmt) {
                $userStmt->bind_param("i", $user_id);
                $userStmt->execute();
                $userResult = $userStmt->get_result();
                
                if ($userResult->num_rows == 1) {
                    $user = $userResult->fetch_assoc();
                    
                    // Set session variables
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['full_name'] = trim($user['first_name'] . " " . ($user['middle_name'] ? $user['middle_name'] . " " : "") . $user['last_name']);
                    $_SESSION['role'] = "Customer";
                    $_SESSION['profile_image'] = $user['ImagePath'] ?? 'Image/default-avatar.png';
                    
                    $response = [
                        'status' => 'success',
                        'message' => 'New session started successfully!',
                        'redirectUrl' => 'Mobile-Dashboard.php'
                    ];
                } else {
                    $response = [
                        'status' => 'error',
                        'message' => 'User not found.'
                    ];
                }
                $userStmt->close();
            } else {
                $response = [
                    'status' => 'error',
                    'message' => 'Database error.'
                ];
            }
            
        } else {
            $response = [
                'status' => 'error',
                'message' => 'Invalid action specified.'
            ];
        }
    } else {
        $response = [
            'status' => 'error',
            'message' => 'Missing required parameters.'
        ];
    }
    
    echo json_encode($response);
} else {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid request method.'
    ]);
}
?>

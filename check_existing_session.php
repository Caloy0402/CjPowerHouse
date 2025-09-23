<?php
session_start();
require_once 'dbconn.php';

header('Content-Type: application/json');

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    if (!empty($email) && !empty($password)) {
        $response = ['status' => 'error', 'message' => 'Wrong email or password'];
        $userData = null;
        $userRole = '';
        $userId = 0;
        
        // Check all user tables to find the user
        $tables = [
            ['table' => 'cjusers', 'role' => 'Admin', 'fields' => 'id, email, password, role'],
            ['table' => 'cjusers', 'role' => 'Cashier', 'fields' => 'id, email, password, role'],
            ['table' => 'users', 'role' => 'Customer', 'fields' => 'id, first_name, middle_name, last_name, email, password'],
            ['table' => 'riders', 'role' => 'Rider', 'fields' => 'id, first_name, middle_name, last_name, email, password'],
            ['table' => 'mechanics', 'role' => 'Mechanic', 'fields' => 'id, first_name, middle_name, last_name, email, password']
        ];
        
        foreach ($tables as $tableInfo) {
            $stmt = $conn->prepare("SELECT {$tableInfo['fields']} FROM {$tableInfo['table']} WHERE email = ?");
            if ($stmt) {
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows == 1) {
                    $user = $result->fetch_assoc();
                    if (password_verify($password, $user['password'])) {
                        $userData = $user;
                        $userRole = $tableInfo['role'];
                        $userId = $user['id'];
                        break;
                    }
                }
                $stmt->close();
            }
        }
        
        if ($userData) {
            // Check for existing unfinished session (only for staff roles)
            if (in_array($userRole, ['Cashier', 'Rider', 'Mechanic'])) {
                $stmt = $conn->prepare("
                    SELECT id, time_in, 
                           TIMESTAMPDIFF(MINUTE, time_in, NOW()) as elapsed_minutes,
                           TIMESTAMPDIFF(MINUTE, time_in, NOW()) as duty_duration_minutes
                    FROM staff_logs 
                    WHERE staff_id = ? AND role = ? AND time_out IS NULL 
                    ORDER BY time_in DESC 
                    LIMIT 1
                ");
                
                if ($stmt) {
                    $stmt->bind_param("is", $userId, $userRole);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result->num_rows > 0) {
                        $sessionData = $result->fetch_assoc();
                        $elapsedMinutes = $sessionData['elapsed_minutes'];
                        $remainingMinutes = 480 - $elapsedMinutes; // 8 hours = 480 minutes
                        
                        if ($remainingMinutes > 0) {
                            $response = [
                                'status' => 'existing_session',
                                'message' => 'You have an existing session. Would you like to continue?',
                                'session_data' => [
                                    'log_id' => $sessionData['id'],
                                    'time_in' => $sessionData['time_in'],
                                    'elapsed_minutes' => $elapsedMinutes,
                                    'remaining_minutes' => $remainingMinutes,
                                    'elapsed_hours' => floor($elapsedMinutes / 60),
                                    'elapsed_mins' => $elapsedMinutes % 60,
                                    'remaining_hours' => floor($remainingMinutes / 60),
                                    'remaining_mins' => $remainingMinutes % 60
                                ],
                                'user_data' => [
                                    'id' => $userId,
                                    'role' => $userRole,
                                    'full_name' => isset($userData['first_name']) ? 
                                        trim($userData['first_name'] . " " . ($userData['middle_name'] ? $userData['middle_name'] . " " : "") . $userData['last_name']) :
                                        $userRole . " User"
                                ]
                            ];
                        } else {
                            // Session expired (over 8 hours), close it and start new
                            $updateStmt = $conn->prepare("UPDATE staff_logs SET time_out = NOW(), duty_duration_minutes = ? WHERE id = ?");
                            if ($updateStmt) {
                                $updateStmt->bind_param("ii", $elapsedMinutes, $sessionData['id']);
                                $updateStmt->execute();
                                $updateStmt->close();
                            }
                            
                            $response = [
                                'status' => 'session_expired',
                                'message' => 'Your previous session has expired. Starting new session.',
                                'user_data' => [
                                    'id' => $userId,
                                    'role' => $userRole,
                                    'full_name' => isset($userData['first_name']) ? 
                                        trim($userData['first_name'] . " " . ($userData['middle_name'] ? $userData['middle_name'] . " " : "") . $userData['last_name']) :
                                        $userRole . " User"
                                ]
                            ];
                        }
                    } else {
                        // No existing session, proceed with normal login
                        $response = [
                            'status' => 'new_session',
                            'message' => 'No existing session found. Proceeding with login.',
                            'user_data' => [
                                'id' => $userId,
                                'role' => $userRole,
                                'full_name' => isset($userData['first_name']) ? 
                                    trim($userData['first_name'] . " " . ($userData['middle_name'] ? $userData['middle_name'] . " " : "") . $userData['last_name']) :
                                    $userRole . " User"
                            ]
                        ];
                    }
                    $stmt->close();
                }
            } else {
                // For Admin and Customer, proceed with normal login
                $response = [
                    'status' => 'new_session',
                    'message' => 'Proceeding with login.',
                    'user_data' => [
                        'id' => $userId,
                        'role' => $userRole,
                        'full_name' => isset($userData['first_name']) ? 
                            trim($userData['first_name'] . " " . ($userData['middle_name'] ? $userData['middle_name'] . " " : "") . $userData['last_name']) :
                            $userRole . " User"
                    ]
                ];
            }
        }
        
        echo json_encode($response);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Please provide email and password']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
}
?>

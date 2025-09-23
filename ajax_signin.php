<?php
session_start();
require_once 'dbconn.php'; // Ensure database connection

header('Content-Type: application/json'); // Set response header to JSON

$response = ['status' => 'error', 'message' => 'An unknown error occurred.']; // Default response

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email'] ?? ''); // Use null coalescing operator
    $password = trim($_POST['password'] ?? '');

    if (!empty($email) && !empty($password)) {
        // Ensure staff_logs table exists for logging
        $conn->query("CREATE TABLE IF NOT EXISTS staff_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            staff_id INT NOT NULL,
            role VARCHAR(20) NOT NULL,
            action VARCHAR(20) NOT NULL,
            time_in DATETIME NOT NULL,
            time_out DATETIME DEFAULT NULL,
            duty_duration_minutes INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_staff_role (staff_id, role),
            INDEX idx_time_in (time_in)
        )");
        $loggedIn = false;
        $redirectUrl = '';
        $errorMessage = "Invalid email or password."; // Default error for failed attempts

        // 1. Check `cjusers` table (Admin/Cashier)
        $stmt = $conn->prepare("SELECT id, email, password, role, profile_image FROM cjusers WHERE email = ?");
        if ($stmt) {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows == 1) {
                $user = $result->fetch_assoc();
                if (password_verify($password, $user['password'])) {
                    $_SESSION['user_id'] = $user['id'];
                    // For Admin/Cashier, maybe just use the role or email if no name fields exist
                    $_SESSION['full_name'] = $user['role'] . " User"; // Example placeholder
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['profile_image'] = $user['profile_image'] ?? 'Image/default-avatar.png'; // Use default if null
                    $loggedIn = true;
                    $redirectUrl = (strtolower($user['role']) == 'admin') ? 'Admin-Dashboard.php' : 'Cashier-Dashboard.php';

                    // Check for existing session for Cashier
                    $normalizedRole = ucfirst(strtolower($user['role']));
                    if ($normalizedRole === 'Cashier') {
                        // Check for existing unfinished session
                        $checkStmt = $conn->prepare("
                            SELECT id, time_in, 
                                   TIMESTAMPDIFF(MINUTE, time_in, NOW()) as elapsed_minutes
                            FROM staff_logs 
                            WHERE staff_id = ? AND role = ? AND time_out IS NULL 
                            ORDER BY time_in DESC 
                            LIMIT 1
                        ");
                        
                        // Debug: Log the query
                        error_log("Checking session for staff_id: " . $user['id'] . ", role: " . $normalizedRole);
                        
                        if ($checkStmt) {
                            $checkStmt->bind_param("is", $user['id'], $normalizedRole);
                            $checkStmt->execute();
                            $checkResult = $checkStmt->get_result();
                            
                            // Debug: Log the result
                            error_log("Session check result: " . $checkResult->num_rows . " rows found");
                            
                            if ($checkResult->num_rows > 0) {
                                $sessionData = $checkResult->fetch_assoc();
                                $elapsedMinutes = $sessionData['elapsed_minutes'];
                                $remainingMinutes = 480 - $elapsedMinutes; // 8 hours = 480 minutes
                                
                                if ($remainingMinutes > 0) {
                                    // Debug: Log that we found a valid session
                                    error_log("Found valid session with remaining time: " . $remainingMinutes . " minutes");
                                    
                                    // Set session variables for the user
                                    $_SESSION['user_id'] = $user['id'];
                                    $_SESSION['full_name'] = $user['role'] . " User";
                                    $_SESSION['role'] = $normalizedRole;
                                    $_SESSION['profile_image'] = $user['profile_image'] ?? 'Image/default-avatar.png';
                                    
                                    // Return session resumption data
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
                                            'id' => $user['id'],
                                            'role' => $normalizedRole,
                                            'full_name' => $user['role'] . " User"
                                        ]
                                    ];
                                    echo json_encode($response);
                                    exit;
                                } else {
                                    // Session expired, close it
                                    $updateStmt = $conn->prepare("UPDATE staff_logs SET time_out = NOW(), duty_duration_minutes = ? WHERE id = ?");
                                    if ($updateStmt) {
                                        $updateStmt->bind_param("ii", $elapsedMinutes, $sessionData['id']);
                                        $updateStmt->execute();
                                        $updateStmt->close();
                                    }
                                }
                            }
                            $checkStmt->close();
                        }
                        
                        // Create new session log
                        $logStmt = $conn->prepare("INSERT INTO staff_logs (staff_id, role, action, time_in) VALUES (?, ?, 'login', NOW())");
                        if ($logStmt) {
                            $logStmt->bind_param('is', $user['id'], $normalizedRole);
                            $logStmt->execute();
                            $logStmt->close();
                        }
                    }
                }
            }
            $stmt->close();
        } else {
            error_log("Prepare failed (cjusers): " . $conn->error);
            $response['message'] = 'Database error during login.';
            echo json_encode($response);
            exit;
        }

        // 2. If not logged in, check `users` table (Customers)
        if (!$loggedIn) {
            $stmt = $conn->prepare("SELECT id, first_name, middle_name, last_name, email, password, ImagePath, email_verified FROM users WHERE email = ?");
             if ($stmt) {
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows == 1) {
                    $customer = $result->fetch_assoc();
                    if (password_verify($password, $customer['password'])) {
                        // Check if email is verified
                        if (!$customer['email_verified']) {
                            $response = [
                                'status' => 'email_not_verified',
                                'message' => 'Please verify your email address before logging in. Check your inbox for a verification link.',
                                'user_id' => $customer['id'],
                                'email' => $customer['email'],
                                'first_name' => $customer['first_name']
                            ];
                            echo json_encode($response);
                            exit;
                        }
                        
                        $_SESSION['user_id'] = $customer['id'];
                        $_SESSION['full_name'] = trim($customer['first_name'] . " " . ($customer['middle_name'] ? $customer['middle_name'] . " " : "") . $customer['last_name']);
                        $_SESSION['role'] = "Customer";
                        $_SESSION['profile_image'] = $customer['ImagePath'] ?? 'Image/default-avatar.png'; // Use default if null
                        $loggedIn = true;
                        $redirectUrl = 'Mobile-Dashboard.php';
                    }
                }
                $stmt->close();
             } else {
                error_log("Prepare failed (users): " . $conn->error);
                $response['message'] = 'Database error during login.';
                echo json_encode($response);
                exit;
             }
        }

        // 3. If not logged in, check `riders` table
        if (!$loggedIn) {
            $stmt = $conn->prepare("SELECT id, first_name, middle_name, last_name, email, password, ImagePath FROM riders WHERE email = ?");
            if ($stmt) {
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows == 1) {
                    $rider = $result->fetch_assoc();
                    if (password_verify($password, $rider['password'])) {
                        $_SESSION['user_id'] = $rider['id'];
                        $_SESSION['full_name'] = trim($rider['first_name'] . " " . ($rider['middle_name'] ? $rider['middle_name'] . " " : "") . $rider['last_name']);
                        $_SESSION['role'] = "Rider";
                        $_SESSION['profile_image'] = $rider['ImagePath'] ?? 'Image/default-avatar.png'; // Use default if null
                        $loggedIn = true;
                        $redirectUrl = 'Rider-Dashboard.php'; // Make sure this file exists

                        // Check for existing session for Rider
                        $checkStmt = $conn->prepare("
                            SELECT id, time_in, 
                                   TIMESTAMPDIFF(MINUTE, time_in, NOW()) as elapsed_minutes
                            FROM staff_logs 
                            WHERE staff_id = ? AND role = 'Rider' AND time_out IS NULL 
                            ORDER BY time_in DESC 
                            LIMIT 1
                        ");
                        
                        if ($checkStmt) {
                            $checkStmt->bind_param("i", $rider['id']);
                            $checkStmt->execute();
                            $checkResult = $checkStmt->get_result();
                            
                            if ($checkResult->num_rows > 0) {
                                $sessionData = $checkResult->fetch_assoc();
                                $elapsedMinutes = $sessionData['elapsed_minutes'];
                                $remainingMinutes = 480 - $elapsedMinutes; // 8 hours = 480 minutes
                                
                                if ($remainingMinutes > 0) {
                                    // Set session variables for the user
                                    $_SESSION['user_id'] = $rider['id'];
                                    $_SESSION['full_name'] = trim($rider['first_name'] . " " . ($rider['middle_name'] ? $rider['middle_name'] . " " : "") . $rider['last_name']);
                                    $_SESSION['role'] = "Rider";
                                    $_SESSION['profile_image'] = $rider['ImagePath'] ?? 'Image/default-avatar.png';
                                    $_SESSION['show_rider_loader'] = true;
                                    $_SESSION['rider_login_time'] = time();
                                    
                                    // Return session resumption data
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
                                            'id' => $rider['id'],
                                            'role' => 'Rider',
                                            'full_name' => trim($rider['first_name'] . " " . ($rider['middle_name'] ? $rider['middle_name'] . " " : "") . $rider['last_name'])
                                        ]
                                    ];
                                    echo json_encode($response);
                                    exit;
                                } else {
                                    // Session expired, close it
                                    $updateStmt = $conn->prepare("UPDATE staff_logs SET time_out = NOW(), duty_duration_minutes = ? WHERE id = ?");
                                    if ($updateStmt) {
                                        $updateStmt->bind_param("ii", $elapsedMinutes, $sessionData['id']);
                                        $updateStmt->execute();
                                        $updateStmt->close();
                                    }
                                }
                            }
                            $checkStmt->close();
                        }
                        
                        // Create new session log
                        $logStmt = $conn->prepare("INSERT INTO staff_logs (staff_id, role, action, time_in) VALUES (?, 'Rider', 'login', NOW())");
                        if ($logStmt) { $logStmt->bind_param('i', $rider['id']); $logStmt->execute(); $logStmt->close(); }
                    }
                }
                $stmt->close();
            } else {
                 error_log("Prepare failed (riders): " . $conn->error);
                 $response['message'] = 'Database error during login.';
                 echo json_encode($response);
                 exit;
            }
        }

        // 4. If not logged in, check `mechanics` table
        if (!$loggedIn) {
            $stmt = $conn->prepare("SELECT id, first_name, middle_name, last_name, email, password, ImagePath FROM mechanics WHERE email = ?");
            if ($stmt) {
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows == 1) {
                    $mechanic = $result->fetch_assoc();
                    if (password_verify($password, $mechanic['password'])) {
                        $_SESSION['user_id'] = $mechanic['id'];
                        $_SESSION['full_name'] = trim($mechanic['first_name'] . " " . ($mechanic['middle_name'] ? $mechanic['middle_name'] . " " : "") . $mechanic['last_name']);
                        $_SESSION['role'] = "Mechanic";
                        $_SESSION['profile_image'] = $mechanic['ImagePath'] ?? 'Image/default-avatar.png'; // Use default if null
                        $loggedIn = true;
                        // Set one-time mechanic loader flag to show custom loading on dashboard
                        $_SESSION['show_mechanic_loader'] = true;
                        $_SESSION['mechanic_login_time'] = time();
                        $redirectUrl = 'Mechanic-Dashboard.php'; // Make sure this file exists

                        // Check for existing session for Mechanic
                        $checkStmt = $conn->prepare("
                            SELECT id, time_in, 
                                   TIMESTAMPDIFF(MINUTE, time_in, NOW()) as elapsed_minutes
                            FROM staff_logs 
                            WHERE staff_id = ? AND role = 'Mechanic' AND time_out IS NULL 
                            ORDER BY time_in DESC 
                            LIMIT 1
                        ");
                        
                        if ($checkStmt) {
                            $checkStmt->bind_param("i", $mechanic['id']);
                            $checkStmt->execute();
                            $checkResult = $checkStmt->get_result();
                            
                            if ($checkResult->num_rows > 0) {
                                $sessionData = $checkResult->fetch_assoc();
                                $elapsedMinutes = $sessionData['elapsed_minutes'];
                                $remainingMinutes = 480 - $elapsedMinutes; // 8 hours = 480 minutes
                                
                                if ($remainingMinutes > 0) {
                                    // Set session variables for the user
                                    $_SESSION['user_id'] = $mechanic['id'];
                                    $_SESSION['full_name'] = trim($mechanic['first_name'] . " " . ($mechanic['middle_name'] ? $mechanic['middle_name'] . " " : "") . $mechanic['last_name']);
                                    $_SESSION['role'] = "Mechanic";
                                    $_SESSION['profile_image'] = $mechanic['ImagePath'] ?? 'Image/default-avatar.png';
                                    
                                    // Return session resumption data
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
                                            'id' => $mechanic['id'],
                                            'role' => 'Mechanic',
                                            'full_name' => trim($mechanic['first_name'] . " " . ($mechanic['middle_name'] ? $mechanic['middle_name'] . " " : "") . $mechanic['last_name'])
                                        ]
                                    ];
                                    echo json_encode($response);
                                    exit;
                                } else {
                                    // Session expired, close it
                                    $updateStmt = $conn->prepare("UPDATE staff_logs SET time_out = NOW(), duty_duration_minutes = ? WHERE id = ?");
                                    if ($updateStmt) {
                                        $updateStmt->bind_param("ii", $elapsedMinutes, $sessionData['id']);
                                        $updateStmt->execute();
                                        $updateStmt->close();
                                    }
                                }
                            }
                            $checkStmt->close();
                        }
                        
                        // Create new session log
                        $logStmt = $conn->prepare("INSERT INTO staff_logs (staff_id, role, action, time_in) VALUES (?, 'Mechanic', 'login', NOW())");
                        if ($logStmt) { $logStmt->bind_param('i', $mechanic['id']); $logStmt->execute(); $logStmt->close(); }
                    }
                }
                $stmt->close();
            } else {
                 error_log("Prepare failed (mechanics): " . $conn->error);
                 $response['message'] = 'Database error during login.';
                 echo json_encode($response);
                 exit;
            }
        }

        // Prepare final response based on login status
        if ($loggedIn) {
            $response['status'] = 'success';
            $response['message'] = 'Login successful!';
            $response['redirectUrl'] = $redirectUrl;
        } else {
            // If we checked all tables and $loggedIn is still false, use the specific error
            $response['status'] = 'error';
            $response['message'] = $errorMessage; // "Invalid email or password." or potentially "User not found." if you refined the logic
        }

    } else {
        $response['message'] = "Please fill in all fields.";
    }
} else {
     $response['message'] = "Invalid request method.";
}

// Close connection
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}

// Send JSON response
echo json_encode($response);
exit;
?>
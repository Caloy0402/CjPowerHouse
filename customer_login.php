<?php
session_start();
require_once 'dbconn.php'; // Database connection

// Set content type to JSON for AJAX responses
header('Content-Type: application/json');

$error = ""; // Error message variable

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);

    if (!empty($email) && !empty($password)) {
        // First check if this is a staff member trying to login through customer form
        $staffCheck = $conn->prepare("SELECT id, email, role FROM cjusers WHERE email = ?");
        $staffCheck->bind_param("s", $email);
        $staffCheck->execute();
        $staffResult = $staffCheck->get_result();
        
        if ($staffResult->num_rows > 0) {
            // This is a staff member - deny access
            echo json_encode([
                'status' => 'error',
                'message' => 'Access denied. This login form is for customers only. Staff members should use the staff login portal.'
            ]);
            exit();
        }
        
        // Check ONLY the `users` table for customers
        $stmt = $conn->prepare("SELECT id, first_name, middle_name, last_name, email, password, ImagePath FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 1) {
            $customer = $result->fetch_assoc();
            if (password_verify($password, $customer['password'])) {
                // Deactivate any existing sessions for this user to start fresh
                $deactivateStmt = $conn->prepare("UPDATE user_sessions SET is_active = FALSE WHERE user_id = ? AND is_active = TRUE");
                if ($deactivateStmt) {
                    $deactivateStmt->bind_param("i", $customer['id']);
                    $deactivateStmt->execute();
                    $deactivateStmt->close();
                }
                
                // Proceed with normal login
                $_SESSION['user_id'] = $customer['id'];
                $_SESSION['full_name'] = trim($customer['first_name'] . " " . ($customer['middle_name'] ? $customer['middle_name'] . " " : "") . $customer['last_name']);
                $_SESSION['role'] = "Customer";
                $_SESSION['profile_image'] = $customer['ImagePath'];

                // Create new session record
                $session_id = session_id();
                $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
                
                $insertStmt = $conn->prepare("INSERT INTO user_sessions (user_id, session_id, ip_address, user_agent, login_time, last_activity, is_active) VALUES (?, ?, ?, ?, NOW(), NOW(), ?)");
                if ($insertStmt) {
                    $is_active = 1;
                    $insertStmt->bind_param("isssi", $customer['id'], $session_id, $ip_address, $user_agent, $is_active);
                    $insertStmt->execute();
                    $insertStmt->close();
                }
                
                // Update last_login_at in users table
                $updateLogin = $conn->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?");
                if ($updateLogin) {
                    $updateLogin->bind_param('i', $customer['id']);
                    $updateLogin->execute();
                    $updateLogin->close();
                }

                // Return success response
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Login successful!',
                    'redirect' => 'Mobile-Dashboard.php'
                ]);
                exit();
            } else {
                $error = "Invalid email or password.";
            }
        } else {
            $error = "Invalid email or password.";
        }
        $stmt->close();
    } else {
        $error = "Please fill in all fields.";
    }
}

// Return error response
echo json_encode([
    'status' => 'error',
    'message' => $error ?: 'An error occurred during login.'
]);
exit();
?>

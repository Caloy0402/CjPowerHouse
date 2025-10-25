<?php
session_start();
require_once 'dbconn.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$staffId = (int)$_SESSION['user_id'];
$role = ucfirst(strtolower($_SESSION['role']));

// Debug logging for session data
error_log("Session Debug - User ID: $staffId, Role: $role, Session Role Raw: " . ($_SESSION['role'] ?? 'NOT SET'));

// Only apply duty hour checks to staff members (not customers)
if (!in_array($role, ['Admin', 'Cashier', 'Rider', 'Mechanic'])) {
    // For customers, proceed with normal logout
    $_SESSION = [];
    session_destroy();
    echo json_encode(['success' => true, 'redirect' => 'signin.php']);
    exit;
}

// Get duty status for staff members
$dutyStatus = getDutyStatus($conn, $staffId, $role);

// Debug logging
error_log("Enhanced Logout Debug - User ID: $staffId, Role: $role, Has Session: " . ($dutyStatus['has_session'] ? 'Yes' : 'No') . ", Minutes: " . $dutyStatus['minutes'] . ", Met Requirement: " . ($dutyStatus['met_requirement'] ? 'Yes' : 'No'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'verify_password') {
        // Handle password verification for early logout
        $password = $_POST['password'] ?? '';
        
        if (empty($password)) {
            echo json_encode(['success' => false, 'message' => 'Password is required']);
            exit;
        }
        
        // Verify password against user's current password based on role
        $user_table = '';
        switch ($role) {
            case 'Admin':
            case 'Cashier':
                $user_table = 'cjusers';
                break;
            case 'Rider':
                $user_table = 'riders';
                break;
            case 'Mechanic':
                $user_table = 'mechanics';
                break;
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid role for password verification']);
                exit;
        }
        
        // Debug logging for password verification
        error_log("Password Verification Debug - Staff ID: $staffId, Role: $role, User Table: $user_table");
        
        $sql = "SELECT password FROM $user_table WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log("Failed to prepare statement for table: $user_table");
            echo json_encode(['success' => false, 'message' => 'Database error during password verification']);
            exit;
        }
        $stmt->bind_param('i', $staffId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            error_log("User found in $user_table table, verifying password");
            if (password_verify($password, $row['password'])) {
                error_log("Password verification successful for user $staffId");
                // Password is correct, proceed with logout
                performLogout($conn, $staffId, $role);
                echo json_encode(['success' => true, 'message' => 'Password verified. Logging out...', 'redirect' => 'signin.php']);
            } else {
                error_log("Password verification failed for user $staffId");
                echo json_encode(['success' => false, 'message' => 'Incorrect password']);
            }
        } else {
            error_log("User $staffId not found in $user_table table");
            echo json_encode(['success' => false, 'message' => 'User not found in ' . $user_table . ' table']);
        }
        $stmt->close();
        exit;
    }
    
    if ($action === 'confirm_logout') {
        // Handle confirmed logout (either completed duty or user confirmed early logout)
        performLogout($conn, $staffId, $role);
        echo json_encode(['success' => true, 'message' => 'Logged out successfully', 'redirect' => 'signin.php']);
        exit;
    }
}

// Return duty status for frontend decision making
echo json_encode([
    'success' => true,
    'duty_status' => $dutyStatus,
    'is_cashier' => ($role === 'Cashier'),
    'role' => $role
]);

function getDutyStatus($conn, $staffId, $role) {
    $requiredMinutes = getRequiredMinutesByRole($role);
    
    // Debug: Check what's in staff_logs table for this user
    $debug_sql = "SELECT id, staff_id, role, time_in, time_out FROM staff_logs WHERE staff_id = ? ORDER BY time_in DESC LIMIT 5";
    if ($debug_stmt = $conn->prepare($debug_sql)) {
        $debug_stmt->bind_param('i', $staffId);
        $debug_stmt->execute();
        $debug_res = $debug_stmt->get_result();
        $debug_logs = [];
        while ($debug_row = $debug_res->fetch_assoc()) {
            $debug_logs[] = $debug_row;
        }
        error_log("Staff logs for user $staffId: " . json_encode($debug_logs));
        $debug_stmt->close();
    }
    
    // Get latest open staff log for this staff member
    $sql = "SELECT id, time_in, TIMESTAMPDIFF(MINUTE, time_in, NOW()) AS elapsed_minutes
            FROM staff_logs
            WHERE staff_id = ? AND role = ? AND time_out IS NULL
            ORDER BY time_in DESC LIMIT 1";
    
    $status = [
        'has_session' => false,
        'minutes' => 0,
        'required_minutes' => $requiredMinutes,
        'met_requirement' => false,
        'missing_minutes' => 0,
        'time_in' => null,
        'log_id' => null,
        'remaining_hours' => 0,
        'remaining_minutes' => 0,
        'role' => $role
    ];
    
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('is', $staffId, $role);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $status['has_session'] = true;
            $status['minutes'] = (int)$row['elapsed_minutes'];
            $status['time_in'] = $row['time_in'];
            $status['met_requirement'] = $status['minutes'] >= $status['required_minutes'];
            $status['missing_minutes'] = max(0, $status['required_minutes'] - $status['minutes']);
            $status['log_id'] = (int)$row['id'];
            
            // Calculate remaining time in hours and minutes
            $remainingMinutes = $status['missing_minutes'];
            $status['remaining_hours'] = floor($remainingMinutes / 60);
            $status['remaining_minutes'] = $remainingMinutes % 60;
        }
        $stmt->close();
    }
    
    return $status;
}

function getRequiredMinutesByRole($role) {
    // Define required duty hours by role (in minutes)
    switch (strtolower($role)) {
        case 'cashier':
            return 480; // 8 hours
        case 'mechanic':
            return 480; // 8 hours
        case 'rider':
            return 480; // 8 hours
        case 'admin':
            return 480; // 8 hours (can be adjusted)
        default:
            return 480; // Default 8 hours
    }
}

function performLogout($conn, $staffId, $role) {
    // Update staff log with time out
    if (isset($_SESSION['resumed_session']) && $_SESSION['resumed_session'] && isset($_SESSION['log_id'])) {
        $logId = (int)$_SESSION['log_id'];
        $sql = "UPDATE staff_logs 
                   SET time_out = NOW(),
                       duty_duration_minutes = TIMESTAMPDIFF(MINUTE, time_in, NOW())
                 WHERE id = ?";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param('i', $logId);
            $stmt->execute();
            $stmt->close();
        }
    } else {
        // Update the latest open log
        $sql = "UPDATE staff_logs 
                   SET time_out = NOW(),
                       duty_duration_minutes = TIMESTAMPDIFF(MINUTE, time_in, NOW())
                 WHERE id = (
                       SELECT id FROM (
                           SELECT id FROM staff_logs
                           WHERE staff_id = ? AND role = ? AND time_out IS NULL
                           ORDER BY time_in DESC LIMIT 1
                       ) AS t
                 )";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param('is', $staffId, $role);
            $stmt->execute();
            $stmt->close();
        }
    }
    
    // Destroy session
    $_SESSION = [];
    session_destroy();
}
?>

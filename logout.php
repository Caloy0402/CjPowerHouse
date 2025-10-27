<?php
session_start();
require_once 'dbconn.php';

// If the user is a staff (Admin, Cashier, Rider, Mechanic), record time out and duration
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    $staffId = (int)$_SESSION['user_id'];
    $role = ucfirst(strtolower($_SESSION['role']));
    if (in_array($role, ['Admin','Cashier','Rider','Mechanic','Customer'])) {
        // If this was a resumed session, use the specific log_id
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
            // Update the latest open log (no time_out yet) for this staff
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
        
        // If it's a customer, also update the users table last_logout_at column and deactivate user sessions
        if ($role === 'Customer') {
            $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS last_logout_at DATETIME NULL");
            $updateLogout = $conn->prepare("UPDATE users SET last_logout_at = NOW() WHERE id = ?");
            if ($updateLogout) {
                $updateLogout->bind_param('i', $staffId);
                $updateLogout->execute();
                $updateLogout->close();
            }
            
            // Deactivate all active sessions for this user
            $deactivateStmt = $conn->prepare("UPDATE user_sessions SET is_active = FALSE WHERE user_id = ? AND is_active = TRUE");
            if ($deactivateStmt) {
                $deactivateStmt->bind_param("i", $staffId);
                $deactivateStmt->execute();
                $deactivateStmt->close();
            }
        }
    }
}

// Destroy session and redirect to sign in
$_SESSION = [];
session_destroy();
header('Location: signin.php');
exit;

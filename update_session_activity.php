<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'dbconn.php';

// Update session activity for the current user
if (isset($_SESSION['user_id']) && $_SESSION['role'] === 'Customer') {
    $user_id = $_SESSION['user_id'];
    $session_id = session_id();
    
    // First, check if session exists
    $checkStmt = $conn->prepare("SELECT id FROM user_sessions WHERE user_id = ? AND session_id = ?");
    if ($checkStmt) {
        $checkStmt->bind_param("is", $user_id, $session_id);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        
        if ($result->num_rows > 0) {
            // Session exists, update activity
            $updateStmt = $conn->prepare("UPDATE user_sessions SET last_activity = NOW() WHERE user_id = ? AND session_id = ?");
            if ($updateStmt) {
                $updateStmt->bind_param("is", $user_id, $session_id);
                $updateStmt->execute();
                $updateStmt->close();
            }
        } else {
            // Session doesn't exist, create it
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            
            $insertStmt = $conn->prepare("INSERT INTO user_sessions (user_id, session_id, ip_address, user_agent, login_time, last_activity, is_active) VALUES (?, ?, ?, ?, NOW(), NOW(), ?)");
            if ($insertStmt) {
                $is_active = true;
                $insertStmt->bind_param("isssb", $user_id, $session_id, $ip_address, $user_agent, $is_active);
                $insertStmt->execute();
                $insertStmt->close();
            }
        }
        $checkStmt->close();
    }
}

// Clean up inactive sessions (older than 30 minutes) - using PHP time calculation to avoid timezone issues
$cleanupStmt = $conn->prepare("SELECT id, last_activity FROM user_sessions WHERE is_active = TRUE");
if ($cleanupStmt) {
    $cleanupStmt->execute();
    $result = $cleanupStmt->get_result();
    
    while ($session = $result->fetch_assoc()) {
        $lastActivity = new DateTime($session['last_activity']);
        $now = new DateTime();
        $diff = $now->diff($lastActivity);
        $minutesDiff = ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;
        
        if ($minutesDiff >= 30) {
            $deactivateStmt = $conn->prepare("UPDATE user_sessions SET is_active = FALSE WHERE id = ?");
            if ($deactivateStmt) {
                $deactivateStmt->bind_param("i", $session['id']);
                $deactivateStmt->execute();
                $deactivateStmt->close();
            }
        }
    }
    $cleanupStmt->close();
}

// Clean up very old session records (older than 7 days)
$deleteOldStmt = $conn->prepare("DELETE FROM user_sessions WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) AND is_active = FALSE");
if ($deleteOldStmt) {
    $deleteOldStmt->execute();
    $deleteOldStmt->close();
}
?>

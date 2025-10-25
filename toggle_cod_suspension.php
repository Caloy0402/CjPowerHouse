<?php
session_start();
require_once 'dbconn.php';

header('Content-Type: application/json');

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in. Please log in as admin.']);
    exit;
}

// Check if user is admin (case insensitive)
$userRole = strtolower(trim($_SESSION['role']));
if ($userRole !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Admin role required. Current role: ' . $_SESSION['role']]);
    exit;
}

$action = isset($_POST['action']) ? $_POST['action'] : '';
$userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
if ($userId <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid user']); exit; }

if ($action === 'suspend') {
    // Add a 7-day suspension starting now if not already active
    $check = $conn->prepare("SELECT 1 FROM users WHERE id=? AND cod_suspended=1 AND cod_suspended_until>NOW() LIMIT 1");
    $check->bind_param('i', $userId);
    $check->execute();
    $check->store_result();
    if ($check->num_rows > 0) { 
        $check->close(); 
        echo json_encode(['success'=>false,'message'=>'User is already suspended']); 
        exit; 
    }
    $check->close();
    
    $suspend_until = date('Y-m-d H:i:s', strtotime('+7 days'));
    $stmt = $conn->prepare("UPDATE users SET cod_suspended=1, cod_suspended_until=? WHERE id=?");
    $stmt->bind_param('si', $suspend_until, $userId);
    $ok = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    
    if ($ok && $affected > 0) {
        // Log the suspension action
        error_log("User $userId suspended until $suspend_until by admin " . $_SESSION['user_id']);
        echo json_encode(['success'=>true, 'message'=>'User suspended for 7 days until ' . $suspend_until]);
    } else {
        error_log("Failed to suspend user $userId. SQL error: " . $conn->error);
        echo json_encode(['success'=>false, 'message'=>'Failed to suspend user. Database error: ' . $conn->error]);
    }
    exit;
}

if ($action === 'lift') {
    // First check current status
    $check = $conn->prepare("SELECT cod_suspended, cod_suspended_until FROM users WHERE id = ?");
    $check->bind_param('i', $userId);
    $check->execute();
    $check->bind_result($current_suspended, $current_until);
    $check->fetch();
    $check->close();
    
    if (!$current_suspended) {
        echo json_encode(['success'=>false, 'message'=>'User is not currently suspended']);
        exit;
    }
    
    // Lift suspension and reset failed attempts counter
    $stmt = $conn->prepare("UPDATE users SET cod_suspended=0, cod_suspended_until=NULL, cod_failed_attempts=0 WHERE id=?");
    $stmt->bind_param('i', $userId);
    $ok = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    
    if ($ok && $affected > 0) {
        // Mark latest pending appeal as resolved for this user
        $conn->query("UPDATE cod_appeals SET status='resolved', reviewed_by=".(int)$_SESSION['user_id'].", reviewed_at=NOW() WHERE user_id=".$userId." AND status='pending' ORDER BY id DESC LIMIT 1");
        echo json_encode(['success'=>true, 'message'=>'Suspension lifted and failed attempts reset successfully']);
    } else {
        echo json_encode(['success'=>false, 'message'=>'Failed to lift suspension. Affected rows: ' . $affected]);
    }
    exit;
}

if ($action === 'reset_attempts') {
    // Manually reset failed attempts counter
    $stmt = $conn->prepare("UPDATE users SET cod_failed_attempts=0 WHERE id=?");
    $stmt->bind_param('i', $userId);
    $ok = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    
    if ($ok && $affected > 0) {
        echo json_encode(['success'=>true, 'message'=>'Failed attempts counter reset successfully']);
    } else {
        echo json_encode(['success'=>false, 'message'=>'Failed to reset attempts counter. Affected rows: ' . $affected]);
    }
    exit;
}

echo json_encode(['success'=>false,'message'=>'Invalid action']);
?>



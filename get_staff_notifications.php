<?php
header('Content-Type: application/json');
header('Cache-Control: no-cache');

require_once 'dbconn.php';

// Function to get staff notifications
function getStaffNotifications($conn) {
    $notifications = array();
    
    // Check for staff currently on duty
    $onDutyQuery = "SELECT COUNT(*) as count FROM staff_logs WHERE time_out IS NULL";
    $onDutyResult = $conn->query($onDutyQuery);
    if ($onDutyResult) {
        $onDutyCount = $onDutyResult->fetch_assoc()['count'];
        if ($onDutyCount > 0) {
            $notifications[] = array(
                'type' => 'staff_on_duty',
                'title' => 'Staff On Duty',
                'message' => "$onDutyCount staff member" . ($onDutyCount > 1 ? 's' : '') . " currently working",
                'count' => $onDutyCount,
                'icon' => 'fa-users',
                'color' => 'text-success'
            );
        }
    }
    
    // Check for recent staff logins
    $recentLoginsQuery = "SELECT COUNT(*) as count FROM staff_logs 
                          WHERE DATE(time_in) = CURDATE()";
    $recentLoginsResult = $conn->query($recentLoginsQuery);
    if ($recentLoginsResult) {
        $recentLoginsCount = $recentLoginsResult->fetch_assoc()['count'];
        if ($recentLoginsCount > 0) {
            $notifications[] = array(
                'type' => 'recent_logins',
                'title' => 'Recent Staff Activity',
                'message' => "$recentLoginsCount staff login" . ($recentLoginsCount > 1 ? 's' : '') . " today",
                'count' => $recentLoginsCount,
                'icon' => 'fa-sign-in-alt',
                'color' => 'text-info'
            );
        }
    }
    
    return $notifications;
}

function getTotalStaffNotificationCount($conn) {
    $total = 0;
    
    // Count staff on duty
    $onDutyQuery = "SELECT COUNT(*) as count FROM staff_logs WHERE time_out IS NULL";
    $onDutyResult = $conn->query($onDutyQuery);
    if ($onDutyResult) {
        $count = $onDutyResult->fetch_assoc()['count'];
        if ($count > 0) $total += $count;
    }
    
    // Count recent logins
    $recentLoginsQuery = "SELECT COUNT(*) as count FROM staff_logs WHERE DATE(time_in) = CURDATE()";
    $recentLoginsResult = $conn->query($recentLoginsQuery);
    if ($recentLoginsResult) {
        $count = $recentLoginsResult->fetch_assoc()['count'];
        if ($count > 0) $total += $count;
    }
    
    return $total;
}

// Get and return notifications
$notifications = getStaffNotifications($conn);
$totalCount = getTotalStaffNotificationCount($conn);

echo json_encode([
    'notifications' => $notifications,
    'total_count' => $totalCount
]);

$conn->close();
?>

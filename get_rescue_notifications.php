<?php
// Set content type to JSON
header('Content-Type: application/json');

// Start session and check admin access
session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Include database connection
require_once 'dbconn.php';

// Rescue request notification functions
function getRescueRequestNotifications($conn) {
    $notifications = array();
    
    // Check for pending rescue requests
    $pendingQuery = "SELECT COUNT(*) as count FROM help_requests WHERE status = 'Pending'";
    $pendingResult = $conn->query($pendingQuery);
    if ($pendingResult) {
        $pendingCount = $pendingResult->fetch_assoc()['count'];
        if ($pendingCount > 0) {
            $notifications[] = array(
                'type' => 'pending_requests',
                'title' => 'Pending Rescue Requests',
                'message' => "$pendingCount rescue request" . ($pendingCount > 1 ? 's' : '') . " waiting for response",
                'count' => $pendingCount,
                'icon' => 'fa-exclamation-triangle',
                'color' => 'text-warning'
            );
        }
    }
    
    // Check for in-progress rescue requests
    $inProgressQuery = "SELECT COUNT(*) as count FROM help_requests WHERE status = 'In Progress'";
    $inProgressResult = $conn->query($inProgressQuery);
    if ($inProgressResult) {
        $inProgressCount = $inProgressResult->fetch_assoc()['count'];
        if ($inProgressCount > 0) {
            $notifications[] = array(
                'type' => 'in_progress_requests',
                'title' => 'Active Rescue Operations',
                'message' => "$inProgressCount rescue operation" . ($inProgressCount > 1 ? 's' : '') . " currently in progress",
                'count' => $inProgressCount,
                'icon' => 'fa-tools',
                'color' => 'text-info'
            );
        }
    }
    
    // Check for today's rescue requests
    $todayQuery = "SELECT COUNT(*) as count FROM help_requests WHERE DATE(created_at) = CURDATE()";
    $todayResult = $conn->query($todayQuery);
    if ($todayResult) {
        $todayCount = $todayResult->fetch_assoc()['count'];
        if ($todayCount > 0) {
            $notifications[] = array(
                'type' => 'today_requests',
                'title' => 'Today\'s Rescue Requests',
                'message' => "$todayCount rescue request" . ($todayCount > 1 ? 's' : '') . " received today",
                'count' => $todayCount,
                'icon' => 'fa-calendar-day',
                'color' => 'text-primary'
            );
        }
    }
    
    // Check for completed rescue requests today
    $completedQuery = "SELECT COUNT(*) as count FROM help_requests WHERE status = 'Completed' AND DATE(completed_at) = CURDATE()";
    $completedResult = $conn->query($completedQuery);
    if ($completedResult) {
        $completedCount = $completedResult->fetch_assoc()['count'];
        if ($completedCount > 0) {
            $notifications[] = array(
                'type' => 'completed_today',
                'title' => 'Completed Today',
                'message' => "$completedCount rescue request" . ($completedCount > 1 ? 's' : '') . " completed today",
                'count' => $completedCount,
                'icon' => 'fa-check-circle',
                'color' => 'text-success'
            );
        }
    }
    
    return $notifications;
}

function getTotalRescueNotificationCount($conn) {
    $total = 0;
    
    // Count pending requests
    $pendingQuery = "SELECT COUNT(*) as count FROM help_requests WHERE status = 'Pending'";
    $pendingResult = $conn->query($pendingQuery);
    if ($pendingResult) {
        $count = $pendingResult->fetch_assoc()['count'];
        if ($count > 0) $total += $count;
    }
    
    // Count in-progress requests
    $inProgressQuery = "SELECT COUNT(*) as count FROM help_requests WHERE status = 'In Progress'";
    $inProgressResult = $conn->query($inProgressQuery);
    if ($inProgressResult) {
        $count = $inProgressResult->fetch_assoc()['count'];
        if ($count > 0) $total += $count;
    }
    
    // Count today's requests
    $todayQuery = "SELECT COUNT(*) as count FROM help_requests WHERE DATE(created_at) = CURDATE()";
    $todayResult = $conn->query($todayQuery);
    if ($todayResult) {
        $count = $todayResult->fetch_assoc()['count'];
        if ($count > 0) $total += $count;
    }
    
    return $total;
}

try {
    // Get current notifications
    $rescueNotifications = getRescueRequestNotifications($conn);
    $totalRescueNotifications = getTotalRescueNotificationCount($conn);
    
    // Return JSON response
    echo json_encode([
        'success' => true,
        'notifications' => $rescueNotifications,
        'total_count' => $totalRescueNotifications
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>

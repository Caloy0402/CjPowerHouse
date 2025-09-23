<?php
session_start();
header('Content-Type: application/json');
require_once 'dbconn.php';

// Check if user is logged in and has admin privileges
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

try {
    // Ultra-simple query for instant loading
    $sql = "SELECT 
                hr.id as request_id,
                hr.name as requestor_name,
                hr.location,
                hr.status,
                hr.created_at,
                m.first_name as mechanic_first_name,
                m.last_name as mechanic_last_name
            FROM help_requests hr
            LEFT JOIN mechanics m ON hr.mechanic_id = m.id
            ORDER BY hr.created_at DESC 
            LIMIT 20";
    
    $result = $conn->query($sql);
    
    $rescue_requests = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            // Simple date format
            $request_date = date('M d, Y', strtotime($row['created_at']));
            
            // Simple mechanic name
            $mechanic_name = 'Not Assigned';
            if ($row['mechanic_first_name'] && $row['mechanic_last_name']) {
                $mechanic_name = $row['mechanic_first_name'] . ' ' . $row['mechanic_last_name'];
            }
            
            $rescue_requests[] = [
                'request_id' => $row['request_id'],
                'requestor_name' => $row['requestor_name'],
                'location' => $row['location'],
                'status' => $row['status'],
                'request_date' => $request_date,
                'mechanic_name' => $mechanic_name
            ];
        }
    }
    
    // Simple stats
    $stats = [
        'total_requests' => count($rescue_requests),
        'pending_count' => 0,
        'in_progress_count' => 0,
        'completed_count' => 0,
        'cancelled_count' => 0,
        'declined_count' => 0
    ];
    
    // Count statuses
    foreach ($rescue_requests as $request) {
        switch ($request['status']) {
            case 'Pending':
                $stats['pending_count']++;
                break;
            case 'In Progress':
                $stats['in_progress_count']++;
                break;
            case 'Completed':
                $stats['completed_count']++;
                break;
            case 'Cancelled':
                $stats['cancelled_count']++;
                break;
            case 'Declined':
                $stats['declined_count']++;
                break;
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => $rescue_requests,
        'stats' => $stats,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Error: ' . $e->getMessage()
    ]);
}

$conn->close();
?>

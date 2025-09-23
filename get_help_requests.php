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
    // Fetch today's help requests with user information
    $sql = "SELECT 
                hr.id,
                hr.name,
                hr.bike_unit,
                hr.problem_description,
                hr.location,
                hr.contact_info,
                hr.status,
                hr.created_at,
                hr.breakdown_barangay_id,
                bb.barangay_name,
                u.ImagePath as user_image,
                u.first_name,
                u.last_name
            FROM help_requests hr
            LEFT JOIN users u ON hr.user_id = u.id
            LEFT JOIN barangays bb ON hr.breakdown_barangay_id = bb.id
            WHERE hr.status IN ('Pending', 'In Progress')
            AND DATE(hr.created_at) = CURDATE()
            ORDER BY hr.created_at DESC
            LIMIT 20";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Database error: ' . $conn->error);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $help_requests = [];
    while ($row = $result->fetch_assoc()) {
        // Calculate time ago
        $created_time = new DateTime($row['created_at']);
        $now = new DateTime();
        $interval = $now->diff($created_time);
        
        $time_ago = '';
        if ($interval->y > 0) {
            $time_ago = $interval->y . ' year' . ($interval->y > 1 ? 's' : '') . ' ago';
        } elseif ($interval->m > 0) {
            $time_ago = $interval->m . ' month' . ($interval->m > 1 ? 's' : '') . ' ago';
        } elseif ($interval->d > 0) {
            $time_ago = $interval->d . ' day' . ($interval->d > 1 ? 's' : '') . ' ago';
        } elseif ($interval->h > 0) {
            $time_ago = $interval->h . ' hour' . ($interval->h > 1 ? 's' : '') . ' ago';
        } elseif ($interval->i > 0) {
            $time_ago = $interval->i . ' minute' . ($interval->i > 1 ? 's' : '') . ' ago';
        } else {
            $time_ago = 'Just now';
        }
        
        // Get user image or default
        $user_image = 'img/user.jpg'; // Default image
        if ($row['user_image'] && !empty($row['user_image'])) {
            $user_image = $row['user_image'];
        }
        
        // Get user name
        $user_name = $row['name'];
        if ($row['first_name'] && $row['last_name']) {
            $user_name = $row['first_name'] . ' ' . $row['last_name'];
        }
        
        $help_requests[] = [
            'id' => $row['id'],
            'name' => $user_name,
            'bike_unit' => $row['bike_unit'],
            'problem_description' => $row['problem_description'],
            'location' => $row['location'],
            'contact_info' => $row['contact_info'],
            'status' => $row['status'],
            'created_at' => $row['created_at'],
            'time_ago' => $time_ago,
            'barangay_name' => $row['barangay_name'] ?? 'Unknown Location',
            'user_image' => $user_image
        ];
    }
    
    $stmt->close();
    
    // Get summary statistics
    $stats_sql = "SELECT 
                    COUNT(*) as total_requests,
                    SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending_count,
                    SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) as in_progress_count,
                    SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed_count
                   FROM help_requests 
                   WHERE DATE(created_at) = CURDATE()";
    
    $stats_stmt = $conn->prepare($stats_sql);
    if ($stats_stmt) {
        $stats_stmt->execute();
        $stats_result = $stats_stmt->get_result();
        $stats = $stats_result->fetch_assoc();
        $stats_stmt->close();
    } else {
        $stats = [
            'total_requests' => 0,
            'pending_count' => 0,
            'in_progress_count' => 0,
            'completed_count' => 0
        ];
    }
    
    echo json_encode([
        'success' => true,
        'help_requests' => $help_requests,
        'stats' => $stats,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Error fetching help requests: ' . $e->getMessage()
    ]);
}

$conn->close();
?>

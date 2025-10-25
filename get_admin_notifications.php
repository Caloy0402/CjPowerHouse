<?php
// Prevent any output before JSON
error_reporting(0);
ini_set('display_errors', 0);

session_start();

try {
    require_once 'dbconn.php';
    
    // Test database connection
    if ($conn->connect_error) {
        throw new Exception("Database connection failed");
    }
    
    // Only admins
    if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    
    header('Content-Type: application/json');
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    exit;
}

function getAdminNotifications($conn) {
    $notifications = array();
    
    try {
        // Simple notifications with error handling
        $queries = [
            [
                'sql' => "SELECT COUNT(*) as count FROM orders WHERE order_status = 'pending'",
                'type' => 'orders',
                'title' => 'New Orders',
                'icon' => 'fa-shopping-cart',
                'color' => 'text-warning'
            ],
            [
                'sql' => "SELECT COUNT(*) as count From products WHERE Quantity <= 10",
                'type' => 'low_stock',
                'title' => 'Low Stock Alert',
                'icon' => 'fa-exclamation-triangle',
                'color' => 'text-danger'
            ],
            [
                'sql' => "SELECT COUNT(*) as count FROM orders WHERE payment_method = 'COD' AND order_status = 'Pending'",
                'type' => 'cod_orders',
                'title' => 'COD Orders',
                'icon' => 'fa-money-bill-wave',
                'color' => 'text-primary'
            ]
        ];
        
        foreach ($queries as $query) {
            $result = $conn->query($query['sql']);
            if ($result) {
                $count = $result->fetch_assoc()['count'];
                if ($count > 0) {
                    $notifications[] = [
                        'type' => $query['type'],
                        'title' => $query['title'],
                        'message' => "$count item" . ($count > 1 ? 's' : ''),
                        'count' => $count,
                        'icon' => $query['icon'],
                        'color' => $query['color']
                    ];
                }
            }
        }
        
    } catch (Exception $e) {
        // Return empty notifications on error
        return array();
    }
    
    return $notifications;
}

function getTotalNotificationCount($conn) {
    $total = 0;
    
    try {
        $queries = [
            "SELECT COUNT(*) as count FROM orders WHERE order_status = 'pending'",
            "SELECT COUNT(*) as count From products WHERE Quantity <= 10",
            "SELECT COUNT(*) as count FROM orders WHERE payment_method = 'COD' AND order_status = 'Pending'"
        ];
        
        foreach ($queries as $query) {
            $result = $conn->query($query);
            if ($result) {
                $count = $result->fetch_assoc()['count'];
                $total += $count;
            }
        }
    } catch (Exception $e) {
        // Return 0 on error
        return 0;
    }
    
    return $total;
}

// Get notifications and total count
$notifications = getAdminNotifications($conn);
$totalCount = getTotalNotificationCount($conn);

echo json_encode([
    'success' => true,
    'notifications' => $notifications,
    'total_count' => $totalCount,
    'timestamp' => date('Y-m-d H:i:s')
]);

$conn->close();
?>

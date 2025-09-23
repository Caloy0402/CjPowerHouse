<?php
header('Content-Type: application/json');
header('Cache-Control: no-cache');

session_start();
require_once 'dbconn.php';

// Only admins
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Function to get weekly orders data
function getWeeklyOrdersData($conn) {
    $weekData = [];
    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    
    // Get the start of the current week (Monday)
    $monday = date('Y-m-d', strtotime('monday this week'));
    
    foreach ($days as $index => $day) {
        $currentDate = date('Y-m-d', strtotime($monday . ' +' . $index . ' days'));
        
        // Get completed orders for this day
        $sql = "SELECT COUNT(*) as order_count, SUM(total_price + delivery_fee) as total_amount 
                FROM orders 
                WHERE DATE(order_date) = ? AND order_status = 'completed'";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('s', $currentDate);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $completedCount = (int)$row['order_count'];
            $completedAmount = (float)($row['total_amount'] ?? 0);
            $stmt->close();
        } else {
            $completedCount = 0;
            $completedAmount = 0;
        }
        
        // Get returned orders for this day (from orders table with status 'Returned')
        $sql = "SELECT COUNT(*) as returned_count, SUM(total_price + delivery_fee) as total_returned_amount 
                FROM orders 
                WHERE DATE(order_date) = ? AND order_status = 'Returned'";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('s', $currentDate);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $returnedCount = (int)$row['returned_count'];
            $returnedAmount = (float)($row['total_returned_amount'] ?? 0);
            $stmt->close();
        } else {
            $returnedCount = 0;
            $returnedAmount = 0;
        }
        
        $weekData[] = [
            'day' => $day,
            'date' => $currentDate,
            'completed_count' => $completedCount,
            'completed_amount' => $completedAmount,
            'returned_count' => $returnedCount,
            'returned_amount' => $returnedAmount
        ];
    }
    
    return $weekData;
}

try {
    $weeklyData = getWeeklyOrdersData($conn);
    
    echo json_encode([
        'success' => true,
        'data' => $weeklyData,
        'message' => 'Weekly orders data retrieved successfully'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving weekly orders data: ' . $e->getMessage()
    ]);
}

$conn->close();
?>

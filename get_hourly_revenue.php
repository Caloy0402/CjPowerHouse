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
    // Get hourly revenue for the last 24 hours
    $sql = "SELECT 
                HOUR(order_date) as hour,
                DATE(order_date) as date,
                SUM(total_price + COALESCE(delivery_fee, 0)) as hourly_revenue,
                COUNT(*) as order_count
            FROM orders 
            WHERE order_status = 'completed' 
            AND order_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY HOUR(order_date), DATE(order_date)
            ORDER BY date DESC, hour DESC";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Database error: ' . $conn->error);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    // First, get the actual data from database to see what hours we have
    $database_hours = [];
    while ($row = $result->fetch_assoc()) {
        // Create hour key exactly as it will be generated in the grid
        $hour_key = $row['date'] . ' ' . $row['hour']; // No padding, match the format from DateTime
        $database_hours[$hour_key] = [
            'hour' => (int)$row['hour'],
            'date' => $row['date'],
            'revenue' => (float)$row['hourly_revenue'],
            'order_count' => (int)$row['order_count']
        ];
    }
    
    $stmt->close();
    
    // Now create the hour structure based on actual data + standard 24-hour grid
    $hourly_data = [];
    $now = new DateTime();
    $yesterday = (new DateTime())->modify('-1 day');
    
    // Create standard 24-hour grid
    for ($i = 23; $i >= 0; $i--) {
        $time = new DateTime();
        $time->modify("-{$i} hours");
        
        $hour_key = $time->format('Y-m-d H'); // This gives "2025-08-31 13" (no padding)
        $hourly_data[$hour_key] = [
            'hour' => (int)$time->format('H'),
            'date' => $time->format('Y-m-d'),
            'revenue' => 0,
            'order_count' => 0,
            'time_label' => $time->format('g A'), // Only show hour, not minutes
            'is_today' => $time->format('Y-m-d') === $now->format('Y-m-d'),
            'is_yesterday' => $time->format('Y-m-d') === $yesterday->format('Y-m-d')
        ];
    }
    
    // Fill in the actual database data
    foreach ($database_hours as $hour_key => $data) {
        if (isset($hourly_data[$hour_key])) {
            $hourly_data[$hour_key]['revenue'] = $data['revenue'];
            $hourly_data[$hour_key]['order_count'] = $data['order_count'];
        }
    }
    
    $stmt->close();
    
    // Format data for chart
    $labels = [];
    $data = [];
    $order_counts = [];
    
    foreach ($hourly_data as $hour_data) {
        // Format time label
        if ($hour_data['is_today']) {
            $labels[] = $hour_data['time_label'];
        } elseif ($hour_data['is_yesterday']) {
            $labels[] = $hour_data['time_label'] . ' (Yesterday)';
        } else {
            $date = new DateTime($hour_data['date']);
            $labels[] = $hour_data['time_label'] . ' (' . $date->format('M d') . ')';
        }
        
        $data[] = $hour_data['revenue'];
        $order_counts[] = $hour_data['order_count'];
    }
    
    // Get current hour revenue for summary
    $current_hour = (int)date('H');
    $current_hour_revenue = 0;
    
    $current_hour_key = date('Y-m-d') . ' ' . $current_hour; // Match the format used above
    
    if (isset($hourly_data[$current_hour_key])) {
        $current_hour_revenue = $hourly_data[$current_hour_key]['revenue'];
    }
    
    // Get today's total revenue
    $today_sql = "SELECT SUM(total_price + COALESCE(delivery_fee, 0)) as today_total 
                   FROM orders 
                   WHERE order_status = 'completed' 
                   AND DATE(order_date) = CURDATE()";
    
    $today_stmt = $conn->prepare($today_sql);
    $today_stmt->execute();
    $today_result = $today_stmt->get_result();
    $today_data = $today_result->fetch_assoc();
    $today_total = (float)($today_data['today_total'] ?? 0);
    $today_stmt->close();
    
    echo json_encode([
        'success' => true,
        'labels' => $labels,
        'data' => $data,
        'order_counts' => $order_counts,
        'current_hour_revenue' => $current_hour_revenue,
        'today_total' => $today_total,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Error fetching hourly revenue: ' . $e->getMessage()
    ]);
}

$conn->close();
?>

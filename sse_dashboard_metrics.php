<?php
// Set headers for Server-Sent Events
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Cache-Control');

// Include database connection
require_once 'dbconn.php';

// Function to send SSE data
function sendSSEData($data) {
    echo "data: " . json_encode($data) . "\n\n";
    ob_flush();
    flush();
}

// Function to get dashboard metrics
function getDashboardMetrics($conn, $user_id = null) {
    $today = date("Y-m-d");
    
    // 1. Total Sales Today
    $sql = "SELECT SUM(
                CASE 
                    WHEN NULLIF(o.total_amount_with_delivery, 0) IS NOT NULL THEN o.total_amount_with_delivery
                    ELSE o.total_price
                END
            ) AS total_sales
            FROM orders o
            JOIN transactions t ON o.id = t.order_id
            WHERE o.order_status = 'completed' AND DATE(t.completed_date_transaction) = '$today'";
    
    // Note: cashier_id column doesn't exist in the database schema
    // User filtering would need to be implemented differently if needed
    
    $result = $conn->query($sql);
    $row = $result->fetch_assoc();
    $totalSalesToday = ($row && $row['total_sales'] !== null) ? number_format($row['total_sales'], 2) : '0.00';

    // 2. Total Orders Today
    $sql = "SELECT COUNT(*) AS total_orders
            FROM orders o
            JOIN transactions t ON o.id = t.order_id
            WHERE o.order_status = 'completed' AND DATE(t.completed_date_transaction) = '$today'";
    
    // Note: cashier_id column doesn't exist in the database schema
    // User filtering would need to be implemented differently if needed
    
    $result = $conn->query($sql);
    $row = $result->fetch_assoc();
    $totalOrdersToday = ($row && $row['total_orders'] !== null) ? $row['total_orders'] : 0;

    // 3. Average Order Value
    if ($totalOrdersToday > 0) {
        $averageOrderValue = number_format((float)str_replace(',', '', $totalSalesToday) / $totalOrdersToday, 2);
    } else {
        $averageOrderValue = '0.00';
    }

    // 4. Total Weight Sold Today
    $sql = "SELECT SUM(total_weight) AS total_weight
            FROM orders
            WHERE order_status = 'completed' AND DATE(order_date) = '$today'";
    
    // Note: cashier_id column doesn't exist in the database schema
    // User filtering would need to be implemented differently if needed
    
    $result = $conn->query($sql);
    $row = $result->fetch_assoc();
    $totalWeightSold = ($row && $row['total_weight'] !== null) ? number_format($row['total_weight'], 2) : '0.00';

    // 5. Pending Orders Count (All Time)
    $sql = "SELECT COUNT(*) AS pending_orders
            FROM orders
            WHERE order_status = 'Pending'";
    
    $result = $conn->query($sql);
    $row = $result->fetch_assoc();
    $pendingOrdersCount = ($row && $row['pending_orders'] !== null) ? $row['pending_orders'] : 0;

    return [
        'total_sales_today' => $totalSalesToday,
        'total_orders_today' => $totalOrdersToday,
        'pending_orders_count' => $pendingOrdersCount,
        'total_weight_sold' => $totalWeightSold,
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

// Get user ID from session or parameter
session_start();
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;

// Create database connection
$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    sendSSEData(['error' => 'Database connection failed']);
    exit;
}

// Send initial data
$metrics = getDashboardMetrics($conn, $user_id);
sendSSEData(['type' => 'initial', 'metrics' => $metrics]);

// Keep connection alive and check for updates
$lastCheck = time();
$lastMetrics = $metrics;

while (true) {
    // Check for new data every 5 seconds
    if (time() - $lastCheck >= 5) {
        $currentMetrics = getDashboardMetrics($conn, $user_id);
        
        // Check if metrics have changed
        if ($currentMetrics['total_sales_today'] != $lastMetrics['total_sales_today'] ||
            $currentMetrics['total_orders_today'] != $lastMetrics['total_orders_today'] ||
            $currentMetrics['average_order_value'] != $lastMetrics['average_order_value'] ||
            $currentMetrics['total_weight_sold'] != $lastMetrics['total_weight_sold']) {
            
            sendSSEData(['type' => 'update', 'metrics' => $currentMetrics]);
            $lastMetrics = $currentMetrics;
        }
        
        $lastCheck = time();
    }
    
    // Send heartbeat every 30 seconds
    if (time() % 30 == 0) {
        sendSSEData(['type' => 'heartbeat', 'timestamp' => date('Y-m-d H:i:s')]);
    }
    
    // Check if client disconnected
    if (connection_aborted()) {
        break;
    }
    
    sleep(1);
}

$conn->close();
?>
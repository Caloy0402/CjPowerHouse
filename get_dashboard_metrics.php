<?php
header('Content-Type: application/json');
header('Cache-Control: no-cache');

require_once 'dbconn.php';

// Function to get dashboard metrics
function getDashboardMetrics($conn) {
    $today = date("Y-m-d");
    $yesterday = date("Y-m-d", strtotime("-1 day"));
    
    // Today's Transactions - use transactions.completed_date_transaction
    $today_orders_query = "SELECT COUNT(*) as order_count, SUM(o.total_price + o.delivery_fee) as total_amount 
                           FROM orders o
                           JOIN transactions t ON t.order_id = o.id
                           WHERE DATE(t.completed_date_transaction) = CURDATE() AND o.order_status = 'completed'";
    $stmt = $conn->prepare($today_orders_query);
    $stmt->execute();
    $today_result = $stmt->get_result();
    $today_data = $today_result->fetch_assoc();
    $today_orders_count = $today_data['order_count'] ?? 0;
    $today_orders_amount = $today_data['total_amount'] ?? 0;
    
    // Yesterday's Transactions for comparison - use transactions.completed_date_transaction
    $yesterday_orders_query = "SELECT COUNT(*) as order_count, SUM(o.total_price + o.delivery_fee) as total_amount 
                               FROM orders o
                               JOIN transactions t ON t.order_id = o.id
                               WHERE DATE(t.completed_date_transaction) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND o.order_status = 'completed'";
    $stmt2 = $conn->prepare($yesterday_orders_query);
    $stmt2->execute();
    $yesterday_result = $stmt2->get_result();
    $yesterday_data = $yesterday_result->fetch_assoc();
    $yesterday_orders_count = $yesterday_data['order_count'] ?? 0;
    $yesterday_orders_amount = $yesterday_data['total_amount'] ?? 0;
    
    // Calculate growth percentages
    $transaction_growth = 0;
    if ($yesterday_orders_count > 0) {
        $transaction_growth = round((($today_orders_count - $yesterday_orders_count) / $yesterday_orders_count) * 100, 2);
    }
    
    $sales_growth = 0;
    if ($yesterday_orders_amount > 0) {
        $sales_growth = round((($today_orders_amount - $yesterday_orders_amount) / $yesterday_orders_amount) * 100, 2);
    }
    
    // Total Shoppers
    $total_shoppers_query = "SELECT COUNT(*) as total_shoppers FROM users";
    $total_shoppers_result = $conn->query($total_shoppers_query);
    $total_shoppers_data = $total_shoppers_result->fetch_assoc();
    $total_shoppers = $total_shoppers_data['total_shoppers'] ?? 0;
    
    // Low Stock Items
    $low_stock_query = "SELECT COUNT(*) as low_count From Products WHERE Quantity BETWEEN 10 AND 20";
    $low_stock_result = $conn->query($low_stock_query);
    $low_stock_data = $low_stock_result->fetch_assoc();
    $low_count = $low_stock_data['low_count'] ?? 0;
    
    // Calculate low stock trend (simplified)
    $low_stock_trend = 0; // For fallback, we'll keep it simple
    
    // All Products Value (total inventory worth)
    $total_products_value_query = "SELECT SUM(Price * Quantity) as total_value From Products";
    $total_products_value_result = $conn->query($total_products_value_query);
    $total_products_value_data = $total_products_value_result->fetch_assoc();
    $total_products_value = $total_products_value_data['total_value'] ?? 0;
    
    // Total Earned (all time earnings from completed orders)
    $total_earned_query = "SELECT SUM(total_price + delivery_fee) as total_earned FROM orders WHERE order_status = 'completed'";
    $total_earned_result = $conn->query($total_earned_query);
    $total_earned_data = $total_earned_result->fetch_assoc();
    $total_earned = $total_earned_data['total_earned'] ?? 0;
    
    $stmt->close();
    $stmt2->close();
    
    return [
        'today_transactions' => [
            'count' => $today_orders_count,
            'growth' => $transaction_growth
        ],
        'today_sales' => [
            'amount' => $today_orders_amount,
            'growth' => $sales_growth
        ],
        'total_shoppers' => $total_shoppers,
        'low_stock' => [
            'count' => $low_count,
            'trend' => $low_stock_trend
        ],
        'all_products_value' => $total_products_value,
        'total_earned' => $total_earned
    ];
}

// Get and return metrics
$metrics = getDashboardMetrics($conn);
echo json_encode($metrics);

$conn->close();
?>

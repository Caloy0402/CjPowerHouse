<?php
// Server-Sent Events endpoint for real-time transaction updates
session_start();
require_once 'dbconn.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Unauthorized');
}

// Set headers for SSE
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Cache-Control');

// Prevent output buffering
if (ob_get_level()) {
    ob_end_clean();
}

// Function to send SSE data
function sendSSE($data, $event = 'message') {
    echo "event: $event\n";
    echo "data: " . json_encode($data) . "\n\n";
    flush();
}

// Function to get latest transactions
function getLatestTransactions($conn, $from_date, $to_date, $limit = 10) {
    $sql = "SELECT t.id, t.transaction_number, u.first_name AS user_name, t.order_id, t.created_at
            FROM transactions t
            JOIN users u ON t.user_id = u.id
            WHERE DATE(t.created_at) BETWEEN ? AND ?
            ORDER BY t.created_at DESC
            LIMIT ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssi", $from_date, $to_date, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $transactions = [];
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
    }
    
    return $transactions;
}

// Function to get transaction count
function getTransactionCount($conn, $from_date, $to_date) {
    $count_sql = "SELECT COUNT(*) as total_records
                  FROM transactions t
                  JOIN users u ON t.user_id = u.id
                  WHERE DATE(t.created_at) BETWEEN ? AND ?";
    
    $stmt = $conn->prepare($count_sql);
    $stmt->bind_param("ss", $from_date, $to_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    return $row['total_records'];
}

// Get parameters
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-d');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$records_per_page = isset($_GET['records_per_page']) ? (int)$_GET['records_per_page'] : 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Store the last transaction ID to detect new transactions
$last_transaction_id = 0;

// Send initial data
$initial_transactions = getLatestTransactions($conn, $from_date, $to_date, $records_per_page);
$total_records = getTransactionCount($conn, $from_date, $to_date);

if (!empty($initial_transactions)) {
    $last_transaction_id = $initial_transactions[0]['id'];
}

sendSSE([
    'type' => 'initial',
    'transactions' => $initial_transactions,
    'total_records' => $total_records,
    'page' => $page,
    'total_pages' => ceil($total_records / $records_per_page),
    'from_date' => $from_date,
    'to_date' => $to_date
], 'initial');

// Keep connection alive and check for new transactions
$check_interval = 2; // Check every 2 seconds
$max_execution_time = 300; // 5 minutes max
$start_time = time();

while ((time() - $start_time) < $max_execution_time) {
    // Check for new transactions
    $sql = "SELECT t.id, t.transaction_number, u.first_name AS user_name, t.order_id, t.created_at
            FROM transactions t
            JOIN users u ON t.user_id = u.id
            WHERE DATE(t.created_at) BETWEEN ? AND ? AND t.id > ?
            ORDER BY t.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssi", $from_date, $to_date, $last_transaction_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $new_transactions = [];
    while ($row = $result->fetch_assoc()) {
        $new_transactions[] = $row;
    }
    
    // If there are new transactions, send update
    if (!empty($new_transactions)) {
        $last_transaction_id = $new_transactions[0]['id'];
        
        // Get updated transaction list
        $updated_transactions = getLatestTransactions($conn, $from_date, $to_date, $records_per_page);
        $updated_total_records = getTransactionCount($conn, $from_date, $to_date);
        
        sendSSE([
            'type' => 'update',
            'transactions' => $updated_transactions,
            'total_records' => $updated_total_records,
            'page' => $page,
            'total_pages' => ceil($updated_total_records / $records_per_page),
            'from_date' => $from_date,
            'to_date' => $to_date,
            'new_transactions' => $new_transactions
        ], 'update');
    }
    
    // Send heartbeat to keep connection alive
    sendSSE(['type' => 'heartbeat', 'timestamp' => time()], 'heartbeat');
    
    // Sleep for the check interval
    sleep($check_interval);
}

// Close connection
sendSSE(['type' => 'close'], 'close');
$conn->close();
?>

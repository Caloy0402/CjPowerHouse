<?php
/**
 * Dashboard Connection Test
 * Upload this file to test database connectivity and query performance
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html><html><head><title>Dashboard Connection Test</title>";
echo "<style>body{font-family:Arial;padding:20px;background:#1a1a2e;color:#fff;}";
echo ".success{color:#00ff00;}.error{color:#ff4444;}.warning{color:#ffaa00;}";
echo "h2{border-bottom:2px solid #0f3460;padding-bottom:10px;margin-top:30px;}";
echo "pre{background:#0f3460;padding:15px;border-radius:8px;overflow-x:auto;}";
echo "</style></head><body>";

echo "<h1>🔍 Admin Dashboard Connection Test</h1>";
echo "<p>Testing database connectivity and query performance...</p>";

// Test 1: Database Connection
echo "<h2>Test 1: Database Connection</h2>";
$start = microtime(true);
try {
    require_once 'dbconn.php';
    $duration = round((microtime(true) - $start) * 1000, 2);
    echo "<p class='success'>✓ Database connected successfully in {$duration}ms</p>";
    echo "<pre>Server: " . $conn->host_info . "</pre>";
} catch (Exception $e) {
    $duration = round((microtime(true) - $start) * 1000, 2);
    echo "<p class='error'>✗ Database connection failed after {$duration}ms</p>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
    exit;
}

// Test 2: Simple Query
echo "<h2>Test 2: Simple Query</h2>";
$start = microtime(true);
try {
    $result = $conn->query("SELECT COUNT(*) as cnt FROM users");
    $duration = round((microtime(true) - $start) * 1000, 2);
    $row = $result->fetch_assoc();
    echo "<p class='success'>✓ Simple query executed in {$duration}ms</p>";
    echo "<pre>Total users: " . $row['cnt'] . "</pre>";
} catch (Exception $e) {
    $duration = round((microtime(true) - $start) * 1000, 2);
    echo "<p class='error'>✗ Query failed after {$duration}ms</p>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}

// Test 3: Orders Query with JOIN
echo "<h2>Test 3: Orders Query with JOIN</h2>";
$start = microtime(true);
try {
    $sql = "SELECT COUNT(*) as cnt FROM orders o 
            JOIN transactions t ON t.order_id = o.id 
            WHERE o.order_status = 'completed'";
    $result = $conn->query($sql);
    $duration = round((microtime(true) - $start) * 1000, 2);
    $row = $result->fetch_assoc();
    
    if ($duration > 2000) {
        echo "<p class='warning'>⚠ Query slow ({$duration}ms) - this could cause page loading issues</p>";
    } else {
        echo "<p class='success'>✓ Join query executed in {$duration}ms</p>";
    }
    echo "<pre>Completed orders: " . $row['cnt'] . "</pre>";
} catch (Exception $e) {
    $duration = round((microtime(true) - $start) * 1000, 2);
    echo "<p class='error'>✗ Join query failed after {$duration}ms</p>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}

// Test 4: Weekly Data Query (Most Heavy)
echo "<h2>Test 4: Weekly Data Query (Heavy)</h2>";
$start = microtime(true);
try {
    $monday = date('Y-m-d', strtotime('monday this week'));
    $currentDate = $monday;
    
    $sql = "SELECT COUNT(*) as order_count, SUM(o.total_price + o.delivery_fee) as total_amount 
            FROM orders o
            JOIN transactions t ON t.order_id = o.id
            WHERE DATE(t.completed_date_transaction) = ? AND o.order_status = 'completed'";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $currentDate);
    $stmt->execute();
    $result = $stmt->get_result();
    $duration = round((microtime(true) - $start) * 1000, 2);
    
    $row = $result->fetch_assoc();
    
    if ($duration > 3000) {
        echo "<p class='error'>✗ CRITICAL: Query too slow ({$duration}ms)</p>";
        echo "<p class='warning'>⚠ This query runs 7 times per page load. Total: " . ($duration * 7) . "ms</p>";
        echo "<p class='warning'>🔧 Recommendation: Add database indexes on orders.order_status and transactions.completed_date_transaction</p>";
    } elseif ($duration > 1000) {
        echo "<p class='warning'>⚠ Query slow ({$duration}ms) - page may load slowly</p>";
        echo "<p>Note: This query runs 7 times. Total estimated: " . ($duration * 7) . "ms</p>";
    } else {
        echo "<p class='success'>✓ Weekly query executed in {$duration}ms</p>";
        echo "<p>Estimated total for 7 days: " . ($duration * 7) . "ms</p>";
    }
    
    echo "<pre>Orders for $currentDate: " . $row['order_count'] . "</pre>";
    $stmt->close();
} catch (Exception $e) {
    $duration = round((microtime(true) - $start) * 1000, 2);
    echo "<p class='error'>✗ Weekly query failed after {$duration}ms</p>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}

// Test 5: Check Indexes
echo "<h2>Test 5: Database Index Check</h2>";
try {
    $result = $conn->query("SHOW INDEX FROM orders WHERE Key_name != 'PRIMARY'");
    $indexes = [];
    while ($row = $result->fetch_assoc()) {
        $indexes[] = $row['Column_name'];
    }
    
    $recommended_indexes = ['order_status', 'order_date', 'user_id'];
    $missing = array_diff($recommended_indexes, $indexes);
    
    if (empty($missing)) {
        echo "<p class='success'>✓ All recommended indexes are present</p>";
    } else {
        echo "<p class='warning'>⚠ Missing recommended indexes: " . implode(', ', $missing) . "</p>";
        echo "<p>To improve performance, add these indexes:</p>";
        echo "<pre>";
        foreach ($missing as $col) {
            echo "ALTER TABLE orders ADD INDEX idx_$col ($col);\n";
        }
        echo "</pre>";
    }
    
    echo "<p>Current indexes on orders table:</p><pre>";
    if (!empty($indexes)) {
        echo implode(', ', array_unique($indexes));
    } else {
        echo "Only PRIMARY key (no additional indexes)";
    }
    echo "</pre>";
} catch (Exception $e) {
    echo "<p class='warning'>⚠ Could not check indexes: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// Test 6: Memory and Execution Limits
echo "<h2>Test 6: PHP Configuration</h2>";
echo "<pre>";
echo "Max Execution Time: " . ini_get('max_execution_time') . " seconds\n";
echo "Memory Limit: " . ini_get('memory_limit') . "\n";
echo "MySQL Wait Timeout: ";
try {
    $result = $conn->query("SHOW VARIABLES LIKE 'wait_timeout'");
    $row = $result->fetch_assoc();
    echo $row['Value'] . " seconds\n";
} catch (Exception $e) {
    echo "Unknown\n";
}
echo "</pre>";

echo "<h2>Summary</h2>";
echo "<p>If all tests passed, your Admin Dashboard should load correctly.</p>";
echo "<p>If any tests showed errors or warnings, those need to be addressed before the dashboard will work properly.</p>";

$conn->close();
echo "</body></html>";
?>


<?php
// Prevent any output before SSE
error_reporting(0);
ini_set('display_errors', 0);

// Set proper headers for SSE
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header("Access-Control-Allow-Origin: *");
header("X-Accel-Buffering: no"); // Disable nginx buffering

// Simple error handling
try {
    require 'dbconn.php';
    
    // Test database connection
    if ($conn->connect_error) {
        throw new Exception("Database connection failed");
    }
} catch (Exception $e) {
    // Send error event and exit
    echo "data: " . json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]) . "\n\n";
    ob_flush();
    flush();
    exit;
}

function sendEvent($data) {
    echo "data: " . json_encode($data) . "\n\n";
    ob_flush();
    flush();
}

// Get rider name from query parameter
$rider_name = isset($_GET['rider_name']) ? $_GET['rider_name'] : '';
$selected_barangay = isset($_GET['barangay']) ? (int)$_GET['barangay'] : null;

if (!$rider_name) {
    sendEvent(['error' => 'Rider name required']);
    exit;
}

// Function to get ready to ship order count for rider
function getRiderReadyToShipOrderCount($conn, $riderFullName, $barangayId = null) {
    $sql = "SELECT COUNT(*) FROM orders o JOIN users u ON o.user_id = u.id WHERE o.rider_name = ? AND o.order_status = 'Ready to Ship'";
    if ($barangayId) {
        $sql .= " AND u.barangay_id = ?";
    }

    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        error_log("Prepare failed: " . $conn->error);
        return "Error";
    }

    if ($barangayId) {
        $stmt->bind_param("si", $riderFullName, $barangayId);
    } else {
        $stmt->bind_param("s", $riderFullName);
    }

    $stmt->execute();
    $count = 0;
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return $count;
}

// Function to get barangay order counts
function getRiderBarangayReadyToShipOrderCount($conn, $riderFullName, $barangayId) {
    $sql = "SELECT COUNT(*) FROM orders o JOIN users u ON o.user_id = u.id WHERE o.rider_name = ? AND o.order_status = 'Ready to Ship' AND u.barangay_id = ?";
    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        error_log("Prepare failed: " . $conn->error);
        return "Error";
    }

    $stmt->bind_param("si", $riderFullName, $barangayId);
    $stmt->execute();
    $count = 0;
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return $count;
}

// Function to get all barangay order count
function getRiderAllBarangayReadyToShipOrderCount($conn, $riderFullName) {
    $sql = "SELECT COUNT(*) FROM orders WHERE rider_name = ? AND order_status = 'Ready to Ship'";
    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        error_log("Prepare failed: " . $conn->error);
        return "Error";
    }

    $stmt->bind_param("s", $riderFullName);
    $stmt->execute();
    $count = 0;
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return $count;
}

// Function to get current deliveries for rider
function getCurrentDeliveries($conn, $riderFullName, $barangayId = null) {
    // Simple check if delivery fee columns exist
    $ordersHasDeliveryCols = false;
    try {
        $testQuery = "SELECT delivery_fee, total_amount_with_delivery FROM orders LIMIT 1";
        $conn->query($testQuery);
        $ordersHasDeliveryCols = true;
    } catch (Exception $e) {
        $ordersHasDeliveryCols = false;
    }

    $selectDeliveryCols = $ordersHasDeliveryCols
        ? ", o.delivery_fee, o.total_amount_with_delivery"
        : ", 0 AS delivery_fee, o.total_price AS total_amount_with_delivery";

    $selectFareFallback = ", 
        COALESCE(NULLIF(o.delivery_fee, 0), bf.fare_amount, 0) AS delivery_fee_effective,
        CASE WHEN (o.total_amount_with_delivery IS NULL OR o.total_amount_with_delivery = 0)
             THEN (o.total_price + COALESCE(NULLIF(o.delivery_fee, 0), bf.fare_amount, 0))
             ELSE o.total_amount_with_delivery END AS total_with_delivery_effective";

    $sql_deliveries = "SELECT o.*, t.transaction_number, u.first_name AS customer_first_name, u.last_name AS customer_last_name, u.phone_number, u.purok, b.barangay_name,
                              bf.fare_amount AS barangay_fare, o.payment_method
                              $selectDeliveryCols $selectFareFallback
                        FROM orders o
                        LEFT JOIN transactions t ON o.id = t.order_id
                        JOIN users u ON o.user_id = u.id
                        JOIN barangays b ON u.barangay_id = b.id
                        LEFT JOIN barangay_fares bf ON b.id = bf.barangay_id
                        WHERE o.rider_name = ? AND o.order_status = 'Ready to Ship'";

    if ($barangayId) {
        $sql_deliveries .= " AND u.barangay_id = ?";
    }

    $sql_deliveries .= " ORDER BY o.order_date DESC";

    $stmt_deliveries = $conn->prepare($sql_deliveries);
    if ($stmt_deliveries === false) {
        return [];
    }

    if ($barangayId) {
        $stmt_deliveries->bind_param("si", $riderFullName, $barangayId);
    } else {
        $stmt_deliveries->bind_param("s", $riderFullName);
    }

    $stmt_deliveries->execute();
    $result_deliveries = $stmt_deliveries->get_result();
    $current_deliveries = [];

    while ($row = $result_deliveries->fetch_assoc()) {
        $current_deliveries[] = $row;
    }

    $stmt_deliveries->close();
    return $current_deliveries;
}

// Function to get recent order history
function getRecentOrderHistory($conn, $riderFullName) {
    $sql_order_history = "SELECT o.id, o.order_date, o.order_status, u.first_name AS customer_first_name, u.last_name AS customer_last_name
                          FROM orders o
                          JOIN users u ON o.user_id = u.id
                          WHERE o.rider_name = ? AND o.order_status IN ('Ready to Ship', 'On-Ship', 'Completed', 'Returned')
                          ORDER BY o.order_date DESC
                          LIMIT 10";

    $stmt_order_history = $conn->prepare($sql_order_history);
    if ($stmt_order_history === false) {
        return [];
    }

    $stmt_order_history->bind_param("s", $riderFullName);
    $stmt_order_history->execute();
    $result_order_history = $stmt_order_history->get_result();
    $order_history = [];

    while ($row = $result_order_history->fetch_assoc()) {
        $order_history[] = $row;
    }

    $stmt_order_history->close();
    return $order_history;
}

// Send initial connection success message with current data
$initial_ready_to_ship_count = getRiderReadyToShipOrderCount($conn, $rider_name, $selected_barangay);
$initial_deliveries = getCurrentDeliveries($conn, $rider_name, $selected_barangay);

sendEvent([
    "type" => "connection_established",
    "rider_name" => $rider_name,
    "selected_barangay" => $selected_barangay,
    "ready_to_ship_count" => $initial_ready_to_ship_count,
    "deliveries" => $initial_deliveries,
    "deliveries_count" => count($initial_deliveries),
    "timestamp" => date('Y-m-d H:i:s')
]);

// Main loop
$counter = 0;
$last_ready_to_ship_count = 0;
$last_deliveries_hash = '';

while ($counter < 1000) { // Limit to prevent infinite loops
    try {
        // Check if client is still connected
        if (connection_aborted()) {
            break;
        }
        
        // Get current ready to ship count
        $current_ready_to_ship_count = getRiderReadyToShipOrderCount($conn, $rider_name, $selected_barangay);
        
        // Get current deliveries
        $current_deliveries = getCurrentDeliveries($conn, $rider_name, $selected_barangay);
        $current_deliveries_hash = md5(json_encode($current_deliveries));
        
        // Get recent order history
        $recent_order_history = getRecentOrderHistory($conn, $rider_name);
        
        // Check if ready to ship count changed
        if ($current_ready_to_ship_count != $last_ready_to_ship_count) {
            sendEvent([
                "type" => "ready_to_ship_update",
                "count" => $current_ready_to_ship_count,
                "timestamp" => date('Y-m-d H:i:s')
            ]);
            $last_ready_to_ship_count = $current_ready_to_ship_count;
        }
        
        // Check if deliveries changed
        if ($current_deliveries_hash != $last_deliveries_hash) {
            error_log("Deliveries changed for rider $rider_name. Count: " . count($current_deliveries));
            sendEvent([
                "type" => "deliveries_update",
                "deliveries" => $current_deliveries,
                "count" => count($current_deliveries),
                "timestamp" => date('Y-m-d H:i:s')
            ]);
            $last_deliveries_hash = $current_deliveries_hash;
        }
        
        // Send heartbeat every 6 iterations (30 seconds with 5-second sleep)
        if ($counter % 6 == 0) {
            sendEvent([
                "type" => "heartbeat",
                "ready_to_ship_count" => $current_ready_to_ship_count,
                "deliveries_count" => count($current_deliveries),
                "timestamp" => date('Y-m-d H:i:s')
            ]);
        }
        
        sleep(5); // Check every 5 seconds
        $counter++;
        
    } catch (Exception $e) {
        // Send error and break
        sendEvent([
            "type" => "error",
            "error" => "Connection error: " . $e->getMessage(),
            "timestamp" => date('Y-m-d H:i:s')
        ]);
        break;
    }
}

$conn->close();
?>

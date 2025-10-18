<?php
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header("Access-Control-Allow-Origin: *"); // ONLY if needed for cross-origin requests (be careful!)
require 'dbconn.php';

$last_order_count = 0;

function sendEvent($data) {
    echo "data: " . json_encode($data) . "\n\n";
    ob_flush();
    flush();
}

while (true) {
    $sql = "SELECT COUNT(*) FROM orders WHERE payment_method = 'GCASH' AND (order_status = 'Pending Payment' OR order_status = 'Processing')";
    $result = $conn->query($sql);

    if ($result === false) {
        error_log("Error fetching order count: " . $conn->error);
        sendEvent(["error" => "Database error"]);
        break;
    }

    $row = $result->fetch_row();
    $current_order_count = (int)$row[0];

    if ($current_order_count != $last_order_count) {
        sendEvent(["new_orders" => true, "order_count" => $current_order_count]);
        $last_order_count = $current_order_count;
    }

    $result->close();
    sleep(5); // Adjust the interval as needed
}

$conn->close();
?>
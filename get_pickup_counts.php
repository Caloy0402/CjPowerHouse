<?php
require 'dbconn.php';

// Start session
session_start();

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

function getPickupOrderCount($conn, $status, $date = null) {
    // Base query and params
    $params = [$status];
    $types = "s";

    // Count pickup orders with specific status (exclude cancelled and completed orders from active counts)
    if ($date !== null && strtolower($status) === 'completed') {
        // For completed count, we still want to show today's completed orders in dashboard stats
        $sql = "SELECT COUNT(*)
                FROM orders o
                JOIN transactions t ON o.id = t.order_id
                WHERE o.delivery_method = 'pickup' AND o.order_status = ?
                      AND DATE(t.completed_date_transaction) = ?";
        $params[] = $date;
        $types .= 's';
    } else {
        // For active pickup orders, exclude both cancelled and completed
        $sql = "SELECT COUNT(*) FROM orders o WHERE o.delivery_method = 'pickup' AND o.order_status = ? AND o.order_status != 'Cancelled' AND o.order_status != 'Completed'";
        if ($date !== null) {
            $sql .= " AND DATE(o.order_date) = ?";
            $params[] = $date;
            $types .= 's';
        }
    }

    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        error_log("Prepare failed: " . $conn->error);
        return 0;
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return $count;
}

try {
    // Get today's date
    $today = date("Y-m-d");
    
    // Get all pickup order counts
    $pending_count = getPickupOrderCount($conn, 'Pending');
    $ready_count = getPickupOrderCount($conn, 'Ready to Ship');
    $today_pending = getPickupOrderCount($conn, 'Pending', $today);
    $today_ready = getPickupOrderCount($conn, 'Ready to Ship', $today);
    $today_completed = getPickupOrderCount($conn, 'Completed', $today);
    
    echo json_encode([
        'success' => true,
        'pending_count' => $pending_count,
        'ready_count' => $ready_count,
        'today_pending' => $today_pending,
        'today_ready' => $today_ready,
        'today_completed' => $today_completed
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

$conn->close();
?>

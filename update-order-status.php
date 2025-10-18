<?php
require 'dbconn.php';

// Start the session to access session variables
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

// Get data from POST request
$orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
$newStatus = isset($_POST['new_status']) ? trim($_POST['new_status']) : '';
$returnReason = isset($_POST['return_reason']) ? trim($_POST['return_reason']) : '';
$returnNotes = isset($_POST['return_notes']) ? trim($_POST['return_notes']) : '';

if ($orderId <= 0 || empty($newStatus)) {
    echo json_encode(['success' => false, 'message' => 'Invalid order ID or status']);
    exit;
}

try {
    // Start transaction
    $conn->begin_transaction();
    
    // Update order status
    $sql = "UPDATE orders SET order_status = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $newStatus, $orderId);
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to update order status');
    }
    
    // If it's a return, also update the orders table with return reason
    if ($newStatus === 'Returned') {
        $updateOrderSql = "UPDATE orders SET reason = ? WHERE id = ?";
        $stmt2 = $conn->prepare($updateOrderSql);
        $stmt2->bind_param("si", $returnReason, $orderId);

        if (!$stmt2->execute()) {
            throw new Exception('Failed to update order return reason');
        }
        $stmt2->close();
    }
    
    // If it's completed, update the transactions table
    if ($newStatus === 'Completed') {
        $completeTransactionSql = "UPDATE transactions SET completed_date_transaction = NOW() WHERE order_id = ?";
        $stmt3 = $conn->prepare($completeTransactionSql);
        $stmt3->bind_param("i", $orderId);
        
        if (!$stmt3->execute()) {
            throw new Exception('Failed to update transaction completion date');
        }
        $stmt3->close();
    }
    
    // Commit transaction
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Order status updated successfully'
    ]);
    
    $stmt->close();
    
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    
    echo json_encode([
        'success' => false,
        'message' => 'Error updating order: ' . $e->getMessage()
    ]);
}

$conn->close();
?>
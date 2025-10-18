<?php
require 'dbconn.php';

header('Content-Type: application/json');

// Start session to get cashier info
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
$orderStatus = isset($_POST['order_status']) ? trim($_POST['order_status']) : '';
$completionNotes = isset($_POST['completion_notes']) ? trim($_POST['completion_notes']) : '';

if ($orderId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order ID']);
    exit;
}

if (empty($orderStatus)) {
    echo json_encode(['success' => false, 'message' => 'Order status is required']);
    exit;
}

// Validate order exists and is a pickup order
$checkSql = "SELECT id, order_status FROM orders WHERE id = ? AND delivery_method = 'pickup'";
$checkStmt = $conn->prepare($checkSql);
$checkStmt->bind_param("i", $orderId);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();

if ($checkResult->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Pickup order not found']);
    exit;
}

$currentOrder = $checkResult->fetch_assoc();
$checkStmt->close();

// Begin transaction
$conn->begin_transaction();

try {
    // Update order status
    $updateSql = "UPDATE orders SET order_status = ? WHERE id = ?";
    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->bind_param("si", $orderStatus, $orderId);
    
    if (!$updateStmt->execute()) {
        throw new Exception("Failed to update order status");
    }
    
    // If order status is changed to 'Completed', reduce product quantity
    if ($orderStatus === 'Completed') {
        // Get order items
        $sql_items = "SELECT product_id, quantity FROM order_items WHERE order_id = ?";
        $stmt_items = $conn->prepare($sql_items);
        if ($stmt_items === false) { 
            throw new Exception('Prepare failed for order items: ' . $conn->error); 
        }
        $stmt_items->bind_param('i', $orderId);
        $stmt_items->execute();
        $result_items = $stmt_items->get_result();
        
        while ($row_item = $result_items->fetch_assoc()) {
            $product_id = $row_item['product_id'];
            $quantity_ordered = $row_item['quantity'];
            
            // Reduce product quantity
            $sql_update_product = "UPDATE products SET Quantity = Quantity - ? WHERE ProductID = ?";
            $stmt_update_product = $conn->prepare($sql_update_product);
            if ($stmt_update_product === false) { 
                throw new Exception('Prepare failed for product update: ' . $conn->error); 
            }
            $stmt_update_product->bind_param('di', $quantity_ordered, $product_id);
            if (!$stmt_update_product->execute()) { 
                throw new Exception('Execute failed for product update: ' . $stmt_update_product->error); 
            }
            $stmt_update_product->close();
        }
        $stmt_items->close();
    }

    // If marking as returned, restore product quantity
    if ($orderStatus === 'Returned') {
        // Get order items
        $sql_items = "SELECT product_id, quantity FROM order_items WHERE order_id = ?";
        $stmt_items = $conn->prepare($sql_items);
        if ($stmt_items === false) {
            throw new Exception('Prepare failed for order items: ' . $conn->error);
        }
        $stmt_items->bind_param('i', $orderId);
        $stmt_items->execute();
        $items_result = $stmt_items->get_result();

        while ($item = $items_result->fetch_assoc()) {
            $productId = (int)$item['product_id'];
            $quantity = (float)$item['quantity'];

            // Restore inventory
            $updateProductSql = "UPDATE products SET Quantity = Quantity + ? WHERE ProductID = ?";
            $stmt_update_product = $conn->prepare($updateProductSql);
            if ($stmt_update_product === false) {
                throw new Exception('Prepare failed for product update: ' . $conn->error);
            }
            $stmt_update_product->bind_param("di", $quantity, $productId);

            if (!$stmt_update_product->execute()) {
                throw new Exception('Execute failed for product update: ' . $stmt_update_product->error);
            }
            $stmt_update_product->close();
        }
        $stmt_items->close();
    }

    // If marking as completed or cancelled, update transaction completion date
    if ($orderStatus === 'Completed' || $orderStatus === 'Cancelled') {
        $completionDate = date('Y-m-d H:i:s');
        $updateTransactionSql = "UPDATE transactions SET completed_date_transaction = ? WHERE order_id = ?";
        $updateTransactionStmt = $conn->prepare($updateTransactionSql);
        $updateTransactionStmt->bind_param("si", $completionDate, $orderId);
        
        if (!$updateTransactionStmt->execute()) {
            throw new Exception("Failed to update transaction completion date");
        }
        $updateTransactionStmt->close();
        
        // Add completion/cancellation notes if provided
        if (!empty($completionNotes)) {
            $noteType = ($orderStatus === 'Cancelled') ? 'pickup_cancellation' : 'pickup_completion';
            $notesSql = "INSERT INTO order_notes (order_id, note_type, note_content, created_by, created_at) VALUES (?, ?, ?, ?, ?)";
            $notesStmt = $conn->prepare($notesSql);
            $createdAt = date('Y-m-d H:i:s');
            $notesStmt->bind_param("issss", $orderId, $noteType, $completionNotes, $_SESSION['user_id'], $createdAt);
            
            // If notes table doesn't exist, just continue without error
            @$notesStmt->execute();
            if ($notesStmt) {
                $notesStmt->close();
            }
        }
    }
    
    // Add notes for Ready to Ship status if provided
    if ($orderStatus === 'Ready to Ship' && !empty($completionNotes)) {
        $noteType = 'pickup_ready';
        $notesSql = "INSERT INTO order_notes (order_id, note_type, note_content, created_by, created_at) VALUES (?, ?, ?, ?, ?)";
        $notesStmt = $conn->prepare($notesSql);
        $createdAt = date('Y-m-d H:i:s');
        $notesStmt->bind_param("issss", $orderId, $noteType, $completionNotes, $_SESSION['user_id'], $createdAt);
        
        // If notes table doesn't exist, just continue without error
        @$notesStmt->execute();
        if ($notesStmt) {
            $notesStmt->close();
        }
    }
    
    // Commit transaction
    $conn->commit();
    
    $statusMessage = '';
    switch ($orderStatus) {
        case 'Ready to Ship':
            $statusMessage = 'Order marked as ready for pickup. Customer can now collect their order.';
            break;
        case 'Cancelled':
            $statusMessage = 'Order has been cancelled successfully.';
            break;
        case 'Completed':
            $statusMessage = 'Order marked as completed. Customer has successfully collected their order and inventory has been updated.';
            break;
        default:
            $statusMessage = 'Order status updated successfully.';
    }
    
    echo json_encode([
        'success' => true, 
        'message' => $statusMessage,
        'new_status' => $orderStatus
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Error updating order: ' . $e->getMessage()]);
}

$updateStmt->close();
$conn->close();
?>

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
$insufficientItems = isset($_POST['insufficient_items']) ? $_POST['insufficient_items'] : '';

if ($orderId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order ID']);
    exit;
}

try {
    // Start transaction
    $conn->begin_transaction();
    
    // Build customer-friendly cancellation message
    $cancellationReason = "ORDER CANCELLED - Insufficient Stock\n\n";
    $cancellationReason .= "We sincerely apologize, but we had to cancel your order due to insufficient stock for the following item(s):\n\n";
    
    // Parse insufficient items to create detailed message
    if (!empty($insufficientItems)) {
        $items = json_decode($insufficientItems, true);
        if (is_array($items)) {
            foreach ($items as $item) {
                $productName = isset($item['product_name']) ? $item['product_name'] : 'Unknown Product';
                $orderedQty = isset($item['ordered_quantity']) ? $item['ordered_quantity'] : 0;
                $availableStock = isset($item['available_stock']) ? $item['available_stock'] : 0;
                $shortage = isset($item['shortage']) ? $item['shortage'] : 0;
                
                $cancellationReason .= "• {$productName}\n";
                $cancellationReason .= "  - You ordered: {$orderedQty} pcs\n";
                $cancellationReason .= "  - Available stock: {$availableStock} pcs\n";
                $cancellationReason .= "  - Short by: {$shortage} pcs\n\n";
            }
        }
    }
    
    $cancellationReason .= "What happens next:\n";
    $cancellationReason .= "• Your order has been automatically cancelled\n";
    $cancellationReason .= "• No payment has been charged\n";
    $cancellationReason .= "• You can place a new order with adjusted quantities\n\n";
    $cancellationReason .= "We apologize for any inconvenience. Our stock levels are updated in real-time, but sometimes orders may exceed available inventory during high demand periods.\n\n";
    $cancellationReason .= "For questions or assistance, please contact us through our support channels.\n\n";
    $cancellationReason .= "Thank you for your understanding!\n";
    $cancellationReason .= "- CJ PowerHouse Team";
    
    // Update order status to Cancelled with detailed reason
    $sql = "UPDATE orders SET order_status = 'Cancelled', reason = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $cancellationReason, $orderId);
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to cancel order');
    }
    
    $stmt->close();
    
    // Commit transaction
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Order has been cancelled due to insufficient stock. Customer has been notified.',
        'cancellation_reason' => $cancellationReason
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    
    echo json_encode([
        'success' => false,
        'message' => 'Error cancelling order: ' . $e->getMessage()
    ]);
}

$conn->close();
?>


<?php
require 'dbconn.php'; // Ensure this file contains your database connection logic

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate and sanitize input data
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $rider_name = isset($_POST['rider_name']) ? htmlspecialchars($_POST['rider_name']) : '';
    $rider_contact = isset($_POST['rider_contact']) ? htmlspecialchars($_POST['rider_contact']) : '';
    $rider_motor_type = isset($_POST['rider_motor_type']) ? htmlspecialchars($_POST['rider_motor_type']) : '';
    $rider_plate_number = isset($_POST['rider_plate_number']) ? htmlspecialchars($_POST['rider_plate_number']) : '';
    $order_status = isset($_POST['order_status']) ? htmlspecialchars($_POST['order_status']) : '';
    $return_reason = isset($_POST['return_reason']) ? htmlspecialchars($_POST['return_reason']) : '';

    // Basic validation to prevent SQL injection and ensure order_id is a number
    if ($order_id <= 0) {
        $response['message'] = 'Invalid order ID.';
        echo json_encode($response);
        exit;
    }

    $conn->begin_transaction(); // Start a transaction for atomicity

    try {
        // Build dynamic UPDATE to avoid blanking rider fields when not provided
        $set_clauses = [];
        $params = [];
        $types = '';

        if ($rider_name !== '') { $set_clauses[] = 'rider_name = ?'; $params[] = $rider_name; $types .= 's'; }
        if ($rider_contact !== '') { $set_clauses[] = 'rider_contact = ?'; $params[] = $rider_contact; $types .= 's'; }
        if ($rider_motor_type !== '') { $set_clauses[] = 'rider_motor_type = ?'; $params[] = $rider_motor_type; $types .= 's'; }
        if ($rider_plate_number !== '') { $set_clauses[] = 'rider_plate_number = ?'; $params[] = $rider_plate_number; $types .= 's'; }

        // Always update order status if provided
        if ($order_status !== '') { $set_clauses[] = 'order_status = ?'; $params[] = $order_status; $types .= 's'; }

        // Include reason only when returning and provided
        if ($order_status === 'Returned' && $return_reason !== '') {
            $set_clauses[] = 'reason = ?';
            $params[] = $return_reason;
            $types .= 's';
        }

        if (empty($set_clauses)) {
            throw new Exception('No valid fields to update.');
        }

        $sql = 'UPDATE orders SET ' . implode(', ', $set_clauses) . ' WHERE id = ?';
        $params[] = $order_id;
        $types .= 'i';

        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }

        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            throw new Exception('Execute failed: ' . $stmt->error);
        }
        $stmt->close();

        // When order is completed, stamp the transaction completion time
        if ($order_status === 'Completed') {
            $sqlTxn = "UPDATE transactions SET completed_date_transaction = CURRENT_TIMESTAMP WHERE order_id = ?";
            $stmtTxn = $conn->prepare($sqlTxn);
            if ($stmtTxn === false) {
                throw new Exception('Prepare failed (transactions): ' . $conn->error);
            }
            $stmtTxn->bind_param('i', $order_id);
            if (!$stmtTxn->execute()) {
                throw new Exception('Execute failed (transactions): ' . $stmtTxn->error);
            }
            $stmtTxn->close();
        }

        // If order status is changed to 'Ready to Ship', 'On-Ship', or 'Completed', reduce product quantity
        if ($order_status === 'Ready to Ship' || $order_status === 'On-Ship' || $order_status === 'Completed') {
            // Get order items
            $sql_items = "SELECT product_id, quantity FROM order_items WHERE order_id = ?";
            $stmt_items = $conn->prepare($sql_items);
            if ($stmt_items === false) { throw new Exception('Prepare failed: ' . $conn->error); }
            $stmt_items->bind_param('i', $order_id);
            $stmt_items->execute();
            $result_items = $stmt_items->get_result();
            while ($row_item = $result_items->fetch_assoc()) {
                $product_id = $row_item['product_id'];
                $quantity_ordered = $row_item['quantity'];
                $sql_update_product = "UPDATE products SET Quantity = Quantity - ? WHERE ProductID = ?";
                $stmt_update_product = $conn->prepare($sql_update_product);
                if ($stmt_update_product === false) { throw new Exception('Prepare failed: ' . $conn->error); }
                $stmt_update_product->bind_param('di', $quantity_ordered, $product_id);
                if (!$stmt_update_product->execute()) { throw new Exception('Execute failed: ' . $stmt_update_product->error); }
                $stmt_update_product->close();
            }
            $stmt_items->close();
        } elseif ($order_status === 'Returned') {
            // DO NOT restore product quantities when returned - only admin can restock
            // The quantities will remain reduced until admin manually restocks via Admin-ReturnedItems.php
            // This prevents automatic inventory manipulation by riders
            
            // Check if this is a COD order and increment failed attempts counter
            $check_cod = $conn->prepare("SELECT payment_method, user_id FROM orders WHERE id = ?");
            if ($check_cod) {
                $check_cod->bind_param('i', $order_id);
                $check_cod->execute();
                $check_cod->bind_result($payment_method, $user_id);
                $check_cod->fetch();
                $check_cod->close();
                
                // If it's a COD order, increment the failed attempts counter
                if (strtoupper($payment_method) === 'COD' && $user_id) {
                    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS cod_failed_attempts INT DEFAULT 0");
                    $increment_attempts = $conn->prepare("UPDATE users SET cod_failed_attempts = cod_failed_attempts + 1 WHERE id = ?");
                    if ($increment_attempts) {
                        $increment_attempts->bind_param('i', $user_id);
                        $increment_attempts->execute();
                        $increment_attempts->close();
                    }
                }
            }
        }

        $conn->commit();  // Commit the transaction
        $response['success'] = true;
        $response['message'] = 'Order updated successfully.';

    } catch (Exception $e) {
        $conn->rollback(); // Rollback the transaction on error
        $response['message'] = 'Error updating order: ' . $e->getMessage();
    }

    $conn->close();
} else {
    $response['message'] = 'Invalid request method.';
}

header('Content-Type: application/json');
echo json_encode($response);
exit;
?>
<?php
require 'dbconn.php'; // Ensure this file contains your database connection logic

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate and sanitize input data
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $order_status = isset($_POST['order_status']) ? htmlspecialchars($_POST['order_status']) : 'Pending';
    $return_reason = isset($_POST['return_reason']) ? htmlspecialchars($_POST['return_reason']) : '';


    // Basic validation to prevent SQL injection and ensure order_id is a number
    if ($order_id <= 0) {
        $response['message'] = 'Invalid order ID.';
        echo json_encode($response);
        exit;
    }

    try {
         // Start a transaction to ensure atomicity
        $conn->begin_transaction();

        // Get the user_id from the orders table
        $sql_get_user_id = "SELECT user_id FROM orders WHERE id = ?";
        $stmt_get_user_id = $conn->prepare($sql_get_user_id);
        if ($stmt_get_user_id === false) {
             throw new Exception('Prepare failed (get user_id): ' . $conn->error);
        }
        $stmt_get_user_id->bind_param("i", $order_id);
        $stmt_get_user_id->execute();
        $result_user_id = $stmt_get_user_id->get_result();
        if ($result_user_id->num_rows === 0) {
             throw new Exception('Order not found');
        }
        $order = $result_user_id->fetch_assoc();
        $user_id = $order['user_id'];
        $stmt_get_user_id->close();


        // Update the order status and return reason
        $sql = "UPDATE orders SET order_status = ?, reason = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
           throw new Exception('Prepare failed (update order): ' . $conn->error);
        }

        $stmt->bind_param("ssi", $order_status, $return_reason, $order_id);
        if (!$stmt->execute()) {
            throw new Exception('Execute failed (update order): ' . $stmt->error);
        }
        $stmt->close();


        // If the order is being returned, DO NOT increment product quantities
        // Only admins can restock items via Admin-ReturnedItems.php
        // This prevents automatic inventory manipulation by riders
        if ($order_status === 'Returned') {
            // Get the order items associated with the order for logging purposes only
            $sql_order_items = "SELECT product_id, quantity FROM order_items WHERE order_id = ?";
            $stmt_order_items = $conn->prepare($sql_order_items);

            if ($stmt_order_items === false) {
               throw new Exception('Prepare failed (get order items): ' . $conn->error);
            }

            $stmt_order_items->bind_param("i", $order_id);
            $stmt_order_items->execute();
            $result_order_items = $stmt_order_items->get_result();

            // Log the return for admin review - but DON'T restore quantities
            // Quantities will remain reduced until admin manually restocks
            $stmt_order_items->close();
            
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
        // Commit the transaction
        $conn->commit();

        $response['success'] = true;
        $response['message'] = 'Order status updated successfully.';


    } catch (Exception $e) {
        // Rollback the transaction in case of error
        $conn->rollback();
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
<?php
session_start();
require_once 'dbconn.php';

header('Content-Type: application/json');
$response = ['success' => false, 'message' => 'Invalid request.'];

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'User not logged in.';
    echo json_encode($response);
    exit;
}

// Get the data sent from the JavaScript fetch call
$data = json_decode(file_get_contents('php://input'), true);

$order_id = isset($data['order_id']) ? intval($data['order_id']) : 0;
$reference_number = isset($data['reference_number']) ? htmlspecialchars($data['reference_number']) : null;
$amount_paid = isset($data['amount_paid']) ? floatval($data['amount_paid']) : 0;

if ($order_id > 0 && $reference_number && $amount_paid > 0) {
    
    $conn->begin_transaction();
    try {
        // 1) Update the order status from 'Pending Payment' to 'Processing'
        $new_status = 'Processing';
        $sql_update_order = "UPDATE orders SET order_status = ? WHERE id = ? AND user_id = ? AND order_status = 'Pending Payment'";
        $stmt_update = $conn->prepare($sql_update_order);
        $stmt_update->bind_param("sii", $new_status, $order_id, $_SESSION['user_id']);
        $stmt_update->execute();

        if ($stmt_update->affected_rows === 0) {
            throw new Exception("Order not found or already processed.");
        }
        $stmt_update->close();

        // 2) Ensure order_items are populated from the cart only once
        $existing_count = 0;
        $stmt_check_items = $conn->prepare("SELECT COUNT(*) FROM order_items WHERE order_id = ?");
        $stmt_check_items->bind_param("i", $order_id);
        $stmt_check_items->execute();
        $stmt_check_items->bind_result($existing_count);
        $stmt_check_items->fetch();
        $stmt_check_items->close();

        if ($existing_count === 0) {
            // Pull items from user's cart, join with products for price
            $sql_cart = "SELECT c.ProductID, c.Quantity, p.Price FROM cart c JOIN products p ON c.ProductID = p.ProductID WHERE c.UserID = ?";
            $stmt_cart = $conn->prepare($sql_cart);
            $stmt_cart->bind_param("i", $_SESSION['user_id']);
            $stmt_cart->execute();
            $result_cart = $stmt_cart->get_result();

            if ($result_cart->num_rows === 0) {
                throw new Exception("Cart is empty. Nothing to convert to order items.");
            }

            $sql_item = "INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)";
            $stmt_item = $conn->prepare($sql_item);

            while ($row = $result_cart->fetch_assoc()) {
                $stmt_item->bind_param("iiid", $order_id, $row['ProductID'], $row['Quantity'], $row['Price']);
                $stmt_item->execute();
            }

            $stmt_item->close();
            $stmt_cart->close();

            // 3) Clear the user's cart now that items are recorded
            $stmt_clear = $conn->prepare("DELETE FROM cart WHERE UserID = ?");
            $stmt_clear->bind_param("i", $_SESSION['user_id']);
            $stmt_clear->execute();
            $stmt_clear->close();
        }

        // 4) Record GCASH transaction details
        $sql_insert_gcash = "INSERT INTO gcash_transactions (order_id, reference_number, amount_paid) VALUES (?, ?, ?)";
        $stmt_insert = $conn->prepare($sql_insert_gcash);
        $stmt_insert->bind_param("isd", $order_id, $reference_number, $amount_paid);
        $stmt_insert->execute();
        $stmt_insert->close();

        // Commit all changes
        $conn->commit();
        $response['success'] = true;
        $response['message'] = 'Payment confirmed successfully.';

    } catch (Exception $e) {
        $conn->rollback();
        $response['message'] = 'Database error: ' . $e->getMessage();
    }

} else {
    $response['message'] = 'Missing required data.';
}

echo json_encode($response);
$conn->close();
?>
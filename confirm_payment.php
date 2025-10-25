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
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if ($data === null) {
    // Fallback to form-encoded POST (some hosts may not send JSON headers)
    $data = $_POST;
}

$reference_number = isset($data['reference_number']) ? htmlspecialchars($data['reference_number']) : null;
$amount_paid = isset($data['amount_paid']) ? floatval($data['amount_paid']) : 0;
$client_transaction_date_str = isset($data['client_transaction_date_str']) ? htmlspecialchars($data['client_transaction_date_str']) : null;

// Check if order data exists in session
if (!isset($_SESSION['pending_gcash_order'])) {
    $response['message'] = 'No pending order found in session.';
    echo json_encode($response);
    exit;
}

$order_data = $_SESSION['pending_gcash_order'];

if ($reference_number && $amount_paid > 0) {
    
    $conn->begin_transaction();
    try {
        // 1) CREATE THE ORDER NOW (after payment confirmation)
        $sql_order = "INSERT INTO orders (user_id, total_price, payment_method, delivery_method, order_status, shipping_address, home_description, total_weight, delivery_fee, total_amount_with_delivery) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt_order = $conn->prepare($sql_order);
        $stmt_order->bind_param("idsssssddd", 
            $order_data['user_id'],
            $order_data['total_price'],
            $order_data['payment_method'],
            $order_data['delivery_method'],
            $order_data['order_status'],
            $order_data['shipping_address'],
            $order_data['home_description'],
            $order_data['total_weight'],
            $order_data['delivery_fee'],
            $order_data['total_amount_with_delivery']
        );
        $stmt_order->execute();
        $order_id = $conn->insert_id;
        $stmt_order->close();

        // 2) CREATE TRANSACTION
        $transaction_number = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
        $sql_transaction = "INSERT INTO transactions (transaction_number, user_id, order_id) VALUES (?, ?, ?)";
        $stmt_transaction = $conn->prepare($sql_transaction);
        $stmt_transaction->bind_param("sii", $transaction_number, $order_data['user_id'], $order_id);
        $stmt_transaction->execute();
        $stmt_transaction->close();

        // 3) MOVE CART ITEMS TO ORDER_ITEMS
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

        // 4) CLEAR THE USER'S CART
        $stmt_clear = $conn->prepare("DELETE FROM cart WHERE UserID = ?");
        $stmt_clear->bind_param("i", $_SESSION['user_id']);
        $stmt_clear->execute();
        $stmt_clear->close();

        // 5) RECORD GCASH TRANSACTION DETAILS
        $sql_insert_gcash = "INSERT INTO gcash_transactions (order_id, reference_number, amount_paid, client_transaction_date_str) VALUES (?, ?, ?, ?)";
        $stmt_insert = $conn->prepare($sql_insert_gcash);
        $stmt_insert->bind_param("isds", $order_id, $reference_number, $amount_paid, $client_transaction_date_str);
        $stmt_insert->execute();
        $stmt_insert->close();

        // 6) CLEAR SESSION DATA
        unset($_SESSION['pending_gcash_order']);

        // Commit all changes
        $conn->commit();
        $response['success'] = true;
        $response['message'] = 'Payment confirmed successfully.';
        $response['order_id'] = $order_id;

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
<?php
session_start();
require_once 'dbconn.php';

$response = ['success' => false, 'message' => ''];

if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'User not logged in';
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

$user_id = $_SESSION['user_id'];

// If placing a COD order, enforce suspension after 2 failed delivery attempts
// Count user's Returned orders where payment is COD
try {
    if (isset($_POST['payment']) && strtoupper(trim($_POST['payment'])) === 'COD') {
        // Check if user is currently suspended using existing columns
        $susp = $conn->prepare("SELECT cod_suspended, cod_suspended_until FROM users WHERE id = ?");
        if ($susp) {
            $susp->bind_param('i', $user_id);
            $susp->execute();
            $susp->bind_result($cod_suspended, $cod_suspended_until);
            $susp->fetch();
            $susp->close();
            
            // Check if currently suspended and suspension hasn't expired
            if ($cod_suspended && $cod_suspended_until && strtotime($cod_suspended_until) > time()) {
                $response['message'] = 'Your COD option is currently suspended until ' . date('Y-m-d h:i A', strtotime($cod_suspended_until)) . '. Please use GCASH.';
                $response['suspended'] = true;
                $response['suspended_until'] = $cod_suspended_until;
                $response['suspended_until_formatted'] = date('Y-m-d h:i A', strtotime($cod_suspended_until));
                header('Content-Type: application/json');
                echo json_encode($response);
                exit;
            }
        }
        
        // Note: Automatic suspension removed - only admins can suspend users manually
        // The system will still track return attempts for admin review
    }
} catch (Exception $e) {
    // Fail-safe: allow placing non-COD or proceed if check fails silently
}

// --- MODIFIED: ROBUST VALIDATION BLOCK ---
// This new block systematically checks each required field.
$required_fields = [
    'fullname', 'email', 'barangay', 'purok', 'contactinfo', 'payment', 
    'delivery_method', 'total_price', 'total_weight'
];

foreach ($required_fields as $field) {
    // Check if the field is either not set or is just an empty string
    if (!isset($_POST[$field]) || trim($_POST[$field]) === '') {
        // If a field fails, send a specific error message and stop.
        $response['message'] = "Required field is missing or empty: '{$field}'";
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
}
// --- END OF MODIFICATION ---
// All your original code below this line is untouched. It is now safe to use the POST variables.


$total = (float)$_POST['total_price'];
$total_weight = (float)$_POST['total_weight'];
$delivery_fee = (float)($_POST['delivery_fee'] ?? 0);
$total_amount_with_delivery = (float)($_POST['total_amount_with_delivery'] ?? $total);
$fullname = htmlspecialchars($_POST['fullname']);
$email = htmlspecialchars($_POST['email']);
$barangay = htmlspecialchars($_POST['barangay']);
$purok = htmlspecialchars($_POST['purok']);
$contactinfo = htmlspecialchars($_POST['contactinfo']);
$paymentMethod = htmlspecialchars($_POST['payment']);
$deliveryMethod = htmlspecialchars($_POST['delivery_method']);
$homeDescription = htmlspecialchars($_POST['home_description']);


// Start a transaction
$conn->begin_transaction();

try {
    // Generate a unique transaction number using UUID
    function generateUUID() {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    $transaction_number = generateUUID();

    // Build the shipping address string
    $shippingAddress = htmlspecialchars($purok . ', ' . $barangay);

    // Insert the order into the 'orders' table, including the shipping address
    // Check if delivery fee-related columns exist
    $hasDeliveryFeeColumns = false;
    $checkOrderCols = $conn->query("SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'orders' AND COLUMN_NAME IN ('delivery_fee','total_amount_with_delivery')");
    if ($checkOrderCols) {
        $present = [];
        while ($c = $checkOrderCols->fetch_assoc()) { $present[] = $c['COLUMN_NAME']; }
        if (in_array('delivery_fee', $present) && in_array('total_amount_with_delivery', $present)) {
            $hasDeliveryFeeColumns = true;
        }
    }

    if ($hasDeliveryFeeColumns) {
        $sql_order = "INSERT INTO orders (user_id, total_price, payment_method, order_status, total_weight, delivery_method, shipping_address, home_description, delivery_fee, total_amount_with_delivery) VALUES (?, ?, ?, 'Pending', ?, ?, ?, ?, ?, ?)";
    } else {
        $sql_order = "INSERT INTO orders (user_id, total_price, payment_method, order_status, total_weight, delivery_method, shipping_address, home_description) VALUES (?, ?, ?, 'Pending', ?, ?, ?, ?)";
    }
    $stmt_order = $conn->prepare($sql_order);

    if ($stmt_order === false) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    if ($hasDeliveryFeeColumns) {
        $stmt_order->bind_param("idsdsssdd", $user_id, $total, $paymentMethod, $total_weight, $deliveryMethod, $shippingAddress, $homeDescription, $delivery_fee, $total_amount_with_delivery);
    } else {
        $stmt_order->bind_param("idsdsss", $user_id, $total, $paymentMethod, $total_weight, $deliveryMethod, $shippingAddress, $homeDescription);
    }

    if (!$stmt_order->execute()) {
        throw new Exception("Execute failed: " . $stmt_order->error);
    }

    $order_id = $conn->insert_id;
    $stmt_order->close();

    // Insert the transaction into the 'transactions' table
    $sql_transaction = "INSERT INTO transactions (transaction_number, user_id, order_id) VALUES (?, ?, ?)";
    $stmt_transaction = $conn->prepare($sql_transaction);

    if ($stmt_transaction === false) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    $stmt_transaction->bind_param("sii", $transaction_number, $user_id, $order_id);
    if (!$stmt_transaction->execute()) {
        throw new Exception("Execute failed: " . $stmt_transaction->error);
    }

    $stmt_transaction->close();

    // Fetch product details from the cart
    $sql_cart = "SELECT c.ProductID, c.Quantity, p.ProductName, p.category, p.Price
                FROM cart c
                INNER JOIN products p ON c.ProductID = p.ProductID
                WHERE c.UserID = ?";
    $stmt_cart = $conn->prepare($sql_cart);

    if ($stmt_cart === false) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    $stmt_cart->bind_param("i", $user_id);
    $stmt_cart->execute();
    $result_cart = $stmt_cart->get_result();

    if ($result_cart->num_rows === 0) {
        throw new Exception("Cart is empty.");
    }

    // Insert order items into the 'order_items' table
    $sql_item = "INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)";
    $stmt_item = $conn->prepare($sql_item);

    if ($stmt_item === false) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    while ($row = $result_cart->fetch_assoc()) {
        $product_id = $row['ProductID'];
        $quantity = $row['Quantity'];
        $price = $row['Price'];

        // Insert order items
        $stmt_item->bind_param("iidd", $order_id, $product_id, $quantity, $price);
        if (!$stmt_item->execute()) {
            throw new Exception("Execute failed: " . $stmt_item->error);
        }
    }

    $stmt_item->close();
    $stmt_cart->close();

    // Clear the user's cart
    $sql_delete_cart = "DELETE FROM cart WHERE UserID = ?";
    $stmt_delete_cart = $conn->prepare($sql_delete_cart);

    if ($stmt_delete_cart === false) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    $stmt_delete_cart->bind_param("i", $user_id);
    if (!$stmt_delete_cart->execute()) {
        throw new Exception("Execute failed: " . $stmt_delete_cart->error);
    }

    $stmt_delete_cart->close();

    // Commit the transaction
    $conn->commit();

    // Redirect to Mobile-Orders.php with success parameter
    $conn->close();
    header("Location: Mobile-Orders.php?order_success=true");
    exit;

} catch (Exception $e) {
    // Rollback the transaction if there was an error
    $conn->rollback();

    // Log the error (important for debugging)
    error_log("Order placement error: " . $e->getMessage() . ", User ID: " . $user_id . ", POST data: " . json_encode($_POST));

    $response['message'] = 'Error placing order: ' . $e->getMessage();

    // Send JSON response in case of error
    $conn->close();
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

?>
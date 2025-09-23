<?php
session_start();
require_once 'dbconn.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => 'An unknown error occurred.'];

if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'User not logged in.';
    echo json_encode($response);
    exit;
}

// 1. VALIDATE ALL INCOMING FORM DATA
$required_fields = [
    'fullname', 'email', 'barangay', 'purok', 'contactinfo', 'payment', 
    'delivery_method', 'total_price', 'total_weight'
];

foreach ($required_fields as $field) {
    if (!isset($_POST[$field]) || trim($_POST[$field]) === '') {
        $response['message'] = "Required field is missing or empty: '{$field}'";
        echo json_encode($response);
        exit;
    }
}

// 2. PREPARE ORDER DATA
$user_id = $_SESSION['user_id'];
$total_price = (float)$_POST['total_price'];
$total_weight = (float)$_POST['total_weight'];
$payment_method = htmlspecialchars($_POST['payment']);
$delivery_method = htmlspecialchars($_POST['delivery_method']);
$barangay_id = (int)$_POST['barangay'];
$purok = htmlspecialchars($_POST['purok']);
$home_description = htmlspecialchars($_POST['home_description']);
$order_status = 'Pending Payment';

// NEW: include delivery fee and total with delivery
$delivery_fee = isset($_POST['delivery_fee']) ? (float)$_POST['delivery_fee'] : 0.0;
$total_amount_with_delivery = isset($_POST['total_amount_with_delivery'])
    ? (float)$_POST['total_amount_with_delivery']
    : ($total_price + $delivery_fee);

$amount_in_centavos = $total_amount_with_delivery * 100;

if ($amount_in_centavos < 10000) {
    $response['message'] = 'Payment failed. The minimum amount for GCash is 100 PHP.';
    echo json_encode($response);
    exit;
}

// --- 3. SAVE THE ORDER TO THE DATABASE ---
$conn->begin_transaction();

try {
    // Get the barangay name from its ID
    $sql_barangay = "SELECT barangay_name FROM barangays WHERE id = ? LIMIT 1";
    $stmt_barangay = $conn->prepare($sql_barangay);
    $stmt_barangay->bind_param("i", $barangay_id);
    $stmt_barangay->execute();
    $result_barangay = $stmt_barangay->get_result();
    if ($result_barangay->num_rows === 0) {
        throw new Exception("Invalid Barangay ID provided.");
    }
    $barangay_data = $result_barangay->fetch_assoc();
    $barangay_name = $barangay_data['barangay_name'];
    $stmt_barangay->close();

    // Build the shipping_address string
    $shipping_address = htmlspecialchars($purok . ', ' . $barangay_name);

    // INSERT into 'orders' table including delivery fields
    $sql_order = "INSERT INTO orders (user_id, total_price, payment_method, delivery_method, order_status, shipping_address, home_description, total_weight, delivery_fee, total_amount_with_delivery) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt_order = $conn->prepare($sql_order);
    // Types: i d s s s s s d d d
    $stmt_order->bind_param("idsssssddd", $user_id, $total_price, $payment_method, $delivery_method, $order_status, $shipping_address, $home_description, $total_weight, $delivery_fee, $total_amount_with_delivery);
    $stmt_order->execute();
    $order_id = $conn->insert_id;
    $stmt_order->close();

    // ✅ Save order_id in session (optional, helps other files)
    $_SESSION['last_order_id'] = $order_id;

    // INSERT into 'transactions' table (uses snake_case)
    $transaction_number = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
    $sql_transaction = "INSERT INTO transactions (transaction_number, user_id, order_id) VALUES (?, ?, ?)";
    $stmt_transaction = $conn->prepare($sql_transaction);
    $stmt_transaction->bind_param("sii", $transaction_number, $user_id, $order_id);
    $stmt_transaction->execute();
    $stmt_transaction->close();

    // NOTE: Do not move cart items into order_items or clear the cart yet.
    // This will now happen only after successful GCASH confirmation
    // inside confirm_payment.php to avoid premature order creation.

    $conn->commit();

} catch (Exception $e) {
    $conn->rollback();
    $response['message'] = 'Failed to save order to database: ' . $e->getMessage();
    echo json_encode($response);
    exit;
}


// --- 4. CREATE PAYMONGO PAYMENT SOURCE ---
// Load secret key from secrets.php (ignored) or environment
@include_once __DIR__ . '/secrets.php';
$secretKey = isset($PAYMONGO_SECRET_KEY) ? trim($PAYMONGO_SECRET_KEY) : '';
if ($secretKey === '') {
    $response['message'] = 'Server misconfiguration: missing PayMongo secret key.';
    echo json_encode($response);
    exit;
}

// Build return URLs dynamically so mobile devices don’t get redirected to localhost
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST']; // includes port if any
$basePath = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$baseURL = $scheme . '://' . $host . $basePath . '/';

$successUrl = $baseURL . 'payment_success.php?order_id=' . $order_id;
$failedUrl = $baseURL . 'payment_failed.php';

$payload = [
    'data' => [
        'attributes' => [
            'amount' => $amount_in_centavos,
            'redirect' => [ 'success' => $successUrl, 'failed' => $failedUrl ],
            'billing' => [
                'name' => htmlspecialchars($_POST['fullname']),
                'email' => htmlspecialchars($_POST['email']),
                'phone' => htmlspecialchars($_POST['contactinfo'])
            ],
            'type' => 'gcash', 'currency' => 'PHP'
        ]
    ]
];

$ch = curl_init('https://api.paymongo.com/v1/sources');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Basic ' . base64_encode($secretKey . ':')
]);

$api_response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$result = json_decode($api_response, true);

if ($httpCode === 200 && isset($result['data']['attributes']['redirect']['checkout_url'])) {
    $response['success'] = true;
    $response['checkout_url'] = $result['data']['attributes']['redirect']['checkout_url'];
} else {
    $errorMessage = 'Order was saved, but payment link creation failed.';
    if (isset($result['errors'])) {
        $errorDetails = [];
        foreach ($result['errors'] as $error) { $errorDetails[] = $error['detail']; }
        $errorMessage .= ' Reason: ' . implode(', ', $errorDetails);
    }
    $response['message'] = $errorMessage;
}

echo json_encode($response);
?>

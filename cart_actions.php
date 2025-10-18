<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

require_once 'dbconn.php'; // Include your database connection file

// **REMOVE THIS LINE IN PRODUCTION**
// $_SESSION['user_id'] = 1;

$response = ['success' => false, 'message' => 'Invalid action'];

if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'User not logged in';
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

$user_id = $_SESSION['user_id'];

// Get data from the POST request
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['action'])) {
    $response['message'] = 'No action specified';
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

$action = $data['action'];

switch ($action) {
    case 'remove':
        if (!isset($data['cartID'])) {
            $response['message'] = 'CartID is missing';
            break;
        }

        $cartID = $data['cartID'];

        // SQL to delete a record
        $sql = "DELETE FROM cart WHERE CartID = ? AND UserID = ?";
        $stmt = $conn->prepare($sql);

        if ($stmt === false) {
            $response['message'] = 'Prepare failed: ' . $conn->error;
            break;
        }

        $stmt->bind_param("ii", $cartID, $user_id);

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $response = ['success' => true, 'message' => 'Item removed from cart'];
            } else {
                $response['message'] = 'Item not found in cart or unauthorized';
            }
        } else {
            $response['message'] = 'Execute failed: ' . $stmt->error;
        }

        $stmt->close();
        break;

    case 'update':
        if (!isset($data['cartID']) || !isset($data['change'])) {
            $response['message'] = 'CartID or change is missing';
            break;
        }

        $cartID = $data['cartID'];
        $change = $data['change'];

        if ($change !== 'increase' && $change !== 'decrease') {
            $response['message'] = 'Invalid change value';
            break;
        }

        // Get current quantity and product's available quantity
        $sql = "SELECT c.Quantity, p.Quantity AS ProductQuantity
                FROM cart c
                JOIN products p ON c.ProductID = p.ProductID
                WHERE c.CartID = ? AND c.UserID = ?";
        $stmt = $conn->prepare($sql);

        if ($stmt === false) {
            $response['message'] = 'Prepare failed: ' . $conn->error;
            break;
        }

        $stmt->bind_param("ii", $cartID, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $response['message'] = 'Cart item not found';
            break;
        }

        $row = $result->fetch_assoc();
        $quantity = $row['Quantity'];
        $productQuantity = $row['ProductQuantity'];


        // Adjust quantity
        if ($change === 'increase') {
            if ($quantity >= $productQuantity) {
                 $response['message'] = 'Not enough stock available.';
                 break;
            }

            $newQuantity = $quantity + 1;
        } else {
            $newQuantity = $quantity - 1;
            if ($newQuantity < 1) {
                $newQuantity = 1;  // Prevent quantity from going below 1
            }
        }

        // Update quantity in database
        $sql = "UPDATE cart SET Quantity = ? WHERE CartID = ? AND UserID = ?";
        $stmt = $conn->prepare($sql);

        if ($stmt === false) {
            $response['message'] = 'Prepare failed: ' . $conn->error;
            break;
        }

        $stmt->bind_param("iii", $newQuantity, $cartID, $user_id);

        if ($stmt->execute()) {
            $response = ['success' => true, 'message' => 'Quantity updated'];
        } else {
            $response['message'] = 'Execute failed: ' . $stmt->error;
        }

        $stmt->close();
        break;

    default:
        $response['message'] = 'Invalid action specified';
}

header('Content-Type: application/json');
echo json_encode($response);

$conn->close();
?>
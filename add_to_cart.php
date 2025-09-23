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

$data = json_decode(file_get_contents('php://input'), true);
$productID = $data['productID'] ?? null;

if (!$productID) {
    $response['message'] = 'Product ID missing';
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Check if the product is already in the cart for this user
$sql = "SELECT CartID, Quantity FROM cart WHERE UserID = ? AND ProductID = ?";
$stmt = $conn->prepare($sql);

if ($stmt === false) {
    $response['message'] = 'Prepare failed: ' . $conn->error;
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

$stmt->bind_param("ii", $user_id, $productID);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    // Product is already in the cart, increase the quantity
    $row = $result->fetch_assoc();
    $cartID = $row['CartID'];
    $currentQuantity = $row['Quantity'];

    // Get the available product quantity from the products table
    $sql = "SELECT Quantity FROM products WHERE ProductID = ?";
    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        $response['message'] = 'Prepare failed: ' . $conn->error;
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    $stmt->bind_param("i", $productID);
    $stmt->execute();
    $productResult = $stmt->get_result();

    if ($productResult->num_rows === 0) {
        $response['message'] = 'Product not found';
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    $productRow = $productResult->fetch_assoc();
    $availableQuantity = $productRow['Quantity'];

    // Check if increasing the quantity exceeds the available quantity
    if ($currentQuantity + 1 > $availableQuantity) {
        $response['message'] = 'Not enough stock available';
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    // Update the quantity in the cart
    $newQuantity = $currentQuantity + 1;
    $sql = "UPDATE cart SET Quantity = ? WHERE CartID = ?";
    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        $response['message'] = 'Prepare failed: ' . $conn->error;
    } else {
        $stmt->bind_param("ii", $newQuantity, $cartID);
        if ($stmt->execute()) {
            $response['success'] = true;
            $response['message'] = 'Cart quantity updated';
        } else {
            $response['message'] = 'Failed to update quantity: ' . $stmt->error;
        }
    }
} else {
    // Product is not in the cart, add it
    // Get the available product quantity from the products table
    $sql = "SELECT Quantity FROM products WHERE ProductID = ?";
    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        $response['message'] = 'Prepare failed: ' . $conn->error;
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    $stmt->bind_param("i", $productID);
    $stmt->execute();
    $productResult = $stmt->get_result();

    if ($productResult->num_rows === 0) {
        $response['message'] = 'Product not found';
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    $productRow = $productResult->fetch_assoc();
    $availableQuantity = $productRow['Quantity'];

    // Check if there is stock available
    if ($availableQuantity < 1) {
        $response['message'] = 'Out of stock';
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    $sql = "INSERT INTO cart (UserID, ProductID, Quantity) VALUES (?, ?, 1)";
    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        $response['message'] = 'Prepare failed: ' . $conn->error;
    } else {
        $stmt->bind_param("ii", $user_id, $productID);
        if ($stmt->execute()) {
            $response['success'] = true;
            $response['message'] = 'Product added to cart';
        } else {
            $response['message'] = 'Failed to add product to cart: ' . $stmt->error;
        }
    }
}

$stmt->close();
$conn->close();

header('Content-Type: application/json');
echo json_encode($response);
?>
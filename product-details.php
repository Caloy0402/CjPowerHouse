<?php
session_start();
require_once 'dbconn.php';

if (!isset($_SESSION['user_id'])) {
    // Handle unauthorized access.  For simplicity, let's just return an error.
    echo json_encode(['error' => 'Not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];

// Check if ProductID is set and is a number
if (!isset($_GET['ProductID']) || !is_numeric($_GET['ProductID'])) {
    echo json_encode(['error' => 'Invalid Product ID']);
    exit();
}

$product_id = (int)$_GET['ProductID']; // Cast to integer for security

// Fetch product details
$sql = "SELECT ProductID, ProductName, Price, Description, ImagePath From products WHERE ProductID = ?"; //Add description in TABLE Products
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $product_id);
$stmt->execute();
$result = $stmt->get_result();
$product = $result->fetch_assoc();

if (!$product) {
    echo json_encode(['error' => 'Product not found']);
    exit();
}

// Fetch existing reviews
$sql_reviews = "SELECT r.*, u.first_name, u.last_name, u.ImagePath AS user_image
                FROM product_reviews r
                JOIN users u ON r.user_id = u.id
                WHERE r.product_id = ?";
$stmt_reviews = $conn->prepare($sql_reviews);
$stmt_reviews->bind_param("i", $product_id);
$stmt_reviews->execute();
$result_reviews = $stmt_reviews->get_result();
$reviews = [];

while ($row = $result_reviews->fetch_assoc()) {
    $reviews[] = $row;
}

// Function to sanitize input (important!)
function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

// Handle review submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate and sanitize input
    $rating = isset($_POST['rating']) && is_numeric($_POST['rating']) ? (int)$_POST['rating'] : 0;
    $comment = isset($_POST['comment']) ? sanitize($_POST['comment']) : '';

    // File upload handling (if a file was uploaded)
    $image_path = null;
    if (isset($_FILES['reviewImage']) && $_FILES['reviewImage']['error'] === 0) {
        $targetDir = "uploads/review_images/";  // Create this directory
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }
        $fileName = basename($_FILES['reviewImage']['name']);
        $targetFile = $targetDir . $fileName;
        $imageFileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));
        $allowedTypes = ['jpg', 'jpeg', 'png', 'gif'];

        if (in_array($imageFileType, $allowedTypes)) {
            if (move_uploaded_file($_FILES['reviewImage']['tmp_name'], $targetFile)) {
                $image_path = $targetFile; // Save the path
            } else {
                echo json_encode(['error' => 'Failed to upload review image.']);
                exit();
            }
        } else {
            echo json_encode(['error' => 'Invalid file type for review image.']);
            exit();
        }
    }

    // Insert the review into the database
    $insert_sql = "INSERT INTO product_reviews (product_id, user_id, rating, comment, image_path) VALUES (?, ?, ?, ?, ?)";
    $insert_stmt = $conn->prepare($insert_sql);
    $insert_stmt->bind_param("iiiss", $product_id, $user_id, $rating, $comment, $image_path);

    if ($insert_stmt->execute()) {
        // Review added successfully - Fetch the new review to display
        $last_review_id = $conn->insert_id;
        $sql_new_review = "SELECT r.*, u.first_name, u.last_name, u.ImagePath AS user_image
                           FROM product_reviews r
                           JOIN users u ON r.user_id = u.id
                           WHERE r.id = ?";
        $stmt_new_review = $conn->prepare($sql_new_review);
        $stmt_new_review->bind_param("i", $last_review_id);
        $stmt_new_review->execute();
        $result_new_review = $stmt_new_review->get_result();
        $new_review = $result_new_review->fetch_assoc();

        // Return the new review data to be displayed
        echo json_encode(['success' => 'Review added successfully', 'new_review' => $new_review]);
        exit();
    } else {
        echo json_encode(['error' => 'Failed to add review: ' . $insert_stmt->error]);
        exit();
    }
}

// Prepare the response data
$response = [
    'product' => $product,
    'reviews' => $reviews,
    'user_id' => $user_id // Pass the user ID for the form
];

// Send the JSON response
header('Content-Type: application/json');
echo json_encode($response);

$conn->close();
?>
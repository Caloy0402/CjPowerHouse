<?php
session_start();
require_once 'dbconn.php';

header('Content-Type: application/json');

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

$response = ['success' => false, 'message' => ''];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Validate required fields
        if (!isset($_POST['product_id'], $_POST['comment'], $_POST['rating'], $_SESSION['user_id'])) {
            throw new Exception('Missing required fields.');
        }

        $product_id = intval($_POST['product_id']);
        $user_id = intval($_SESSION['user_id']);
        $comment = trim($_POST['comment']);
        $rating = isset($_POST['rating']) ? intval($_POST['rating']) : 0; // default to 0
        $image_path = null;

        // Log the user_id for debugging
        error_log("Received user_id: " . $user_id);

        // Check if the user exists
        $sql_check_user = "SELECT id FROM users WHERE id = ?";
        $stmt_check_user = $conn->prepare($sql_check_user);
        if (!$stmt_check_user) {
            throw new Exception('Failed to prepare SQL statement: ' . $conn->error);
        }
        $stmt_check_user->bind_param("i", $user_id);
        $stmt_check_user->execute();
        $stmt_check_user->store_result();

        if ($stmt_check_user->num_rows === 0) {
            throw new Exception('Invalid user ID: User does not exist.');
        }
        $stmt_check_user->close();

        // Handle image upload (allow common formats across PC/Android/iOS)
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $targetDir = "uploads/feedback_images/";
            if (!is_dir($targetDir)) { mkdir($targetDir, 0777, true); }

            $fileName = basename($_FILES['image']['name']);
            // Normalize extension and provide a safe unique filename
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $safeName = uniqid('fb_', true) . '.' . $ext;
            $targetFile = $targetDir . $safeName;

            // Allow more formats commonly produced by devices
            $allowedExtensions = ['jpg','jpeg','png','gif','webp','bmp','jfif','heic','heif','tif','tiff'];

            // Validate by extension first
            if (!in_array($ext, $allowedExtensions)) {
                throw new Exception('Unsupported image format. Allowed: JPG, JPEG, PNG, GIF, WEBP, BMP, JFIF, HEIC/HEIF, TIFF.');
            }

            // Validate by MIME type as well
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $_FILES['image']['tmp_name']);
            finfo_close($finfo);
            $allowedMimes = [
                'image/jpeg','image/pjpeg','image/png','image/gif','image/webp','image/bmp','image/tiff','image/heic','image/heif'
            ];
            if (strpos($mime, 'image/') !== 0 && !in_array($mime, $allowedMimes)) {
                throw new Exception('Invalid image file. Please upload a valid image.');
            }

            if (move_uploaded_file($_FILES['image']['tmp_name'], $targetFile)) {
                $image_path = $targetFile;
            } else {
                throw new Exception('Failed to upload image.');
            }
        }

        // Insert feedback into the database
        $sql = "INSERT INTO product_feedback (product_id, user_id, comment, rating, image_path) VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('Failed to prepare SQL statement: ' . $conn->error);
        }

        $stmt->bind_param("iisis", $product_id, $user_id, $comment, $rating, $image_path);

        if ($stmt->execute()) {
            $response['success'] = true;
            $response['message'] = 'Feedback submitted successfully!';
        } else {
            throw new Exception('Failed to execute SQL statement: ' . $stmt->error);
        }

        $stmt->close();
    } else {
        throw new Exception('Invalid request method.');
    }
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
} finally {
    $conn->close();
    echo json_encode($response);
}
?>
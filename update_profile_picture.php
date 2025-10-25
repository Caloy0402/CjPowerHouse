<?php
session_start();
require_once 'dbconn.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit;
}

$user_id = $_SESSION['user_id'];

// Check if file was uploaded
if (!isset($_FILES['profile_image']) || $_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
    exit;
}

$file = $_FILES['profile_image'];

// Validate file type
$allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
if (!in_array($file['type'], $allowed_types)) {
    echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPEG, PNG, and GIF are allowed.']);
    exit;
}

// Validate file size (max 5MB)
if ($file['size'] > 5 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'File size too large. Maximum 5MB allowed.']);
    exit;
}

// Create uploads directory if it doesn't exist
$upload_dir = 'uploads/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Generate unique filename
$file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
$new_filename = 'profile_' . $user_id . '_' . time() . '.' . $file_extension;
$upload_path = $upload_dir . $new_filename;

// Move uploaded file
if (move_uploaded_file($file['tmp_name'], $upload_path)) {
    // Update database with new image path
    $sql = "UPDATE users SET ImagePath = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("si", $upload_path, $user_id);
        
        if ($stmt->execute()) {
            // Return success with image URL
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];
            $path = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
            $baseURL = $protocol . '://' . $host . $path . '/';
            
            echo json_encode([
                'success' => true, 
                'message' => 'Profile picture updated successfully',
                'image_url' => $baseURL . $upload_path
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database update failed']);
        }
        
        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'message' => 'Database prepare failed']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'File upload failed']);
}

$conn->close();
?>

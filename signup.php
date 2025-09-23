<?php
session_start();
require_once 'dbconn.php';  

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retrieve form data
    $first_name = $_POST['first_name'];
    $middle_name = $_POST['middle_name'];
    $last_name = $_POST['last_name'];
    $email = $_POST['email'];
    $contactinfo = $_POST['contactinfo'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $barangay_id = $_POST['barangay'];
    $purok = $_POST['purok'];

    // Handle profile image upload
    if (isset($_FILES['profilePicture']) && $_FILES['profilePicture']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/profile_images/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $fileName = basename($_FILES['profilePicture']['name']);
        $filePath = $uploadDir . uniqid() . '_' . $fileName;

        if (move_uploaded_file($_FILES['profilePicture']['tmp_name'], $filePath)) {
            // File uploaded successfully
        } else {
            die("Failed to upload profile image.");
        }
    } else {
        die("Profile image is required.");
    }

    // Insert user into the database
    $sql = "INSERT INTO users (password, email, first_name, middle_name, last_name, phone_number, barangay_id, purok, ImagePath) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssssssiss", $password, $email, $first_name, $middle_name, $last_name, $contactinfo, $barangay_id, $purok, $filePath);


    if ($stmt->execute()) {
        // Redirect to success page or login page
        header('Location: signin.php');
        exit();
    } else {
        die("Registration failed. Please try again.");
    }

    $stmt->close();
    $conn->close();
}
?>
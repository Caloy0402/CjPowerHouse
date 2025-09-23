<?php
require_once 'dbconn.php';

// Check if the form has been submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Retrieve form data
    $first_name = $_POST['first_name'];
    $middle_name = $_POST['middle_name'];
    $last_name = $_POST['last_name'];
    $email = $_POST['email'];
    $phone_number = $_POST['contactinfo'];
    $password = $_POST['password']; // **IMPORTANT: HASH THIS!**
    $barangay_id = $_POST['barangay'];
    $purok = $_POST['purok'];
    $motor_type = $_POST['MotorType'];
    $plate_number = $_POST['PlateNumber'];

    // Handle the image upload
    $imagePath = "img/default_profile.png"; // Default image
    if (isset($_FILES['profilePicture']) && $_FILES['profilePicture']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['profilePicture'];
        $fileName = basename($file['name']); // Get the original filename
        $fileTmpName = $file['tmp_name'];
        $fileSize = $file['size'];
        $fileError = $file['error'];

        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowed = array('jpg', 'jpeg', 'png');

        if (in_array($fileExt, $allowed)) {
            if ($fileError === 0) {
                if ($fileSize < 1000000) {  // Limit file size to 1MB (adjust as needed)
                    $fileNameNew = uniqid('', true) . "." . $fileExt; // Unique filename
                    $fileDestination = 'img/' . $fileNameNew; // Where to store the image

                    if (move_uploaded_file($fileTmpName, $fileDestination)) {
                        $imagePath = $fileDestination;
                    } else {
                        echo "Error uploading image."; // Handle upload errors better
                    }
                } else {
                    echo "File size too large!";
                }
            } else {
                echo "Error uploading file!";
            }
        } else {
            echo "Invalid file type!";
        }
    }

    // Hash the password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Prepare the SQL statement to insert into the 'riders' table
    $sql = "INSERT INTO riders (password, email, first_name, middle_name, last_name, phone_number, barangay_id, purok, ImagePath, MotorType, PlateNumber)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    // Prepare and execute the statement
    $stmt = $conn->prepare($sql);

    if ($stmt) {
        $stmt->bind_param("sssssssssss", $hashed_password, $email, $first_name, $middle_name, $last_name, $phone_number, $barangay_id, $purok, $imagePath, $motor_type, $plate_number);

        if ($stmt->execute()) {
            // Success!  Redirect to a success page or display a success message.
            //echo "Rider added successfully!";
            header("Location: Admin-Dashboard.php"); // Redirect to dashboard
            exit(); // Important: Stop further execution
        } else {
            // Error!  Display an error message.  $stmt->error will give you more details.
            echo "Error adding rider: " . $stmt->error;
        }

        $stmt->close();
    } else {
        echo "Error preparing statement: " . $conn->error;
    }

    $conn->close(); // Close the database connection
} else {
    // If the form wasn't submitted, redirect to the form page or display an error.
    header("Location: Admin-AddRider.php"); // Redirect back to the form
    exit();
}
?>
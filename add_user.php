<?php
// Include your database connection
require_once 'dbconn.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Get form data with proper field name handling
    $firstName = $_POST['firstName'] ?? '';
    $middleName = $_POST['middleName'] ?? '';
    $lastName = $_POST['lastName'] ?? '';
    $emailAddress = $_POST['emailAddress'] ?? '';
    $homeAddress = $_POST['homeAddress'] ?? '';
    $contact_info = $_POST['contactNumber'] ?? ''; // Fixed field name
    $passwordInput = $_POST['passwordInput'] ?? '';
    $roleSelect = $_POST['roleSelect'] ?? '';

    // Get mechanic-specific fields if role is mechanic
    $motor_type = '';
    $plate_number = '';
    $specialization = '';
    
    if ($roleSelect === 'Mechanic') {
        $motor_type = $_POST['motorType'] ?? '';
        $plate_number = $_POST['plateNumber'] ?? '';
        $specialization = $_POST['specialization'] ?? 'General Mechanic';
    }

    // Hash the password
    $hashedPassword = password_hash($passwordInput, PASSWORD_DEFAULT);

    // Handle image upload
    $profileImage = '';
    if (isset($_FILES['profileImage']) && $_FILES['profileImage']['error'] == 0) {
        // Define the target directory and file name
        $targetDir = "uploads/";
        $targetFile = $targetDir . basename($_FILES["profileImage"]["name"]);
        $imageFileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));

        // Check if the file is an actual image
        $check = getimagesize($_FILES["profileImage"]["tmp_name"]);
        if ($check !== false) {
            // Move the uploaded image to the "uploads" directory
            if (move_uploaded_file($_FILES["profileImage"]["tmp_name"], $targetFile)) {
                $profileImage = $targetFile;
            } else {
                echo "Sorry, there was an error uploading your file.";
                exit(); // Stop further execution if image upload fails
            }
        } else {
            echo "File is not an image.";
            exit(); // Stop further execution if it's not an image
        }
    }

    // Check if email already exists
    $checkEmail = $conn->prepare("SELECT id FROM CJusers WHERE email = ?");
    $checkEmail->bind_param("s", $emailAddress);
    $checkEmail->execute();
    $emailResult = $checkEmail->get_result();
    
    if ($emailResult->num_rows > 0) {
        echo "Email address already exists in the system.";
        $checkEmail->close();
        exit();
    }
    $checkEmail->close();

    // Insert user data into the database based on role
    if ($roleSelect === 'Mechanic') {
        // Check if email exists in mechanics table
        $checkMechanicEmail = $conn->prepare("SELECT id FROM mechanics WHERE email = ?");
        $checkMechanicEmail->bind_param("s", $emailAddress);
        $checkMechanicEmail->execute();
        $mechanicEmailResult = $checkMechanicEmail->get_result();
        
        if ($mechanicEmailResult->num_rows > 0) {
            echo "Email address already exists in the mechanics system.";
            $checkMechanicEmail->close();
            exit();
        }
        $checkMechanicEmail->close();

        // Insert into mechanics table
        $stmt = $conn->prepare("INSERT INTO mechanics (password, email, first_name, middle_name, last_name, phone_number, home_address, ImagePath, MotorType, PlateNumber, specialization) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        if ($stmt) {
            $stmt->bind_param("sssssssssss", $hashedPassword, $emailAddress, $firstName, $middleName, $lastName, $contact_info, $homeAddress, $profileImage, $motor_type, $plate_number, $specialization);
            
            if ($stmt->execute()) {
                echo "Mechanic added successfully!";
            } else {
                echo $stmt->error;
            }
            $stmt->close();
        } else {
            echo "Database error: " . $conn->error;
        }
    } elseif ($roleSelect === 'rider') {
        // Get rider-specific fields
        $barangay_id = $_POST['barangaySelect'] ?? '';
        $purok = $_POST['purokInput'] ?? '';
        $motor_type = $_POST['riderMotorType'] ?? '';
        $plate_number = $_POST['riderPlateNumber'] ?? '';
        
        // Check if email exists in riders table
        $checkRiderEmail = $conn->prepare("SELECT id FROM riders WHERE email = ?");
        $checkRiderEmail->bind_param("s", $emailAddress);
        $checkRiderEmail->execute();
        $riderEmailResult = $checkRiderEmail->get_result();
        
        if ($riderEmailResult->num_rows > 0) {
            echo "Email address already exists in the riders system.";
            $checkRiderEmail->close();
            exit();
        }
        $checkRiderEmail->close();

        // Insert into riders table
        $stmt = $conn->prepare("INSERT INTO riders (password, email, first_name, middle_name, last_name, phone_number, barangay_id, purok, ImagePath, MotorType, PlateNumber) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        if ($stmt) {
            $stmt->bind_param("sssssssssss", $hashedPassword, $emailAddress, $firstName, $middleName, $lastName, $contact_info, $barangay_id, $purok, $profileImage, $motor_type, $plate_number);
            
            if ($stmt->execute()) {
                echo "Rider added successfully!";
            } else {
                echo $stmt->error;
            }
            $stmt->close();
        } else {
            echo "Database error: " . $conn->error;
        }
    } else {
        // Insert into CJusers table for admin and cashier roles only
        $stmt = $conn->prepare("INSERT INTO CJusers (first_name, middle_name, last_name, email, home_address, contact_info, password, role, profile_image) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        if ($stmt) {
            $stmt->bind_param("sssssssss", $firstName, $middleName, $lastName, $emailAddress, $homeAddress, $contact_info, $hashedPassword, $roleSelect, $profileImage);

            if ($stmt->execute()) {
                echo "User added successfully!";
            } else {
                echo $stmt->error;
            }
            $stmt->close();
        } else {
            echo "Database error: " . $conn->error;
        }
    }

    // Close the database connection
    mysqli_close($conn);
}
?>

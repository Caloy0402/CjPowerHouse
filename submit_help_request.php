<?php
session_start();
require_once 'dbconn.php';

// Check if the request method is POST
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // Get form data
    $name = $_POST['name'] ?? '';
    $bike_unit = $_POST['bikeUnit'] ?? '';
    $plate_number = $_POST['plateNumber'] ?? '';
    $problem = $_POST['problem'] ?? '';
    $location = $_POST['location'] ?? '';
    $contact_info = $_POST['contactInfo'] ?? '';
    $user_id = $_SESSION['user_id'] ?? null;
    $latitude = isset($_POST['latitude']) ? floatval($_POST['latitude']) : null;
    $longitude = isset($_POST['longitude']) ? floatval($_POST['longitude']) : null;
    $breakdown_barangay_id = isset($_POST['barangay']) ? intval($_POST['barangay']) : null;

    // Validate required fields
    if (empty($name) || empty($bike_unit) || empty($problem) || empty($location) || empty($contact_info)) {
        echo json_encode(['success' => false, 'message' => 'Please fill in all required fields']);
        exit();
    }

    // If user is not logged in, try to find user by contact info
    if (!$user_id) {
        $find_user_sql = "SELECT id FROM users WHERE phone_number = ?";
        $find_user_stmt = $conn->prepare($find_user_sql);
        if ($find_user_stmt) {
            $find_user_stmt->bind_param("s", $contact_info);
            $find_user_stmt->execute();
            $result = $find_user_stmt->get_result();
            if ($result->num_rows > 0) {
                $user = $result->fetch_assoc();
                $user_id = $user['id'];
            }
            $find_user_stmt->close();
        }
    }

    // If still no user_id, create a guest request
    if (!$user_id) {
        $user_id = null; // Allow null for guest requests
    }

    // Prepare SQL statement to insert help request
    $sql = "INSERT INTO help_requests (user_id, breakdown_barangay_id, name, bike_unit, plate_number, problem_description, location, contact_info, latitude, longitude, status, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())";

    $stmt = $conn->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("iissssssdd", $user_id, $breakdown_barangay_id, $name, $bike_unit, $plate_number, $problem, $location, $contact_info, $latitude, $longitude);
        
        if ($stmt->execute()) {
            $request_id = $conn->insert_id;
            
            // Log the request for debugging
            error_log("Help request submitted: ID=$request_id, Name=$name, Contact=$contact_info");
            
            echo json_encode([
                'success' => true, 
                'message' => 'Help request submitted successfully! A mechanic will contact you soon.',
                'request_id' => $request_id
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error submitting request: ' . $stmt->error]);
        }
        
        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    }
    
    $conn->close();
} else {
    // If not POST request, return error
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?> 
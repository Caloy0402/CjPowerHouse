<?php
require_once 'dbconn.php';
session_start();

// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

$user_id = 1; // Replace this with the logged-in user's ID

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Log the received data
    error_log("Received POST request: " . print_r($_POST, true));
    
    if (isset($_POST['product_id'])) {
        $product_id = intval($_POST['product_id']);
        
        try {
            // Check if already in wishlist
            $checkSql = "SELECT * FROM wishlist WHERE user_id = ? AND product_id = ?";
            $stmt = $conn->prepare($checkSql);
            $stmt->bind_param("ii", $user_id, $product_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows == 0) {
                // Insert into wishlist
                $sql = "INSERT INTO wishlist (user_id, product_id) VALUES (?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ii", $user_id, $product_id);
                
                if ($stmt->execute()) {
                    echo json_encode(["success" => true]);
                } else {
                    echo json_encode(["success" => false, "message" => "Database error: " . $stmt->error]);
                }
            } else {
                echo json_encode(["success" => false, "message" => "Already in wishlist"]);
            }
        } catch (Exception $e) {
            error_log("Exception: " . $e->getMessage());
            echo json_encode(["success" => false, "message" => "Exception: " . $e->getMessage()]);
        }
    } else {
        echo json_encode(["success" => false, "message" => "No product_id provided"]);
    }
} else {
    echo json_encode(["success" => false, "message" => "Invalid request method"]);
}
?>

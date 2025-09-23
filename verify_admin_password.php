<?php
session_start();
require_once 'dbconn.php';

header('Content-Type: application/json');

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$userRole = strtolower(trim($_SESSION['role']));
if ($userRole !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Admin role required. Current role: ' . $_SESSION['role']]);
    exit();
}

// Get the password from POST data
$password = isset($_POST['password']) ? $_POST['password'] : '';

if (empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Password is required']);
    exit();
}

try {
    // Get the current admin's password from database (using cjusers table for admin)
    $adminId = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT password FROM cjusers WHERE id = ? AND role = 'Admin'");
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Admin not found']);
        exit();
    }
    
    $admin = $result->fetch_assoc();
    $hashedPassword = $admin['password'];
    
    // Verify the password
    if (password_verify($password, $hashedPassword)) {
        // Password is correct
        echo json_encode(['success' => true, 'message' => 'Password verified']);
        
        // Log this security action
        $logMessage = "Admin password verification for clean record suspension - User ID: " . $adminId . " - Time: " . date('Y-m-d H:i:s');
        error_log($logMessage);
        
    } else {
        // Password is incorrect
        echo json_encode(['success' => false, 'message' => 'Incorrect password']);
        
        // Log failed attempt
        $logMessage = "Failed admin password verification attempt - User ID: " . $adminId . " - Time: " . date('Y-m-d H:i:s');
        error_log($logMessage);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

$conn->close();
?>

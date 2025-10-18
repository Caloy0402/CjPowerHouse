<?php
session_start();
require_once 'dbconn.php';

// Check if the user is logged in and has the 'Mechanic' role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Mechanic') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Get JSON input
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Validate required fields
if (!isset($data['request_id']) || !isset($data['status']) || !isset($data['mechanic_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

$request_id = $data['request_id'];
$status = $data['status'];
$mechanic_id = $data['mechanic_id'];

// Validate status values
$valid_statuses = ['Pending', 'In Progress', 'Completed', 'Cancelled'];
if (!in_array($status, $valid_statuses)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid status value']);
    exit();
}

// Update the help request status
$sql = "UPDATE help_requests SET status = ?, mechanic_id = ?, updated_at = NOW() WHERE id = ?";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    exit();
}

$stmt->bind_param("sii", $status, $mechanic_id, $request_id);

if ($stmt->execute()) {
    // If status is completed, add completion timestamp
    if ($status === 'Completed') {
        $completion_sql = "UPDATE help_requests SET completed_at = NOW() WHERE id = ?";
        $completion_stmt = $conn->prepare($completion_sql);
        if ($completion_stmt) {
            $completion_stmt->bind_param("i", $request_id);
            $completion_stmt->execute();
            $completion_stmt->close();
        }
    }
    
    // If status is cancelled, add cancellation timestamp
    if ($status === 'Cancelled') {
        $cancellation_sql = "UPDATE help_requests SET cancelled_at = NOW() WHERE id = ?";
        $cancellation_stmt = $conn->prepare($cancellation_sql);
        if ($cancellation_stmt) {
            $cancellation_stmt->bind_param("i", $request_id);
            $cancellation_stmt->execute();
            $cancellation_stmt->close();
        }
    }
    
    echo json_encode(['success' => true, 'message' => 'Request status updated successfully']);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error updating request: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
?> 
<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'dbconn.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
    exit;
}

// Validate required fields
if (!isset($input['request_id']) || !isset($input['reason']) || !isset($input['reason_text'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

$request_id = intval($input['request_id']);
$reason = $input['reason'];
$reason_text = $input['reason_text'];

// Validate request ID
if ($request_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
    exit;
}

try {
    // Log the incoming request for debugging
    error_log("Decline request received: " . json_encode($input));
    
    // Start transaction
    $conn->begin_transaction();
    
    // First, get the request details to verify it exists and get customer info
    $stmt = $conn->prepare("
        SELECT hr.*, u.first_name, u.last_name, hr.contact_info, u.device_token
        FROM help_requests hr 
        LEFT JOIN users u ON hr.user_id = u.id 
        WHERE hr.id = ? AND hr.status = 'Pending'
    ");
    $stmt->bind_param("i", $request_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Request not found or already processed']);
        exit;
    }
    
    $request = $result->fetch_assoc();
    
    // Update the help request status to declined
    $stmt = $conn->prepare("
        UPDATE help_requests 
        SET status = 'declined', 
            declined_at = NOW(), 
            decline_reason = ?, 
            decline_reason_text = ?
        WHERE id = ?
    ");
    $stmt->bind_param("ssi", $reason, $reason_text, $request_id);
    
    if (!$stmt->execute()) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Failed to update request status']);
        exit;
    }
    
    // Insert notification for the customer (check if notifications table exists and has title column)
    $notification_message = "Your help request has been declined. Reason: " . $reason_text;
    
    // First check if notifications table exists and what columns it has
    $checkTable = $conn->query("SHOW TABLES LIKE 'notifications'");
    if ($checkTable->num_rows > 0) {
        // Check if title column exists
        $checkTitle = $conn->query("SHOW COLUMNS FROM notifications LIKE 'title'");
        if ($checkTitle->num_rows > 0) {
            // Table has title column, use full insert
            $stmt = $conn->prepare("
                INSERT INTO notifications (user_id, title, message, type, related_id, created_at) 
                VALUES (?, 'Request Declined', ?, 'request_declined', ?, NOW())
            ");
            $stmt->bind_param("isi", $request['user_id'], $notification_message, $request_id);
        } else {
            // Table exists but no title column, use simplified insert
            $stmt = $conn->prepare("
                INSERT INTO notifications (user_id, message, type, related_id, created_at) 
                VALUES (?, ?, 'request_declined', ?, NOW())
            ");
            $stmt->bind_param("isi", $request['user_id'], $notification_message, $request_id);
        }
        
        if (!$stmt->execute()) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Failed to create notification: ' . $stmt->error]);
            exit;
        }
    } else {
        // Notifications table doesn't exist, skip notification creation
        error_log("Notifications table does not exist, skipping notification creation");
    }
    
    // Commit transaction
    $conn->commit();
    
    // Send push notification to customer's mobile device
    if (!empty($request['device_token'])) {
        sendPushNotification($request['device_token'], 'Request Declined', $notification_message);
    }
    
    // Log the decline action
    error_log("Help request {$request_id} declined by mechanic. Reason: {$reason_text}");
    
    echo json_encode([
        'success' => true, 
        'message' => 'Request declined successfully',
        'request_id' => $request_id,
        'customer_name' => $request['first_name'] . ' ' . $request['last_name']
    ]);
    
} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
    }
    error_log("Error declining help request: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    echo json_encode([
        'success' => false, 
        'message' => 'Database error occurred: ' . $e->getMessage(),
        'debug' => $e->getTraceAsString()
    ]);
}

function sendPushNotification($deviceToken, $title, $message) {
    // Firebase Cloud Messaging configuration
    $serverKey = 'YOUR_FIREBASE_SERVER_KEY'; // Replace with your actual Firebase server key
    
    $data = [
        'to' => $deviceToken,
        'notification' => [
            'title' => $title,
            'body' => $message,
            'sound' => 'default',
            'badge' => 1
        ],
        'data' => [
            'type' => 'request_declined',
            'title' => $title,
            'message' => $message
        ]
    ];
    
    $headers = [
        'Authorization: key=' . $serverKey,
        'Content-Type: application/json'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://fcm.googleapis.com/fcm/send');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    
    $result = curl_exec($ch);
    curl_close($ch);
    
    // Log push notification result
    error_log("Push notification sent to {$deviceToken}: " . $result);
    
    return $result;
}

$conn->close();
?>

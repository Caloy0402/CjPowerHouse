<?php
session_start();
require_once 'dbconn.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['staff_id']) || !isset($input['staff_type']) || !isset($input['is_active'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit();
}

$staffId = (int)$input['staff_id'];
$staffType = trim($input['staff_type']);
$isActive = (int)$input['is_active'];

try {
    // Determine which table to update
    $tableName = '';
    switch ($staffType) {
        case 'cashier':
            $tableName = 'cjusers';
            break;
        case 'mechanic':
            $tableName = 'mechanics';
            break;
        case 'rider':
            $tableName = 'riders';
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid staff type']);
            exit();
    }
    
    // Check if is_active column exists, if not create it
    $checkColumn = $conn->query("SHOW COLUMNS FROM `{$tableName}` LIKE 'is_active'");
    if ($checkColumn->num_rows == 0) {
        $conn->query("ALTER TABLE `{$tableName}` ADD COLUMN `is_active` TINYINT(1) DEFAULT 1");
    }
    
    // Update the staff status
    $sql = "UPDATE `{$tableName}` SET is_active = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("ii", $isActive, $staffId);
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            echo json_encode([
                'success' => true,
                'message' => "Staff status updated to " . ($isActive ? 'active' : 'inactive') . " successfully"
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'No changes made or staff member not found'
            ]);
        }
        
        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

$conn->close();
?>


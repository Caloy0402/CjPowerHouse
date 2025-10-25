<?php
session_start();
require_once 'dbconn.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

// Ensure tracking table exists
$conn->query("CREATE TABLE IF NOT EXISTS order_restocks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL UNIQUE,
  admin_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_order_id (order_id)
)");

$action = isset($_GET['action']) ? $_GET['action'] : '';
$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : (isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0);

if ($action === 'check') {
    if ($orderId <= 0) { echo json_encode(['success' => true, 'restocked' => false]); exit; }
    $stmt = $conn->prepare("SELECT 1 FROM order_restocks WHERE order_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        echo json_encode(['success' => true, 'restocked' => $exists]);
        exit;
    }
    echo json_encode(['success' => true, 'restocked' => false]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method']);
    exit;
}

if ($orderId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order ID']);
    exit;
}

// Prevent duplicate restock
$already = $conn->prepare("SELECT 1 FROM order_restocks WHERE order_id = ? LIMIT 1");
if ($already) {
    $already->bind_param('i', $orderId);
    $already->execute();
    $already->store_result();
    if ($already->num_rows > 0) {
        $already->close();
        echo json_encode(['success' => false, 'message' => 'This return is already restocked.']);
        exit;
    }
    $already->close();
}

// Verify order exists and is Returned
$check = $conn->prepare("SELECT order_status, delivery_method FROM orders WHERE id = ?");
if (!$check) { echo json_encode(['success' => false, 'message' => 'Order not found']); exit; }
$check->bind_param('i', $orderId);
$check->execute();
$res = $check->get_result();
if ($res->num_rows === 0) { $check->close(); echo json_encode(['success' => false, 'message' => 'Order not found']); exit; }
$row = $res->fetch_assoc();
$check->close();
if (strtolower($row['order_status']) !== 'returned') {
    echo json_encode(['success' => false, 'message' => 'Only returned orders can be restocked.']);
    exit;
}

// Determine which table to use based on delivery method
$table_name = (strtolower($row['delivery_method']) === 'pickup') ? 'products' : 'products';

$conn->begin_transaction();
try {
    // Load items
    $itemsStmt = $conn->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
    if ($itemsStmt === false) { throw new Exception('Failed to prepare items query'); }
    $itemsStmt->bind_param('i', $orderId);
    $itemsStmt->execute();
    $itemsRes = $itemsStmt->get_result();
        while ($it = $itemsRes->fetch_assoc()) {
            $pid = (int)$it['product_id'];
            $qty = (float)$it['quantity'];

            // Use correct column name based on delivery method (both use ProductID currently)
            $column_name = 'ProductID';
            $upd = $conn->prepare("UPDATE products SET Quantity = Quantity + ? WHERE $column_name = ?");
            if ($upd === false) { throw new Exception('Failed to prepare product update'); }
            $upd->bind_param('di', $qty, $pid);
            if (!$upd->execute()) { $upd->close(); throw new Exception('Product update failed'); }
            $upd->close();
        }
    $itemsStmt->close();

    // Log restock
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $log = $conn->prepare("INSERT INTO order_restocks (order_id, admin_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE admin_id = VALUES(admin_id)");
    if ($log === false) { throw new Exception('Failed to prepare restock log'); }
    $log->bind_param('ii', $orderId, $userId);
    if (!$log->execute()) { $log->close(); throw new Exception('Failed to log restock'); }
    $log->close();

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Items restocked back to inventory.']);
    exit;
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    exit;
}
?>



<?php
session_start();
require_once 'dbconn.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userId = (int)$_SESSION['user_id'];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request');
    }
    if (!isset($_POST['feedback_id'])) {
        throw new Exception('Feedback ID is required');
    }
    $fid = (int)$_POST['feedback_id'];

    // Verify ownership
    $stmt = $conn->prepare('SELECT id, user_id FROM product_feedback WHERE id = ?');
    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
    $stmt->bind_param('i', $fid);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new Exception('Feedback not found');
    }
    if ((int)$row['user_id'] !== $userId) {
        throw new Exception('You can only delete your own feedback');
    }

    // Delete reactions first (FK-safe)
    $dr = $conn->prepare('DELETE FROM feedback_reactions WHERE feedback_id = ?');
    if ($dr) { $dr->bind_param('i', $fid); $dr->execute(); $dr->close(); }

    // Delete feedback
    $df = $conn->prepare('DELETE FROM product_feedback WHERE id = ?');
    if (!$df) throw new Exception('Prepare failed: ' . $conn->error);
    $df->bind_param('i', $fid);
    $ok = $df->execute();
    $df->close();

    if (!$ok) throw new Exception('Failed to delete feedback');

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} finally {
    $conn->close();
}
?>



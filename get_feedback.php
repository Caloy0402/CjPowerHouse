<?php
session_start();
require_once 'dbconn.php';

header('Content-Type: application/json');

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

$response = [];

try {
    if (!isset($_GET['product_id'])) {
        throw new Exception('Product ID is required.');
    }

    $product_id = intval($_GET['product_id']);

    // Fetch feedback with user details and reaction aggregates
    $sql = "SELECT pf.*, u.first_name, u.last_name, u.ImagePath
            FROM product_feedback pf
            JOIN users u ON pf.user_id = u.id
            WHERE pf.product_id = ?
            ORDER BY pf.created_at DESC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Failed to prepare SQL statement: ' . $conn->error);
    }

    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $feedback = [];
    while ($row = $result->fetch_assoc()) {
        // Get reaction counts for each feedback
        $counts = [];
        $qr = $conn->prepare('SELECT reaction_type, COUNT(*) cnt FROM feedback_reactions WHERE feedback_id = ? GROUP BY reaction_type');
        if ($qr) {
            $fid = (int)$row['id'];
            $qr->bind_param('i', $fid);
            $qr->execute();
            $rs = $qr->get_result();
            while ($r = $rs->fetch_assoc()) { $counts[$r['reaction_type']] = (int)$r['cnt']; }
            $qr->close();
        }
        $row['reactions'] = $counts;
        $feedback[] = $row;
    }

    $response = $feedback;

    $stmt->close();
} catch (Exception $e) {
    $response = ['error' => $e->getMessage()];
} finally {
    $conn->close();
    echo json_encode($response);
}
?>
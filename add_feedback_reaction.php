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
    
    if (!isset($_POST['feedback_id']) || !isset($_POST['reaction'])) {
        throw new Exception('Feedback ID and reaction are required');
    }
    
    $feedbackId = (int)$_POST['feedback_id'];
    $reaction = trim($_POST['reaction']);
    
    // Validate reaction type
    $validReactions = ['like', 'love', 'care', 'haha', 'wow', 'sad', 'angry'];
    if (!in_array($reaction, $validReactions, true)) {
        throw new Exception('Invalid reaction type');
    }
    
    // Check if user already reacted to this feedback
    $checkStmt = $conn->prepare('SELECT id, reaction_type FROM feedback_reactions WHERE feedback_id = ? AND user_id = ?');
    if (!$checkStmt) throw new Exception('Prepare failed: ' . $conn->error);
    $checkStmt->bind_param('ii', $feedbackId, $userId);
    $checkStmt->execute();
    $existing = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();
    
    if ($existing) {
        // User already reacted - toggle: if same reaction, remove it; otherwise update
        if ($existing['reaction_type'] === $reaction) {
            // Remove reaction
            $delStmt = $conn->prepare('DELETE FROM feedback_reactions WHERE feedback_id = ? AND user_id = ?');
            if (!$delStmt) throw new Exception('Prepare failed: ' . $conn->error);
            $delStmt->bind_param('ii', $feedbackId, $userId);
            $delStmt->execute();
            $delStmt->close();
            
            echo json_encode([
                'success' => true,
                'user_reaction' => null,
                'message' => 'Reaction removed',
                'counts' => []
            ]);
        } else {
            // Update to new reaction
            $updateStmt = $conn->prepare('UPDATE feedback_reactions SET reaction_type = ? WHERE feedback_id = ? AND user_id = ?');
            if (!$updateStmt) throw new Exception('Prepare failed: ' . $conn->error);
            $updateStmt->bind_param('sii', $reaction, $feedbackId, $userId);
            $updateStmt->execute();
            $updateStmt->close();
        }
    } else {
        // Insert new reaction
        $insertStmt = $conn->prepare('INSERT INTO feedback_reactions (feedback_id, user_id, reaction_type) VALUES (?, ?, ?)');
        if (!$insertStmt) throw new Exception('Prepare failed: ' . $conn->error);
        $insertStmt->bind_param('iis', $feedbackId, $userId, $reaction);
        $insertStmt->execute();
        $insertStmt->close();
    }
    
    // Get updated counts for all reactions
    $countStmt = $conn->prepare('SELECT reaction_type, COUNT(*) as cnt FROM feedback_reactions WHERE feedback_id = ? GROUP BY reaction_type');
    if (!$countStmt) throw new Exception('Prepare failed: ' . $conn->error);
    $countStmt->bind_param('i', $feedbackId);
    $countStmt->execute();
    $result = $countStmt->get_result();
    $counts = [];
    while ($row = $result->fetch_assoc()) {
        $counts[$row['reaction_type']] = (int)$row['cnt'];
    }
    $countStmt->close();
    
    // Get user's current reaction
    $userReactionStmt = $conn->prepare('SELECT reaction_type FROM feedback_reactions WHERE feedback_id = ? AND user_id = ?');
    if ($userReactionStmt) {
        $userReactionStmt->bind_param('ii', $feedbackId, $userId);
        $userReactionStmt->execute();
        $userReactionResult = $userReactionStmt->get_result();
        $userReactionRow = $userReactionResult->fetch_assoc();
        $userReactionStmt->close();
        $userReaction = $userReactionRow ? $userReactionRow['reaction_type'] : null;
    } else {
        $userReaction = null;
    }
    
    echo json_encode([
        'success' => true,
        'user_reaction' => $userReaction,
        'message' => 'Reaction saved',
        'counts' => $counts
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} finally {
    $conn->close();
}
?>


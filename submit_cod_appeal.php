<?php
session_start();
require_once 'dbconn.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
	echo json_encode(['success' => false, 'message' => 'Not logged in']);
	exit;
}

$userId = (int)$_SESSION['user_id'];
$appeal = isset($_POST['appeal']) ? trim($_POST['appeal']) : '';
$forceReplace = isset($_POST['force_replace']) ? (bool)$_POST['force_replace'] : false;

if ($appeal === '') {
	echo json_encode(['success' => false, 'message' => 'Please provide an appeal message.']);
	exit;
}

// Ensure table exists
$conn->query("CREATE TABLE IF NOT EXISTS cod_appeals (
	id INT AUTO_INCREMENT PRIMARY KEY,
	user_id INT NOT NULL,
	appeal_text TEXT NOT NULL,
	status ENUM('pending','resolved') NOT NULL DEFAULT 'pending',
	reviewed_by INT NULL,
	reviewed_at DATETIME NULL,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	INDEX idx_user_status (user_id, status),
	FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

// Check if user already has a pending appeal
$checkStmt = $conn->prepare('SELECT id FROM cod_appeals WHERE user_id = ? AND status = "pending"');
$checkStmt->bind_param('i', $userId);
$checkStmt->execute();
$existingAppeal = $checkStmt->get_result()->fetch_assoc();
$checkStmt->close();

if ($existingAppeal && !$forceReplace) {
	// User already has a pending appeal, ask for confirmation
	echo json_encode([
		'success' => false, 
		'has_existing_appeal' => true,
		'message' => 'You already have a pending appeal. Would you like to replace it?'
	]);
	exit;
}

if ($existingAppeal && $forceReplace) {
	// Update existing appeal
	$stmt = $conn->prepare('UPDATE cod_appeals SET appeal_text = ?, created_at = NOW() WHERE user_id = ? AND status = "pending"');
	if (!$stmt) {
		echo json_encode(['success' => false, 'message' => 'Database error.']);
		exit;
	}
	$stmt->bind_param('si', $appeal, $userId);
	$ok = $stmt->execute();
	$stmt->close();
} else {
	// Insert new appeal
	$stmt = $conn->prepare('INSERT INTO cod_appeals (user_id, appeal_text) VALUES (?, ?)');
	if (!$stmt) {
		echo json_encode(['success' => false, 'message' => 'Database error.']);
		exit;
	}
	$stmt->bind_param('is', $userId, $appeal);
	$ok = $stmt->execute();
	$stmt->close();
}

if ($ok) {
	$message = $existingAppeal ? 'Appeal updated successfully. We will review it soon.' : 'Appeal submitted. We will review it soon.';
	echo json_encode(['success' => true, 'message' => $message]);
} else {
	echo json_encode(['success' => false, 'message' => 'Failed to submit appeal.']);
}
?>



<?php
session_start();
header('Content-Type: application/json');
require_once 'dbconn.php';
require_once 'email_config.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$email = isset($_POST['email']) ? trim($_POST['email']) : '';
if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please provide a valid email.']);
    exit;
}

// Find user by email
$stmt = $conn->prepare('SELECT id, first_name FROM users WHERE email = ? LIMIT 1');
if (!$stmt) { echo json_encode(['success' => true, 'message' => 'If the email exists, a reset link has been sent.']); exit; }
$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    // Do not reveal user existence
    echo json_encode(['success' => true, 'message' => 'If the email exists, a reset link has been sent.']);
    exit;
}
$user = $result->fetch_assoc();
$stmt->close();

// Create tokens table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS password_resets (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, token VARCHAR(64) NOT NULL, expires_at DATETIME NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(token)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$token = bin2hex(random_bytes(16));
$expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour

// Upsert: delete old tokens for user then insert new
$del = $conn->prepare('DELETE FROM password_resets WHERE user_id = ?');
if ($del) { $del->bind_param('i', $user['id']); $del->execute(); $del->close(); }

$ins = $conn->prepare('INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)');
if ($ins) { $ins->bind_param('iss', $user['id'], $token, $expires); $ins->execute(); $ins->close(); }

$resetLink = WEBSITE_URL . '/reset_password.php?token=' . urlencode($token);

// Send email
try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = SMTP_HOST; $mail->SMTPAuth = true; $mail->Username = SMTP_USERNAME; $mail->Password = SMTP_PASSWORD; $mail->SMTPSecure = SMTP_ENCRYPTION; $mail->Port = SMTP_PORT;
    $mail->setFrom(FROM_EMAIL, FROM_NAME);
    $mail->addAddress($email, $user['first_name'] ?? '');
    $mail->Subject = 'Reset your password - ' . WEBSITE_NAME;
    $body  = '<p>Hello ' . htmlspecialchars($user['first_name'] ?? '') . ',</p>';
    $body .= '<p>We received a request to reset your password. Click the link below to set a new one. This link expires in 1 hour.</p>';
    $body .= '<p><a href="' . htmlspecialchars($resetLink) . '">Reset Password</a></p>';
    $body .= '<p>If you did not request this, you can ignore this email.</p>';
    $mail->isHTML(true);
    $mail->Body = $body;
    $mail->AltBody = "Open this link to reset your password: $resetLink";
    $mail->send();
    echo json_encode(['success' => true, 'message' => 'If the email exists, a reset link has been sent.']);
} catch (Exception $e) {
    echo json_encode(['success' => true, 'message' => 'If the email exists, a reset link has been sent.']);
}
?>




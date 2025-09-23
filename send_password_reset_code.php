<?php
session_start();
header('Content-Type: application/json');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/debug_reset_code.log');

require_once __DIR__ . '/dbconn.php';
require_once __DIR__ . '/email_config.php';

// Prefer same robust PHPMailer loading used by signup flow
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    $manual_paths = [
        __DIR__ . DIRECTORY_SEPARATOR . 'PHPMailer' . DIRECTORY_SEPARATOR . 'PHPMailer.php',
        __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'PHPMailer' . DIRECTORY_SEPARATOR . 'PHPMailer.php'
    ];
    foreach ($manual_paths as $path) {
        if (file_exists($path)) {
            $phpmailer_dir = dirname($path);
            require_once $phpmailer_dir . '/PHPMailer.php';
            require_once $phpmailer_dir . '/SMTP.php';
            require_once $phpmailer_dir . '/Exception.php';
            break;
        }
    }
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

$email = isset($_POST['email']) ? trim($_POST['email']) : '';
if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please provide a valid email.']);
    exit;
}

// Lookup the user
$stmt = $conn->prepare('SELECT id, first_name FROM users WHERE email = ? LIMIT 1');
if (!$stmt) { echo json_encode(['success' => true, 'message' => 'If the email exists, a code has been sent.']); exit; }
$stmt->bind_param('s', $email);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'No account found with that email.']);
    exit;
}
$user = $res->fetch_assoc();
$stmt->close();

// Ensure table exists
$conn->query("CREATE TABLE IF NOT EXISTS password_reset_codes (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, code VARCHAR(6) NOT NULL, expires_at DATETIME NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(user_id), INDEX(code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Generate code and expiry (15 minutes)
$code = sprintf('%06d', mt_rand(0, 999999));
$expiresAt = date('Y-m-d H:i:s', time() + 15 * 60);

// Remove previous codes for this user
$del = $conn->prepare('DELETE FROM password_reset_codes WHERE user_id = ?');
if ($del) { $del->bind_param('i', $user['id']); $del->execute(); $del->close(); }

// Insert new code
$ins = $conn->prepare('INSERT INTO password_reset_codes (user_id, code, expires_at) VALUES (?, ?, ?)');
if ($ins) { $ins->bind_param('iss', $user['id'], $code, $expiresAt); $ins->execute(); $ins->close(); }

// Send email with code
try {
    $mail = new PHPMailer(true);
    // Enable SMTP debug to error_log
    $mail->SMTPDebug = SMTP::DEBUG_SERVER; // verbose
    $mail->Debugoutput = function($str){ error_log('SMTP: ' . $str); };
    $mail->isSMTP();
    $mail->Host = SMTP_HOST; $mail->SMTPAuth = true; $mail->Username = SMTP_USERNAME; $mail->Password = SMTP_PASSWORD; $mail->SMTPSecure = SMTP_ENCRYPTION; $mail->Port = SMTP_PORT;
    $mail->setFrom(FROM_EMAIL, FROM_NAME);
    $mail->addAddress($email, $user['first_name'] ?? '');
    $mail->Subject = 'Your password reset code - ' . WEBSITE_NAME;
    $body  = '<p>Hello ' . htmlspecialchars($user['first_name'] ?? '') . ',</p>';
    $body .= '<p>Use the code below to reset your password. This code expires in 15 minutes.</p>';
    $body .= '<p style="font-size:22px;font-weight:bold;letter-spacing:4px;background:#ff9900;color:#fff;display:inline-block;padding:8px 12px;border-radius:6px;">' . $code . '</p>';
    $body .= '<p>Go to ' . htmlspecialchars(WEBSITE_URL) . '/enter_reset_code.php?email=' . urlencode($email) . ' to enter the code and set a new password.</p>';
    $mail->isHTML(true);
    $mail->Body = $body;
    $mail->AltBody = "Your password reset code is: $code";
    $mail->send();
    echo json_encode(['success' => true, 'message' => 'If the email exists, a code has been sent.', 'redirect' => 'enter_reset_code.php?email=' . urlencode($email)]);
} catch (Exception $e) {
    error_log('send_password_reset_code mail error: ' . $e->getMessage());
    echo json_encode(['success' => true, 'message' => 'If the email exists, a code has been sent.']);
}
?>



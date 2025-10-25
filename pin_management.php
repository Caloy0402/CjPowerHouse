<?php
session_start();
require_once 'dbconn.php';

header('Content-Type: application/json');

// Debug logging (uncomment for debugging)
// error_log("PIN Management - Session ID: " . session_id());
// error_log("PIN Management - User ID in session: " . ($_SESSION['user_id'] ?? 'NOT SET'));
// error_log("PIN Management - POST data: " . print_r($_POST, true));

if (!isset($_SESSION['user_id'])) {
    error_log("PIN Management - Unauthorized access attempt");
    echo json_encode(['success' => false, 'message' => 'Unauthorized - Please log in again']);
    exit;
}

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'get_status':
            // Get current PIN status
            $stmt = $conn->prepare("SELECT pin_enabled FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();
            
            echo json_encode([
                'success' => true,
                'pin_enabled' => (bool)$user['pin_enabled']
            ]);
            break;
            
        case 'set_pin':
            $pin = $_POST['pin'] ?? '';
            $confirm_pin = $_POST['confirm_pin'] ?? '';
            
            // error_log("PIN Management - Setting PIN for user $user_id");
            
            if (strlen($pin) !== 4 || !ctype_digit($pin)) {
                throw new Exception('PIN must be exactly 4 digits');
            }
            
            if ($pin !== $confirm_pin) {
                throw new Exception('PIN confirmation does not match');
            }
            
            // Hash the PIN
            $hashed_pin = password_hash($pin, PASSWORD_DEFAULT);
            // error_log("PIN Management - PIN hashed successfully");
            
            // Insert or update PIN
            $stmt = $conn->prepare("INSERT INTO user_pin_codes (user_id, pin_code, is_enabled) VALUES (?, ?, TRUE) 
                                   ON DUPLICATE KEY UPDATE pin_code = VALUES(pin_code), is_enabled = TRUE");
            if (!$stmt) {
                throw new Exception('Database prepare failed: ' . $conn->error);
            }
            $stmt->bind_param("is", $user_id, $hashed_pin);
            if (!$stmt->execute()) {
                throw new Exception('Database execute failed: ' . $stmt->error);
            }
            $stmt->close();
            // error_log("PIN Management - PIN inserted/updated in user_pin_codes table");
            
            // Update users table
            $stmt = $conn->prepare("UPDATE users SET pin_enabled = TRUE WHERE id = ?");
            if (!$stmt) {
                throw new Exception('Database prepare failed for users table: ' . $conn->error);
            }
            $stmt->bind_param("i", $user_id);
            if (!$stmt->execute()) {
                throw new Exception('Database execute failed for users table: ' . $stmt->error);
            }
            $stmt->close();
            // error_log("PIN Management - Users table updated successfully");
            
            echo json_encode(['success' => true, 'message' => 'PIN set successfully']);
            break;
            
        case 'toggle_pin':
            $enabled = $_POST['enabled'] === 'true';
            
            // If trying to enable PIN protection, check if PIN exists first
            if ($enabled) {
                $stmt = $conn->prepare("SELECT pin_code FROM user_pin_codes WHERE user_id = ?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $pin_data = $result->fetch_assoc();
                $stmt->close();
                
                if (!$pin_data || !$pin_data['pin_code']) {
                    throw new Exception('Please set a PIN code first before enabling PIN protection');
                }
            }
            
            // Update users table
            $stmt = $conn->prepare("UPDATE users SET pin_enabled = ? WHERE id = ?");
            $stmt->bind_param("ii", $enabled, $user_id);
            $stmt->execute();
            $stmt->close();
            
            // Update pin_codes table
            $stmt = $conn->prepare("UPDATE user_pin_codes SET is_enabled = ? WHERE user_id = ?");
            $stmt->bind_param("ii", $enabled, $user_id);
            $stmt->execute();
            $stmt->close();
            
            echo json_encode([
                'success' => true, 
                'message' => $enabled ? 'PIN protection enabled' : 'PIN protection disabled',
                'pin_enabled' => $enabled
            ]);
            break;
            
        case 'verify_pin':
            $pin = $_POST['pin'] ?? '';
            
            if (strlen($pin) !== 4 || !ctype_digit($pin)) {
                throw new Exception('Invalid PIN format');
            }
            
            // Get user's PIN
            $stmt = $conn->prepare("SELECT pin_code FROM user_pin_codes WHERE user_id = ? AND is_enabled = TRUE");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $pin_data = $result->fetch_assoc();
            $stmt->close();
            
            if (!$pin_data) {
                throw new Exception('No PIN set for this user');
            }
            
            if (password_verify($pin, $pin_data['pin_code'])) {
                echo json_encode(['success' => true, 'message' => 'PIN verified']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid PIN']);
            }
            break;
            
        case 'secure_toggle_pin':
            $pin = $_POST['pin'] ?? '';
            $enabled = $_POST['enabled'] === 'true';
            
            // First verify the PIN if trying to disable
            if (!$enabled) {
                if (strlen($pin) !== 4 || !ctype_digit($pin)) {
                    throw new Exception('Invalid PIN format');
                }
                
                // Get user's PIN
                $stmt = $conn->prepare("SELECT pin_code FROM user_pin_codes WHERE user_id = ? AND is_enabled = TRUE");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $pin_data = $result->fetch_assoc();
                $stmt->close();
                
                if (!$pin_data) {
                    throw new Exception('No PIN set for this user');
                }
                
                if (!password_verify($pin, $pin_data['pin_code'])) {
                    throw new Exception('Invalid PIN. Cannot disable PIN protection.');
                }
            }
            
            // Update users table
            $stmt = $conn->prepare("UPDATE users SET pin_enabled = ? WHERE id = ?");
            $stmt->bind_param("ii", $enabled, $user_id);
            $stmt->execute();
            $stmt->close();
            
            // Update pin_codes table
            $stmt = $conn->prepare("UPDATE user_pin_codes SET is_enabled = ? WHERE user_id = ?");
            $stmt->bind_param("ii", $enabled, $user_id);
            $stmt->execute();
            $stmt->close();
            
            echo json_encode([
                'success' => true, 
                'message' => $enabled ? 'PIN protection enabled' : 'PIN protection disabled',
                'pin_enabled' => $enabled
            ]);
            break;
            
        case 'send_pin_email':
            // Get user's email and current PIN
            $stmt = $conn->prepare("SELECT u.email, u.first_name, upc.pin_code FROM users u 
                                   LEFT JOIN user_pin_codes upc ON u.id = upc.user_id 
                                   WHERE u.id = ? AND upc.is_enabled = TRUE");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user_data = $result->fetch_assoc();
            $stmt->close();
            
            if (!$user_data || !$user_data['pin_code']) {
                throw new Exception('No PIN set for this user');
            }
            
            // We can't retrieve the original PIN since it's hashed, so we'll generate a new one
            $new_pin = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
            $hashed_pin = password_hash($new_pin, PASSWORD_DEFAULT);
            
            // Update the PIN in database
            $stmt = $conn->prepare("UPDATE user_pin_codes SET pin_code = ? WHERE user_id = ?");
            $stmt->bind_param("si", $hashed_pin, $user_id);
            $stmt->execute();
            $stmt->close();
            
            // Send email with new PIN
            require_once 'PHPMailer/PHPMailer.php';
            require_once 'PHPMailer/SMTP.php';
            require_once 'PHPMailer/Exception.php';
            require_once 'email_config.php';
            
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            
            try {
                // Server settings
                $mail->isSMTP();
                $mail->Host = SMTP_HOST;
                $mail->SMTPAuth = true;
                $mail->Username = SMTP_USERNAME;
                $mail->Password = SMTP_PASSWORD;
                $mail->SMTPSecure = SMTP_ENCRYPTION;
                $mail->Port = SMTP_PORT;
                
                // Recipients
                $mail->setFrom(FROM_EMAIL, FROM_NAME);
                $mail->addAddress($user_data['email'], $user_data['first_name']);
                
                // Content
                $mail->isHTML(true);
                $mail->Subject = 'Your PIN Code - CJ PowerHouse';
                
                $email_body = '
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset="UTF-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                    <title>Your PIN Code</title>
                    <style>
                        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .header { background-color: #dc3545; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
                        .content { background-color: #f9f9f9; padding: 30px; border-radius: 0 0 5px 5px; }
                        .pin-box { background-color: #dc3545; color: white; padding: 20px; text-align: center; border-radius: 5px; margin: 20px 0; font-size: 24px; font-weight: bold; letter-spacing: 5px; }
                        .footer { text-align: center; margin-top: 30px; color: #666; font-size: 14px; }
                        .warning { background-color: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin: 20px 0; }
                        .instructions { background-color: #d1ecf1; border: 1px solid #bee5eb; padding: 15px; border-radius: 5px; margin: 20px 0; }
                    </style>
                </head>
                <body>
                    <div class="container">
                        <div class="header">
                            <h1>CJ PowerHouse</h1>
                            <p>Your PIN Code</p>
                        </div>
                        <div class="content">
                            <h2>Hello ' . htmlspecialchars($user_data['first_name']) . '!</h2>
                            <p>You requested your PIN code. Here is your new 4-digit PIN:</p>
                            
                            <div class="pin-box">
                                ' . $new_pin . '
                            </div>
                            
                            <div class="instructions">
                                <strong>Important:</strong>
                                <ul style="text-align: left; margin: 10px 0;">
                                    <li>This is your new PIN code for CJ PowerHouse</li>
                                    <li>Use this PIN to access protected features</li>
                                    <li>Keep this PIN secure and do not share it</li>
                                </ul>
                            </div>
                            
                            <div class="warning">
                                <strong>Security Note:</strong> If you did not request this PIN reset, please contact our support team immediately.
                            </div>
                            
                            <p>Best regards,<br>The CJ PowerHouse Team</p>
                        </div>
                        <div class="footer">
                            <p>This is an automated email. Please do not reply to this message.</p>
                            <p>&copy; ' . date('Y') . ' CJ PowerHouse. All rights reserved.</p>
                        </div>
                    </div>
                </body>
                </html>';
                
                $mail->Body = $email_body;
                $mail->send();
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'PIN code has been sent to your email address'
                ]);
                
            } catch (Exception $e) {
                throw new Exception('Failed to send email: ' . $e->getMessage());
            }
            break;
            
        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    // error_log("PIN Management - Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>

<?php
require_once 'dbconn.php';
require_once 'email_config.php';

// Check if PHPMailer is available via Composer
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    // Try manual installation paths
    $manual_paths = [
        __DIR__ . DIRECTORY_SEPARATOR . 'PHPMailer' . DIRECTORY_SEPARATOR . 'PHPMailer.php',
        __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'PHPMailer' . DIRECTORY_SEPARATOR . 'PHPMailer.php'
    ];
    
    $phpmailer_found = false;
    foreach ($manual_paths as $path) {
        if (file_exists($path)) {
            $phpmailer_dir = dirname($path);
            
            // Direct include approach - simpler and more reliable
            require_once $phpmailer_dir . '/PHPMailer.php';
            require_once $phpmailer_dir . '/SMTP.php';
            require_once $phpmailer_dir . '/Exception.php';
            
            // Check if classes are available
            if (class_exists('PHPMailer\PHPMailer\PHPMailer') && 
                class_exists('PHPMailer\PHPMailer\SMTP') && 
                class_exists('PHPMailer\PHPMailer\Exception')) {
                $phpmailer_found = true;
                break;
            }
        }
    }
    
    if (!$phpmailer_found) {
        // Debug information
        echo "PHPMailer not found. Debug info:<br>";
        echo "Current directory: " . __DIR__ . "<br>";
        echo "Checking paths:<br>";
        foreach ($manual_paths as $path) {
            echo "- $path: " . (file_exists($path) ? "EXISTS" : "NOT FOUND") . "<br>";
        }
        die('Please install PHPMailer via composer or download manually.');
    }
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class EmailVerification {
    private $conn;
    private $mailer;
    
    public function __construct($conn) {
        $this->conn = $conn;
        $this->setupMailer();
    }
    
    private function setupMailer() {
        $this->mailer = new PHPMailer(true);
        
        try {
            // Server settings
            $this->mailer->isSMTP();
            $this->mailer->Host = SMTP_HOST;
            $this->mailer->SMTPAuth = true;
            $this->mailer->Username = SMTP_USERNAME;
            $this->mailer->Password = SMTP_PASSWORD;
            $this->mailer->SMTPSecure = SMTP_ENCRYPTION;
            $this->mailer->Port = SMTP_PORT;
            
            // Default settings
            $this->mailer->setFrom(FROM_EMAIL, FROM_NAME);
            $this->mailer->addReplyTo(REPLY_TO_EMAIL, FROM_NAME);
            $this->mailer->isHTML(true);
            
        } catch (Exception $e) {
            error_log("PHPMailer setup failed: " . $e->getMessage());
            throw new Exception("Email service configuration failed");
        }
    }
    
    public function sendVerificationEmail($userId, $email, $firstName) {
        try {
            // Generate a 6-digit verification code
            $verificationCode = sprintf('%06d', mt_rand(0, 999999));
            
            // Calculate expiry time
            $expiryTime = date('Y-m-d H:i:s', strtotime('+' . VERIFICATION_TOKEN_EXPIRY_HOURS . ' hours'));
            
            // Update user record with verification code
            $updateSql = "UPDATE users SET 
                          email_verification_token = ?, 
                          email_verification_expires = ?,
                          verification_sent_at = NOW()
                          WHERE id = ?";
            
            $updateStmt = $this->conn->prepare($updateSql);
            $updateStmt->bind_param("ssi", $verificationCode, $expiryTime, $userId);
            $updateStmt->execute();
            
            if ($updateStmt->affected_rows === 0) {
                throw new Exception("Failed to update user verification data");
            }
            
            // Log the verification attempt
            $logSql = "INSERT INTO email_verification_logs (user_id, token, sent_at, expires_at) VALUES (?, ?, NOW(), ?)";
            $logStmt = $this->conn->prepare($logSql);
            $logStmt->bind_param("iss", $userId, $verificationCode, $expiryTime);
            $logStmt->execute();
            
            // Prepare email content
            $emailContent = str_replace(
                ['{FIRST_NAME}', '{VERIFICATION_CODE}'],
                [$firstName, $verificationCode],
                VERIFICATION_EMAIL_TEMPLATE
            );
            
            // Configure email
            $this->mailer->setFrom(FROM_EMAIL, FROM_NAME);
            $this->mailer->addReplyTo(REPLY_TO_EMAIL);
            $this->mailer->addAddress($email);
            $this->mailer->Subject = VERIFICATION_EMAIL_SUBJECT;
            $this->mailer->isHTML(true);
            $this->mailer->Body = $emailContent;
            $this->mailer->AltBody = "Hello {$firstName}! Your verification code is: {$verificationCode}. Go to http://localhost/Motorshop to enter this code.";
            
            // Send email
            if ($this->mailer->send()) {
                $this->mailer->clearAddresses();
                return [
                    'success' => true,
                    'message' => 'Verification code sent successfully',
                    'code' => $verificationCode // For debugging purposes
                ];
            } else {
                throw new Exception("Failed to send email: " . $this->mailer->ErrorInfo);
            }
            
        } catch (Exception $e) {
            error_log("Email sending failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to send verification code: ' . $e->getMessage(),
                'debug_info' => $e->getMessage()
            ];
        }
    }
    
    private function generateVerificationToken() {
        return bin2hex(random_bytes(32));
    }
    
    private function updateUserVerificationToken($userId, $token, $expiresAt) {
        $sql = "UPDATE users SET 
                email_verification_token = ?, 
                email_verification_expires = ?, 
                verification_sent_at = NOW() 
                WHERE id = ?";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ssi", $token, $expiresAt, $userId);
        $stmt->execute();
        $stmt->close();
    }
    
    private function logVerificationAttempt($userId, $email, $token, $expiresAt) {
        $sql = "INSERT INTO email_verification_logs 
                (user_id, email, token, sent_at, expires_at, ip_address, user_agent) 
                VALUES (?, ?, ?, NOW(), ?, ?, ?)";
        
        $stmt = $this->conn->prepare($sql);
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $stmt->bind_param("isssss", $userId, $email, $token, $expiresAt, $ipAddress, $userAgent);
        $stmt->execute();
        $stmt->close();
    }
    
    public function verifyEmail($token) {
        try {
            // Check if token exists and is valid
            $sql = "SELECT u.id, u.email, u.first_name, u.email_verified, u.email_verification_expires 
                    FROM users u 
                    WHERE u.email_verification_token = ? AND u.email_verification_expires > NOW()";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("s", $token);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                return [
                    'success' => false,
                    'message' => 'Invalid or expired verification token'
                ];
            }
            
            $user = $result->fetch_assoc();
            
            if ($user['email_verified']) {
                return [
                    'success' => false,
                    'message' => 'Email is already verified'
                ];
            }
            
            // Mark email as verified
            $updateSql = "UPDATE users SET 
                         email_verified = 1, 
                         email_verification_token = NULL, 
                         email_verification_expires = NULL 
                         WHERE id = ?";
            
            $updateStmt = $this->conn->prepare($updateSql);
            $updateStmt->bind_param("i", $user['id']);
            $updateStmt->execute();
            $updateStmt->close();
            
            // Update verification log
            $logSql = "UPDATE email_verification_logs SET verified_at = NOW() WHERE token = ?";
            $logStmt = $this->conn->prepare($logSql);
            $logStmt->bind_param("s", $token);
            $logStmt->execute();
            $logStmt->close();
            
            return [
                'success' => true,
                'message' => 'Email verified successfully',
                'user_id' => $user['id'],
                'email' => $user['email'],
                'first_name' => $user['first_name']
            ];
            
        } catch (Exception $e) {
            error_log("Email verification failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Verification failed: ' . $e->getMessage()
            ];
        }
    }
    
    public function canResendVerification($email) {
        // Check if user has reached maximum verification attempts today
        $sql = "SELECT COUNT(*) as attempt_count 
                FROM email_verification_logs 
                WHERE email = ? AND DATE(sent_at) = CURDATE()";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        return $row['attempt_count'] < MAX_VERIFICATION_ATTEMPTS;
    }
}

// Function to send verification email (for use in other scripts)
function sendVerificationEmail($userId, $email, $firstName) {
    global $conn;
    $emailVerification = new EmailVerification($conn);
    return $emailVerification->sendVerificationEmail($userId, $email, $firstName);
}

// Function to verify email (for use in other scripts)
function verifyEmailToken($token) {
    global $conn;
    $emailVerification = new EmailVerification($conn);
    return $emailVerification->verifyEmail($token);
}

// Function to check if user can resend verification
function canResendVerification($email) {
    global $conn;
    $emailVerification = new EmailVerification($conn);
    return $emailVerification->canResendVerification($email);
}

// Lightweight helper to send a signup verification code BEFORE creating a DB user row
function sendSignupVerificationEmail($email, $firstName, $verificationCode) {
    // Build a minimal mailer instance using the same config
    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        require_once __DIR__ . '/vendor/autoload.php';
    }
    $mailer = new PHPMailer(true);
    try {
        $mailer->isSMTP();
        $mailer->Host = SMTP_HOST;
        $mailer->SMTPAuth = true;
        $mailer->Username = SMTP_USERNAME;
        $mailer->Password = SMTP_PASSWORD;
        $mailer->SMTPSecure = SMTP_ENCRYPTION;
        $mailer->Port = SMTP_PORT;
        $mailer->setFrom(FROM_EMAIL, FROM_NAME);
        $mailer->addReplyTo(REPLY_TO_EMAIL, FROM_NAME);
        $mailer->addAddress($email);
        $mailer->isHTML(true);
        $mailer->Subject = VERIFICATION_EMAIL_SUBJECT;
        $body = str_replace(
            ['{FIRST_NAME}', '{VERIFICATION_CODE}'],
            [$firstName, $verificationCode],
            VERIFICATION_EMAIL_TEMPLATE
        );
        $mailer->Body = $body;
        $mailer->AltBody = "Hello {$firstName}! Your verification code is: {$verificationCode}. Go to http://localhost/Motorshop to enter this code.";
        $mailer->send();
        return ['success' => true, 'message' => 'Verification code sent successfully'];
    } catch (Exception $e) {
        error_log('Signup code email failed: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to send verification code'];
    }
}
?>

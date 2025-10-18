<?php
session_start();
require_once 'dbconn.php';

$error_message = '';
$success_message = '';
$email = '';

// Get email from session or URL parameter
if (isset($_SESSION['pending_verification']['email'])) {
    $email = $_SESSION['pending_verification']['email'];
} elseif (isset($_GET['email'])) {
    $email = $_GET['email'];
}

// Handle code verification (supports both: existing user row or pre-signup session)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input_email = $_POST['email'] ?? '';
    $verification_code = $_POST['verification_code'] ?? '';
    
    if (empty($input_email) || empty($verification_code)) {
        $error_message = 'Please enter both email and verification code.';
    } else {
        try {
            // Branch 1: Pre-signup flow (no user row yet)
            if (isset($_SESSION['pending_signup']) && $_SESSION['pending_signup']['email'] === $input_email) {
                $pending = $_SESSION['pending_signup'];
                // check code matches and not older than 30 minutes
                if ($pending['code'] !== $verification_code) {
                    $error_message = 'Invalid verification code.';
                } elseif (time() - ($pending['created_at'] ?? 0) > 30 * 60) {
                    $error_message = 'Verification code expired. Please restart signup.';
                } else {
                    // Create user now
                    $conn->begin_transaction();
                    try {
                        // Move buffered profile image into final location
                        $uploadDir = 'uploads/profile_images/';
                        if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
                        $storedPath = '';
                        if (!empty($pending['profile_tmp_path']) && file_exists($pending['profile_tmp_path'])) {
                            $ext = $pending['profile_ext'] ?? 'jpg';
                            $storedPath = $uploadDir . uniqid('user_', true) . '.' . $ext;
                            if (!rename($pending['profile_tmp_path'], $storedPath)) {
                                // fallback to copy
                                if (!copy($pending['profile_tmp_path'], $storedPath)) {
                                    $storedPath = '';
                                } else {
                                    @unlink($pending['profile_tmp_path']);
                                }
                            }
                        }
                        $password_hashed = password_hash($pending['password_raw'], PASSWORD_DEFAULT);
                        $ins = $conn->prepare("INSERT INTO users (password, email, first_name, middle_name, last_name, phone_number, barangay_id, purok, ImagePath, email_verified) VALUES (?,?,?,?,?,?,?,?,?,1)");
                        $ins->bind_param('ssssssiss', $password_hashed, $pending['email'], $pending['first_name'], $pending['middle_name'], $pending['last_name'], $pending['contactinfo'], $pending['barangay_id'], $pending['purok'], $storedPath);
                        $ins->execute();
                        $ins->close();
                        $conn->commit();
                        $success_message = 'Email verified and account created! You can now log in.';
                        // cleanup pending session and temp
                        if (!empty($pending['profile_tmp_path']) && file_exists($pending['profile_tmp_path'])) { @unlink($pending['profile_tmp_path']); }
                        unset($_SESSION['pending_signup']);
                    } catch (Exception $e) {
                        $conn->rollback();
                        error_log('Pre-signup insert failed: '.$e->getMessage());
                        $error_message = 'Failed to create account after verification.';
                    }
                }
            } else {
                // Branch 2: Existing DB user awaiting verification
                $sql = "SELECT id, first_name, email, email_verified, email_verification_expires 
                        FROM users 
                        WHERE email = ? AND email_verification_token = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ss", $input_email, $verification_code);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows === 0) {
                    $error_message = 'Invalid email or verification code.';
                } else {
                    $user = $result->fetch_assoc();
                    if ($user['email_verified']) {
                        $error_message = 'This email is already verified.';
                    } elseif ($user['email_verification_expires'] && strtotime($user['email_verification_expires']) < time()) {
                        $error_message = 'Verification code has expired. Please request a new one.';
                    } else {
                        $conn->begin_transaction();
                        try {
                            $updateSql = "UPDATE users SET email_verified = 1, email_verification_token = NULL, email_verification_expires = NULL WHERE id = ?";
                            $updateStmt = $conn->prepare($updateSql);
                            $updateStmt->bind_param("i", $user['id']);
                            $updateStmt->execute();
                            $updateLogSql = "UPDATE email_verification_logs SET verified_at = NOW() WHERE token = ?";
                            $updateLogStmt = $conn->prepare($updateLogSql);
                            $updateLogStmt->bind_param("s", $verification_code);
                            $updateLogStmt->execute();
                            $conn->commit();
                            $success_message = 'Email verified successfully! You can now log in to your account.';
                            unset($_SESSION['verification_sent']);
                            unset($_SESSION['pending_verification']);
                        } catch (Exception $e) {
                            $conn->rollback();
                            throw $e;
                        }
                    }
                }
                $stmt->close();
            }
            
        } catch (Exception $e) {
            error_log("Verification failed: " . $e->getMessage());
            $error_message = 'An error occurred during verification. Please try again.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enter Verification Code - CJ PowerHouse</title>
    <link rel="icon" type="image/png" href="Image/logo.png">
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #121212 0%, #1a1a1a 100%);
            color: #ffffff;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }
        
        .verification-container {
            max-width: 500px;
            margin: 50px auto;
            background-color: #1f1f1f;
            border-radius: 20px;
            padding: 3rem;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5);
            border: 1px solid #333;
            text-align: center;
        }
        
        .logo {
            width: 100px;
            height: auto;
            margin-bottom: 2rem;
            border-radius: 15px;
        }
        
        h1 {
            color: #FF9900;
            margin-bottom: 1rem;
            font-size: 2.2rem;
            font-weight: 700;
        }
        
        .subtitle {
            color: #cccccc;
            margin-bottom: 2rem;
            font-size: 1.1rem;
            line-height: 1.6;
        }
        
        .email-display {
            background: linear-gradient(135deg, #FF9900, #E58900);
            color: #000;
            padding: 15px 20px;
            border-radius: 15px;
            margin-bottom: 2rem;
            font-weight: 600;
            font-size: 1.1rem;
            box-shadow: 0 5px 15px rgba(255, 153, 0, 0.3);
        }
        
        .form-control {
            background-color: #2a2a2a;
            border: 2px solid #444;
            color: #ffffff;
            padding: 18px 20px;
            border-radius: 15px;
            margin-bottom: 1.5rem;
            font-size: 1.1rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            background-color: #2a2a2f;
            border-color: #FF9900;
            color: #ffffff;
            box-shadow: 0 0 0 0.3rem rgba(255, 153, 0, 0.25);
            transform: translateY(-2px);
        }
        
        .form-control::placeholder {
            color: #888;
        }
        
        .verify-btn {
            background: linear-gradient(135deg, #FF9900, #E58900);
            border: none;
            color: #000;
            padding: 18px 40px;
            font-size: 1.3rem;
            font-weight: 700;
            border-radius: 25px;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
            margin-top: 1rem;
            box-shadow: 0 8px 25px rgba(255, 153, 0, 0.3);
        }
        
        .verify-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 35px rgba(255, 153, 0, 0.4);
            color: #000;
        }
        
        .alert {
            border-radius: 15px;
            margin-bottom: 2rem;
            padding: 20px;
            font-size: 1.1rem;
        }
        
        .alert-success {
            background: linear-gradient(135deg, #28a745, #20c997);
            border: none;
            color: white;
            box-shadow: 0 8px 25px rgba(40, 167, 69, 0.3);
        }
        
        .alert-danger {
            background: linear-gradient(135deg, #dc3545, #c82333);
            border: none;
            color: white;
            box-shadow: 0 8px 25px rgba(220, 53, 69, 0.3);
        }
        
        .back-link {
            margin-top: 2rem;
            color: #FF9900;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
        }
        
        .back-link:hover {
            color: #E58900;
            text-decoration: underline;
        }
        
        .code-info {
            background-color: #2a2a2a;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 2rem;
            border: 1px solid #444;
            text-align: left;
        }
        
        .code-info strong {
            color: #FF9900;
        }
        
        .code-info ol {
            margin: 15px 0;
            padding-left: 20px;
        }
        
        .code-info li {
            margin-bottom: 8px;
            color: #cccccc;
        }
        
        .success-actions {
            margin-top: 2rem;
        }
        
        .btn-login {
            background: linear-gradient(135deg, #28a745, #20c997);
            border: none;
            color: white;
            padding: 15px 30px;
            font-size: 1.2rem;
            font-weight: 600;
            border-radius: 25px;
            text-decoration: none;
            display: inline-block;
            margin: 10px;
            transition: all 0.3s ease;
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(40, 167, 69, 0.4);
            color: white;
        }
        
        .resend-section {
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid #444;
        }
        
        .resend-btn {
            background: transparent;
            border: 2px solid #FF9900;
            color: #FF9900;
            padding: 12px 25px;
            border-radius: 20px;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
            font-weight: 600;
        }
        
        .resend-btn:hover {
            background: #FF9900;
            color: #000;
            transform: translateY(-2px);
        }
        
        @media (max-width: 768px) {
            .verification-container {
                margin: 20px auto;
                padding: 2rem;
            }
            
            h1 {
                font-size: 1.8rem;
            }
            
            .form-control {
                padding: 15px 18px;
                font-size: 1rem;
            }
            
            .verify-btn {
                padding: 15px 30px;
                font-size: 1.1rem;
            }
        }
    </style>
</head>
<body>
    <div class="verification-container">
        <img src="Image/logo.png" alt="CJ PowerHouse Logo" class="logo">
        
        <h1>Verify Your Email</h1>
        <p class="subtitle">We've sent a 6-digit verification code to your email address</p>
        
        <?php if ($email): ?>
            <div class="email-display">
                <i class="fas fa-envelope me-2"></i>
                <?php echo htmlspecialchars($email); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle fa-2x me-3"></i>
                <div>
                    <h4 class="mb-2">🎉 Verification Successful!</h4>
                    <p class="mb-0"><?php echo htmlspecialchars($success_message); ?></p>
                </div>
            </div>
            
            <div class="success-actions">
                <a href="LandingPage.php" class="btn-login">
                    <i class="fas fa-sign-in-alt me-2"></i>Go to Login
                </a>
            </div>
        <?php else: ?>
            <div class="code-info">
                <p><strong>How to verify:</strong></p>
                <ol>
                    <li>Check your email inbox (and spam folder)</li>
                    <li>Look for the email from CJ PowerHouse</li>
                    <li>Copy the 6-digit verification code</li>
                    <li>Enter it below and click "Verify Code"</li>
                </ol>
            </div>
            
            <form method="POST">
                <div class="form-group">
                    <input type="email" 
                           class="form-control" 
                           name="email" 
                           placeholder="Enter your email address" 
                           value="<?php echo htmlspecialchars($email); ?>"
                           required>
                </div>
                
                <div class="form-group">
                    <input type="text" 
                           class="form-control" 
                           name="verification_code" 
                           placeholder="Enter 6-digit verification code" 
                           maxlength="6" 
                           pattern="[0-9]{6}" 
                           required
                           autocomplete="off">
                </div>
                
                <button type="submit" class="verify-btn">
                    <i class="fas fa-check-circle me-2"></i>Verify Code
                </button>
            </form>
            
            <div class="resend-section">
                <p class="text-muted mb-2">Didn't receive the code?</p>
                <a href="LandingPage.php" class="resend-btn">
                    <i class="fas fa-arrow-left me-2"></i>Back to Sign Up
                </a>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Auto-focus on verification code input
        document.addEventListener('DOMContentLoaded', function() {
            const codeInput = document.querySelector('input[name="verification_code"]');
            if (codeInput) {
                codeInput.focus();
            }
            
            // Auto-format verification code (add spaces every 2 digits)
            codeInput.addEventListener('input', function(e) {
                let value = e.target.value.replace(/\D/g, '');
                if (value.length > 6) {
                    value = value.substring(0, 6);
                }
                e.target.value = value;
            });
        });
    </script>
</body>
</html>

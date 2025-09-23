<?php
session_start();
require_once 'dbconn.php';
require_once 'send_verification_email.php';

$verificationStatus = null;
$userData = null;

// Check if token is provided
if (isset($_GET['token']) && !empty($_GET['token'])) {
    $token = trim($_GET['token']);
    
    // Verify the email
    $result = verifyEmailToken($token);
    
    if ($result['success']) {
        $verificationStatus = 'success';
        $userData = $result;
        
        // Set session data for the verified user
        $_SESSION['user_id'] = $result['user_id'];
        $_SESSION['full_name'] = $result['first_name'];
        $_SESSION['role'] = "Customer";
        $_SESSION['email_verified'] = true;
        
    } else {
        $verificationStatus = 'error';
        $errorMessage = $result['message'];
    }
} else {
    $verificationStatus = 'no_token';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification - CJ PowerHouse</title>
    <link rel="icon" type="image/png" href="Image/logo.png">
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Rubik:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #121212;
            color: #ffffff;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .verification-container {
            background-color: #1f1f1f;
            border-radius: 15px;
            padding: 3rem;
            max-width: 500px;
            width: 90%;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
            border: 1px solid #333;
        }
        
        .logo {
            width: 80px;
            height: auto;
            margin-bottom: 2rem;
        }
        
        .status-icon {
            font-size: 4rem;
            margin-bottom: 1.5rem;
        }
        
        .status-icon.success {
            color: #28a745;
        }
        
        .status-icon.error {
            color: #dc3545;
        }
        
        .status-icon.warning {
            color: #ffc107;
        }
        
        h1 {
            font-family: 'Rubik', sans-serif;
            font-weight: 600;
            margin-bottom: 1rem;
            color: #ffffff;
        }
        
        .message {
            margin-bottom: 2rem;
            line-height: 1.6;
        }
        
        .btn {
            padding: 12px 30px;
            border-radius: 25px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            margin: 0.5rem;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background-color: #FF9900;
            border-color: #FF9900;
            color: #fff;
        }
        
        .btn-primary:hover {
            background-color: #E58900;
            border-color: #E58900;
            color: #fff;
            transform: translateY(-2px);
        }
        
        .btn-outline-light {
            border-color: #6c757d;
            color: #6c757d;
        }
        
        .btn-outline-light:hover {
            background-color: #6c757d;
            border-color: #6c757d;
            color: #fff;
        }
        
        .resend-section {
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid #333;
        }
        
        .resend-form {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            align-items: center;
        }
        
        .form-control {
            background-color: #343a40;
            border-color: #495057;
            color: #fff;
            border-radius: 8px;
            padding: 12px 15px;
        }
        
        .form-control:focus {
            background-color: #343a40;
            border-color: #FF9900;
            color: #fff;
            box-shadow: 0 0 0 0.25rem rgba(255, 193, 7, 0.25);
        }
        
        .alert {
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        
        .alert-success {
            background-color: rgba(40, 167, 69, 0.2);
            border-color: #28a745;
            color: #28a745;
        }
        
        .alert-danger {
            background-color: rgba(220, 53, 69, 0.2);
            border-color: #dc3545;
            color: #dc3545;
        }
        
        .alert-warning {
            background-color: rgba(255, 193, 7, 0.2);
            border-color: #ffc107;
            color: #ffc107;
        }
        
        @media (max-width: 768px) {
            .verification-container {
                padding: 2rem 1.5rem;
                margin: 1rem;
            }
            
            .logo {
                width: 60px;
                margin-bottom: 1.5rem;
            }
            
            .status-icon {
                font-size: 3rem;
                margin-bottom: 1rem;
            }
            
            h1 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="verification-container">
        <img src="Image/logo.png" alt="CJ PowerHouse Logo" class="logo">
        
        <?php if ($verificationStatus === 'success'): ?>
            <!-- Success State -->
            <div class="status-icon success">
                <i class="fas fa-check-circle"></i>
            </div>
            <h1>Email Verified Successfully!</h1>
            <div class="message">
                <p>Congratulations, <strong><?php echo htmlspecialchars($userData['first_name']); ?></strong>!</p>
                <p>Your email address has been verified. You can now access your account and start shopping.</p>
            </div>
            <div class="alert alert-success">
                <i class="fas fa-info-circle me-2"></i>
                Your account is now fully activated!
            </div>
            <a href="Mobile-Dashboard.php" class="btn btn-primary">
                <i class="fas fa-home me-2"></i>Go to Dashboard
            </a>
            
        <?php elseif ($verificationStatus === 'error'): ?>
            <!-- Error State -->
            <div class="status-icon error">
                <i class="fas fa-times-circle"></i>
            </div>
            <h1>Verification Failed</h1>
            <div class="message">
                <p><?php echo htmlspecialchars($errorMessage); ?></p>
                <p>This could happen if:</p>
                <ul style="text-align: left; display: inline-block;">
                    <li>The verification link has expired</li>
                    <li>The link has already been used</li>
                    <li>The link is invalid or corrupted</li>
                </ul>
            </div>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle me-2"></i>
                Please try signing up again or contact support if the problem persists.
            </div>
            <a href="LandingPage.php" class="btn btn-primary">
                <i class="fas fa-arrow-left me-2"></i>Back to Home
            </a>
            
        <?php elseif ($verificationStatus === 'no_token'): ?>
            <!-- No Token State -->
            <div class="status-icon warning">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h1>Invalid Verification Link</h1>
            <div class="message">
                <p>No verification token was provided.</p>
                <p>Please check your email for the correct verification link, or request a new one.</p>
            </div>
            <div class="alert alert-warning">
                <i class="fas fa-info-circle me-2"></i>
                Make sure you're using the complete verification link from your email.
            </div>
            <a href="LandingPage.php" class="btn btn-primary">
                <i class="fas fa-arrow-left me-2"></i>Back to Home
            </a>
        <?php endif; ?>
        
        <!-- Resend Verification Section -->
        <?php if ($verificationStatus === 'error' || $verificationStatus === 'no_token'): ?>
            <div class="resend-section">
                <h4>Need a new verification email?</h4>
                <p>Enter your email address below to receive a new verification link.</p>
                
                <form class="resend-form" id="resendForm">
                    <input type="email" class="form-control" id="resendEmail" name="email" 
                           placeholder="Enter your email address" required style="max-width: 300px;">
                    <button type="submit" class="btn btn-outline-light">
                        <i class="fas fa-paper-plane me-2"></i>Resend Verification
                    </button>
                </form>
                
                <div id="resendMessage" class="mt-3"></div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        $(document).ready(function() {
            // Handle resend verification form
            $('#resendForm').on('submit', function(e) {
                e.preventDefault();
                
                const email = $('#resendEmail').val();
                const submitBtn = $(this).find('button[type="submit"]');
                const originalText = submitBtn.html();
                
                // Disable button and show loading
                submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i>Sending...');
                
                // Send AJAX request
                $.ajax({
                    url: 'resend_verification.php',
                    method: 'POST',
                    data: { email: email },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            $('#resendMessage').html(
                                '<div class="alert alert-success">' +
                                '<i class="fas fa-check-circle me-2"></i>' + response.message +
                                '</div>'
                            );
                            $('#resendForm')[0].reset();
                        } else {
                            $('#resendMessage').html(
                                '<div class="alert alert-danger">' +
                                '<i class="fas fa-exclamation-triangle me-2"></i>' + response.message +
                                '</div>'
                            );
                        }
                    },
                    error: function() {
                        $('#resendMessage').html(
                            '<div class="alert alert-danger">' +
                            '<i class="fas fa-exclamation-triangle me-2"></i>An error occurred. Please try again.' +
                            '</div>'
                        );
                    },
                    complete: function() {
                        // Re-enable button
                        submitBtn.prop('disabled', false).html(originalText);
                    }
                });
            });
        });
    </script>
</body>
</html>

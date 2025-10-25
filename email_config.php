<?php
// Email configuration for CJ PowerHouse
// You'll need to install PHPMailer: composer require phpmailer/phpmailer

// SMTP Configuration (for Gmail)
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'carljandi87@gmail.com'); // Replace with your Gmail
define('SMTP_PASSWORD', 'ddtz qeyt yczi biam'); // Replace with your Gmail app password
define('SMTP_ENCRYPTION', 'tls');

// Email settings
define('FROM_EMAIL', 'carljandi87@gmail.com');
define('FROM_NAME', 'CJ PowerHouse');
define('REPLY_TO_EMAIL', 'carljandi87@gmail.com');

// Website settings
define('WEBSITE_URL', 'http://localhost/Motorshop'); // Keep on localhost for code verification
define('WEBSITE_NAME', 'CJ PowerHouse');

// Verification settings
define('VERIFICATION_TOKEN_EXPIRY_HOURS', 24); // Token expires in 24 hours
define('MAX_VERIFICATION_ATTEMPTS', 3); // Maximum verification attempts per day
define('VERIFICATION_CODE_LENGTH', 6); // 6-digit verification code

// Email templates
define('VERIFICATION_EMAIL_SUBJECT', 'Your Verification Code - CJ PowerHouse');
define('VERIFICATION_EMAIL_TEMPLATE', '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification Code</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #FF9900; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
        .content { background-color: #f9f9f9; padding: 30px; border-radius: 0 0 5px 5px; }
        .code-box { background-color: #FF9900; color: white; padding: 20px; text-align: center; border-radius: 5px; margin: 20px 0; font-size: 24px; font-weight: bold; letter-spacing: 5px; }
        .footer { text-align: center; margin-top: 30px; color: #666; font-size: 14px; }
        .warning { background-color: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin: 20px 0; }
        .instructions { background-color: #d1ecf1; border: 1px solid #bee5eb; padding: 15px; border-radius: 5px; margin: 20px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>CJ PowerHouse</h1>
            <p>Email Verification Code</p>
        </div>
        <div class="content">
            <h2>Hello {FIRST_NAME}!</h2>
            <p>Thank you for signing up with CJ PowerHouse! To complete your registration, please use the verification code below:</p>
            
            <div class="code-box">
                {VERIFICATION_CODE}
            </div>
            
            <div class="instructions">
                <strong>How to verify:</strong>
                <ol style="text-align: left; margin: 10px 0;">
                    <li>Go to <a href="http://localhost/Motorshop" style="color: #FF9900;">http://localhost/Motorshop</a></li>
                    <li>Click "Login" and then "Enter Verification Code"</li>
                    <li>Enter the code above: <strong>{VERIFICATION_CODE}</strong></li>
                    <li>Click "Verify Code"</li>
                </ol>
            </div>
            
            <div class="warning">
                <strong>Important:</strong> This verification code will expire in 24 hours for security reasons.
            </div>
            
            <p>If you didn\'t create an account with CJ PowerHouse, please ignore this email.</p>
            
            <p>Best regards,<br>The CJ PowerHouse Team</p>
        </div>
        <div class="footer">
            <p>This is an automated email. Please do not reply to this message.</p>
            <p>&copy; ' . date('Y') . ' CJ PowerHouse. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
');

// Resend verification email template
define('RESEND_VERIFICATION_EMAIL_SUBJECT', 'Resend: Your Verification Code - CJ PowerHouse');
define('RESEND_VERIFICATION_EMAIL_TEMPLATE', '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification Code (Resent)</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #FF9900; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
        .content { background-color: #f9f9f9; padding: 30px; border-radius: 0 0 5px 5px; }
        .code-box { background-color: #FF9900; color: white; padding: 20px; text-align: center; border-radius: 5px; margin: 20px 0; font-size: 24px; font-weight: bold; letter-spacing: 5px; }
        .footer { text-align: center; margin-top: 30px; color: #666; font-size: 14px; }
        .info { background-color: #d1ecf1; border: 1px solid #bee5eb; padding: 15px; border-radius: 5px; margin: 20px 0; }
        .instructions { background-color: #d1ecf1; border: 1px solid #bee5eb; padding: 15px; border-radius: 5px; margin: 20px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>CJ PowerHouse</h1>
            <p>Email Verification Code (Resent)</p>
        </div>
        <div class="content">
            <h2>Hello {FIRST_NAME}!</h2>
            <p>We\'ve resent your verification code as requested. Please use the code below:</p>
            
            <div class="code-box">
                {VERIFICATION_CODE}
            </div>
            
            <div class="instructions">
                <strong>How to verify:</strong>
                <ol style="text-align: left; margin: 10px 0;">
                    <li>Go to <a href="http://localhost/Motorshop" style="color: #FF9900;">http://localhost/Motorshop</a></li>
                    <li>Click "Login" and then "Enter Verification Code"</li>
                    <li>Enter the code above: <strong>{VERIFICATION_CODE}</strong></li>
                    <li>Click "Verify Code"</li>
                </ol>
            </div>
            
            <div class="info">
                <strong>Note:</strong> This is a new verification code that will expire in 24 hours.
            </div>
            
            <p>If you didn\'t request this email, please ignore it.</p>
            
            <p>Best regards,<br>The CJ PowerHouse Team</p>
        </div>
        <div class="footer">
            <p>This is an automated email. Please do not reply to this message.</p>
            <p>&copy; ' . date('Y') . ' CJ PowerHouse. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
');
?>

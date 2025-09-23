<?php
header('Content-Type: application/json');
require_once 'dbconn.php';
require_once 'send_verification_email.php';

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $response['message'] = 'Email address is required.';
        echo json_encode($response);
        exit;
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $response['message'] = 'Please enter a valid email address.';
        echo json_encode($response);
        exit;
    }
    
    try {
        // Check if user exists and needs verification
        $sql = "SELECT id, first_name, email_verified FROM users WHERE email = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $response['message'] = 'No account found with this email address.';
            echo json_encode($response);
            exit;
        }
        
        $user = $result->fetch_assoc();
        
        if ($user['email_verified']) {
            $response['message'] = 'This email is already verified. You can log in normally.';
            echo json_encode($response);
            exit;
        }
        
        // Check if user can resend verification
        if (!canResendVerification($email)) {
            $response['message'] = 'You have reached the maximum verification attempts for today. Please try again tomorrow.';
            echo json_encode($response);
            exit;
        }
        
        // Send verification email
        $emailResult = sendVerificationEmail($user['id'], $email, $user['first_name'], true);
        
        if ($emailResult['success']) {
            $response['success'] = true;
            $response['message'] = 'Verification email sent successfully! Please check your inbox and spam folder.';
        } else {
            $response['message'] = $emailResult['message'];
        }
        
    } catch (Exception $e) {
        error_log("Resend verification error: " . $e->getMessage());
        $response['message'] = 'An error occurred while sending the verification email. Please try again later.';
    }
    
} else {
    $response['message'] = 'Invalid request method.';
}

echo json_encode($response);
?>

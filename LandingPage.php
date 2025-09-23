<?php
session_start();
require_once 'dbconn.php'; // Ensure this path is correct

// *** Retrieve session data AND check for success/error flags ***
$form_data = $_SESSION['signup_form_data'] ?? [];
$server_errors = $_SESSION['signup_errors'] ?? [];
$verification_success = isset($_GET['verified']) && $_GET['verified'] == '1';
$verification_error = isset($_GET['verification_error']) && $_GET['verification_error'] == '1';
unset($_SESSION['signup_form_data']);
unset($_SESSION['signup_errors']);

$show_success_modal = false;
if (isset($_SESSION['registration_success']) && $_SESSION['registration_success'] === true) {
    $show_success_modal = true;
    unset($_SESSION['registration_success']);
}

// *** ADDED: Check for login errors passed back via session (optional fallback) ***
$login_error = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']); // Clear after retrieving
// *** END OF ADDED CODE ***


// Dynamically determine the base URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$path = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$baseURL = $protocol . '://' . $host . $path . '/';

$signup_errors = []; // Array to hold server-side validation errors for THIS request

// Check if the signup form has been submitted
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['signup_submit'])) {

    // --- Server-Side Validation ---
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $contactinfo = trim($_POST['contactinfo'] ?? '');
    $password_raw = $_POST['password'] ?? ''; // Note: This is the signup password, distinct from login
    $barangay_id = $_POST['barangay'] ?? '';
    $purok = trim($_POST['purok'] ?? '');
    $privacy_policy = isset($_POST['privacy_policy']);

    // 1. Required fields check
    if (empty($first_name)) $signup_errors[] = "First Name is required.";
    if (empty($last_name)) $signup_errors[] = "Last Name is required.";
    if (empty($email)) $signup_errors[] = "Email is required.";
    if (empty($contactinfo)) $signup_errors[] = "Phone Number is required.";
    if (empty($password_raw)) $signup_errors[] = "Password is required.";
    if (empty($barangay_id)) $signup_errors[] = "Barangay selection is required.";
    if (empty($purok)) $signup_errors[] = "Purok is required.";

    // 2. Email format validation & check
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $signup_errors[] = "Invalid Email format.";
    } else if (!empty($email)) {
        $checkEmailSql = "SELECT id FROM users WHERE email = ?";
        $checkStmt = $conn->prepare($checkEmailSql);
        if ($checkStmt) {
            $checkStmt->bind_param("s", $email);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            if ($checkResult->num_rows > 0) {
                $signup_errors['email_taken'] = "Email is taken , plss use another gmail";
            }
            $checkStmt->close();
        } else {
             error_log("SQL Prepare Error (Email Check): " . $conn->error);
             $signup_errors[] = "Database error checking email.";
        }
    }

    // 3. Phone number validation
    if (!empty($contactinfo) && !preg_match('/^9[0-9]{9}$/', $contactinfo)) {
         $signup_errors[] = "Phone number must be exactly 10 digits and start with '9'.";
    }

    // 4. Password strength validation
    if (!empty($password_raw)) {
        if (strlen($password_raw) < 7) $signup_errors[] = "Password must be at least 7 characters long.";
        if (!preg_match('/[A-Z]/', $password_raw)) $signup_errors[] = "Password must include at least one uppercase letter.";
    }

    // 5. Data Privacy Agreement
    if (!$privacy_policy) $signup_errors[] = "You must agree to the Data Privacy Policy.";

    // 6. Profile Image Upload Check
    $filePath = '';
    $valid_upload = false;
    if (isset($_FILES['profilePicture']) && $_FILES['profilePicture']['error'] === UPLOAD_ERR_OK && $_FILES['profilePicture']['size'] > 0) {
        $valid_upload = true;
        $allowed_mime_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $file_info = finfo_open(FILEINFO_MIME_TYPE);
        $file_mime_type = finfo_file($file_info, $_FILES['profilePicture']['tmp_name']);
        finfo_close($file_info);
        if (!in_array($file_mime_type, $allowed_mime_types)) {
             $signup_errors['upload_error'] = "Invalid file type for profile image. Please upload a JPG, PNG, GIF, or WEBP.";
             $valid_upload = false;
        }
    } else {
         $signup_errors['upload_error'] = "Profile image is required.";
         if(isset($_FILES['profilePicture']['error']) && $_FILES['profilePicture']['error'] !== UPLOAD_ERR_NO_FILE) {
             switch ($_FILES['profilePicture']['error']) {
                 case UPLOAD_ERR_INI_SIZE: case UPLOAD_ERR_FORM_SIZE: $signup_errors['upload_error'] = "Profile image file is too large."; break;
                 case UPLOAD_ERR_PARTIAL: $signup_errors['upload_error'] = "Profile image was only partially uploaded."; break;
                 default: $signup_errors['upload_error'] = "An error occurred during profile image upload."; break;
             }
         }
    }


    // --- If NO errors, proceed with pre-verification (send code) BEFORE DB insert ---
    if (empty($signup_errors) && $valid_upload) {
        // 0) Generate verification code and send email BEFORE inserting user
        $pre_first = $first_name ?: 'Customer';
        $pre_code = sprintf('%06d', mt_rand(0, 999999));
        require_once 'send_verification_email.php';
        $preEmail = sendSignupVerificationEmail($email, $pre_first, $pre_code);
        if (!$preEmail['success']) {
             $_SESSION['signup_form_data'] = $_POST;
             $_SESSION['signup_errors'] = ['email_error' => 'Failed to send verification email. Please try again.'];
             header('Location: ' . $_SERVER['REQUEST_URI']);
             exit;
        }

        // Persist the uploaded file to a temporary location that survives redirect
        $tmpDir = 'uploads/profile_images/tmp/';
        if (!is_dir($tmpDir)) { if (!mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) { $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR; } }
        $origExt = strtolower(pathinfo($_FILES['profilePicture']['name'], PATHINFO_EXTENSION));
        $pendingTmpPath = $tmpDir . uniqid('pending_', true) . '.' . $origExt;
        if (!move_uploaded_file($_FILES['profilePicture']['tmp_name'], $pendingTmpPath)) {
            $_SESSION['signup_form_data'] = $_POST;
            $_SESSION['signup_errors'] = ['upload_error' => 'Failed to buffer profile image before verification. Please try again.'];
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }

        // Stash pending signup data in session and redirect to code entry page
        $_SESSION['pending_signup'] = [
            'first_name' => $first_name,
            'middle_name' => $middle_name,
            'last_name' => $last_name,
            'email' => $email,
            'contactinfo' => $contactinfo,
            'password_raw' => $password_raw,
            'barangay_id' => $barangay_id,
            'purok' => $purok,
            'profile_tmp_path' => $pendingTmpPath,
            'profile_ext' => $origExt,
            'code' => $pre_code,
            'created_at' => time()
        ];

        // Redirect to lightweight code entry page dedicated for signup flow
        header('Location: enter_verification_code.php?email=' . urlencode($email));
        exit;

        // The old code path below (immediate DB insert + then verify) is left as fallback and will not run in the new flow
        $uploadDir = 'uploads/profile_images/';
        if (!is_dir($uploadDir)) {
             if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                error_log("Failed to create upload directory: " . $uploadDir);
                 $_SESSION['signup_form_data'] = $_POST;
                 $_SESSION['signup_errors'] = ['server_error' => 'Server error: Could not create upload directory. Registration failed.'];
                 header('Location: ' . $_SERVER['REQUEST_URI']);
                 exit;
             }
        }

        $fileExtension = strtolower(pathinfo($_FILES['profilePicture']['name'], PATHINFO_EXTENSION));
        $fileName = uniqid('user_', true) . '.' . $fileExtension;
        $filePath = $uploadDir . $fileName;

        if (move_uploaded_file($_FILES['profilePicture']['tmp_name'], $filePath)) {
            $password_hashed = password_hash($password_raw, PASSWORD_DEFAULT);
            $sql = "INSERT INTO users (password, email, first_name, middle_name, last_name, phone_number, barangay_id, purok, ImagePath)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);

             if ($stmt === false) {
                error_log("SQL Prepare Error in landingpage.php: " . $conn->error . " | SQL: " . $sql);
                if (file_exists($filePath)) { unlink($filePath); }
                 $_SESSION['signup_form_data'] = $_POST;
                 $_SESSION['signup_errors'] = ['db_error' => 'Database statement error. Registration failed.'];
                 header('Location: ' . $_SERVER['REQUEST_URI']);
                 exit;
            }

            $stmt->bind_param("ssssssiss", $password_hashed, $email, $first_name, $middle_name, $last_name, $contactinfo, $barangay_id, $purok, $filePath);

            if ($stmt->execute()) {
                $user_id = $stmt->insert_id;
                $stmt->close();

                // Send verification email instead of immediately logging in
                require_once 'send_verification_email.php';
                $emailResult = sendVerificationEmail($user_id, $email, $first_name);
                
                if ($emailResult['success']) {
                    // Store user info in session for verification success modal
                    $_SESSION['pending_verification'] = [
                        'user_id' => $user_id,
                        'email' => $email,
                        'first_name' => $first_name,
                        'full_name' => trim($first_name . " " . ($middle_name ? $middle_name . " " : "") . $last_name),
                        'profile_image' => $filePath
                    ];
                    
                    $_SESSION['verification_sent'] = true;
                    
                    // Redirect directly to verification code page
                    header('Location: enter_verification_code.php?email=' . urlencode($email));
                    exit();
                } else {
                    // If email sending fails, delete the user and show error
                    $deleteSql = "DELETE FROM users WHERE id = ?";
                    $deleteStmt = $conn->prepare($deleteSql);
                    $deleteStmt->bind_param("i", $user_id);
                    $deleteStmt->execute();
                    $deleteStmt->close();
                    
                    if (file_exists($filePath)) { unlink($filePath); }
                    
                    $_SESSION['signup_form_data'] = $_POST;
                    $_SESSION['signup_errors'] = ['email_error' => 'Registration completed but verification email could not be sent. Please contact support.'];
                    header('Location: ' . $_SERVER['REQUEST_URI']);
                    exit();
                }

            } else {
                 error_log("Database Execute Error: " . $stmt->error);
                 if (file_exists($filePath)) { unlink($filePath); }
                 $stmt->close();
                 $_SESSION['signup_form_data'] = $_POST;
                 if ($conn->errno === 1062) {
                      $signup_errors['email_taken'] = "Email is taken , plss use another gmail";
                      $_SESSION['signup_errors'] = $signup_errors;
                 } else {
                      $_SESSION['signup_errors'] = ['db_error' => 'Registration failed due to a database error (Code: '.$conn->errno.').'];
                 }
                 header('Location: ' . $_SERVER['REQUEST_URI']);
                 exit();
            }

        } else {
            error_log("Failed to move uploaded file: " . $_FILES['profilePicture']['name'] . " to " . $filePath);
             $_SESSION['signup_form_data'] = $_POST;
             $_SESSION['signup_errors'] = ['upload_error' => 'Failed to save profile image. Registration cancelled.'];
             header('Location: ' . $_SERVER['REQUEST_URI']);
             exit();
        }

    } else {
        $_SESSION['signup_form_data'] = $_POST;
        $_SESSION['signup_errors'] = $signup_errors;
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit();
    }

} // End of POST handling block

// --- Fetch data needed for the page ---
$barangayResult = null;
$query_barangay = "SELECT id, barangay_name FROM barangays ORDER BY barangay_name ASC";
$barangayResult = $conn->query($query_barangay);
if ($barangayResult === false) { error_log("Error fetching barangays: " . $conn->error); }

$productQuery = " SELECT ProductID, ProductName, Price, ImagePath, Category FROM products";
$productResult = $conn->query($productQuery);
$products = [];
if ($productResult && $productResult->num_rows > 0) {
    while ($row = $productResult->fetch_assoc()) {
        if (!empty($row['ImagePath']) && strpos($row['ImagePath'], 'uploads/') !== 0) {
             $row['ImagePath'] = 'uploads/' . ltrim($row['ImagePath'], '/');
        }
        $products[] = $row;
    }
} else if ($productResult === false) { error_log("Error fetching products: " . $conn->error); }

$feedbackQuery = " SELECT pf.comment, pf.rating, pf.image_path, pf.created_at, u.first_name, u.last_name, u.ImagePath AS user_image FROM product_feedback pf JOIN users u ON pf.user_id = u.id ORDER BY pf.created_at DESC LIMIT 10";
$feedbackResult = $conn->query($feedbackQuery);
$feedbacks = [];
if ($feedbackResult && $feedbackResult->num_rows > 0) {
    while ($row = $feedbackResult->fetch_assoc()) {
         if (empty($row['user_image'])) { $row['user_image'] = 'Image/default-avatar.png'; }
        $feedbacks[] = $row;
    }
} else if ($feedbackResult === false) { error_log("Error fetching feedback: " . $conn->error); }

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Landing Page</title>
    <link rel="icon" type="image/png" href="<?= $baseURL ?>image/logo.png">
    <link rel="stylesheet" href="landingpage.css"> <!-- Ensure this CSS file exists -->
    <link href="css/bootstrap.min.css" rel="stylesheet"> <!-- Ensure this CSS file exists -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" integrity="sha512-9usAa10IRO0HhonpyAIVpjrylPvoDwiPUiKdWk5t3PyolY1cOd4DSE0Ga+ri4AuTroPR5aQvXU9xC6qOPnzFeg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link href="https://fonts.googleapis.com/css2?family=Rubik:wght@400;600&display=swap" rel="stylesheet">

    <style>
        /* --- Your Existing CSS Styles --- */
        #spinner { opacity: 0; visibility: hidden; transition: opacity .5s ease-out, visibility 0s linear .5s; z-index: 99999; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); display: flex; justify-content: center; align-items: center; }
        #spinner.show { transition: opacity .5s ease-out, visibility 0s linear 0s; visibility: visible; opacity: 1; }
        #spinner img { width: 200px; height: 200px; }
        body { font-family: 'Poppins', sans-serif; background-color: #121212; color: #ffffff; }
        h1, h2, h3 { font-family: 'Rubik', sans-serif; font-weight: 600; }
        .navbar { background-color: #1f1f1f; padding: 1rem 2rem; }
        .logo span { color: #FF9900; }
        .navbar nav a { margin-left: 1rem; color: #ffffff; text-decoration: none; }
        .navbar nav a:hover { color: #FF9900; }
        .hero { text-align: center; padding: 4rem 1rem; background: url('Image/cover.jpg') no-repeat center center/cover; position: relative; min-height: 85vh; display: flex; flex-direction: column; justify-content: center; align-items: center; }
        .hero::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.65); }
        .hero-content { position: relative; z-index: 1; }
        .center-logo { width: 100%; max-width: 300px; height: auto; margin-bottom: 1.5rem; }
        .hero h1 { font-size: 2.8rem; margin-bottom: 0.8rem; }
        .hero p { font-size: 1.3rem; margin-bottom: 2rem; }
        .hero .btn-warning { padding: 0.8rem 1.8rem; font-size: 1.1rem; }
        .info { background-color: #1f1f1f; padding: 4rem 1rem; text-align: center; }
        .info h2 { color: #FFC107; margin-bottom: 2.5rem; font-size: 2.2rem; }
        .steps { display: flex; justify-content: center; flex-wrap: wrap; gap: 30px; margin-top: 2rem; }
        .step { background-color: #2a2a2a; padding: 2rem; border-radius: 10px; margin: 0; flex-basis: calc(33.333% - 20px); min-width: 280px; border: 1px solid #444; transition: transform 0.2s ease-in-out; text-align: center; /* <<< Added text-align center */ }
        .step:hover { transform: translateY(-5px); }
        .step h3 { color: #ffffff; margin-bottom: 1rem; }
        .step h3 i { color: #FFC107; font-size: 1.8em; display: block; margin-bottom: 0.5rem; /* <<< Made icon block for centering */ }
        .step p { color: #cccccc; font-size: 1.05rem; }
        .feedback { padding: 3rem 1rem; text-align: center; }
        .feedback h2 { margin-bottom: 2rem; }
        .feedback-carousel { overflow: hidden; width: 100%; max-width: 800px; margin: 0 auto; position: relative; min-height: auto; padding-bottom: 20px; }
        .feedback-slide { width: 100%; padding: 0; box-sizing: border-box; position: absolute; top: 0; left: 0; opacity: 0; transition: opacity 0.5s ease-in-out; z-index: 0; }
        .feedback-slide.active { opacity: 1; z-index: 1; position: relative; }
        .feedback-container { background-color: white; color: black; padding: 25px; border-radius: 8px; margin: 10px auto; text-align: left; box-shadow: 0 2px 10px rgba(255,255,255,0.1); min-height: 200px; }
        .feedback-container p, .feedback-container span, .feedback-container small { color: black; }
        .user-info { display: flex; align-items: center; margin-bottom: 10px; }
        .user-info img { width: 50px; height: 50px; border-radius: 50%; object-fit: cover; margin-right: 15px; }
        .user-info div { display: flex; flex-direction: column; }
        .user-info span { font-weight: bold; font-size: 1.1rem;}
        .user-info small { color: #555 !important; }
        .rating { margin: 10px 0; }
        .star { font-size: 1.1em; color: #ccc; margin-right: 0.3em; }
        .star.checked { color: gold; }
        .star.unselected { color: #e0e0e0; }
        .feedback-container p em { font-style: italic; display: block; margin-bottom: 15px; font-size: 1.1rem;}
        .feedback-container img[alt="Feedback Image"] { display: block; max-width: 100%; height: auto; margin: 15px auto 0 auto; border-radius: 5px; max-height: 300px; object-fit: contain; }
        .items { padding: 3rem 1rem; text-align: center; }
        .items h2 { margin-bottom: 2rem; }
        .product-carousel { overflow: hidden; width: 100%; position: relative; min-height: 400px; }
        .product-item { width: 100%; padding: 25px; box-sizing: border-box; position: absolute; top: 0; left: 0; opacity: 0; transition: opacity 0.5s ease-in-out; z-index: 0; background-color: #fff; border-radius: 10px; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1); text-align: center; }
        .product-item.active { opacity: 1; z-index: 1; position: relative; }
        .product-image { max-width: 200px; max-height: 200px; width: auto; height: auto; object-fit: contain; border-radius: 8px; margin-bottom: 15px; display: inline-block; }
        .product-item h3 { font-size: 1.4em; margin-top: 0; margin-bottom: 10px; color: #333; }
        .product-item p { font-size: 1.2em; color: #E87A00; margin-bottom: 20px; font-weight: bold; }
        .product-item .btn { background-color: #FF9900; color: #fff; padding: 12px 25px; border-radius: 25px; text-decoration: none; font-size: 1em; transition: background-color 0.3s; border: none; }
        .product-item .btn:hover { background-color: #E58900; }
        .modal-body { max-height: 75vh; overflow-y: auto; padding: 25px; }
        .modal-logo { display: block; margin: 0 auto 20px auto; width: 90px; }
        .modal-header { border-bottom: none; padding: 1rem 1.5rem; }
        .modal-content { border-radius: 10px; }
        .modal-content.bg-dark { background-color: #212529 !important; color: #fff; }
        .modal-content.bg-dark .form-control { background-color: #343a40; border-color: #495057; color: #fff; }
        .modal-content.bg-dark .form-control:focus { background-color: #343a40; border-color: #ffc107; color: #fff; box-shadow: 0 0 0 0.25rem rgba(255, 193, 7, 0.25); }
        .modal-content.bg-dark .form-select { background-color: #343a40; border-color: #495057; color: #fff; }
        .modal-content.bg-dark .form-floating>label { color: #adb5bd; }
        .modal-content.bg-dark .input-group-text { background-color: #495057; border-color: #495057; color: #fff; }
        .modal-content.bg-dark .form-check-label { color: #fff; }
        .modal-content.bg-dark .text-muted { color: #adb5bd !important; }
        .modal-content.bg-dark .btn-close-white { filter: invert(1) grayscale(100%) brightness(200%); }
        .modal-content.bg-dark .invalid-feedback { color: #f8d7da; }
        .form-floating>label { padding-top: 0.75rem; }
        .eye-icon { position: absolute; right: 15px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #ced4da; z-index: 10; font-size: 1.1em; }
        #loginModal .input-group .eye-icon { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); z-index: 10; background: transparent; border: 0; padding: 0; color: #ced4da; }
        #loginModal .input-group .form-control { padding-right: 45px; }
        #loginModal .input-group .eye-icon:hover { color: #fff; }
        
        /* Fix for password strength indicator not affecting eye icon position */
        .form-floating.position-relative .eye-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            z-index: 10;
            pointer-events: auto;
        }
        
        /* Ensure password strength indicator doesn't affect layout */
        #passwordStrengthIndicator {
            position: relative;
            z-index: 1;
            margin-top: 0.25rem;
        }
        
        /* Adjust password field container to accommodate both icons side by side */
        .form-floating.position-relative {
            position: relative;
        }
        
        .form-floating.position-relative .form-control {
            padding-right: 80px; /* Space for both eye icon and warning icon */
        }
        
        /* Position eye icon on the left of warning icon - fixed positioning */
        .form-floating.position-relative .eye-icon {
            position: absolute;
            right: 50px; /* Eye icon on the left of warning */
            top: 50%;
            transform: translateY(-50%);
            z-index: 10;
            pointer-events: auto;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        /* Add warning icon positioning if needed */
        .form-floating.position-relative .warning-icon {
            position: absolute;
            right: 15px; /* Warning icon on the right */
            top: 50%;
            transform: translateY(-50%);
            z-index: 9;
            color: #dc3545;
            font-size: 1.1em;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        /* Ensure password field maintains consistent layout */
        .form-floating.position-relative .form-control {
            padding-right: 80px !important;
            position: relative;
        }
        
        /* Fix for when password field is focused or has content */
        .form-floating.position-relative .form-control:focus,
        .form-floating.position-relative .form-control:not(:placeholder-shown) {
            padding-right: 80px !important;
        }
        
        /* Ensure icons stay in place during input */
        .form-floating.position-relative .form-control:focus + label + .eye-icon,
        .form-floating.position-relative .form-control:not(:placeholder-shown) + label + .eye-icon {
            right: 50px !important;
            top: 50% !important;
            transform: translateY(-50%) !important;
        }
        
        /* Additional fixes for icon stability */
        .form-floating.position-relative .eye-icon {
            transition: none !important; /* Prevent any transitions that might cause movement */
        }
        
        /* Ensure consistent positioning regardless of input state */
        .form-floating.position-relative .form-control:valid + label + .eye-icon,
        .form-floating.position-relative .form-control:invalid + label + .eye-icon {
            right: 50px !important;
            top: 50% !important;
            transform: translateY(-50%) !important;
        }
        
        /* Fix for Bootstrap form-floating label animation */
        .form-floating.position-relative .form-control:focus ~ .eye-icon,
        .form-floating.position-relative .form-control:not(:placeholder-shown) ~ .eye-icon {
            right: 50px !important;
            top: 50% !important;
            transform: translateY(-50%) !important;
        }

        .input-group-text img { vertical-align: middle; }
        .form-control.is-invalid, .form-select.is-invalid { border-color: #dc3545; }
        .invalid-feedback { display: none; width: 100%; margin-top: .25rem; font-size: .875em; color: #dc3545; }
        .modal-content.bg-dark .invalid-feedback { color: #f8d7da; }
        .was-validated .form-control:invalid ~ .invalid-feedback,
        .was-validated .form-select:invalid ~ .invalid-feedback,
        .was-validated .form-check-input:invalid ~ .invalid-feedback,
        .form-control.is-invalid ~ .invalid-feedback,
        .form-select.is-invalid ~ .invalid-feedback,
        .form-check-input.is-invalid ~ .invalid-feedback { display: block; }
        .form-check-input.is-invalid { border-color: #dc3545 !important; }
        .form-check-input:invalid:checked { background-color: #dc3545; border-color: #dc3545;}
        .contact { padding: 3rem 1rem; text-align: center; }
        .contact h2 { margin-bottom: 1.5rem; }
        .contact-info p { margin-bottom: 0.8rem; font-size: 1.1rem; }
        .contact-info p i { margin-right: 8px; color: #FFC107; }
        .contact-info a { color: #fff; text-decoration: none; }
        .contact-info a:hover { color: #FFC107; }
        .map-container { width: 100%; max-width: 800px; height: 350px; margin: 25px auto 0 auto; border-radius: 8px; overflow: hidden; border: 1px solid #444; }
        .map-container iframe { width: 100%; height: 100%; border: 0; }
        footer { background-color: #1f1f1f; padding: 1.5rem; text-align: center; margin-top: 2rem;}
        footer p { margin-bottom: 0.5rem; }
        footer a { color: #aaa; text-decoration: none; }
        footer a:hover { color: #FFC107; }
        .shop-gallery { display: flex; justify-content: space-around; flex-wrap: wrap; gap: 20px; padding: 3rem 1rem; }
        .shop-item img { width: 100%; max-width: 450px; height: auto; border-radius: 10px; box-shadow: 0 5px 15px rgba(0,0,0,0.4); }
        #privacyPolicyModal .modal-body { color: #e9ecef; line-height: 1.7; }
        #privacyPolicyModal .modal-body h4 { color: #ffc107; margin-top: 1.5rem; margin-bottom: 0.75rem; }
        #privacyPolicyModal .modal-body strong { color: #fff; }
        #privacyPolicyModal .modal-body ul { padding-left: 25px; }
        #privacyPolicyModal .modal-body li { margin-bottom: 0.5rem; }
        #passwordStrengthIndicator { font-weight: bold; transition: color 0.3s ease-in-out; font-size: 0.875em; min-height: 1.2em; }
        .strength-weak { color: #dc3545; }
        .strength-good { color: #ffc107; }
        .strength-very-good { color: #198754; }
        .modal-content.bg-dark #passwordStrengthIndicator.strength-weak { color: #f8d7da; }
        .modal-content.bg-dark #passwordStrengthIndicator.strength-good { color: #fff3cd; }
        .modal-content.bg-dark #passwordStrengthIndicator.strength-very-good { color: #d1e7dd; }

        /* Smooth entrance/exit just for the login modal */
        #loginModal.modal.fade .modal-dialog {
            transform: translateY(18px) scale(0.98);
            opacity: 0;
            transition: transform 260ms cubic-bezier(0.22, 1, 0.36, 1), opacity 260ms ease;
        }
        #loginModal.modal.show .modal-dialog { transform: translateY(0) scale(1); opacity: 1; }
        /* Slightly softer backdrop */
        .modal-backdrop.show { opacity: 0.35; }

        /* Swipe gesture styles */
        .hero {
            position: relative;
            touch-action: pan-y; /* Allow vertical scrolling but capture horizontal swipes */
        }
        
        
        .staff-btn-visible {
            display: inline-block !important;
            opacity: 1 !important;
        }

        #loginErrorMessages {
            font-size: 0.9em; padding: 0.75rem 1rem; margin-bottom: 1rem; border-radius: .25rem;
             /* Using rgba for better dark mode blending */
             background-color: rgba(248, 215, 218, 0.1);
             border: 1px solid rgba(245, 198, 203, 0.2);
             color: #f8d7da; /* Light red text */
        }
        #loginErrorMessages:not(.d-none) { /* Ensure display block when not hidden */
            display: block !important;
        }

        /* --- Media Queries --- */
        @media (max-width: 768px) {
            .navbar { padding: 0.8rem 1rem; flex-direction: column; align-items: center; }
            .logo { font-size: 1.8rem; margin-bottom: 0.5rem; }
            .navbar nav { margin-top: 0.5rem; text-align: center; }
            .navbar nav a { font-size: 0.9rem; margin-left: 0.5rem; margin-right: 0.5rem; display: inline-block; padding: 5px 0; }
            .hero { min-height: 70vh; padding: 3rem 1rem; }
            .center-logo { max-width: 200px; margin-bottom: 1rem; }
            .hero h1 { font-size: 2.0rem; }
            .hero p { font-size: 1.1rem; margin-bottom: 1.5rem; }
            .hero .btn-lg { font-size: 1rem; padding: 0.6rem 1.2rem; }
            .shop-gallery { padding: 2rem 0.5rem; gap: 10px; }
            .shop-item img { max-width: 100%; }
            .info { padding: 2.5rem 1rem; }
            .info h2 { font-size: 1.8rem; margin-bottom: 1.5rem; }

            /* --- START: How It Works Mobile Fix --- */
            .steps {
                gap: 20px; /* Adjust vertical gap */
            }
            .step {
                padding: 1.5rem;
                flex-basis: 100%; /* Make step take full width */
                width: 100%; /* Ensure full width */
                min-width: 0; /* Override desktop min-width */
                max-width: 400px; /* Optional: Set max width for better appearance */
                margin-left: auto; /* Center the box if max-width is used */
                margin-right: auto; /* Center the box if max-width is used */
            }
            .step h3 { font-size: 1.2rem; }
            .step h3 i { font-size: 1.6em; } /* Adjust icon size */
            .step p { font-size: 0.95rem; }
             /* --- END: How It Works Mobile Fix --- */

            .items { padding: 2.5rem 1rem; }
            .items h2 { font-size: 1.8rem; margin-bottom: 1.5rem; }
            .product-carousel { min-height: 380px; }
            .product-item { padding: 15px; }
            .product-image { max-width: 150px; max-height: 150px; }
            .product-item h3 { font-size: 1.1rem; }
            .product-item p { font-size: 1rem; margin-bottom: 15px;}
            .product-item .btn { padding: 8px 18px; font-size: 0.9rem;}
            .feedback { padding: 2.5rem 1rem; }
            .feedback h2 { font-size: 1.8rem; margin-bottom: 1.5rem; }
            .feedback-carousel { max-width: 95%; }
            .feedback-container { padding: 15px; }
            .user-info { flex-direction: column; align-items: center; text-align: center;}
            .user-info img { margin-right: 0; margin-bottom: 8px;}
            .user-info div { align-items: center; }
            .rating { text-align: center; }
            .feedback-container p em { font-size: 1rem; }
            .contact { padding: 2.5rem 1rem; }
            .contact h2 { font-size: 1.8rem; margin-bottom: 1.5rem; }
            .contact-info p { font-size: 1rem; }
            .map-container { height: 250px; }
            .modal-body { padding: 15px; }
            .modal-logo { width: 70px; margin-bottom: 15px;}
            .modal-title { font-size: 1.3rem; }
        }

        @media (max-width: 480px) {
            .hero h1 { font-size: 1.8rem; }
            .hero p { font-size: 1rem; }
            .navbar nav a { font-size: 0.8rem; margin-left: 0.3rem; margin-right: 0.3rem;}
            .step { max-width: 90%; } /* Further adjust max-width */
            .step h3 i { font-size: 1.5em; }
        }
        
        /* ===== Start: Product Card Grid (inserted) ===== */
        .card-grid-wrapper { padding: 3rem 1rem; background-color: #1f1f1f; text-align: center; }
        .card-grid-wrapper h2 { color: #FFC107; margin-bottom: 2rem; font-size: 2rem; }
        .products-panel { max-height: 70vh; overflow-y: auto; padding-right: 6px; }
        .products-panel::-webkit-scrollbar { width: 8px; }
        .products-panel::-webkit-scrollbar-thumb { background: #444; border-radius: 4px; }
        .card-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; justify-items: center; }
        .card-row + .card-row { margin-top: 20px; }
        .filter-toolbar { display: flex; gap: 12px; justify-content: center; align-items: center; flex-wrap: wrap; margin-bottom: 16px; }
        .filter-toolbar select { background: #fff; color: #000; border: 1px solid #ccc; border-radius: 6px; padding: 6px 10px; }
        
        /* Card styles from user-provided snippet */
        .card {
          --font-color: #323232;
          --font-color-sub: #666;
          --bg-color: #fff;
          --main-color: #323232;
          --main-focus: #2d8cf0;
          width: 260px;
          height: auto;
          min-height: 360px;
          background: var(--bg-color);
          border: 2px solid var(--main-color);
          box-shadow: 4px 4px var(--main-color);
          border-radius: 5px;
          display: flex;
          flex-direction: column;
          justify-content: flex-start;
          padding: 20px;
          gap: 10px;
          font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Open Sans', 'Helvetica Neue', sans-serif;
        }

        .card:last-child { justify-content: flex-end; }

        .card-img { transition: all 0.5s; display: flex; justify-content: center; }

        .card-img .img { transform: none; position: relative; box-sizing: border-box; width: 140px; height: 140px; border: 0; background-color: transparent; background-size: contain; background-repeat: no-repeat; background-position: center; }

        .card-title { font-size: 20px; font-weight: 500; text-align: center; color: var(--font-color); }
        .card-subtitle { font-size: 14px; font-weight: 400; color: var(--font-color-sub); }
        .card-divider { width: 100%; border: 1px solid var(--main-color); border-radius: 50px; }
        .card-footer { display: flex; flex-direction: row; justify-content: space-between; align-items: center; margin-top: auto; gap: 10px; }
        .card-price { font-size: 20px; font-weight: 500; color: var(--font-color); }
        .card-price span { font-size: 20px; font-weight: 500; color: var(--font-color-sub); }
        /* Add subtle chip styling to price to separate it from the button */
        .card-price { background: #f5f5f5; border: 1px solid #ddd; border-radius: 6px; padding: 6px 12px; }
        .card-btn { height: 35px; background: var(--bg-color); border: 2px solid var(--main-color); border-radius: 5px; padding: 0 15px; transition: all 0.3s; min-width: 44px; }
        .card-btn svg { width: 100%; height: 100%; fill: var(--main-color); transition: all 0.3s; }
        .card-img:hover { transform: translateY(-3px); }
        .card-btn:hover { border: 2px solid var(--main-focus); }
        .card-btn:hover svg { fill: var(--main-focus); }
        .card-btn:active { transform: translateY(3px); }

        @media (max-width: 1200px) { .card-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (max-width: 900px)  { .card-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .filter-toolbar { justify-content: stretch; padding: 0 8px; } .filter-toolbar select { flex: 1; } }
        /* Mobile: show two cards per view and allow horizontal swipe */
        @media (max-width: 560px)  {
            /* Hide vertical panel on mobile, show two horizontal rows */
            .products-panel { display: none; }
            .card-rows { display: flex; flex-direction: column; gap: 12px; }
            .card-row-scroll { display: flex; gap: 10px; overflow-x: auto; -webkit-overflow-scrolling: touch; scroll-snap-type: x mandatory; padding: 4px 2px 8px; }
            .card-row-scroll .card { flex: 0 0 calc(50% - 10px); min-width: calc(50% - 10px); scroll-snap-align: start; }
            .card { min-height: 310px; padding: 12px; gap: 8px; }
            .card-img .img { width: 110px; height: 110px; }
            .card-title { font-size: 15px; }
            .card-subtitle { font-size: 12px; }
            .card-divider { margin-top: 4px; }
            .card-footer { margin-top: auto; }
            .card-price { font-size: 15px; padding: 4px 8px; }
            .card-price span { font-size: 15px; }
            .card-btn { height: 28px; padding: 0 10px; min-width: 38px; }
        }
        /* ===== End: Product Card Grid (inserted) ===== */
    </style>

</head>

<body>
    <!-- Spinner Start -->
    <div id="spinner">
        <img src="img/Loading.gif" alt="Loading..." />
    </div>
    <!-- Spinner End -->

    <!-- Header Section -->
    <header class="navbar">
        <div class="logo"> CJ <span>PowerHouse</span> </div>
        <nav> <a href="#home">Home</a> <a href="#how-it-works">How It Works</a> <a href="#featured">Featured Products</a> <a href="#feedback">Reviews</a> <a href="#contact">Contact</a> </nav>
    </header>

    <!-- Shop Images Section -->
     <section id="shop-images" class="shop">
        <div class="shop-gallery">
            <div class="shop-item"> <img src="Image/pic6.jfif" alt="Motorcycle Shop Image 1"> </div>
            <div class="shop-item"> <img src="Image/pic5.jpg" alt="Motorcycle Shop Image 2"> </div>
        </div>
    </section>

    <!-- Removed filter script -->

    <!-- Hero Section -->
     <section id="home" class="hero">
        <div class="hero-content">
            <img src="Image/logo.png" alt="Logo" class="center-logo">
            <h1>Welcome to CJ PowerHouse</h1>
            <p>Get Premium Motorcycle Accessories at Affordable Prices!</p>
            <button type="button" class="btn btn-warning btn-lg" data-bs-toggle="modal" data-bs-target="#loginModal"> Shop now! <i class="fas fa-shopping-cart ms-2"></i> </button>
            <a href="<?= $baseURL ?>signin.php" class="btn btn-outline-warning btn-lg ms-2" id="staffSignInBtn" style="display: none; opacity: 0; transition: opacity 0.3s ease-in-out;">Staff Sign In</a>
        </div>
        </div>
    </section>

    <!-- How It Works Section -->
     <section id="how-it-works" class="info">
        <h2>How Our System Works</h2>
        <div class="steps">
             <!-- Removed me-2 from icons for better centering with text-align:center -->
            <div class="step"> <h3><i class="fas fa-search"></i>Browse</h3> <p>Explore a wide range of high-quality motorcycle accessories.</p> </div>
            <div class="step"> <h3><i class="fas fa-cart-plus"></i>Order</h3> <p>Select your desired items and proceed to checkout securely.</p> </div>
            <div class="step"> <h3><i class="fas fa-truck-fast"></i>Delivery</h3> <p>Get your products delivered fast right to your doorstep!</p> </div>
        </div>
    </section>

    <!-- Browse Products Cards Section (inserted) -->
     <section id="browse-products" class="card-grid-wrapper">
        <h2>Browse Products</h2>
        <!-- Removed broken filter toolbar -->
        <!-- Desktop/Tablet vertical scroll panel -->
        <div class="products-panel">
            <div class="card-grid" id="bp-grid">
            <?php if (!empty($products)): ?>
                <?php foreach ($products as $product): ?>
                    <?php $catRaw = isset($product['Category']) ? $product['Category'] : (isset($product['category']) ? $product['category'] : ''); $catNorm = strtolower(trim($catRaw)); ?>
                    <div class="card" data-price="<?php echo htmlspecialchars($product['Price']); ?>" data-category="<?php echo htmlspecialchars($catNorm); ?>">
                        <div class="card-img">
                            <div class="img" style="background-image:url('<?php echo htmlspecialchars($product['ImagePath']); ?>');"></div>
                        </div>
                        <div class="card-title"><?php echo htmlspecialchars($product['ProductName']); ?></div>
                        <div class="card-subtitle">Premium accessory from our catalog.</div>
                        <hr class="card-divider">
                        <div class="card-footer">
                            <div class="card-price"><span>₱</span> <?php echo htmlspecialchars(number_format($product['Price'], 2)); ?></div>
                            <button class="card-btn" data-bs-toggle="modal" data-bs-target="#loginModal">
                              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="m397.78 316h-205.13a15 15 0 0 1 -14.65-11.67l-34.54-150.48a15 15 0 0 1 14.62-18.36h274.27a15 15 0 0 1 14.65 18.36l-34.6 150.48a15 15 0 0 1 -14.62 11.67zm-193.19-30h181.25l27.67-120.48h-236.6z"></path><path d="m222 450a57.48 57.48 0 1 1 57.48-57.48 57.54 57.54 0 0 1 -57.48 57.48zm0-84.95a27.48 27.48 0 1 0 27.48 27.47 27.5 27.5 0 0 0 -27.48-27.47z"></path><path d="m368.42 450a57.48 57.48 0 1 1 57.48-57.48 57.54 57.54 0 0 1 -57.48 57.48zm0-84.95a27.48 27.48 0 1 0 27.48 27.47 27.5 27.5 0 0 0 -27.48-27.47z"></path><path d="m158.08 165.49a15 15 0 0 1 -14.23-10.26l-25.71-77.23h-47.44a15 15 0 1 1 0-30h58.3a15 15 0 0 1 14.23 10.26l29.13 87.49a15 15 0 0 1 -14.23 19.74z"></path></svg>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No products available right now.</p>
            <?php endif; ?>
            </div>
        </div>

        <!-- Mobile: two horizontal swipe rows for faster browsing -->
        <div class="card-rows d-sm-block d-md-none">
            <?php $total = count($products); $half = (int)ceil($total/2); ?>
            <div class="card-row-scroll">
                <?php foreach ($products as $idx => $product): if ($idx >= $half) break; ?>
                    <?php $catRaw = isset($product['Category']) ? $product['Category'] : (isset($product['category']) ? $product['category'] : ''); $catNorm = strtolower(trim($catRaw)); ?>
                    <div class="card" data-price="<?php echo htmlspecialchars($product['Price']); ?>" data-category="<?php echo htmlspecialchars($catNorm); ?>">
                        <div class="card-img"><div class="img" style="background-image:url('<?php echo htmlspecialchars($product['ImagePath']); ?>');"></div></div>
                        <div class="card-title"><?php echo htmlspecialchars($product['ProductName']); ?></div>
                        <div class="card-subtitle">Premium accessory from our catalog.</div>
                        <hr class="card-divider">
                        <div class="card-footer">
                            <div class="card-price"><span>₱</span> <?php echo htmlspecialchars(number_format($product['Price'], 2)); ?></div>
                            <button class="card-btn" data-bs-toggle="modal" data-bs-target="#loginModal">
                              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="m397.78 316h-205.13a15 15 0 0 1 -14.65-11.67l-34.54-150.48a15 15 0 0 1 14.62-18.36h274.27a15 15 0 0 1 14.65 18.36l-34.6 150.48a15 15 0 0 1 -14.62 11.67zm-193.19-30h181.25l27.67-120.48h-236.6z"></path><path d="m222 450a57.48 57.48 0 1 1 57.48-57.48 57.54 57.54 0 0 1 -57.48 57.48zm0-84.95a27.48 27.48 0 1 0 27.48 27.47 27.5 27.5 0 0 0 -27.48-27.47z"></path><path d="m368.42 450a57.48 57.48 0 1 1 57.48-57.48 57.54 57.54 0 0 1 -57.48 57.48zm0-84.95a27.48 27.48 0 1 0 27.48 27.47 27.5 27.5 0 0 0 -27.48-27.47z"></path><path d="m158.08 165.49a15 15 0 0 1 -14.23-10.26l-25.71-77.23h-47.44a15 15 0 1 1 0-30h58.3a15 15 0 0 1 14.23 10.26l29.13 87.49a15 15 0 0 1 -14.23 19.74z"></path></svg>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="card-row-scroll">
                <?php foreach ($products as $idx => $product): if ($idx < $half) continue; ?>
                    <?php $catRaw = isset($product['Category']) ? $product['Category'] : (isset($product['category']) ? $product['category'] : ''); $catNorm = strtolower(trim($catRaw)); ?>
                    <div class="card" data-price="<?php echo htmlspecialchars($product['Price']); ?>" data-category="<?php echo htmlspecialchars($catNorm); ?>">
                        <div class="card-img"><div class="img" style="background-image:url('<?php echo htmlspecialchars($product['ImagePath']); ?>');"></div></div>
                        <div class="card-title"><?php echo htmlspecialchars($product['ProductName']); ?></div>
                        <div class="card-subtitle">Premium accessory from our catalog.</div>
                        <hr class="card-divider">
                        <div class="card-footer">
                            <div class="card-price"><span>₱</span> <?php echo htmlspecialchars(number_format($product['Price'], 2)); ?></div>
                            <button class="card-btn" data-bs-toggle="modal" data-bs-target="#loginModal">
                              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="m397.78 316h-205.13a15 15 0 0 1 -14.65-11.67l-34.54-150.48a15 15 0 0 1 14.62-18.36h274.27a15 15 0 0 1 14.65 18.36l-34.6 150.48a15 15 0 0 1 -14.62 11.67zm-193.19-30h181.25l27.67-120.48h-236.6z"></path><path d="m222 450a57.48 57.48 0 1 1 57.48-57.48 57.54 57.54 0 0 1 -57.48 57.48zm0-84.95a27.48 27.48 0 1 0 27.48 27.47 27.5 27.5 0 0 0 -27.48-27.47z"></path><path d="m368.42 450a57.48 57.48 0 1 1 57.48-57.48 57.54 57.54 0 0 1 -57.48 57.48zm0-84.95a27.48 27.48 0 1 0 27.48 27.47 27.5 27.5 0 0 0 -27.48-27.47z"></path><path d="m158.08 165.49a15 15 0 0 1 -14.23-10.26l-25.71-77.23h-47.44a15 15 0 1 1 0-30h58.3a15 15 0 0 1 14.23 10.26l29.13 87.49a15 15 0 0 1 -14.23 19.74z"></path></svg>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        </div>
    </section>

    <!-- Featured Products Section -->
     <section id="featured" class="items">
        <h2>Featured Products</h2>
        <div class="product-carousel">
            <?php if (!empty($products)): ?>
                <?php foreach ($products as $index => $product): ?>
                <div class="product-item <?php echo ($index === 0) ? 'active' : ''; ?>">
                    <img class="product-image" src="<?php echo htmlspecialchars($product['ImagePath']); ?>" alt="<?php echo htmlspecialchars($product['ProductName']); ?>">
                    <h3><?php echo htmlspecialchars($product['ProductName']); ?></h3>
                    <p>Price: ₱<?php echo htmlspecialchars(number_format($product['Price'], 2)); ?></p>
                    <button class="btn shop-now-button" data-bs-toggle="modal" data-bs-target="#loginModal"> Shop Now <i class="fas fa-arrow-right ms-1"></i> </button>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No featured products to display at this moment.</p>
            <?php endif; ?>
        </div>
    </section>

    <!-- Feedback product section -->
      <section id="feedback" class="feedback">
        <h2>Customer Reviews</h2>
        <div class="feedback-carousel">
            <?php if (!empty($feedbacks)): ?>
                <?php foreach ($feedbacks as $index => $feedback): ?>
                <div class="feedback-slide <?php echo ($index === 0) ? 'active' : ''; ?>">
                    <div class="feedback-container">
                        <div class="user-info mb-2">
                             <img src="<?php echo htmlspecialchars($feedback['user_image'] ?: 'Image/default-avatar.png'); ?>" alt="User Profile">
                            <div> <span><?php echo htmlspecialchars($feedback['first_name'] . ' ' . $feedback['last_name']); ?></span> <small class="text-muted d-block"><?php echo htmlspecialchars(date('F j, Y', strtotime($feedback['created_at']))); ?></small> </div>
                        </div>
                        <div class="rating mb-2">
                            <?php $rating = intval($feedback['rating']); for ($i = 1; $i <= 5; $i++) { $starClass = ($i <= $rating) ? 'fas fa-star star checked star-size' : 'far fa-star star star-size'; echo '<i class="' . $starClass . '"></i>'; } ?>
                        </div>
                        <p class="mb-2"><em>"<?php echo nl2br(htmlspecialchars($feedback['comment'])); ?>"</em></p>
                        <?php if (!empty($feedback['image_path'])): ?> <img src="<?php echo htmlspecialchars($feedback['image_path']); ?>" alt="Feedback Image"> <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No customer reviews yet. Be the first!</p>
            <?php endif; ?>
        </div>
    </section>

    <!-- Contact Section -->
     <section id="contact" class="contact">
        <h2>Contact Us</h2>
        <p>Have questions? Reach out to us!</p>
        <div class="contact-info mb-3">
             <p><i class="fas fa-map-marker-alt me-2"></i>Location: P5 Sinayawan, Valencia City, Bukidnon</p>
             <p><i class="fas fa-phone me-2"></i>Phone: <a href="tel:09513866413" class="text-white">0951-386-6413</a></p>
             <p><i class="fab fa-facebook me-2"></i>Facebook: <a href="https://www.facebook.com/profile.php?id=100076358041290" target="_blank" class="text-white">Visit our Page</a></p>
        </div>
        <div class="map-container"> <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3957.6592454474054!2d125.17778847477234!3d7.920996092079601!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x32547a3518d63b9b%3A0x649d02f90af95642!2sSinayawan%2C%20Valencia%20City%2C%20Bukidnon!5e0!3m2!1sen!2sph!4v1713696397868!5m2!1sen!2sph" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe> </div>
    </section>

    <!-- Footer Section -->
     <footer>
        <p>© <?php echo date("Y"); ?> CJ PowerHouse. All Rights Reserved.</p>
         <p><a href="#" data-bs-toggle="modal" data-bs-target="#privacyPolicyModal">Privacy Policy</a></p>
    </footer>

    <!-- ============================================================== -->
    <!-- LOGIN MODAL START (AJAX IMPLEMENTATION) -->
    <!-- ============================================================== -->
    <div class="modal fade" id="loginModal" tabindex="-1" aria-labelledby="loginModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-dark text-white">
                <div class="modal-header">
                    <img src="Image/logo.png" alt="Logo" class="modal-logo">
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <h5 class="modal-title text-center mb-4" id="loginModalLabel">Login to Your Account</h5>
                    <div id="loginErrorMessages" class="alert alert-danger d-none" role="alert">
                        <?php if (!empty($login_error)) { echo htmlspecialchars($login_error); echo '<script>document.addEventListener("DOMContentLoaded", function() { var el = document.getElementById("loginErrorMessages"); if(el) { el.classList.remove("d-none"); el.style.display="block !important";} });</script>'; } ?>
                    </div>
                    
                    <?php if ($verification_success): ?>
                        <div class="alert alert-success" role="alert">
                            <i class="fas fa-check-circle me-2"></i>
                            Email verified successfully! You can now log in to your account.
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($verification_error): ?>
                        <div class="alert alert-danger" role="alert">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Email verification failed. Please try again or contact support.
                        </div>
                    <?php endif; ?>
                    <form id="loginForm" method="post" action="customer_login.php">
                        <div class="form-floating mb-3">
                            <input type="email" class="form-control" id="loginEmail" name="email" placeholder="name@example.com" required>
                            <label for="loginEmail">Email address</label>
                             <div class="invalid-feedback">Please enter your email.</div><!-- Basic feedback -->
                        </div>
                        <div class="input-group mb-3 position-relative"> <!-- Added position-relative -->
                             <input type="password" class="form-control" id="loginPassword" name="password" placeholder="Password" required>
                             <button class="btn btn-outline-secondary eye-icon" type="button" id="togglePassword">
                                 <i class="fa-solid fa-eye" aria-hidden="true"></i>
                             </button>
                              <div class="invalid-feedback" style="width: 100%; position: absolute; top: 100%; left: 0; margin-top: .25rem;">Please enter your password.</div><!-- Basic feedback -->
                        </div>
                        <div class="d-flex justify-content-end mb-1">
                            <a href="#" class="text-warning" data-bs-toggle="modal" data-bs-target="#forgotPasswordModal" data-bs-dismiss="modal">Forgot password?</a>
                        </div>
                        <button type="submit" id="loginButton" class="btn btn-warning w-100 py-2 mt-4">Login</button> <!-- Added margin-top -->
                    </form>
                    <p class="mt-3 text-center">Don't have an account? <a href="#" data-bs-toggle="modal" data-bs-target="#signupModal" data-bs-dismiss="modal" class="text-warning">Sign Up</a></p>
                </div>
            </div>
        </div>
    </div>
    <!-- ============================================================== -->
    <!-- LOGIN MODAL END -->
    <!-- ============================================================== -->


    <!-- ============================================================== -->
    <!-- SIGNUP MODAL START (Remains the same as your provided code) -->
    <!-- ============================================================== -->
      <div class="modal fade" id="signupModal" tabindex="-1" aria-labelledby="signupModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-dark text-white">
                <div class="modal-header"> <h5 class="modal-title w-100 text-center" id="signupModalLabel">Create Your Account</h5> <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button> </div>
                <div class="modal-body">
                    <div id="signupServerErrors" class="alert alert-danger <?php $general_server_errors = array_filter($server_errors ?? [], function($key){ return !in_array($key, ['email_taken', 'upload_error']); }, ARRAY_FILTER_USE_KEY); echo empty($general_server_errors) ? 'd-none' : ''; ?>" role="alert">
                        <?php if(!empty($general_server_errors)) { echo '<strong>Please correct the following:</strong><br>'; foreach($general_server_errors as $error) { echo htmlspecialchars($error) . '<br>'; } } ?>
                    </div>
                    <div id="signupErrorMessages" class="alert alert-danger d-none" role="alert"></div>
                     <form id="signupForm" method="POST" enctype="multipart/form-data" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" novalidate>
                         <div class="mb-3 text-center">
                             <label for="profilePicture" class="form-label">Add Profile Image <span class="text-danger">*</span></label>
                             <input type="file" id="profilePicture" name="profilePicture" accept="image/jpeg,image/png,image/gif,image/webp" class="form-control mt-2 <?php echo isset($server_errors['upload_error']) ? 'is-invalid' : ''; ?>" required>
                             <div class="invalid-feedback" data-default-error="Profile picture is required (JPG, PNG, GIF, WEBP).">
                                 <?php echo isset($server_errors['upload_error']) ? htmlspecialchars($server_errors['upload_error']) : 'Profile picture is required (JPG, PNG, GIF, WEBP).'; ?>
                             </div>
                         </div>
                         <div class="row g-2 mb-3">
                             <div class="col-md"> <div class="form-floating"> <input type="text" name="first_name" id="first_name" class="form-control" placeholder="First Name" required value="<?php echo htmlspecialchars($form_data['first_name'] ?? ''); ?>"> <label for="first_name">First Name <span class="text-danger">*</span></label> <div class="invalid-feedback" data-default-error="First name is required.">First name is required.</div> </div> </div>
                             <div class="col-md"> <div class="form-floating"> <input type="text" name="last_name" id="last_name" class="form-control" placeholder="Last Name" required value="<?php echo htmlspecialchars($form_data['last_name'] ?? ''); ?>"> <label for="last_name">Last Name <span class="text-danger">*</span></label> <div class="invalid-feedback" data-default-error="Last name is required.">Last name is required.</div> </div> </div>
                         </div>
                        <div class="form-floating mb-3"> <input type="text" name="middle_name" id="middle_name" class="form-control" placeholder="Middle Name (Optional)" value="<?php echo htmlspecialchars($form_data['middle_name'] ?? ''); ?>"> <label for="middle_name">Middle Name (Optional)</label> </div>
                        <div class="form-floating mb-3">
                            <input type="email" name="email" id="email" class="form-control <?php echo isset($server_errors['email_taken']) ? 'is-invalid' : ''; ?>" placeholder="Email" required value="<?php echo htmlspecialchars($form_data['email'] ?? ''); ?>">
                            <label for="email">Email <span class="text-danger">*</span></label>
                            <div class="invalid-feedback" data-default-error="Please enter a valid email address.">
                                <?php echo isset($server_errors['email_taken']) ? htmlspecialchars($server_errors['email_taken']) : 'Please enter a valid email address.'; ?>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="contactinfo">Phone Number <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"> <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/9/99/Flag_of_the_Philippines.svg/32px-Flag_of_the_Philippines.svg.png" alt="PH Flag" style="width: 20px; height: auto; margin-right: 5px;"> +63 </span>
                                <input type="tel" name="contactinfo" id="contactinfo" class="form-control" placeholder="9xxxxxxxxx" required pattern="^9[0-9]{9}$" maxlength="10" title="Enter 10 digits starting with 9 (e.g., 9171234567)" value="<?php echo htmlspecialchars($form_data['contactinfo'] ?? ''); ?>">
                                <div class="invalid-feedback" data-default-error="Please enter a valid 10-digit PH mobile number starting with 9.">Please enter a valid 10-digit PH mobile number starting with 9.</div>
                            </div>
                        </div>
                        <div class="form-floating mb-3 position-relative">
                            <input type="password" name="password" id="password" class="form-control" placeholder="Password" required aria-describedby="passwordStrengthIndicator" style="padding-right: 80px;">
                            <label for="password">Password <span class="text-danger">*</span></label>
                            <span class="eye-icon" id="toggleSignupPassword"> <i class="fa-solid fa-eye"></i> </span>
                            <div class="invalid-feedback" data-default-error="Please enter a valid password (7+ chars, 1 uppercase)."> Please enter a valid password (7+ chars, 1 uppercase). </div>
                        </div>
                        <div id="passwordStrengthIndicator" class="form-text mt-1" style="min-height: 1.2em; margin-top: -0.5rem;"></div>
                        <div class="form-floating mb-3">
                            <select id="barangay" name="barangay" class="form-select" required>
                                <option value="" selected disabled>Select Barangay</option>
                                <?php
                                if ($barangayResult && $barangayResult->num_rows > 0) {
                                    $barangayResult->data_seek(0);
                                    while ($row = $barangayResult->fetch_assoc()) {
                                        $selected = (isset($form_data['barangay']) && $form_data['barangay'] == $row['id']) ? 'selected' : '';
                                        echo "<option value='" . htmlspecialchars($row['id']) . "' $selected>" . htmlspecialchars($row['barangay_name']) . "</option>";
                                    }
                                } else { echo "<option value='' disabled>Could not load barangays</option>"; }
                                ?>
                            </select>
                            <label for="barangay">Barangay <span class="text-danger">*</span></label>
                            <div class="invalid-feedback" data-default-error="Please select your Barangay.">Please select your Barangay.</div>
                        </div>
                        <div class="form-floating mb-3">
                            <input type="text" id="purok" name="purok" class="form-control" placeholder="Enter Purok/Street/House #" required value="<?php echo htmlspecialchars($form_data['purok'] ?? ''); ?>">
                            <label for="purok">Purok/Street/House # <span class="text-danger">*</span></label>
                            <div class="invalid-feedback" data-default-error="Please enter your Purok/Street/House #.">Please enter your Purok/Street/House #.</div>
                        </div>
                         <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" value="agreed" id="privacy_policy" name="privacy_policy" required <?php echo (isset($form_data['privacy_policy'])) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="privacy_policy"> I agree to the <a href="#" data-bs-toggle="modal" data-bs-target="#privacyPolicyModal" class="text-warning">Data Privacy Policy</a> <span class="text-danger">*</span> </label>
                             <div class="invalid-feedback" data-default-error="You must agree to the Data Privacy Policy to proceed.">You must agree to the Data Privacy Policy to proceed.</div>
                        </div>
                        <button type="submit" name="signup_submit" class="btn btn-warning py-2 w-100">Sign Up</button>
                        <p class="text-center mt-3">Already have an account? <a href="#" data-bs-toggle="modal" data-bs-target="#loginModal" data-bs-dismiss="modal" class="text-warning">Login</a></p>
                    </form>
                    

                </div>
            </div>
        </div>
    </div>
    <!-- ============================================================== -->
    <!-- SIGNUP MODAL END -->
    <!-- ============================================================== -->


    <!-- Privacy Policy Modal -->
     <div class="modal fade" id="privacyPolicyModal" tabindex="-1" aria-labelledby="privacyPolicyModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content bg-dark text-white">
                <div class="modal-header"> <h5 class="modal-title" id="privacyPolicyModalLabel">Data Privacy Policy</h5> <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button> </div>
                <div class="modal-body"> <p><strong></strong></p><p>CJ PowerHouse is committed to protecting your privacy. This Data Privacy Policy explains how we collect, use, disclose, and safeguard your information when you visit our website and use our services.</p><h4>1. Information We Collect</h4><p>We may collect personal information that you voluntarily provide to us when you register on the website, place an order, subscribe to our newsletter, respond to a survey, fill out a form, or otherwise interact with the site. The information we may collect includes:</p><ul><li><strong>Personal Identification Information:</strong> Name (first, middle, last), email address, phone number, delivery address (including Barangay and Purok/Street/House #).</li><li><strong>Account Information:</strong> Username, password, profile picture.</li><li><strong>Transaction Information:</strong> Details about products you purchase, order history, payment information (though sensitive payment details like full credit card numbers are typically processed by third-party payment gateways and not stored by us).</li><li><strong>Technical Data:</strong> IP address, browser type, operating system, referring URLs, pages visited, and timestamps when you access our site.</li><li><strong>Usage Data:</strong> Information about how you use our website, products, and services.</li></ul><h4>2. How We Use Your Information</h4><p>We use the information we collect for various purposes, including:</p><ul><li>To create and manage your account.</li><li>To process your transactions and deliver the products you ordered.</li><li>To communicate with you, including sending order confirmations, shipping notifications, and responding to inquiries.</li><li>To improve our website, products, and services based on your feedback and usage patterns.</li><li></li><li>To personalize your experience on our website.</li><li>To ensure the security of our website and prevent fraud.</li><li>To comply with legal obligations.</li></ul><h4>3. How We Protect Your Information</h4><p>We implement a variety of security measures to maintain the safety of your personal information:</p><ul><li>Using secure servers (SSL/TLS encryption for data transmission).</li><li>Hashing passwords before storing them in our database.</li><li>Regularly reviewing our information collection, storage, and processing practices.</li><li>Restricting access to personal information to authorized personnel only.</li></ul><p>However, no electronic transmission over the Internet or information storage technology can be guaranteed to be 100% secure. While we strive to use commercially acceptable means to protect your personal information, we cannot guarantee its absolute security.</p><h4>4. Sharing Your Information</h4><p>We do not sell, trade, or otherwise transfer your personally identifiable information to outside parties except in the following circumstances:</p><ul><li><strong>Service Providers:</strong> We may share information with third-party vendors who perform services on our behalf, such as payment processing, order fulfillment, delivery services, email delivery, and website hosting. These providers only have access to the information necessary to perform their functions and are obligated to protect your information.</li><li><strong>Legal Requirements:</strong> We may disclose your information if required to do so by law or in response to valid requests by public authorities (e.g., a court or government agency).</li><li><strong>Business Transfers:</strong> If we are involved in a merger, acquisition, or asset sale, your personal information may be transferred.</li><li><strong>With Your Consent:</strong> We may share your information for other purposes with your explicit consent.</li></ul><h4>5. Your Data Protection Rights</h4><p>Depending on your location, you may have the following rights regarding your personal data:</p><ul><li><strong>Right to Access:</strong> You can request copies of your personal data.</li><li><strong>Right to Rectification:</strong> You can request that we correct any information you believe is inaccurate or complete information you believe is incomplete.</li><li><strong>Right to Erasure:</strong> You can request that we erase your personal data, under certain conditions.</li><li><strong>Right to Restrict Processing:</strong> You can request that we restrict the processing of your personal data, under certain conditions.</li><li><strong>Right to Object to Processing:</strong> You can object to our processing of your personal data, under certain conditions.</li><li><strong>Right to Data Portability:</strong> You can request that we transfer the data that we have collected to another organization, or directly to you, under certain conditions.</li></ul><p>To exercise any of these rights, please contact us using the contact information provided below.</p><h4>6. Cookies and Tracking Technologies</h4><p>We may use cookies and similar tracking technologies to track activity on our website and hold certain information. You can instruct your browser to refuse all cookies or to indicate when a cookie is being sent. However, if you do not accept cookies, you may not be able to use some portions of our service.</p><h4>7. Changes to This Privacy Policy</h4><p>We may update our Privacy Policy from time to time. We will notify you of any changes by posting the new Privacy Policy on this page and updating the "Last Updated" date. You are advised to review this Privacy Policy periodically for any changes.</p><h4>8. Contact Us</h4><p>If you have any questions about this Data Privacy Policy, please contact us:</p><ul><li>By email: CjPowerhouse@Gmail.com</li><li>By phone number: 0951-386-6413</li><li>By visiting us Located @Brgy Sinayawan P-5 Valencia City, Bukidnon 8709 </li></ul></div>
                <div class="modal-footer"> <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button> </div>
            </div>
        </div>
    </div>
    <!-- End Privacy Policy Modal -->

    <!-- Registration Success Modal -->
    <div class="modal fade" id="registrationSuccessModal" tabindex="-1" aria-labelledby="registrationSuccessModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-dark text-white">
                <div class="modal-header border-0">
                     <h5 class="modal-title w-100 text-center" id="registrationSuccessModalLabel">
                        <i class="fas fa-check-circle text-success me-2"></i>Registration Successful!
                    </h5>
                </div>
                <div class="modal-body text-center">
                    <p>Welcome to CJ PowerHouse!</p>
                    <p>Your account has been created successfully.</p>
                    <img src="Image/logo.png" alt="Logo" class="modal-logo" style="width: 80px; margin-top: 15px;">
                </div>
                <div class="modal-footer border-0 justify-content-center">
                    <button type="button" id="proceedToDashboardBtn" class="btn btn-success">Proceed to Dashboard</button>
                </div>
            </div>
        </div>
    </div>
    <!-- End Registration Success Modal -->

    <!-- Email Verification Required Modal -->
    <div class="modal fade" id="emailVerificationModal" tabindex="-1" aria-labelledby="emailVerificationModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-dark text-white">
                <div class="modal-header border-0">
                     <h5 class="modal-title w-100 text-center" id="emailVerificationModalLabel">
                        <i class="fas fa-envelope text-warning me-2"></i>Email Verification Required
                    </h5>
                </div>
                <div class="modal-body text-center">
                    <p>Welcome to CJ PowerHouse!</p>
                    <p>Your account has been created successfully, but we need to verify your email address before you can access your account.</p>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        We've sent a verification email to: <strong><?php echo isset($_SESSION['pending_verification']['email']) ? htmlspecialchars($_SESSION['pending_verification']['email']) : ''; ?></strong>
                    </div>
                    
                    <p>Please check your email inbox (and spam folder) for a verification link.</p>
                    <p>Click the verification link in the email to activate your account.</p>
                    
                    <img src="Image/logo.png" alt="Logo" class="modal-logo" style="width: 80px; margin-top: 15px;">
                </div>
                <div class="modal-footer border-0 justify-content-center">
                    <button type="button" id="resendVerificationBtn" class="btn btn-warning me-2">
                        <i class="fas fa-paper-plane me-2"></i>Resend Email
                    </button>
                    <button type="button" id="closeVerificationModalBtn" class="btn btn-secondary">Close</button>
                </div>
            </div>
        </div>
    </div>
    <!-- End Email Verification Required Modal -->

    <!-- Forgot Password Modal -->
    <div class="modal fade" id="forgotPasswordModal" tabindex="-1" aria-labelledby="forgotPasswordModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-dark text-white">
                <div class="modal-header">
                    <h5 class="modal-title" id="forgotPasswordModalLabel">Reset your password</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="forgotAlert" class="alert d-none" role="alert"></div>
                    <form id="forgotPasswordForm">
                        <div class="form-floating mb-3">
                            <input type="email" class="form-control" id="forgotEmail" name="email" placeholder="name@example.com" required>
                            <label for="forgotEmail">Email address</label>
                            <div class="invalid-feedback">Please enter a valid email.</div>
                        </div>
                        <button type="submit" id="forgotSubmitBtn" class="btn btn-warning w-100">Send reset link</button>
                    </form>
                </div>
            </div>
        </div>
    </div>


    <!-- ============================================================== -->
    <!-- SCRIPTS START -->
    <!-- ============================================================== -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Spinner functions
        function showSpinner() { const spinner = document.getElementById("spinner"); if (spinner) spinner.classList.add("show"); }
        function hideSpinner() { const spinner = document.getElementById("spinner"); if (spinner) spinner.classList.remove("show"); }
        showSpinner(); window.addEventListener('load', function () { setTimeout(hideSpinner, 500); });

        document.addEventListener('DOMContentLoaded', function () {

            // --- Modal Instances ---
            const loginModalEl = document.getElementById('loginModal');
            const signupModalEl = document.getElementById('signupModal');
            const privacyModalEl = document.getElementById('privacyPolicyModal');
            const successModalEl = document.getElementById('registrationSuccessModal');
            const loginModal = loginModalEl ? new bootstrap.Modal(loginModalEl) : null;
            const signupModal = signupModalEl ? new bootstrap.Modal(signupModalEl) : null;
            const privacyModal = privacyModalEl ? new bootstrap.Modal(privacyModalEl) : null;
            const successModal = successModalEl ? new bootstrap.Modal(successModalEl) : null;

            // --- Modal Switching Logic ---
              if (loginModalEl && signupModalEl) {
                  signupModalEl.addEventListener('click', (event) => { if (event.target.matches('a[data-bs-target="#loginModal"]')) signupModal?.hide(); });
                  loginModalEl.addEventListener('click', (event) => { if (event.target.matches('a[data-bs-target="#signupModal"]')) loginModal?.hide(); });
              }
              if (signupModalEl && privacyModalEl) {
                   privacyModalEl.addEventListener('hidden.bs.modal', () => { if (document.querySelectorAll('.modal.show').length === 0) { document.body.style.overflow = 'auto'; document.body.style.paddingRight = ''; } else { document.body.classList.add('modal-open'); document.body.style.overflow = 'hidden'; } });
              }

            // --- "Shop Now" Buttons ---
              document.querySelectorAll('.shop-now-button').forEach(button => button.addEventListener('click', () => loginModal ? loginModal.show() : console.error("Login modal not found")));

             // --- Carousels ---
             function setupCarousel(selector, intervalTime) {
                 let slideIndex = 0;
                 const slides = document.querySelectorAll(selector);
                 if (slides.length <= 1) { // Only show if more than 0, activate if 1
                      if(slides.length === 1) {
                           slides[0].classList.add('active');
                           slides[0].style.opacity = '1';
                           slides[0].style.position = 'relative';
                           slides[0].style.zIndex = '1';
                      }
                      return; // No interval needed for 0 or 1 slide
                 }
                 let interval;
                 function showSlides() {
                     slides.forEach((slide) => { slide.classList.remove('active'); slide.style.opacity = '0'; slide.style.position = 'absolute'; slide.style.zIndex = '0'; });
                     slideIndex++;
                     if (slideIndex > slides.length) slideIndex = 1;
                     const currentSlide = slides[slideIndex - 1];
                     if (currentSlide) { currentSlide.classList.add('active'); currentSlide.style.opacity = '1'; currentSlide.style.position = 'relative'; currentSlide.style.zIndex = '1'; }
                 }
                 function startCarousel() { stopCarousel(); showSlides(); interval = setInterval(showSlides, intervalTime); }
                 function stopCarousel() { clearInterval(interval); }
                 startCarousel();
             }
             setupCarousel('#feedback .feedback-slide', 5000);
             setupCarousel('#featured .product-item', 4000);


             // --- Password Toggles ---
             function setupPasswordToggle(toggleButtonId, passwordInputId) {
                const toggleButton = document.getElementById(toggleButtonId);
                const passwordInput = document.getElementById(passwordInputId);
                if (toggleButton && passwordInput) {
                    toggleButton.addEventListener('click', function () {
                        const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                        passwordInput.setAttribute('type', type);
                        const icon = this.querySelector('i');
                        if (icon) { icon.classList.toggle('fa-eye'); icon.classList.toggle('fa-eye-slash'); }
                    });
                }
             }
             setupPasswordToggle('togglePassword', 'loginPassword');
             setupPasswordToggle('toggleSignupPassword', 'password');

            // --- Signup Form Client-Side Validation ---
            const signupForm = document.getElementById('signupForm');
            const signupErrorMessages = document.getElementById('signupErrorMessages');
            const signupServerErrorsDiv = document.getElementById('signupServerErrors');
            const signupEmailInput = document.getElementById('email'); // Signup email specific

            if (signupServerErrorsDiv && !signupServerErrorsDiv.classList.contains('d-none') && signupModal) {
                signupModal.show(); signupForm?.closest('.modal-body')?.scrollTo(0, 0);
            } else if (signupEmailInput?.classList.contains('is-invalid') && signupModal) {
                 signupModal.show(); signupForm?.closest('.modal-body')?.scrollTo(0, 0);
            }

            if (signupForm) {
                function isValidPhilippineMobile(phone) { return /^9[0-9]{9}$/.test(phone); }
                function getPasswordStrength(password) { let score = 0; if (!password || password.length < 7) return 'weak'; if (password.length >= 7) score++; if (password.length >= 10) score++; if (/[A-Z]/.test(password)) score++; if (/[a-z]/.test(password)) score++; if (/[0-9]/.test(password)) score++; if (/[^A-Za-z0-9]/.test(password)) score++; if (score < 3) return 'weak'; if (score < 5) return 'good'; return 'very-good'; }

                const signupPasswordStrengthInput = document.getElementById('password');
                const strengthIndicator = document.getElementById('passwordStrengthIndicator');

                if (signupPasswordStrengthInput && strengthIndicator) {
                    signupPasswordStrengthInput.addEventListener('input', function() {
                        const password = signupPasswordStrengthInput.value;
                        const strength = getPasswordStrength(password);
                        strengthIndicator.className = 'form-text mt-1'; strengthIndicator.style.minHeight = '1.2em';
                        if (password.length === 0) { strengthIndicator.textContent = ''; }
                        else { switch(strength) { case 'weak': strengthIndicator.textContent = 'Strength: Weak'; strengthIndicator.classList.add('strength-weak'); break; case 'good': strengthIndicator.textContent = 'Strength: Good'; strengthIndicator.classList.add('strength-good'); break; case 'very-good': strengthIndicator.textContent = 'Strength: Very Good'; strengthIndicator.classList.add('strength-very-good'); break; } }
                        validateSignupField(signupPasswordStrengthInput); // Use specific validation function
                    });
                     if(signupPasswordStrengthInput.value) { signupPasswordStrengthInput.dispatchEvent(new Event('input')); }
                }

                 // --- Consolidated Signup Field Validation Function ---
                 function validateSignupField(field) {
                     let isValid = true; let errorMsg = '';
                     const parentContainer = field.closest('.mb-3, .col-md, .form-floating, .input-group, .form-check');
                     const feedbackDiv = parentContainer ? parentContainer.querySelector('.invalid-feedback') : null;
                     const defaultError = feedbackDiv?.dataset.defaultError || 'This field is required.';

                     field.classList.remove('is-invalid');
                     if (feedbackDiv) { feedbackDiv.style.display = 'none'; feedbackDiv.textContent = defaultError; }

                     // Required Check
                     if (field.required) {
                         if (field.type === 'checkbox' && !field.checked) isValid = false;
                         else if (field.type === 'file' && (!field.files || field.files.length === 0 || field.files[0].size === 0)) isValid = false;
                         else if (field.type !== 'checkbox' && field.type !== 'file' && field.value.trim() === '') isValid = false;
                     }
                     if (!isValid && !errorMsg) errorMsg = defaultError;

                     // Format/Pattern Checks (only if non-empty or not required but has value)
                     if ((isValid || !field.required) && field.value.trim() !== '') {
                          if (field.type === 'file' && field.files && field.files.length > 0) {
                             const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                             if (!allowedTypes.includes(field.files[0].type)) { isValid = false; errorMsg = 'Invalid file type (JPG, PNG, GIF, WEBP).'; }
                          } else if (field.type === 'email') {
                             if (!/\S+@\S+\.\S+/.test(field.value)) { isValid = false; errorMsg = "Invalid email format."; }
                             // Check server error only if field was marked invalid by server
                             else if (field.classList.contains('is-invalid') && feedbackDiv && feedbackDiv.textContent === "Email is taken , plss use another gmail") { isValid = false; errorMsg = "Email is taken , plss use another gmail"; }
                         } else if (field.id === 'contactinfo' && !isValidPhilippineMobile(field.value)) { isValid = false; errorMsg = "Must be 10 digits starting with 9."; }
                         else if (field.id === 'password' && field.value.trim()) { // Signup password check
                             const strength = getPasswordStrength(field.value);
                             if (strength === 'weak' || !/[A-Z]/.test(field.value) || field.value.length < 7) { isValid = false; errorMsg = "Password: 7+ chars, 1 uppercase."; }
                         } else if (field.pattern && !new RegExp(field.pattern).test(field.value)) { isValid = false; errorMsg = field.title || 'Invalid format.'; }
                     }

                     // Apply Validation State
                     if (!isValid) {
                         field.classList.add('is-invalid');
                         if (feedbackDiv) { feedbackDiv.textContent = errorMsg; feedbackDiv.style.display = 'block'; }
                     }
                     return isValid;
                  } // End validateSignupField

                 // Add listeners for signup form fields using the specific validation function
                 signupForm.querySelectorAll('input[required], select[required], textarea[required]').forEach(input => {
                     input.addEventListener('input', () => validateSignupField(input));
                     input.addEventListener('blur', () => validateSignupField(input));
                 });
                 document.getElementById('profilePicture')?.addEventListener('change', () => validateSignupField(document.getElementById('profilePicture')));
                 document.getElementById('privacy_policy')?.addEventListener('change', () => validateSignupField(document.getElementById('privacy_policy')));

                 // Signup Form Submit Handler
                 signupForm.addEventListener('submit', function(event) {
                    let isFormValid = true;
                    signupErrorMessages.classList.add('d-none'); signupErrorMessages.innerHTML = '';
                    signupForm.querySelectorAll('input[required], select[required], textarea[required]').forEach(field => { if (!validateSignupField(field)) isFormValid = false; });
                    // Explicitly re-validate file and checkbox on submit
                    if (!validateSignupField(document.getElementById('profilePicture'))) isFormValid = false;
                    if (!validateSignupField(document.getElementById('privacy_policy'))) isFormValid = false;

                    if (!isFormValid) {
                        event.preventDefault();
                        signupErrorMessages.innerHTML = '<strong>Please properly fill or correct the inputs below.</strong>';
                        signupErrorMessages.classList.remove('d-none');
                        signupForm.closest('.modal-body')?.scrollTo(0, 0);
                    } else {
                        showSpinner(); // Show spinner only if client validation passes
                    }
                });

                 // Phone input formatting for signup
                  const phoneInput = document.getElementById('contactinfo');
                  if(phoneInput){ phoneInput.addEventListener('input', (e) => { e.target.value = e.target.value.replace(/[^0-9]/g, '').slice(0, 10); }); }
            } // end if(signupForm)


            // --- AJAX LOGIN HANDLER ---
            const loginForm = document.getElementById('loginForm');
            const loginErrorMessages = document.getElementById('loginErrorMessages');
            const loginButton = document.getElementById('loginButton');
            const loginEmailInput = document.getElementById('loginEmail');
            const loginPasswordInput = document.getElementById('loginPassword');
            const loginEmailFeedback = loginEmailInput?.closest('.form-floating')?.querySelector('.invalid-feedback');
            const loginPasswordFeedback = loginPasswordInput?.closest('.input-group')?.querySelector('.invalid-feedback');

            if (loginForm && loginErrorMessages && loginButton && loginEmailInput && loginPasswordInput && loginEmailFeedback && loginPasswordFeedback) {
                loginForm.addEventListener('submit', function(event) {
                    event.preventDefault();
                    let loginValid = true;
                    loginErrorMessages.classList.add('d-none'); loginErrorMessages.textContent = '';
                    loginEmailInput.classList.remove('is-invalid'); loginPasswordInput.classList.remove('is-invalid');
                    loginEmailFeedback.style.display = 'none'; loginPasswordFeedback.style.display = 'none';

                    if (!loginEmailInput.value.trim()) {
                        loginEmailInput.classList.add('is-invalid'); loginEmailFeedback.style.display = 'block';
                        loginValid = false;
                    }
                    if (!loginPasswordInput.value.trim()) {
                        loginPasswordInput.classList.add('is-invalid'); loginPasswordFeedback.style.display = 'block';
                        loginValid = false;
                    }

                    if (!loginValid) return;

                    loginButton.disabled = true; showSpinner();
                    const formData = new FormData(loginForm);

                    fetch('customer_login.php', { method: 'POST', body: formData })
                    .then(response => { if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`); return response.json(); })
                    .then(data => {
                        hideSpinner(); // Hide spinner once response is received
                        console.log('Login response:', data); // Debug log
                        if (data.status === 'success') { 
                            window.location.href = data.redirect; 
                        } else if (data.status === 'existing_session') {
                            console.log('Showing session resumption modal'); // Debug log
                            // Show session resumption modal
                            showSessionResumptionModal(data);
                        } else if (data.status === 'email_not_verified') {
                            // Show email verification required message
                            loginErrorMessages.innerHTML = `
                                <div class="alert alert-warning mb-0">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    ${data.message}
                                    <br><br>
                                    <button type="button" class="btn btn-sm btn-warning" onclick="resendVerificationFromLogin('${data.email}', ${data.user_id})">
                                        <i class="fas fa-paper-plane me-2"></i>Resend Verification Email
                                    </button>
                                </div>
                            `;
                            loginErrorMessages.classList.remove('d-none');
                            loginButton.disabled = false;
                        } else { 
                            loginErrorMessages.textContent = data.message || 'Login failed.'; 
                            loginErrorMessages.classList.remove('d-none'); 
                            loginButton.disabled = false; 
                        }
                    })
                    .catch(error => { console.error('Login Fetch Error:', error); loginErrorMessages.textContent = 'Login error. Please try again.'; loginErrorMessages.classList.remove('d-none'); hideSpinner(); loginButton.disabled = false; });
                });

                 // Clear login errors/state when modal is closed
                 loginModalEl.addEventListener('hidden.bs.modal', function () {
                     loginErrorMessages.classList.add('d-none'); loginErrorMessages.textContent = '';
                     loginEmailInput.classList.remove('is-invalid'); loginPasswordInput.classList.remove('is-invalid');
                     loginEmailFeedback.style.display = 'none'; loginPasswordFeedback.style.display = 'none';
                     loginForm.reset(); loginButton.disabled = false;
                     const eyeIcon = document.querySelector('#togglePassword i'); if (eyeIcon?.classList.contains('fa-eye-slash')) { eyeIcon.classList.remove('fa-eye-slash'); eyeIcon.classList.add('fa-eye'); if(loginPasswordInput) loginPasswordInput.setAttribute('type', 'password'); }
                 });
            }


            // --- Handle Registration Success Modal Trigger ---
            <?php if ($show_success_modal): ?>
                // Check if the session role is Customer, might need adjustment if role isn't set immediately after signup success redirect
                <?php // if(isset($_SESSION['role']) && $_SESSION['role'] === 'Customer'): ?>
                    if (successModal) {
                        successModal.show();
                        const proceedBtn = document.getElementById('proceedToDashboardBtn');
                        if (proceedBtn) {
                            proceedBtn.addEventListener('click', () => { showSpinner(); window.location.href = 'Mobile-Dashboard.php'; });
                        }
                    }
                <?php // endif; ?>
            <?php endif; ?>

            // --- Handle Email Verification Modal Trigger ---
            <?php if (isset($_SESSION['verification_sent']) && $_SESSION['verification_sent'] && !isset($_SESSION['email_verified'])): ?>
                const emailVerificationModal = new bootstrap.Modal(document.getElementById('emailVerificationModal'));
                if (emailVerificationModal) {
                    emailVerificationModal.show();
                    
                    // Handle resend verification button
                    const resendBtn = document.getElementById('resendVerificationBtn');
                    if (resendBtn) {
                        resendBtn.addEventListener('click', function() {
                            const email = '<?php echo $_SESSION['pending_verification']['email'] ?? ''; ?>';
                            if (email) {
                                resendVerificationEmail(email, resendBtn);
                            }
                        });
                    }
                    
                    // Handle close button
                    const closeBtn = document.getElementById('closeVerificationModalBtn');
                    if (closeBtn) {
                        closeBtn.addEventListener('click', function() {
                            emailVerificationModal.hide();
                            // Clear the verification session data
                            fetch('clear_verification_session.php', { method: 'POST' });
                        });
                    }
                }
            <?php endif; ?>

            // --- Fallback: Check URL parameters for verification status ---
            <?php if (isset($_GET['verification_sent']) && $_GET['verification_sent'] === '1'): ?>
                console.log('Verification modal triggered via URL parameter');
                document.addEventListener('DOMContentLoaded', function() {
                    const emailVerificationModal = new bootstrap.Modal(document.getElementById('emailVerificationModal'));
                    if (emailVerificationModal) {
                        console.log('Email verification modal found via URL, showing...');
                        emailVerificationModal.show();
                        
                        // Handle resend verification button
                        const resendBtn = document.getElementById('resendVerificationBtn');
                        if (resendBtn) {
                            resendBtn.addEventListener('click', function() {
                                // Get email from URL or use a default
                                const urlParams = new URLSearchParams(window.location.search);
                                const email = urlParams.get('email') || '<?php echo $_GET['email'] ?? ''; ?>';
                                if (email) {
                                    resendVerificationEmail(email, resendBtn);
                                }
                            });
                        }
                        
                        // Handle close button
                        const closeBtn = document.getElementById('closeVerificationModalBtn');
                        if (closeBtn) {
                            closeBtn.addEventListener('click', function() {
                                emailVerificationModal.hide();
                                // Clear the verification session data
                                fetch('clear_verification_session.php', { method: 'POST' });
                            });
                        }
                    }
                });
            <?php endif; ?>

            // --- Email Verification Functions ---
            function resendVerificationFromLogin(email, userId) {
                // Show loading state
                const resendBtn = event.target;
                const originalText = resendBtn.innerHTML;
                resendBtn.disabled = true;
                resendBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Sending...';
                
                fetch('resend_verification.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `email=${encodeURIComponent(email)}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Show success message
                        const loginErrorMessages = document.getElementById('loginErrorMessages');
                        loginErrorMessages.innerHTML = `
                            <div class="alert alert-success mb-0">
                                <i class="fas fa-check-circle me-2"></i>
                                ${data.message}
                            </div>
                        `;
                        loginErrorMessages.classList.remove('d-none');
                    } else {
                        // Show error message
                        const loginErrorMessages = document.getElementById('loginErrorMessages');
                        loginErrorMessages.innerHTML = `
                            <div class="alert alert-danger mb-0">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                ${data.message}
                            </div>
                        `;
                        loginErrorMessages.classList.remove('d-none');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    const loginErrorMessages = document.getElementById('loginErrorMessages');
                    loginErrorMessages.innerHTML = `
                        <div class="alert alert-danger mb-0">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            An error occurred. Please try again.
                        </div>
                    `;
                    loginErrorMessages.classList.remove('d-none');
                })
                .finally(() => {
                    // Re-enable button
                    resendBtn.disabled = false;
                    resendBtn.innerHTML = originalText;
                });
            }

            function resendVerificationEmail(email, button) {
                const originalText = button.innerHTML;
                button.disabled = true;
                button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Sending...';
                
                fetch('resend_verification.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `email=${encodeURIComponent(email)}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Redirect user to enter verification code page after resending
                        const targetEmail = email || (document.getElementById('email') ? document.getElementById('email').value : '');
                        window.location.href = `enter_verification_code.php?email=${encodeURIComponent(targetEmail)}`;
                    } else {
                        // Show error message
                        const modalBody = document.querySelector('#emailVerificationModal .modal-body');
                        const errorAlert = document.createElement('div');
                        errorAlert.className = 'alert alert-danger';
                        errorAlert.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i>' + data.message;
                        modalBody.appendChild(errorAlert);
                        
                        // Remove the alert after 5 seconds
                        setTimeout(() => {
                            errorAlert.remove();
                        }, 5000);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    const modalBody = document.querySelector('#emailVerificationModal .modal-body');
                    const errorAlert = document.createElement('div');
                    errorAlert.className = 'alert alert-danger';
                    errorAlert.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i>An error occurred. Please try again.';
                    modalBody.appendChild(errorAlert);
                    
                    setTimeout(() => {
                        errorAlert.remove();
                    }, 5000);
                })
                .finally(() => {
                    button.disabled = false;
                    button.innerHTML = originalText;
                });
            }

            // --- Swipe Gesture Detection for Staff Sign In ---
            const heroSection = document.querySelector('.hero');
            const staffSignInBtn = document.getElementById('staffSignInBtn');
            let startX = 0;
            let startY = 0;
            let isStaffBtnVisible = false;

            if (heroSection && staffSignInBtn) {
                // Touch events for mobile
                heroSection.addEventListener('touchstart', function(e) {
                    startX = e.touches[0].clientX;
                    startY = e.touches[0].clientY;
                }, { passive: true });

                heroSection.addEventListener('touchend', function(e) {
                    if (!startX || !startY) return;
                    
                    const endX = e.changedTouches[0].clientX;
                    const endY = e.changedTouches[0].clientY;
                    const diffX = endX - startX;
                    const diffY = endY - startY;
                    
                    // Check if it's a horizontal swipe (more horizontal than vertical)
                    if (Math.abs(diffX) > Math.abs(diffY) && Math.abs(diffX) > 50) {
                        if (diffX > 0) { // Right swipe
                            showStaffSignIn();
                        } else { // Left swipe
                            hideStaffSignIn();
                        }
                    }
                    
                    startX = 0;
                    startY = 0;
                }, { passive: true });

                // Mouse events for desktop (optional)
                let mouseStartX = 0;
                heroSection.addEventListener('mousedown', function(e) {
                    mouseStartX = e.clientX;
                });

                heroSection.addEventListener('mouseup', function(e) {
                    if (!mouseStartX) return;
                    
                    const diffX = e.clientX - mouseStartX;
                    
                    if (Math.abs(diffX) > 100) { // Require larger movement for mouse
                        if (diffX > 0) { // Right swipe
                            showStaffSignIn();
                        } else { // Left swipe
                            hideStaffSignIn();
                        }
                    }
                    
                    mouseStartX = 0;
                });

                function showStaffSignIn() {
                    if (!isStaffBtnVisible) {
                        staffSignInBtn.style.display = 'inline-block';
                        setTimeout(() => {
                            staffSignInBtn.classList.add('staff-btn-visible');
                        }, 10);
                        isStaffBtnVisible = true;
                        
                        // Auto-hide after 10 seconds
                        setTimeout(() => {
                            if (isStaffBtnVisible) {
                                hideStaffSignIn();
                            }
                        }, 10000);
                    }
                }

                function hideStaffSignIn() {
                    if (isStaffBtnVisible) {
                        staffSignInBtn.classList.remove('staff-btn-visible');
                        setTimeout(() => {
                            staffSignInBtn.style.display = 'none';
                        }, 300);
                        isStaffBtnVisible = false;
                    }
                }
            }

            // --- Session Resumption Modal and Functions ---
            let sessionModal = null;
            let sessionData = null;

            function showSessionResumptionModal(data) {
                // Create modal if it doesn't exist
                if (!sessionModal) {
                    createSessionModal();
                }
                
                // Populate modal with session data
                document.getElementById('sessionMessage').textContent = data.message;
                document.getElementById('timeIn').textContent = new Date(data.session_data.time_in).toLocaleString();
                document.getElementById('elapsedTime').textContent = 
                    `${data.session_data.elapsed_hours}h ${data.session_data.elapsed_mins}m`;
                document.getElementById('remainingTime').textContent = 
                    `${data.session_data.remaining_hours}h ${data.session_data.remaining_mins}m`;
                
                // Store session data
                sessionData = data;
                
                // Show modal
                sessionModal.show();
            }

            function createSessionModal() {
                const modalHTML = `
                    <div class="modal fade" id="sessionResumptionModal" tabindex="-1" aria-labelledby="sessionModalLabel" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="sessionModalLabel">Resume Previous Session</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i>
                                        <span id="sessionMessage"></span>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <strong>Time In:</strong>
                                            <p id="timeIn"></p>
                                        </div>
                                        <div class="col-md-6">
                                            <strong>Elapsed Time:</strong>
                                            <p id="elapsedTime"></p>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <strong>Remaining Duty Time:</strong>
                                            <p id="remainingTime" class="text-success"></p>
                                        </div>
                                        <div class="col-md-6">
                                            <strong>Required Daily Duty:</strong>
                                            <p>8 hours</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" id="startNewBtn">Start New Session</button>
                                    <button type="button" class="btn btn-primary" id="resumeBtn">Resume Session</button>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                
                document.body.insertAdjacentHTML('beforeend', modalHTML);
                sessionModal = new bootstrap.Modal(document.getElementById('sessionResumptionModal'));
                
                // Add event listeners
                document.getElementById('resumeBtn').addEventListener('click', resumeSession);
                document.getElementById('startNewBtn').addEventListener('click', startNewSession);
            }

            function resumeSession() {
                if (sessionData) {
                    showSpinner();
                    fetch('resume_session.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `action=resume&log_id=${sessionData.session_data.log_id}&user_id=${sessionData.user_data.id}&user_role=${sessionData.user_data.role}&user_full_name=${encodeURIComponent(sessionData.user_data.full_name)}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        hideSpinner();
                        if (data.status === 'success') {
                            window.location.href = data.redirect;
                        } else {
                            alert(data.message || 'Failed to resume session');
                        }
                    })
                    .catch(error => {
                        hideSpinner();
                        console.error('Error:', error);
                        alert('An error occurred while resuming session');
                    });
                }
            }

            function startNewSession() {
                if (sessionData) {
                    showSpinner();
                    fetch('resume_session.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `action=start_new&log_id=${sessionData.session_data.log_id}&user_id=${sessionData.user_data.id}&user_role=${sessionData.user_data.role}&user_full_name=${encodeURIComponent(sessionData.user_data.full_name)}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        hideSpinner();
                        if (data.status === 'success') {
                            window.location.href = data.redirect;
                        } else {
                            alert(data.message || 'Failed to start new session');
                        }
                    })
                    .catch(error => {
                        hideSpinner();
                        console.error('Error:', error);
                        alert('An error occurred while starting new session');
                    });
                }
            }

            // --- Forgot Password Submit ---
            const forgotPasswordForm = document.getElementById('forgotPasswordForm');
            const forgotEmail = document.getElementById('forgotEmail');
            const forgotAlert = document.getElementById('forgotAlert');
            const forgotSubmitBtn = document.getElementById('forgotSubmitBtn');
            if (forgotPasswordForm && forgotEmail && forgotAlert && forgotSubmitBtn) {
                forgotPasswordForm.addEventListener('submit', function(e){
                    e.preventDefault();
                    forgotAlert.className = 'alert d-none';
                    if (!forgotEmail.value.trim()) {
                        forgotEmail.classList.add('is-invalid');
                        return;
                    }
                    forgotEmail.classList.remove('is-invalid');
                    forgotSubmitBtn.disabled = true; showSpinner();
                    fetch('send_password_reset_code.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `email=${encodeURIComponent(forgotEmail.value)}`
                    })
                    .then(r => r.json())
                    .then(data => {
                        forgotAlert.classList.remove('d-none');
                        forgotAlert.classList.add(data.success ? 'alert-success' : 'alert-danger');
                        forgotAlert.innerHTML = data.message || (data.success ? 'If the email exists, a code has been sent.' : 'Unable to send code.');
                        if (data.success) {
                            const redirect = data.redirect || (`enter_reset_code.php?email=${encodeURIComponent(forgotEmail.value)}`);
                            window.location.href = redirect;
                        }
                    })
                    .catch(() => {
                        forgotAlert.classList.remove('d-none');
                        forgotAlert.classList.add('alert-danger');
                        forgotAlert.textContent = 'An error occurred. Please try again.';
                    })
                    .finally(() => { hideSpinner(); forgotSubmitBtn.disabled = false; });
                });
            }

        }); // End DOMContentLoaded
    </script>
    <!-- ============================================================== -->
    <!-- SCRIPTS END -->
    <!-- ============================================================== -->
</body>

</html>
<?php
// Close DB connection if open
if (isset($conn) && $conn instanceof mysqli && $conn->ping()) {
    $conn->close();
}
?>

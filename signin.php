<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'dbconn.php'; // Ensure database connection

// Dynamically determine the base URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$path = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$baseURL = $protocol . '://' . $host . $path . '/';

$error = ""; // Initialize error variable

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);

    if (!empty($email) && !empty($password)) {
        // Ensure staff_logs exists for tracking time-in/out
        $conn->query("CREATE TABLE IF NOT EXISTS staff_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            staff_id INT NOT NULL,
            role VARCHAR(20) NOT NULL,
            action VARCHAR(20) NOT NULL,
            time_in DATETIME NOT NULL,
            time_out DATETIME DEFAULT NULL,
            duty_duration_minutes INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_staff_role (staff_id, role),
            INDEX idx_time_in (time_in)
        )");
        // Check ONLY the `cjusers` table for staff (Admin, Cashier, etc.)
        $stmt = $conn->prepare("SELECT id, email, password, role, profile_image FROM cjusers WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();
            if (password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['full_name'] = "Admin User"; // Use a placeholder (cjusers table has no full_name)
                // Normalize role spelling/case
                $normalizedRole = ucfirst(strtolower($user['role']));
                $_SESSION['role'] = $normalizedRole;
                $_SESSION['profile_image'] = $user['profile_image'];
                // Check for existing session for Cashier
                if ($normalizedRole === 'Cashier') {
                    // Check for existing unfinished session
                    $checkStmt = $conn->prepare("
                        SELECT id, time_in, 
                               TIMESTAMPDIFF(MINUTE, time_in, NOW()) as elapsed_minutes
                        FROM staff_logs 
                        WHERE staff_id = ? AND role = ? AND time_out IS NULL 
                        ORDER BY time_in DESC 
                        LIMIT 1
                    ");
                    
                    if ($checkStmt) {
                        $checkStmt->bind_param("is", $user['id'], $normalizedRole);
                        $checkStmt->execute();
                        $checkResult = $checkStmt->get_result();
                        
                        if ($checkResult->num_rows > 0) {
                            $sessionData = $checkResult->fetch_assoc();
                            $elapsedMinutes = $sessionData['elapsed_minutes'];
                            $remainingMinutes = 480 - $elapsedMinutes; // 8 hours = 480 minutes
                            
                            if ($remainingMinutes > 0) {
                                // Store session data for JavaScript
                                $_SESSION['pending_session_data'] = [
                                    'log_id' => $sessionData['id'],
                                    'time_in' => $sessionData['time_in'],
                                    'elapsed_minutes' => $elapsedMinutes,
                                    'remaining_minutes' => $remainingMinutes,
                                    'elapsed_hours' => floor($elapsedMinutes / 60),
                                    'elapsed_mins' => $elapsedMinutes % 60,
                                    'remaining_hours' => floor($remainingMinutes / 60),
                                    'remaining_mins' => $remainingMinutes % 60
                                ];
                                $_SESSION['pending_user_data'] = [
                                    'id' => $user['id'],
                                    'role' => $normalizedRole,
                                    'full_name' => $normalizedRole . " User"
                                ];
                                
                                // Redirect to dashboard but with session data available
                                if ($normalizedRole == 'Cashier') {
                                    header("Location: Cashier-Dashboard.php");
                                    exit();
                                }
                            } else {
                                // Session expired, close it
                                $updateStmt = $conn->prepare("UPDATE staff_logs SET time_out = NOW(), duty_duration_minutes = ? WHERE id = ?");
                                if ($updateStmt) {
                                    $updateStmt->bind_param("ii", $elapsedMinutes, $sessionData['id']);
                                    $updateStmt->execute();
                                    $updateStmt->close();
                                }
                            }
                        }
                        $checkStmt->close();
                    }
                    
                    // Create new session log
                    if ($stmtLog = $conn->prepare("INSERT INTO staff_logs (staff_id, role, action, time_in) VALUES (?, ?, 'login', NOW())")) {
                        $stmtLog->bind_param('is', $user['id'], $normalizedRole);
                        $stmtLog->execute();
                        $stmtLog->close();
                    }
                }

                // Redirect based on role
                if ($normalizedRole == 'Admin') {
                    header("Location: Admin-Dashboard.php");
                    exit();
                } elseif ($normalizedRole == 'Cashier') {
                    header("Location: Cashier-Dashboard.php");
                    exit();
                }
            } else {
                $error = "Invalid email or password.";
            }
        } else {
            // Check the `riders` table
            $stmt = $conn->prepare("SELECT id, first_name, middle_name, last_name, email, password, ImagePath FROM riders WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows == 1) {
                $rider = $result->fetch_assoc();
                if (password_verify($password, $rider['password'])) {
                    $_SESSION['user_id'] = $rider['id'];
                    $_SESSION['full_name'] = trim($rider['first_name'] . " " . ($rider['middle_name'] ? $rider['middle_name'] . " " : "") . $rider['last_name']);
                    $_SESSION['role'] = "Rider"; // Set role as 'Rider'
                    $_SESSION['profile_image'] = $rider['ImagePath'];
                    
                    // Check for existing session for Rider
                    $checkStmt = $conn->prepare("
                        SELECT id, time_in, 
                               TIMESTAMPDIFF(MINUTE, time_in, NOW()) as elapsed_minutes
                        FROM staff_logs 
                        WHERE staff_id = ? AND role = 'Rider' AND time_out IS NULL 
                        ORDER BY time_in DESC 
                        LIMIT 1
                    ");
                    
                    if ($checkStmt) {
                        $checkStmt->bind_param("i", $rider['id']);
                        $checkStmt->execute();
                        $checkResult = $checkStmt->get_result();
                        
                        if ($checkResult->num_rows > 0) {
                            $sessionData = $checkResult->fetch_assoc();
                            $elapsedMinutes = $sessionData['elapsed_minutes'];
                            $remainingMinutes = 480 - $elapsedMinutes; // 8 hours = 480 minutes
                            
                            if ($remainingMinutes > 0) {
                                // Store session data for JavaScript
                                $_SESSION['pending_session_data'] = [
                                    'log_id' => $sessionData['id'],
                                    'time_in' => $sessionData['time_in'],
                                    'elapsed_minutes' => $elapsedMinutes,
                                    'remaining_minutes' => $remainingMinutes,
                                    'elapsed_hours' => floor($elapsedMinutes / 60),
                                    'elapsed_mins' => $elapsedMinutes % 60,
                                    'remaining_hours' => floor($remainingMinutes / 60),
                                    'remaining_mins' => $remainingMinutes % 60
                                ];
                                $_SESSION['pending_user_data'] = [
                                    'id' => $rider['id'],
                                    'role' => 'Rider',
                                    'full_name' => trim($rider['first_name'] . " " . ($rider['middle_name'] ? $rider['middle_name'] . " " : "") . $rider['last_name'])
                                ];
                            } else {
                                // Session expired, close it
                                $updateStmt = $conn->prepare("UPDATE staff_logs SET time_out = NOW(), duty_duration_minutes = ? WHERE id = ?");
                                if ($updateStmt) {
                                    $updateStmt->bind_param("ii", $elapsedMinutes, $sessionData['id']);
                                    $updateStmt->execute();
                                    $updateStmt->close();
                                }
                            }
                        }
                        $checkStmt->close();
                    }
                    
                    // Create new session log
                    if ($stmtLog = $conn->prepare("INSERT INTO staff_logs (staff_id, role, action, time_in) VALUES (?, 'Rider', 'login', NOW())")) {
                        $stmtLog->bind_param('i', $rider['id']);
                        $stmtLog->execute();
                        $stmtLog->close();
                    }

                    // Set one-time rider loader flag then redirect to rider dashboard
                    $_SESSION['show_rider_loader'] = true;
                    $_SESSION['rider_login_time'] = time();
                    // Redirect to rider dashboard (replace with your rider dashboard URL)
                    header("Location: Rider-Dashboard.php");
                    exit();
                } else {
                    $error = "Invalid email or password.";
                }
            } else {
                // Check the `mechanics` table
                $stmt = $conn->prepare("SELECT id, first_name, middle_name, last_name, email, password, ImagePath FROM mechanics WHERE email = ?");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows == 1) {
                    $mechanic = $result->fetch_assoc();
                    if (password_verify($password, $mechanic['password'])) {
                        $_SESSION['user_id'] = $mechanic['id'];
                        $_SESSION['full_name'] = trim($mechanic['first_name'] . " " . ($mechanic['middle_name'] ? $mechanic['middle_name'] . " " : "") . $mechanic['last_name']);
                        $_SESSION['role'] = "Mechanic"; // Set role as 'Mechanic'
                        $_SESSION['profile_image'] = $mechanic['ImagePath'];
                        
                        // Check for existing session for Mechanic
                        $checkStmt = $conn->prepare("
                            SELECT id, time_in, 
                                   TIMESTAMPDIFF(MINUTE, time_in, NOW()) as elapsed_minutes
                            FROM staff_logs 
                            WHERE staff_id = ? AND role = 'Mechanic' AND time_out IS NULL 
                            ORDER BY time_in DESC 
                            LIMIT 1
                        ");
                        
                        if ($checkStmt) {
                            $checkStmt->bind_param("i", $mechanic['id']);
                            $checkStmt->execute();
                            $checkResult = $checkStmt->get_result();
                            
                            if ($checkResult->num_rows > 0) {
                                $sessionData = $checkResult->fetch_assoc();
                                $elapsedMinutes = $sessionData['elapsed_minutes'];
                                $remainingMinutes = 480 - $elapsedMinutes; // 8 hours = 480 minutes
                                
                                if ($remainingMinutes > 0) {
                                    // Store session data for JavaScript
                                    $_SESSION['pending_session_data'] = [
                                        'log_id' => $sessionData['id'],
                                        'time_in' => $sessionData['time_in'],
                                        'elapsed_minutes' => $elapsedMinutes,
                                        'remaining_minutes' => $remainingMinutes,
                                        'elapsed_hours' => floor($elapsedMinutes / 60),
                                        'elapsed_mins' => $elapsedMinutes % 60,
                                        'remaining_hours' => floor($remainingMinutes / 60),
                                        'remaining_mins' => $remainingMinutes % 60
                                    ];
                                    $_SESSION['pending_user_data'] = [
                                        'id' => $mechanic['id'],
                                        'role' => 'Mechanic',
                                        'full_name' => trim($mechanic['first_name'] . " " . ($mechanic['middle_name'] ? $mechanic['middle_name'] . " " : "") . $mechanic['last_name'])
                                    ];
                                } else {
                                    // Session expired, close it
                                    $updateStmt = $conn->prepare("UPDATE staff_logs SET time_out = NOW(), duty_duration_minutes = ? WHERE id = ?");
                                    if ($updateStmt) {
                                        $updateStmt->bind_param("ii", $elapsedMinutes, $sessionData['id']);
                                        $updateStmt->execute();
                                        $updateStmt->close();
                                    }
                                }
                            }
                            $checkStmt->close();
                        }
                        
                        // Create new session log
                        if ($stmtLog = $conn->prepare("INSERT INTO staff_logs (staff_id, role, action, time_in) VALUES (?, 'Mechanic', 'login', NOW())")) {
                            $stmtLog->bind_param('i', $mechanic['id']);
                            $stmtLog->execute();
                            $stmtLog->close();
                        }

                        // Set one-time mechanic loader flag then redirect to mechanic dashboard
                        $_SESSION['show_mechanic_loader'] = true;
                        $_SESSION['mechanic_login_time'] = time();
                        // Redirect to mechanic dashboard
                        header("Location: Mechanic-Dashboard.php");
                        exit();
                    } else {
                        $error = "Invalid email or password.";
                    }
                } else {
                    $error = "Access denied. This login form is for staff members only. Customers should use the main website.";
                }
            }
        }
        $stmt->close();
    } else {
        $error = "Please fill in all fields.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In</title>
    <link rel="icon" type="image/png" href="Image/logo.png">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600&family=Roboto:wght@500;700&display=swap" rel="stylesheet">

    <!-- Icon Font Stylesheet -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.10.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.4.1/font/bootstrap-icons.css">

    <!-- Libraries Stylesheet -->
    <link href="/lib/owlcarousel/assets/owl.carousel.min.css" rel="stylesheet">
    <link href="/lib/tempusdominus/css/tempusdominus-bootstrap-4.min.css" rel="stylesheet">

    <!-- Customized Bootstrap Stylesheet -->
    <link href="/css/bootstrap.min.css" rel="stylesheet">

    <!-- Template Stylesheet -->
    <link href="/css/style.css" rel="stylesheet">

    <style>
        .login-container {
            min-height: 100vh;
        }
        .login-box {
            width: 100%;
            max-width: 450px;
        }
        .password-wrapper {
            position: relative;
        }
        .password-toggle {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #adb5bd;
            z-index: 5;
        }
        .password-toggle:hover {
            color: #ffffff;
        }
    </style>
</head>
<body>
    <div class="container-fluid position-relative d-flex p-0">

        <!-- Spinner Start -->
        <div id="spinner" class="show bg-dark position-fixed translate-middle w-100 vh-100 top-50 start-50 d-flex align-items-center justify-content-center">
            <img src="img/Loading.gif" alt="Loading..." style="width: 200px; height: 200px;" />
        </div>
        <!-- Spinner End -->

        <!-- Sign In Start -->
        <div class="container-fluid d-flex justify-content-center align-items-center login-container">
            <div class="login-box bg-secondary rounded p-4 p-sm-5">
                <div class="mb-2">
                    <a href="LandingPage.php" class="text-light" aria-label="Back to landing" title="Back">
                        <i class="bi bi-arrow-left" style="font-size: 1.25rem;"></i>
                    </a>
                </div>
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h3 class="text-primary"><i class="fa fa-user-edit me-2"></i> CJ Powerhouse</h3>
                    <h3>Sign In</h3>
                </div>

                <!-- Display error message -->
                <div id="inlineError" class="alert alert-danger py-2 mb-3<?= empty($error) ? ' d-none' : '' ?>" role="alert">
                    <?= !empty($error) ? htmlspecialchars($error) : 'Invalid email or password.'; ?>
                </div>

                <form id="loginForm" method="POST" action="signin.php">
                    <div class="form-floating mb-3">
                        <input type="email" name="email" id="email" class="form-control" placeholder="name@example.com" required>
                        <label>Email address</label>
                    </div>
                    <div class="form-floating mb-3 password-wrapper">
                        <input type="password" name="password" id="password" class="form-control" placeholder="Password" required>
                        <label>Password</label>
                        <span id="togglePassword" class="password-toggle" aria-label="Show password" title="Show password">
                            <i class="fa fa-eye" id="togglePasswordIcon"></i>
                        </span>
                    </div>
                    <div class="d-flex align-items-center justify-content-start mb-4">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="exmCheck1">
                            <label class="form-check-label" for="exmCheck1">Remember me</label>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary py-3 w-100 mb-4">Sign In</button>
                </form>
             
            </div>
        </div>
        <!-- Sign In End -->
    </div>

    <!-- Session Resumption Modal -->
    <div class="modal fade" id="sessionModal" tabindex="-1" aria-labelledby="sessionModalLabel" aria-hidden="true">
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



    <!-- Error Modal -->
    <div class="modal fade" id="errorModal" tabindex="-1" aria-labelledby="errorModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="errorModalLabel">Notice</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger mb-0" id="errorMessage">An error occurred.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Hide spinner when page loads
        window.onload = function () {
            document.getElementById("spinner").classList.remove("show");
        };

        // Toggle password visibility
        (function() {
            var toggle = document.getElementById('togglePassword');
            var passwordInput = document.getElementById('password');
            var icon = document.getElementById('togglePasswordIcon');
            if (toggle && passwordInput && icon) {
                toggle.addEventListener('click', function () {
                    var isPassword = passwordInput.getAttribute('type') === 'password';
                    passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
                    icon.classList.toggle('fa-eye');
                    icon.classList.toggle('fa-eye-slash');
                    toggle.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
                    toggle.setAttribute('title', isPassword ? 'Hide password' : 'Show password');
                });
            }
        })();

        // Session resumption functionality
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const email = document.getElementById('email').value;
            const password = document.getElementById('password').value;
            
            // First check for existing session
            fetch('check_existing_session.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `email=${encodeURIComponent(email)}&password=${encodeURIComponent(password)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'existing_session') {
                    // Show session resumption modal (for staff)
                    document.getElementById('sessionMessage').textContent = data.message;
                    document.getElementById('timeIn').textContent = new Date(data.session_data.time_in).toLocaleString();
                    document.getElementById('elapsedTime').textContent = 
                        `${data.session_data.elapsed_hours}h ${data.session_data.elapsed_mins}m`;
                    document.getElementById('remainingTime').textContent = 
                        `${data.session_data.remaining_hours}h ${data.session_data.remaining_mins}m`;
                    
                    // Store session data for modal buttons
                    window.sessionData = data.session_data;
                    window.userData = data.user_data;
                    
                    const modal = new bootstrap.Modal(document.getElementById('sessionModal'));
                    modal.show();

                } else if (data.status === 'new_session' || data.status === 'session_expired') {
                    // Proceed with normal login
                    proceedWithLogin(email, password);
                } else {
                    showInlineError(data.message || 'Invalid email or password.');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showInlineError('An error occurred during login');
            });
        });



        // Resume session button
        document.getElementById('resumeBtn').addEventListener('click', function() {
            if (window.sessionData && window.userData) {
                fetch('resume_session.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=resume&log_id=${window.sessionData.log_id}&user_id=${window.userData.id}&user_role=${window.userData.role}&user_full_name=${encodeURIComponent(window.userData.full_name)}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        window.location.href = data.redirectUrl;
                    } else {
                        showInlineError(data.message || 'Failed to resume session');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showInlineError('An error occurred while resuming session');
                });
            }
        });

        // Start new session button
        document.getElementById('startNewBtn').addEventListener('click', function() {
            if (window.sessionData && window.userData) {
                fetch('resume_session.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=start_new&log_id=${window.sessionData.log_id}&user_id=${window.userData.id}&user_role=${window.userData.role}&user_full_name=${encodeURIComponent(window.userData.full_name)}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        window.location.href = data.redirectUrl;
                    } else {
                        showInlineError(data.message || 'Failed to start new session');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showInlineError('An error occurred while starting new session');
                });
            }
        });

        // Resume session button
        // document.getElementById('yesMeBtn').addEventListener('click', function() {
        //     if (window.sessionData && window.userData) {
        //         fetch('handle_user_session.php', {
        //             method: 'POST',
        //             headers: {
        //                 'Content-Type': 'application/x-www-form-urlencoded',
        //             },
        //             body: `action=continue_existing&user_id=${window.userData.id}&existing_session_id=${window.sessionData.session_id}`
        //         })
        //         .then(response => response.json())
        //         .then(data => {
        //             if (data.status === 'success') {
        //                 window.location.href = data.redirectUrl;
        //             } else {
        //                 showInlineError(data.message || 'Failed to continue session');
        //             }
        //         })
        //         .catch(error => {
        //             console.error('Error:', error);
        //             showInlineError('An error occurred while continuing session');
        //         });
        //     }
        // });

        // // Start new session button
        // document.getElementById('notMeBtn').addEventListener('click', function() {
        //     if (window.sessionData && window.userData) {
        //         fetch('handle_user_session.php', {
        //             method: 'POST',
        //             headers: {
        //                 'Content-Type': 'application/x-www-form-urlencoded',
        //             },
        //             body: `action=start_new&user_id=${window.userData.id}&existing_session_id=${window.sessionData.session_id}`
        //         })
        //         .then(response => response.json())
        //         .then(data => {
        //             if (data.status === 'success') {
        //                 window.location.href = data.redirectUrl;
        //             } else {
        //                 showInlineError(data.message || 'Failed to start new session');
        //             }
        //         })
        //         .catch(error => {
        //             console.error('Error:', error);
        //             showInlineError('An error occurred while starting new session');
        //         });
        //     }
        // });

        function proceedWithLogin(email, password) {
            // Submit the form normally
            const form = document.getElementById('loginForm');
            const formData = new FormData(form);
            
            fetch('signin.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (response.redirected) {
                    window.location.href = response.url;
                } else {
                    return response.text();
                }
            })
            .then(html => {
                if (html) {
                    // If we get HTML back, there might be an error
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const errorElement = doc.querySelector('.text-danger');
                    if (errorElement) {
                        showInlineError(errorElement.textContent);
                    } else {
                        // Reload the page to show any PHP errors
                        window.location.reload();
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showInlineError('An error occurred during login');
            });
        }
        
        function showError(message) {
            var el = document.getElementById('errorMessage');
            if (el) {
                el.textContent = message;
                var modal = new bootstrap.Modal(document.getElementById('errorModal'));
                modal.show();
            } else {
                // Fallback
                alert(message);
            }
        }

        function showInlineError(message) {
            var alertBox = document.getElementById('inlineError');
            if (alertBox) {
                alertBox.textContent = message;
                alertBox.classList.remove('d-none');
            } else {
                showError(message);
            }
        }
        

    </script>
</body>
</html>
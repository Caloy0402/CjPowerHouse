<?php
session_start();
require_once __DIR__ . '/dbconn.php';

$error = '';
$success = '';

$userId = isset($_SESSION['reset_verified_user_id']) ? (int)$_SESSION['reset_verified_user_id'] : 0;
$expires = isset($_SESSION['reset_verified_expires']) ? (int)$_SESSION['reset_verified_expires'] : 0;

if (!$userId || $expires < time()) {
    $error = 'Your verification session has expired. Please request a new code.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    if (strlen($password) < 7) {
        $error = 'Password must be at least 7 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $upd = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
        if ($upd) { $upd->bind_param('si', $hash, $userId); $upd->execute(); $upd->close(); }

        // Clear session flags
        unset($_SESSION['reset_verified_user_id'], $_SESSION['reset_verified_expires']);
        $success = 'Password updated. You can now log in.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New Password</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background:#0b0b0b; color:#fff; display:flex; align-items:center; justify-content:center; min-height:100vh; }
        .card { max-width: 420px; width: 100%; }
    </style>
    </head>
<body>
        <div class="card bg-secondary">
            <div class="card-body">
                <h5 class="card-title text-center">Set New Password</h5>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                    <div class="text-center mt-3"><a href="LandingPage.php" class="btn btn-warning">Back to Login</a></div>
                <?php else: ?>
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label">New password</label>
                        <input type="password" class="form-control" name="password" minlength="7" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm password</label>
                        <input type="password" class="form-control" name="confirm_password" minlength="7" required>
                    </div>
                    <button type="submit" class="btn btn-warning w-100" <?= $userId && $expires >= time() ? '' : 'disabled' ?>>Update password</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0/dist/js/bootstrap.bundle.min.js"></script>
 </body>
</html>



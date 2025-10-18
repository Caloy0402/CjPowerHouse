<?php
session_start();
require_once 'dbconn.php';

$token = isset($_GET['token']) ? trim($_GET['token']) : '';
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';
    $password = $_POST['password'] ?? '';
    if (!$token || strlen($password) < 7) { $error = 'Invalid token or weak password.'; }
    else {
        $stmt = $conn->prepare('SELECT user_id, expires_at FROM password_resets WHERE token = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $token);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                if (strtotime($row['expires_at']) < time()) { $error = 'This reset link has expired.'; }
                else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $upd = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
                    if ($upd) { $upd->bind_param('si', $hash, $row['user_id']); $upd->execute(); $upd->close(); }
                    $del = $conn->prepare('DELETE FROM password_resets WHERE token = ?');
                    if ($del) { $del->bind_param('s', $token); $del->execute(); $del->close(); }
                    $success = 'Password updated. You can now log in.';
                }
            } else { $error = 'Invalid token.'; }
            $stmt->close();
        } else { $error = 'Server error.'; }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-dark text-white">
    <div class="container d-flex align-items-center justify-content-center" style="min-height: 100vh;">
        <div class="card bg-secondary" style="max-width: 420px; width: 100%;">
            <div class="card-body">
                <h5 class="card-title text-center">Reset Password</h5>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                    <div class="text-center mt-3"><a href="LandingPage.php" class="btn btn-warning">Back to Login</a></div>
                <?php else: ?>
                <form method="post">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                    <div class="mb-3">
                        <label class="form-label">New password</label>
                        <input type="password" class="form-control" name="password" minlength="7" required>
                    </div>
                    <button type="submit" class="btn btn-warning w-100">Update password</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0/dist/js/bootstrap.bundle.min.js"></script>
 </body>
</html>




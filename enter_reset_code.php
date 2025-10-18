<?php
session_start();
require_once __DIR__ . '/dbconn.php';

$email = isset($_GET['email']) ? trim($_GET['email']) : '';
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $code = trim($_POST['code'] ?? '');
    // Normalize to digits only to avoid whitespace or hyphen issues
    $code = preg_replace('/\D+/', '', $code);
    if (!$email || !$code) {
        $error = 'Please provide a valid email and 6-digit code.';
    } else {
        // Find user
        $u = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        if ($u) {
            $u->bind_param('s', $email);
            $u->execute();
            $ur = $u->get_result();
            if ($rowU = $ur->fetch_assoc()) {
                $userId = (int)$rowU['id'];

                // Validate code
                $s = $conn->prepare('SELECT id, expires_at FROM password_reset_codes WHERE user_id = ? AND code = ? LIMIT 1');
                if ($s) {
                    $s->bind_param('is', $userId, $code);
                    $s->execute();
                    $rs = $s->get_result();
                    if ($row = $rs->fetch_assoc()) {
                        if (strtotime($row['expires_at']) < time()) {
                            $error = 'The code has expired. Please request a new one.';
                        } else {
                            // Code is valid: mark session and remove codes for user
                            $_SESSION['reset_verified_user_id'] = $userId;
                            $_SESSION['reset_verified_expires'] = time() + (15 * 60);
                            $del = $conn->prepare('DELETE FROM password_reset_codes WHERE user_id = ?');
                            if ($del) { $del->bind_param('i', $userId); $del->execute(); $del->close(); }
                            header('Location: set_new_password.php');
                            exit;
                        }
                    } else {
                        $error = 'Invalid code.';
                    }
                    $s->close();
                } else { $error = 'Server error.'; }
            } else { $error = 'Invalid email.'; }
            $u->close();
        } else { $error = 'Server error.'; }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enter Reset Code</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background:#0b0b0b; color:#fff; display:flex; align-items:center; justify-content:center; min-height:100vh; }
        .card { max-width: 420px; width: 100%; }
    </style>
    </head>
<body>
        <div class="card bg-secondary">
            <div class="card-body">
                <h5 class="card-title text-center">Reset Password</h5>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
                    <div class="mb-3">
                        <label class="form-label">Verification code</label>
                        <input type="text" class="form-control" name="code" maxlength="6" minlength="6" required>
                    </div>
                    <button type="submit" class="btn btn-warning w-100">Verify code</button>
                </form>
            </div>
        </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0/dist/js/bootstrap.bundle.min.js"></script>
 </body>
</html>



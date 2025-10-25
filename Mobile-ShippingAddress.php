<?php
session_start();
require_once 'dbconn.php';

// Determine base URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$path = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$baseURL = $protocol . '://' . $host . $path . '/';

if (!isset($_SESSION['user_id'])) {
    header("Location: {$baseURL}signin.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Load barangays for dropdown
$barangays = [];
$res = $conn->query("SELECT id, barangay_name FROM barangays ORDER BY barangay_name");
if ($res) {
    while ($row = $res->fetch_assoc()) { $barangays[] = $row; }
}

// Get current user address
$sql = "SELECT u.purok, u.phone_number, u.barangay_id, b.barangay_name FROM users u LEFT JOIN barangays b ON u.barangay_id=b.id WHERE u.id=?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$current = $stmt->get_result()->fetch_assoc();
$stmt->close();

$successMsg = $errorMsg = '';

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $barangay_id = isset($_POST['barangay']) ? (int)$_POST['barangay'] : 0;
    $purok = trim($_POST['purok'] ?? '');
    $phone = trim($_POST['contactinfo'] ?? '');

    if ($barangay_id <= 0 || $purok === '' || !preg_match('/^\d{10}$/', $phone)) {
        $errorMsg = 'Please provide valid details (10-digit phone).';
    } else {
        $upd = $conn->prepare('UPDATE users SET barangay_id=?, purok=?, phone_number=? WHERE id=?');
        $upd->bind_param('issi', $barangay_id, $purok, $phone, $user_id);
        if ($upd->execute()) {
            $successMsg = 'Shipping address updated.';
            // Refresh current data
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $current = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } else {
            $errorMsg = 'Failed to update. Please try again.';
        }
        $upd->close();
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit Shipping Address</title>
  <link rel="icon" type="image/png" href="<?= $baseURL ?>Image/logo.png">
  <link rel="stylesheet" href="<?= $baseURL ?>css/styles.css">
  <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
  <style>
    body { background: linear-gradient(180deg, #f5f7fb 0%, #eef2f7 100%); }
    .container { max-width: 520px; margin: 18px auto 28px; padding:0 14px; }
    .card { background:#ffffff; border-radius:14px; box-shadow:0 8px 24px rgba(33,37,41,0.08); overflow:hidden; border:1px solid #eef0f3; }
    .card-header { display:flex; align-items:center; gap:10px; padding:14px 16px; background:linear-gradient(90deg,#0d6efd,#3aa0ff); color:#fff; }
    .card-header .material-icons { font-size:22px; }
    .card-body { padding:16px; }
    .form-group { margin-bottom:12px; }
    .label { display:block; margin:0 0 6px; font-weight:700; color:#364152; font-size:0.95rem; }
    .input-group { display:flex; align-items:center; background:#f7f9fc; border:1px solid #dfe3eb; border-radius:10px; padding:8px 10px; }
    .input-group .material-icons { color:#6c7a91; margin-right:8px; font-size:20px; }
    .input-group input, .input-group select { border:none; outline:none; background:transparent; width:100%; font-size:0.98rem; color:#2b3441; }
    .hint { color:#6c7a91; font-size:0.85rem; margin-top:4px; }
    .actions { margin-top:16px; display:flex; gap:10px; }
    .btn { padding:12px 16px; border:none; border-radius:10px; cursor:pointer; font-weight:700; letter-spacing:0.2px; }
    .btn-primary { background:#0D6EFD; color:#fff; box-shadow:0 6px 14px rgba(13,110,253,0.25); }
    .btn-primary:hover { filter:brightness(0.97); }
    .btn-secondary { background:#6c757d; color:#fff; }
    .msg { margin-bottom:12px; padding:10px 12px; border-radius:10px; font-weight:600; }
    .msg.success { color:#0f5132; background:#d1e7dd; border:1px solid #badbcc; }
    .msg.error { color:#842029; background:#f8d7da; border:1px solid #f5c2c7; }
    .divider { height:1px; background:#eef0f3; margin:10px 0 14px; }
  </style>
</head>
<body>
  <div class="app">
    <header class="cart-header" style="display:flex;align-items:center;justify-content:center;position:relative;">
      <a href="<?= $baseURL ?>Mobile-Dashboard.php" class="back-button" style="position:absolute;left:0;"><span class="material-icons">arrow_back</span></a>
      <h1 style="margin:0;color:#333;">Edit Shipping Address</h1>
    </header>
    <div class="container">
      <div class="card">
        <div class="card-header"><span class="material-icons">location_on</span> Edit Shipping Address</div>
        <div class="card-body">
          <?php if ($successMsg): ?><div class="msg success" id="flashMsg"><?= htmlspecialchars($successMsg) ?></div><?php endif; ?>
          <?php if ($errorMsg): ?><div class="msg error" id="flashMsg"><?= htmlspecialchars($errorMsg) ?></div><?php endif; ?>
          <form method="POST">
            <div class="form-group">
              <span class="label">Barangay</span>
              <div class="input-group">
                <span class="material-icons">map</span>
                <select id="barangay" name="barangay" required>
                  <option value="">Select Barangay</option>
                  <?php foreach ($barangays as $b): $sel = ($b['id'] == ($current['barangay_id'] ?? 0)) ? 'selected' : ''; ?>
                    <option value="<?= (int)$b['id'] ?>" <?= $sel ?>><?= htmlspecialchars($b['barangay_name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="form-group">
              <span class="label">Purok</span>
              <div class="input-group">
                <span class="material-icons">home</span>
                <input type="text" id="purok" name="purok" value="<?= htmlspecialchars($current['purok'] ?? '') ?>" required>
              </div>
              <div class="hint">Provide a short, precise landmark (e.g., near barangay hall).</div>
            </div>

            <div class="form-group">
              <span class="label">Phone Number</span>
              <div class="input-group">
                <span class="material-icons">call</span>
                <span style="color:#6c7a91;margin-right:6px;">+63</span>
                <input type="tel" id="contactinfo" name="contactinfo" value="<?= htmlspecialchars($current['phone_number'] ?? '') ?>" placeholder="9XXXXXXXXX" inputmode="numeric" pattern="\d{10}" maxlength="10">
              </div>
            </div>

            <div class="divider"></div>
            <div class="actions">
              <button type="submit" class="btn btn-primary" style="flex:1;">Save Changes</button>
              <a href="<?= $baseURL ?>Mobile-Dashboard.php" class="btn btn-secondary" style="flex:1; text-align:center;">Cancel</a>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</body>
</html>

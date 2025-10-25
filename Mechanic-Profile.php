<?php
session_start();
require_once 'dbconn.php';

// Dynamically determine the base URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$path = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$baseURL = $protocol . '://' . $host . $path . '/';

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: {$baseURL}signin.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch mechanic data directly from mechanics table
$sql = "SELECT m.* FROM mechanics m WHERE m.id = ?";

$stmt = $conn->prepare($sql);

if ($stmt === false) {
    // Handle the error
    echo "Error preparing statement: " . $conn->error; // Output the specific MySQL error
    exit(); // Or handle the error in a way that makes sense for your application
}

$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if (!$user) {
    echo "Mechanic not found!";
    exit(); // Or redirect to an error page
}

// Fetch transaction history (Completed or Cancelled requests for this mechanic)
$historyRecords = [];
$sqlHistory = "SELECT id, name, bike_unit, plate_number, problem_description, location, status,
                      COALESCE(updated_at, created_at) AS activity_time
               FROM help_requests
               WHERE mechanic_id = ? AND status IN ('Completed','Cancelled')
               ORDER BY activity_time DESC";
$stmtHistory = $conn->prepare($sqlHistory);
if ($stmtHistory) {
    $stmtHistory->bind_param("i", $user_id);
    $stmtHistory->execute();
    $resHistory = $stmtHistory->get_result();
    while ($row = $resHistory->fetch_assoc()) {
        $historyRecords[] = $row;
    }
    $stmtHistory->close();
}

$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mechanic Profile</title>
    <link rel="icon" type="image/png" href="<?= $baseURL ?>Image/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= $baseURL ?>css/mechanic-responsive.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" integrity="sha512-9usAa10IRO0HhonpyAIVpjrylPvoDwiPUiKdWk5t3PyolY1cOd4DSE0Ga+ri4AuTroPR5aQvXU9xC6qOPnzFeg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        body {
            background-color: #f8f9fa;
            margin: 0;
            padding-bottom: 85px; /* Prevent bottom nav overlap */
        }

        .profile-container {
            width: 100%;
            max-width: 100%;
            margin: 0;
            padding: 20px;
            background-color: #fff;
            border-radius: 0;      /* edge-to-edge like a window */
            box-shadow: none;       /* remove card look */
            margin-bottom: 70px;    /* Space for the bottom navigation */
        }
        
        /* Responsive profile container */
        @media (min-width: 576px) {
            .profile-container {
                max-width: 540px;
                margin: 0 auto;
                border-radius: 12px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.08);
                margin-bottom: 70px;
            }
        }
        
        @media (min-width: 768px) {
            .profile-container {
                max-width: 720px;
                padding: 30px;
            }
        }
        
        @media (min-width: 992px) {
            .profile-container {
                max-width: 960px;
            }
        }
        
        @media (min-width: 1200px) {
            .profile-container {
                max-width: 1140px;
            }
        }

        .profile-header {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 20px;
        }

        .profile-title {
            text-align: center;
            font-size: 1.5em;
            color: #333;
            margin-bottom: 20px;
        }

        .close-button {
            background: none;
            border: none;
            font-size: 24px;
            color: #6c757d;
            cursor: pointer;
        }

        .profile-info {
            text-align: center;
            margin-bottom: 20px; /* Reduced margin */
        }

        .profile-image {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            margin-bottom: 15px;
        }
        
        /* Responsive profile image */
        @media (max-width: 576px) {
            .profile-image {
                width: 120px;
                height: 120px;
            }
        }
        
        @media (min-width: 768px) {
            .profile-image {
                width: 180px;
                height: 180px;
            }
        }

        .profile-name {
            font-size: 24px;
            font-weight: bold;
            color: #343a40;
        }

        .profile-email,
        .profile-phone {
            color: #6c757d;
            margin-bottom: 8px;
        }

        .profile-address {
            color: #6c757d;
            margin-bottom: 8px;
            text-align: center; /* Center the address text */
        }

       .vehicle-info {
            text-align: center;
            margin-top: 10px; /* Add some space above vehicle info */
            color: #6c757d; /* Match other profile info text color */
        }
       .vehicle-info strong {
            font-weight: bold;
            color: #343a40; /* Match heading color */
        }

        .profile-menu {
            display: flex;
            flex-direction: column;
            margin-bottom: 20px; /* Spacing before logout button */
        }

        .menu-item {
            background-color: #f8f9fa;
            padding: 15px;
            margin-bottom: 10px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: #343a40;
            text-decoration: none;
            transition: background-color 0.3s ease;
            cursor: pointer;
        }

        .menu-item:hover {
            background-color: #e9ecef;
        }

        .menu-item i {
            margin-right: 10px;
            color: #495057;
        }

        .logout-button {
            background-color: #e74c3c; /* Red color */
            color: #fff;
            padding: 15px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 18px;
            transition: background-color 0.3s ease;
            display: flex;          /* Use flexbox for icon and text */
            align-items: center;   /* Vertically center content */
            justify-content: center; /* Horizontally center content */
            width: fit-content;     /* Shrink to content width */
            margin: 15px auto 0;    /* Center the button horizontally */
        }

        .logout-button:hover {
            background-color: #c0392b; /* Darker red on hover */
        }

        .logout-button .material-icons {
             margin-right: 8px; /* Space between icon and text */
        }

        /* Styles for the bottom navigation */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #530707;
            display: flex;
            justify-content: space-around;
            padding: 0.8rem;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
            width: 100%;
            z-index: 1000;
        }

        .bottom-nav a {
            text-decoration: none;
            color: #ddd;
            display: flex;
            flex-direction: column;
            align-items: center;
            font-size: 0.8rem;
        }

        .bottom-nav a.active {
            color: #ffc107; /* Highlight color - yellow */
        }

        .bottom-nav .material-icons {
            font-size: 1.5rem;
            margin-bottom: 0.2rem;
        }

        /* Centered inner content while keeping full-width page */
        .profile-inner {
            max-width: 960px;
            margin: 0 auto;
        }

        /* Full-width layout on all breakpoints */
        @media (min-width: 576px) { .profile-container { max-width: 100%; } }
        @media (min-width: 768px) { .profile-container { max-width: 100%; } }
        @media (min-width: 992px) { .profile-container { max-width: 100%; } }
        @media (min-width: 1200px) { .profile-container { max-width: 100%; } }
    </style>
</head>
<body>
    <div class="profile-container">
        <div class="profile-inner">
        <div class="profile-header">
            <button class="close-button" onclick="window.location.href='<?= $baseURL ?>Mechanic-Dashboard.php'">
                ×
            </button>
        </div>

        <div class="profile-title">
            Profile
        </div>

        <div class="profile-info">
            <?php if (!empty($user['ImagePath'])): ?>
                <img src="<?= htmlspecialchars($baseURL . $user['ImagePath']) ?>" alt="Profile" class="profile-image">
            <?php else: ?>
                <img src="https://ui-avatars.com/api/?name=<?= urlencode(($user['first_name'] ?? 'Mechanic') . ' ' . ($user['last_name'] ?? '')) ?>&size=150" alt="Profile" class="profile-image">
            <?php endif; ?>
            <h2 class="profile-name"><?= htmlspecialchars(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?></h2>
            <p class="profile-email"><?= htmlspecialchars($user['email']) ?></p>
            <p class="profile-phone">+(63)<?= htmlspecialchars($user['phone_number'] ?? '') ?></p>

          <p class="profile-address">
            <?= nl2br(htmlspecialchars($user['home_address'] ?? '')) ?>
          </p>

          <div class="vehicle-info">
            <strong>Vehicle Information:</strong><br>
            Motor Type: <?= htmlspecialchars($user['MotorType'] ?? '-') ?><br>
            Plate Number: <?= htmlspecialchars($user['PlateNumber'] ?? '-') ?><br>
            Specialization: <?= htmlspecialchars($user['specialization'] ?? 'General Mechanic') ?>
          </div>
        </div>

        <div class="profile-menu">
            <div id="historyToggle" class="menu-item">
                <span style="display:flex; align-items:center; gap:10px;"><i class="material-icons">history</i>Transaction History</span>
                <span id="historyChevron" class="material-icons">expand_more</span>
            </div>
            <div id="historyContainer" class="table-responsive" style="background:#fff; border-radius:8px; display:none;">
                <table class="table table-sm" style="margin:0;">
                    <thead>
                        <tr>
                            <th style="width:25%">Customer</th>
                            <th style="width:20%">Bike</th>
                            <th style="width:35%">Problem</th>
                            <th style="width:10%">Status</th>
                            <th style="width:10%">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($historyRecords)): ?>
                            <tr><td colspan="5" class="text-center" style="color:#6c757d;">No completed or cancelled requests yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($historyRecords as $h): ?>
                                <tr>
                                    <td><?= htmlspecialchars($h['name'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($h['bike_unit'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($h['problem_description'] ?? '-') ?></td>
                                    <td>
                                        <?php $st = strtolower($h['status']); ?>
                                        <span class="badge <?= $st === 'completed' ? 'bg-success' : 'bg-danger' ?>"><?= htmlspecialchars($h['status']) ?></span>
                                    </td>
                                    <td><?= htmlspecialchars(date('Y-m-d', strtotime($h['activity_time']))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <button class="logout-button" onclick="logout()">
    <span class="material-icons">logout</span>
    <span>Logout</span>
</button>
        </div>
    </div>

    <!-- Bottom Navigation -->
    <nav class="bottom-nav">
        <a href="<?= $baseURL ?>Mechanic-Dashboard.php">
            <span class="material-icons">dashboard</span>
            <span>Dashboard</span>
        </a>
        <a href="<?= $baseURL ?>Mechanic-Transaction.php">
            <span class="material-icons">history</span>
            <span>Transactions</span>
        </a>
        <a href="<?= $baseURL ?>Mechanic-Profile.php" class="active">
            <span class="material-icons">person</span>
            <span>Profile</span>
        </a>
    </nav>
<script>
  (function() {
    var toggle = document.getElementById('historyToggle');
    var container = document.getElementById('historyContainer');
    var chevron = document.getElementById('historyChevron');
    if (toggle && container && chevron) {
      toggle.addEventListener('click', function() {
        var isHidden = container.style.display === 'none' || container.style.display === '';
        container.style.display = isHidden ? 'block' : 'none';
        chevron.textContent = isHidden ? 'expand_less' : 'expand_more';
      });
    }
  })();
</script>
</body>
</html> 
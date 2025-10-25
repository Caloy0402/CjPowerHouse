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

// Fetch rider data from the database (Riders table) and join with barangays table
$sql = "SELECT 
            r.*, 
            b.barangay_name 
        FROM 
            riders r
        LEFT JOIN 
            barangays b ON r.barangay_id = b.id
        WHERE 
            r.id = ?";

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
    echo "Rider not found!";
    exit(); // Or redirect to an error page
}

// Fetch recent completed/returned deliveries for this rider (limit 5)
$recent_orders = [];
if ($user) {
    $riderFullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    $sql_recent = "SELECT 
                        o.id, o.order_date, o.order_status, o.total_price, o.payment_method, o.delivery_method,
                        t.transaction_number, gt.reference_number,
                        o.delivery_fee, o.total_amount_with_delivery,
                        bf.fare_amount AS barangay_fare, bf.staff_fare_amount AS barangay_staff_fare,
                        CASE 
                            WHEN o.delivery_method = 'staff' THEN COALESCE(NULLIF(o.delivery_fee, 0), bf.staff_fare_amount, 0)
                            ELSE COALESCE(NULLIF(o.delivery_fee, 0), bf.fare_amount, 0) 
                        END AS delivery_fee_effective,
                        CASE 
                            WHEN (o.total_amount_with_delivery IS NULL OR o.total_amount_with_delivery = 0)
                                THEN (o.total_price + 
                                    CASE 
                                        WHEN o.delivery_method = 'staff' THEN COALESCE(NULLIF(o.delivery_fee, 0), bf.staff_fare_amount, 0)
                                        ELSE COALESCE(NULLIF(o.delivery_fee, 0), bf.fare_amount, 0) 
                                    END)
                            ELSE o.total_amount_with_delivery 
                        END AS total_with_delivery_effective
                    FROM orders o
                    LEFT JOIN transactions t ON o.id = t.order_id
                    LEFT JOIN gcash_transactions gt ON o.id = gt.order_id
                    LEFT JOIN users u ON o.user_id = u.id
                    LEFT JOIN barangays b ON u.barangay_id = b.id
                    LEFT JOIN barangay_fares bf ON b.id = bf.barangay_id
                    WHERE o.rider_name = ? AND o.order_status IN ('Completed','Returned')
                    ORDER BY o.order_date DESC
                    LIMIT 5";
    if ($stmt2 = $conn->prepare($sql_recent)) {
        $stmt2->bind_param('s', $riderFullName);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        while ($row = $res2->fetch_assoc()) { $recent_orders[] = $row; }
        $stmt2->close();
    }
}

$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rider Profile</title>
    <link rel="icon" type="image/png" href="<?= $baseURL ?>Image/logo.png">
    <link href="<?= $baseURL ?>css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= $baseURL ?>css/styles.css">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" integrity="sha512-9usAa10IRO0HhonpyAIVpjrylPvoDwiPUiKdWk5t3PyolY1cOd4DSE0Ga+ri4AuTroPR5aQvXU9xC6qOPnzFeg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        body {
            background-color: #f8f9fa;
        }

        .profile-container {
            width: 100%;
            max-width: 480px; /* Match the app max-width */
            margin: 0 auto;
            padding: 20px;
            background-color: #fff;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 70px; /* Space for the bottom navigation */
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
            text-align: left;
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
            max-width: 480px;
            margin: 0 auto;
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
            color: #ff4500; /* Highlight color */
        }

        .bottom-nav .material-icons {
            font-size: 1.5rem;
            margin-bottom: 0.2rem;
        }
    </style>
</head>
<body>
    <div class="profile-container">
        <div class="profile-header">
            <button class="close-button" onclick="window.location.href='<?= $baseURL ?>Rider-Dashboard.php'">
                ×
            </button>
        </div>

        <div class="profile-title">
            Profile
        </div>

        <div class="profile-info">
            <?php if (!empty($user['ImagePath'])): ?>
                <img src="<?= $baseURL . $user['ImagePath'] ?>" alt="Profile" class="profile-image">
            <?php else: ?>
                <img src="https://ui-avatars.com/api/?name=<?= urlencode($user['first_name'] . ' ' . $user['last_name']) ?>&size=150" alt="Profile" class="profile-image">
            <?php endif; ?>
            <h2 class="profile-name"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></h2>
            <p class="profile-email"><?= htmlspecialchars($user['email']) ?></p>
            <p class="profile-phone">+(63)<?= htmlspecialchars($user['phone_number']) ?></p>

          <p class="profile-address">
            Brgy: <?= htmlspecialchars($user['barangay_name']) ?><br>
            Purok: <?= htmlspecialchars($user['purok']) ?><br>
            Valencia City, Bukidnon
          </p>

            <!-- Vehicle Information Section - Blended in -->
            <div class="vehicle-info">
               <strong>Vehicle Information</strong>
               <?php if ($user && isset($user['MotorType']) && isset($user['PlateNumber'])): ?>
                   <p>Motor Type: <?= htmlspecialchars($user['MotorType']) ?></p>
                   <p>Plate Number: <?= htmlspecialchars($user['PlateNumber']) ?></p>
               <?php else: ?>
                   <p>Vehicle information not available.</p>
               <?php endif; ?>
           </div>
        </div>

        <div class="profile-menu">
            <div class="menu-item" id="recentDeliveriesToggle" style="cursor: pointer;">
                <span style="display:flex; align-items:center; gap:10px;"><i class="fas fa-shopping-bag"></i><strong>Recent Deliveries</strong></span>
                <i class="fas fa-chevron-down" id="recentChevron"></i>
            </div>
            <div id="recentDeliveriesContent" style="display:none;">
            <?php if (!empty($recent_orders)): ?>
                <?php foreach ($recent_orders as $ro): ?>
                    <div class="menu-item" style="background-color:#fff; border:1px solid #eee;">
                        <div style="display:flex; flex-direction:column; gap:4px;">
                            <span style="font-weight:600; color:#343a40;">Order #<?= htmlspecialchars($ro['id']) ?></span>
                            <span style="font-size:12px; color:#6c757d;"><?= date("M j, Y, g:i a", strtotime($ro['order_date'])) ?></span>
                            <span style="font-size:12px; color:#6c757d;">Status: <strong style="color:<?= ($ro['order_status']==='Returned'?'#dc3545':'#198754') ?>;"><?= htmlspecialchars($ro['order_status']) ?></strong></span>
                            <span style="font-size:12px; color:#6c757d;">Payment: <?= htmlspecialchars($ro['payment_method'] ?? '') ?><?= (strtoupper($ro['payment_method'] ?? '')==='GCASH' && !empty($ro['reference_number'])) ? ' (RFN#: '.htmlspecialchars($ro['reference_number']).')' : '' ?></span>
                        </div>
                        <div style="text-align:right;">
                            <?php
                                $fee = $ro['delivery_fee_effective'] ?? $ro['delivery_fee'] ?? 0;
                            ?>
                            <div style="font-size:12px; color:#6c757d;">Delivery Fee: ₱<?= htmlspecialchars($fee) ?></div>
                            <div style="font-weight:700; color:#343a40;">Total: ₱<?= htmlspecialchars($ro['total_with_delivery_effective'] ?? $ro['total_amount_with_delivery'] ?? $ro['total_price']) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <a href="<?= $baseURL ?>Rider-Transaction.php" class="menu-item" style="justify-content:center; background:#0d6efd; color:#fff;">
                    <i class="fas fa-list" style="color:#fff;"></i>
                    <span>View All Deliveries</span>
                </a>
            <?php else: ?>
                <div class="menu-item" style="background-color:#fff; border:1px dashed #ddd; color:#6c757d; justify-content:center;">No recent deliveries yet.</div>
            <?php endif; ?>
            </div>
        </div>

        <button class="logout-button" onclick="logout()">
    <span class="material-icons">logout</span>Logout
</button>
    </div>

    <nav class="bottom-nav">
        <a href="<?= $baseURL ?>Rider-Dashboard.php">
            <span class="material-icons">home</span>
            <span>Home</span>
        </a>
        <a href="<?= $baseURL ?>Rider-Transaction.php">
            <span class="material-icons">history</span>
            <span>Delivery Status</span>
        </a>
        <a href="<?= $baseURL ?>Rider-Profile.php" class="active">
            <span class="material-icons">person</span>
            <span>Profile</span>
        </a>
    </nav>
</body>
<script>
// Toggle dropdown for Recent Deliveries
document.addEventListener('DOMContentLoaded', function() {
    const toggle = document.getElementById('recentDeliveriesToggle');
    const content = document.getElementById('recentDeliveriesContent');
    const chevron = document.getElementById('recentChevron');
    if (toggle && content && chevron) {
        toggle.addEventListener('click', function() {
            const isHidden = content.style.display === 'none' || content.style.display === '';
            content.style.display = isHidden ? 'block' : 'none';
            chevron.classList.toggle('fa-chevron-down', !isHidden);
            chevron.classList.toggle('fa-chevron-up', isHidden);
        });
    }
});
</script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/script.js"></script>
</html>
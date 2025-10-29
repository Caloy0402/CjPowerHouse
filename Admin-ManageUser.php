<?php
session_start();
require_once 'dbconn.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'admin') {
    header('Location: login.php');
    exit();
}

// Ensure is_active column exists in all staff tables
$tables = ['cjusers', 'mechanics', 'riders'];
foreach ($tables as $table) {
    $checkColumn = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE 'is_active'");
    if ($checkColumn->num_rows == 0) {
        $conn->query("ALTER TABLE `{$table}` ADD COLUMN `is_active` TINYINT(1) DEFAULT 1");
    }
}

// Get user data for profile image
$user_id = $_SESSION['user_id'];
$user_query = $conn->prepare("SELECT first_name, last_name, profile_image FROM cjusers WHERE id = ?");
$user_query->bind_param("i", $user_id);
$user_query->execute();
$user_result = $user_query->get_result();
$user_data = $user_result->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Jandi - Admin Dashboard</title>
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <meta content="" name="keywords">
    <meta content="" name="description">

    <!-- Favicons -->
    <link href="image/logo.png" rel="icon">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600&family=Roboto:wght@500;700&display=swap" rel="stylesheet">

    <!-- icon font Stylesheet -->
     <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.10.0/css/all.min.css"> 
     <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.4.1/font/bootstrap-icons.css">

     <!--libraries stylesheet-->
    <link href="lib/owlcarousel/assets/owl.carousel.min.css" rel="stylesheet">
    <link href="lib/tempusdominus/css/tempusdominus-bootstrap-4.min.css" rel="stylesheet">

    <!--customized Bootstrap Stylesheet-->
    <link href="css/bootstrap.min.css" rel="stylesheet">

    <!--Template Stylesheet-->
    <link href="css/style.css" rel="stylesheet">

</head>
<body>
    <div class="container-fluid position-relative d-flex p-0">
         <!-- Spinner Start -->
    <div id="spinner" class="show bg-dark position-fixed translate-middle w-100 vh-100 top-50 start-50 d-flex align-items-center justify-content-center">
    <img src="img/Loading.gif" alt="Loading..." style="width: 200px; height: 200px;" />
    </div>
    <!-- Spinner End -->
        <!-- Sidebar Start -->
     <!-- Sidebar Start -->
<div class="sidebar pe-4 pb-3">
    <nav class="navbar bg-secondary navbar-dark">
        <a href="Admin-Dashboard.php" class="navbar-brand mx-4 mb-3">
            <h3 class="text-primary"><i class="fa fa-user-edit me-2"></i>Cj P'House</h3>
        </a>
        <div class="d-flex align-items-center ms-4 mb-4">
            <div class="position-relative">
                <img src="<?= $user_data['profile_image'] ? (strpos($user_data['profile_image'], 'uploads/') === 0 ? $user_data['profile_image'] : 'uploads/' . $user_data['profile_image']) : 'img/jandi.jpg' ?>" alt="" class="rounded-circle" style="width: 40px; height: 40px;">
                <div class="bg-success rounded-circle border border-2 border-white position-absolute end-0 bottom-0 p-1"></div>
            </div>
            <div class="ms-3">
                <h6 class="mb-0"><?= htmlspecialchars($user_data['first_name']) ?></h6>
                <span>Admin</span>
            </div>
        </div>
        <div class="navbar-nav w-100">
            <a href="Admin-Dashboard.php" class="nav-item nav-link active"><i class="fa fa-tachometer-alt me-2"></i>Dashboard</a>

            <div class="nav-item dropdown">
                <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="fa fa-users me-2"></i>Users
                </a>
                <div class="dropdown-menu bg-transparent border-0">
                    <a href="Admin-AddUser.php" class="dropdown-item">Add Users</a>
                    <a href="Admin-ManageUser.php" class="dropdown-item">Manage Users</a>
                </div>
            </div>

            <!-- Updated Product Dropdown -->
            <div class="nav-item dropdown">
                <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="fa fa-th me-2"></i>Product
                </a>
                <div class="dropdown-menu bg-transparent border-0">
                    <a href="Admin-Stockmanagement.php" class="dropdown-item">Stock Management</a>
                    <a href="Admin-buy-out-item.php" class="dropdown-item">Buy-out Item</a>
                    <a href="Admin-ReturnedItems.php" class="dropdown-item">Returned Item</a>
                </div>
            </div>

            <a href="Admin-OrderLogs.php" class="nav-item nav-link"><i class="fa fa-shopping-cart me-2"></i>Order Logs</a>
            <a href="Admin-SalesReport.php" class="nav-item nav-link"><i class="fa fa-file-alt me-2"></i>Sales Report</a>
            <a href="Admin-StaffLogs.php" class="nav-item nav-link"><i class="fa fa-user-clock me-2"></i>Staff Logs</a>
            <a href="Admin-RescueLogs.php" class="nav-item nav-link"><i class="fa fa-tools me-2"></i>Rescue Logs</a>

            </div>
        </div>
    </nav>
</div>
<!-- Sidebar End -->

        <!--Content Start-->
            <div class="content">
                <!--Navbar Start-->
                   <nav class="navbar navbar-expand bg-secondary navbar-dark sticky-top 
                   px-4 py-0">
                        <a href="Admin-Dashboard.php" class="navbar-brand d-flex d-lg-none me-4">
                            <h2 class="text-primary mb-0"><i class="fa fa-user-edit"></i></h2>
                        </a>
                        <a href="#" class="sidebar-toggler flex-shrink-0">
                            <i class="fa fa-bars"></i>
                        </a>

                        <div class="navbar-nav align-items-center ms-auto">
                <?php include 'admin_notifications.php'; ?>
                <?php include 'admin_rescue_notifications.php'; ?>
                <?php include 'admin_user_notifications.php'; ?>
                            <div class="nav-item dropdown">
                        <div class="dropdown-menu dropdown-menu-end bg-secondary 
                        border-0 rounded-0 rounded-bottom m-0">
                        <a href="#" class="dropdown-item">
                            <div class="d-flex aligns-items-center">
                                <img src="img/johanns.jpg" alt="User Profile"
                                class="rounded-circle" style="width: 40px; height: 
                                40px;">
                                <div class="ms-2">
                                    <h6 class="fw-normal mb-0">Johanns send you a 
                                    message</h6>
                                    <small>5 minutes ago</small>
                            </div>
                        </div>
                        </a>
                         <hr class="dropdown-divider">
                         <a href="#" class="dropdown-item">
                            <div class="d-flex aligns-items-center">
                                <img src="img/carlo.jpg" alt=""
                                class="rounded-circle" style="width: 40px; height: 
                                40px;">
                                <div class="ms-2">
                                    <h6 class="fw-normal mb-0">Carlo send you a 
                                    message</h6>
                                    <small>10 minutes ago</small>
                            </div>
                        </div>
                        </a>
                        <hr class="dropdown-divider">
                        <a href="#" class="dropdown-item">
                            <div class="d-flex aligns-items-center">
                                <img src="img/alquin.jpg" alt=""
                                class="rounded-circle" style="width: 40px; height: 
                                40px;">
                                <div class="ms-2">
                                    <h6 class="fw-normal mb-0">Alquin send you a 
                                    message</h6>
                                    <small>15 minutes ago</small>
                            </div>
                        </div>
                        </a>
                        <hr class="dropdown-divider">
                        <a href="#" class="dropdown-item text-center">See all 
                        Messages</a>
                    </div>
                </div>
                <div class="nav-item dropdown">
                    
                    <div class="dropdown-menu dropdown-menu-end bg-secondary 
                    border-0 rounded-0 rounded-bottom m-0">
                        <a href="#" class="dropdown-item">
                            <h6 class="fw-normal mb-0">profile updated</h6>

                            <small>10 minutes ago</small>
                        </a>
                        <hr class="dropdown-divider">
                        <a href="#" class="dropdown-item">
                            <h6 class="fw-normal mb-0">Password Changed</h6>
                            <small>15 minutes ago</small>
                        </a>
                        <a hr class="dropdown-divider">
                        <a href="#" class="dropdown-item">
                            <h6 class="fw-normal mb-0">User Added</h6>
                            <small>20 minutes ago</small>
                        </a>
                        <a hr class="dropdown-divider">
                        <a href="#" class="dropdown-item text-center">See all 
                        Notification</a>
                 </div>        
            </div>
            <div class="nav-item dropdown">
                <a href="" class="nav-link dropdown-toggle" 
                data-bs-toggle="dropdown">
                <img src="<?= $user_data['profile_image'] ? (strpos($user_data['profile_image'], 'uploads/') === 0 ? $user_data['profile_image'] : 'uploads/' . $user_data['profile_image']) : 'img/jandi.jpg' ?>" alt="" class="rounded-circle me-lg-2" 
                    alt="" style="width: 40px; height: 40px;">
                <span class="d-none d-lg-inline"><?= htmlspecialchars($user_data['first_name']) ?></span>
                </a>
                <div class="dropdown-menu dropdown-menu-end bg-dark border-0 rounded-3 shadow-lg m-0" style="min-width: 200px;">
                    <div class="dropdown-header text-light border-bottom border-secondary">
                        <small class="text-muted">Account</small>
                    </div>
                    <a href="Admin-Profile.php" class="dropdown-item text-light d-flex align-items-center py-2">
                        <i class="fas fa-user me-2 text-primary"></i>
                        <span>Profile</span>
                    </a>
                    <a href="logout.php" class="dropdown-item text-light d-flex align-items-center py-2">
                        <i class="fas fa-sign-out-alt me-2 text-danger"></i>
                        <span>Log out</span>
                    </a>
                    </div> 
                </div>
            </div>
         </nav>
         <!--Navbar End-->

 <!-- Manage Users -->
<div class="container-fluid pt-4 px-4">
    <div class="bg-secondary text-center rounded p-4">
        <div class="d-flex align-items-center justify-content-between mb-4">
            <h6 class="mb-0">Manage Users</h6>
            <button class="btn btn-primary btn-sm" onclick="refreshUserTable()" id="refreshBtn">
                <i class="fas fa-sync-alt me-1"></i>Refresh Table
            </button>
        </div>
        <?php
        require_once 'dbconn.php';
        
        // Set a timeout to prevent hanging
        set_time_limit(30);
        
        // Pagination
        $perPage = 10;
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        if ($page < 1) { $page = 1; }
        $offset = ($page - 1) * $perPage;

        // Total count for pagination
        $countRes = $conn->query("SELECT COUNT(*) AS c FROM users");
        $totalUsers = ($countRes && ($row = $countRes->fetch_assoc())) ? (int)$row['c'] : 0;
        $totalPages = max(1, (int)ceil($totalUsers / $perPage));

        // Ensure required columns exist first
        $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS cod_suspended TINYINT(1) DEFAULT 0");
        $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS cod_suspended_until DATETIME NULL");
        $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS cod_failed_attempts INT DEFAULT 0");
        $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL");
        $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS last_logout_at DATETIME NULL");
        
        // Ensure staff_logs table exists
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
        
        // Auto-clear expired suspensions
        $conn->query("UPDATE users SET cod_suspended = 0, cod_suspended_until = NULL WHERE cod_suspended = 1 AND cod_suspended_until < NOW()");
        
        // Fetch users with last login/out and COD status using existing columns
        $sql = "SELECT u.id, u.first_name, u.last_name, u.email, u.ImagePath, u.barangay_id, u.purok,
                       u.cod_suspended, u.cod_suspended_until, u.cod_failed_attempts,
                       u.last_login_at, u.last_logout_at,
                       (SELECT appeal_text FROM cod_appeals ca WHERE ca.user_id = u.id AND ca.status='pending' ORDER BY ca.id DESC LIMIT 1) AS latest_appeal,
                       (SELECT created_at FROM cod_appeals ca2 WHERE ca2.user_id = u.id AND ca2.status='pending' ORDER BY ca2.id DESC LIMIT 1) AS latest_appeal_at,
                       CASE 
                           WHEN u.cod_suspended = 1 AND u.cod_suspended_until > NOW() THEN 1 
                           ELSE 0 
                       END AS is_actually_suspended,
                       CASE 
                           WHEN EXISTS (
                               SELECT 1 FROM staff_logs sl 
                               WHERE sl.staff_id = u.id 
                                 AND sl.time_out IS NULL
                           ) THEN 1
                           WHEN u.last_login_at IS NOT NULL AND (u.last_logout_at IS NULL OR u.last_login_at > u.last_logout_at) THEN 1 
                           ELSE 0 
                       END AS is_online
                FROM users u
                ORDER BY u.id DESC
                LIMIT $perPage OFFSET $offset";
        
        $res = $conn->query($sql);
        
        // Check for database errors
        if (!$res) {
            echo '<div class="alert alert-danger">Database Error: ' . $conn->error . '</div>';
            // Try a simpler fallback query
            $fallback_sql = "SELECT id, first_name, last_name, email, ImagePath FROM users ORDER BY id DESC";
            $res = $conn->query($fallback_sql);
            if (!$res) {
                echo '<div class="alert alert-danger">Fallback query also failed: ' . $conn->error . '</div>';
                $res = null;
            }
        }
        ?>
        <div class="table-responsive">
            <table class="table align-middle table-bordered table-hover mb-0">
                <thead>
                    <tr class="text-white">
                        <th class="hidden-column"><input type="checkbox" class="form-check-input"></th>
                        <th class="hidden-column">Image</th>
                        <th>ID</th>
                        <th>Name</th>
                        <th class="hidden-column">Email</th>
                        <th>Status</th>
                        <th>Suspension Expires</th>
                        <th>COD Failed Attempts</th>
                        <th>Appealed Action</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($res && $res->num_rows > 0): while ($u = $res->fetch_assoc()): ?>
                        <tr>
                            <td class="hidden-column"><input type="checkbox" class="form-check-input"></td>
                            <td class="hidden-column"><img src="<?= htmlspecialchars($u['ImagePath']) ?>" class="img-fluid rounded-circle" style="width: 40px; height: 40px;"></td>
                            <td><?= (int)$u['id'] ?></td>
                            <td><?= htmlspecialchars(trim($u['first_name'].' '.$u['last_name'])) ?></td>
                            <td class="hidden-column"><?= htmlspecialchars($u['email']) ?></td>
                            <td>
                                <?php if ($u['is_actually_suspended']): ?>
                                    <div class="text-center">
                                        <span class="badge bg-danger">--Suspended (COD)--</span>
                                    </div>
                                <?php else: ?>
                                    <span class="badge bg-success">--Good--</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($u['is_actually_suspended']): ?>
                                    <span class="badge bg-warning text-dark">
                                        <?= $u['cod_suspended_until'] ? date('Y-m-d H:i', strtotime($u['cod_suspended_until'])) : 'N/A' ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-success">--Good--</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                $attempts = (int)$u['cod_failed_attempts'];
                                $badgeClass = '';
                                if ($attempts == 0) {
                                    $badgeClass = 'bg-success';
                                } elseif ($attempts == 1) {
                                    $badgeClass = 'bg-warning text-dark';
                                } elseif ($attempts >= 2) {
                                    $badgeClass = 'bg-danger';
                                }
                                ?>
                                <span class="badge <?= $badgeClass ?>"><?= $attempts ?></span>
                            </td>
                            <td>
                                <div class="text-center">
                                    <?php $hasAppeal = (!empty($u['latest_appeal']) && (int)$u['is_actually_suspended'] === 1); ?>
                                    <button class="btn <?= $hasAppeal ? 'btn-info appeal-attn' : 'btn-secondary' ?>"
                                            onclick="<?= $hasAppeal ? 'viewAppeal(this)' : 'return false;' ?>"
                                            <?= $hasAppeal ? '' : 'disabled' ?>
                                            data-appeal="<?= $hasAppeal ? htmlspecialchars($u['latest_appeal'], ENT_QUOTES) : '' ?>"
                                            data-appeal-at="<?= $hasAppeal ? ($u['latest_appeal_at'] ? date('Y-m-d H:i', strtotime($u['latest_appeal_at'])) : '-') : '' ?>">
                                        <?= $hasAppeal ? '👋 View Appeal' : 'View Appeal' ?>
                                    </button>
                                </div>
                            </td>
                            <td>
                                <div class="text-center">
                                    <div class="btn-group" role="group">
                                        <?php if ($u['is_actually_suspended']): ?>
                                            <button class="btn btn-sm btn-warning" onclick="liftSuspension(<?= (int)$u['id'] ?>)">Unsuspend</button>
                                        <?php else: ?>
                                                <?php 
                                                $attempts = (int)$u['cod_failed_attempts'];
                                                $userId = (int)$u['id'];
                                                $userName = htmlspecialchars(trim($u['first_name'].' '.$u['last_name']));
                                                ?>
                                                <?php if ($attempts == 0): ?>
                                                    <button class="btn btn-sm btn-outline-danger" onclick="showCleanRecordModal(<?= $userId ?>, '<?= $userName ?>')">Suspend (7 days)</button>
                                                <?php else: ?>
                                                    <button class="btn btn-sm btn-outline-danger" onclick="suspendUser(<?= $userId ?>)">Suspend (7 days)</button>
                                        <?php endif; ?>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-outline-info" onclick="viewUserDetails(<?= (int)$u['id'] ?>)" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; else: ?>
                        <tr><td colspan="10" class="text-center">No users found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Manage Staff Section -->
<div class="container-fluid pt-4 px-4">
    <div class="bg-secondary text-center rounded p-4">
        <div class="d-flex align-items-center justify-content-between mb-4">
            <h6 class="mb-0">Manage Staff</h6>
            <button class="btn btn-primary btn-sm" onclick="refreshAllStaffTables()" id="refreshAllStaffBtn">
                <i class="fas fa-sync-alt me-1"></i>Refresh All Staff
            </button>
        </div>

        <!-- Cashiers Table -->
        <div class="mb-4">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h6 class="text-primary mb-0"><i class="fas fa-cash-register me-2"></i>Cashiers</h6>
                <span class="badge bg-primary"><?= $cashier_result ? $cashier_result->num_rows : 0 ?> Staff</span>
            </div>
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th class="text-center" style="width: 60px;">ID</th>
                            <th style="width: 250px;">Name</th>
                            <th>Email</th>
                            <th class="text-center" style="width: 200px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Fetch cashiers from cjusers table
                        $cashier_sql = "SELECT id, first_name, last_name, email, profile_image, COALESCE(is_active, 1) as is_active FROM cjusers WHERE role = 'Cashier' ORDER BY id DESC";
                        $cashier_result = $conn->query($cashier_sql);
                        
                        if ($cashier_result && $cashier_result->num_rows > 0):
                            while ($cashier = $cashier_result->fetch_assoc()):
                        ?>
                        <tr>
                            <td class="text-center fw-bold"><?= (int)$cashier['id'] ?></td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="position-relative me-3">
                                        <img src="<?= $cashier['profile_image'] ? (strpos($cashier['profile_image'], 'uploads/') === 0 ? $cashier['profile_image'] : 'uploads/' . $cashier['profile_image']) : 'img/jandi.jpg' ?>" 
                                             class="rounded-circle border border-2 border-primary" style="width: 40px; height: 40px; object-fit: cover;">
                                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill <?= $cashier['is_active'] ? 'bg-success' : 'bg-danger' ?>" style="font-size: 0.6em;">
                                            <?= $cashier['is_active'] ? '<i class="fas fa-check"></i>' : '<i class="fas fa-ban"></i>' ?>
                                        </span>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-muted"><?= htmlspecialchars(trim($cashier['first_name'].' '.$cashier['last_name'])) ?></div>
                                        <small class="text-muted">Cashier</small>
                                        <?php if (!$cashier['is_active']): ?>
                                            <span class="badge bg-danger ms-1">Inactive</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="text-center">
                                <div class="d-flex align-items-center justify-content-center">
                                    <i class="fas fa-envelope me-2 text-muted"></i>
                                    <span class="text-muted"><?= htmlspecialchars($cashier['email']) ?></span>
                                </div>
                            </td>
                            <td class="text-center">
                                <div class="btn-group" role="group">
                                    <button class="btn btn-sm btn-outline-danger" onclick="editStaffDetails(<?= $cashier['id'] ?>, 'cashier')" title="Edit Staff">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-info" onclick="viewStaffDetails(<?= $cashier['id'] ?>, 'cashier')" title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if ($cashier['is_active']): ?>
                                        <button class="btn btn-sm btn-outline-warning" onclick="toggleStaffStatus(<?= $cashier['id'] ?>, 'cashier', 0)" title="Deactivate Staff">
                                            <i class="fas fa-ban"></i>
                                        </button>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-outline-success" onclick="toggleStaffStatus(<?= $cashier['id'] ?>, 'cashier', 1)" title="Activate Staff">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php 
                            endwhile;
                        else:
                        ?>
                        <tr>
                            <td colspan="4" class="text-center py-4">
                                <div class="text-muted">
                                    <i class="fas fa-users fa-2x mb-2"></i>
                                    <div>No cashiers found</div>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Mechanics Table -->
        <div class="mb-4">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h6 class="text-success mb-0"><i class="fas fa-tools me-2"></i>Mechanics</h6>
                <span class="badge bg-success"><?= $mechanic_result ? $mechanic_result->num_rows : 0 ?> Staff</span>
            </div>
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th class="text-center" style="width: 60px;">ID</th>
                            <th style="width: 250px;">Name</th>
                            <th>Email</th>
                            <th class="text-center" style="width: 200px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Fetch mechanics from mechanics table
                        $mechanic_sql = "SELECT id, first_name, last_name, email, ImagePath, COALESCE(is_active, 1) as is_active FROM mechanics ORDER BY id DESC";
                        $mechanic_result = $conn->query($mechanic_sql);
                        
                        if ($mechanic_result && $mechanic_result->num_rows > 0):
                            while ($mechanic = $mechanic_result->fetch_assoc()):
                        ?>
                        <tr>
                            <td class="text-center fw-bold"><?= (int)$mechanic['id'] ?></td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="position-relative me-3">
                                        <img src="<?= htmlspecialchars($mechanic['ImagePath']) ?>" 
                                             class="rounded-circle border border-2 border-success" style="width: 40px; height: 40px; object-fit: cover;">
                                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-success" style="font-size: 0.6em;">
                                            <i class="fas fa-check"></i>
                                        </span>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-muted"><?= htmlspecialchars(trim($mechanic['first_name'].' '.$mechanic['last_name'])) ?></div>
                                        <small class="text-muted">Mechanic</small>
                                    </div>
                                </div>
                            </td>
                            <td class="text-center">
                                <div class="d-flex align-items-center justify-content-center">
                                    <i class="fas fa-envelope me-2 text-muted"></i>
                                    <span class="text-muted"><?= htmlspecialchars($mechanic['email']) ?></span>
                                </div>
                            </td>
                            <td class="text-center">
                                <div class="btn-group" role="group">
                                    <button class="btn btn-sm btn-outline-success" onclick="editStaffDetails(<?= $mechanic['id'] ?>, 'mechanic')" title="Edit Staff">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-info" onclick="viewStaffDetails(<?= $mechanic['id'] ?>, 'mechanic')" title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if ($mechanic['is_active']): ?>
                                        <button class="btn btn-sm btn-outline-warning" onclick="toggleStaffStatus(<?= $mechanic['id'] ?>, 'mechanic', 0)" title="Deactivate Staff">
                                            <i class="fas fa-ban"></i>
                                        </button>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-outline-success" onclick="toggleStaffStatus(<?= $mechanic['id'] ?>, 'mechanic', 1)" title="Activate Staff">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php 
                            endwhile;
                        else:
                        ?>
                        <tr>
                            <td colspan="4" class="text-center py-4">
                                <div class="text-muted">
                                    <i class="fas fa-tools fa-2x mb-2"></i>
                                    <div>No mechanics found</div>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Riders Table -->
        <div class="mb-4">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h6 class="text-warning mb-0"><i class="fas fa-motorcycle me-2"></i>Riders</h6>
                <span class="badge bg-warning text-dark"><?= $rider_result ? $rider_result->num_rows : 0 ?> Staff</span>
            </div>
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th class="text-center" style="width: 60px;">ID</th>
                            <th style="width: 250px;">Name</th>
                            <th>Email</th>
                            <th class="text-center" style="width: 200px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Fetch riders from riders table
                        $rider_sql = "SELECT id, first_name, last_name, email, ImagePath, COALESCE(is_active, 1) as is_active FROM riders ORDER BY id DESC";
                        $rider_result = $conn->query($rider_sql);
                        
                        if ($rider_result && $rider_result->num_rows > 0):
                            while ($rider = $rider_result->fetch_assoc()):
                        ?>
                        <tr>
                            <td class="text-center fw-bold"><?= (int)$rider['id'] ?></td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="position-relative me-3">
                                        <img src="<?= htmlspecialchars($rider['ImagePath']) ?>" 
                                             class="rounded-circle border border-2 border-warning" style="width: 40px; height: 40px; object-fit: cover;">
                                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-success" style="font-size: 0.6em;">
                                            <i class="fas fa-check"></i>
                                        </span>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-muted"><?= htmlspecialchars(trim($rider['first_name'].' '.$rider['last_name'])) ?></div>
                                        <small class="text-muted">Rider</small>
                                    </div>
                                </div>
                            </td>
                            <td class="text-center">
                                <div class="d-flex align-items-center justify-content-center">
                                    <i class="fas fa-envelope me-2 text-muted"></i>
                                    <span class="text-muted"><?= htmlspecialchars($rider['email']) ?></span>
                                </div>
                            </td>
                            <td class="text-center">
                                <div class="btn-group" role="group">
                                    <button class="btn btn-sm btn-outline-warning" onclick="editStaffDetails(<?= $rider['id'] ?>, 'rider')" title="Edit Staff">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-info" onclick="viewStaffDetails(<?= $rider['id'] ?>, 'rider')" title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if ($rider['is_active']): ?>
                                        <button class="btn btn-sm btn-outline-warning" onclick="toggleStaffStatus(<?= $rider['id'] ?>, 'rider', 0)" title="Deactivate Staff">
                                            <i class="fas fa-ban"></i>
                                        </button>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-outline-success" onclick="toggleStaffStatus(<?= $rider['id'] ?>, 'rider', 1)" title="Activate Staff">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php 
                            endwhile;
                        else:
                        ?>
                        <tr>
                            <td colspan="4" class="text-center py-4">
                                <div class="text-muted">
                                    <i class="fas fa-motorcycle fa-2x mb-2"></i>
                                    <div>No riders found</div>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<nav aria-label="Users pagination" class="mt-3">
  <ul class="pagination justify-content-center">
    <?php for ($p = 1; $p <= $totalPages; $p++): $active = ($p === $page) ? 'active' : ''; ?>
      <li class="page-item <?= $active ?>">
        <a class="page-link" href="?page=<?= $p ?>"><?= $p ?></a>
      </li>
    <?php endfor; ?>
  </ul>
  <style>
    .pagination .page-link{background:#212529;border-color:#343a40;color:#fff}
    .pagination .active .page-link{background:#0d6efd;border-color:#0d6efd}
    
    /* Center all text in table */
    .table td, .table th {
        text-align: center !important;
        vertical-align: middle !important;
    }
    
    /* Override Bootstrap text alignment classes */
    .table.text-start td, .table.text-start th,
    .table td.text-start, .table th.text-start {
        text-align: center !important;
    }
    
    /* Center specific elements that might override */
    .table .badge {
        display: inline-block;
        text-align: center;
    }
    
    .table .btn {
        margin: 0 auto;
        display: block;
    }
    
    /* Center all content in table cells */
    .table td > *, .table th > * {
        text-align: center;
    }
    
    /* Refresh button styling */
    #refreshBtn, #refreshAllStaffBtn {
        transition: all 0.3s ease;
    }
    
    #refreshBtn:hover, #refreshAllStaffBtn:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    }
    
    #refreshBtn:disabled, #refreshAllStaffBtn:disabled {
        opacity: 0.7;
        cursor: not-allowed;
    }
    
    /* Shake animation for validation */
    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
        20%, 40%, 60%, 80% { transform: translateX(5px); }
    }
    
    /* Custom validation styling */
    .form-control.is-invalid {
        border-color: #dc3545;
        box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
    }
    
    .form-control.is-valid {
        border-color: #28a745;
        box-shadow: 0 0 0 0.2rem rgba(40, 167, 69, 0.25);
    }
    
    .invalid-feedback {
        display: none;
        width: 100%;
        margin-top: 0.25rem;
        font-size: 0.875em;
        color: #dc3545;
    }
  </style>
  </nav>
<?php endif; ?>

<!-- Real-time Updates Script -->
<script>
// Update current time
function updateCurrentTime() {
    const now = new Date();
    const timeString = now.toLocaleTimeString('en-US', { 
        hour12: true, 
        hour: '2-digit', 
        minute: '2-digit' 
    });
    const timeElement = document.getElementById('currentTime');
    if (timeElement) {
        timeElement.textContent = timeString;
    }
}

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    // Update time immediately and every second
    updateCurrentTime();
    setInterval(updateCurrentTime, 1000);
});

// Refresh user table function
function refreshUserTable() {
    const refreshBtn = document.getElementById('refreshBtn');
    const originalText = refreshBtn.innerHTML;
    
    // Show loading state
    refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Refreshing...';
    refreshBtn.disabled = true;
    
    // Reload the page
    location.reload();
}

// Refresh all staff tables function
function refreshAllStaffTables() {
    const refreshBtn = document.getElementById('refreshAllStaffBtn');
    const originalText = refreshBtn.innerHTML;
    
    // Show loading state
    refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Refreshing All Staff...';
    refreshBtn.disabled = true;
    
    // Reload the page to refresh all staff tables
    setTimeout(() => location.reload(), 1000);
}

// Toggle staff active/inactive status
function toggleStaffStatus(staffId, staffType, status) {
    // Store the action details for the modal
    window.pendingStaffAction = {
        staffId: staffId,
        staffType: staffType,
        status: status
    };
    
    // Update modal content
    const actionText = status ? 'activate' : 'deactivate';
    const actionIcon = status ? 'check-circle' : 'ban';
    const actionColor = status ? 'success' : 'warning';
    const modalIcon = status ? '<i class="fas fa-check-circle text-success fa-3x mb-3"></i>' : '<i class="fas fa-ban text-warning fa-3x mb-3"></i>';
    
    document.getElementById('confirmModalTitle').textContent = status ? 'Activate Staff Member' : 'Deactivate Staff Member';
    document.getElementById('confirmModalIcon').innerHTML = modalIcon;
    document.getElementById('confirmModalMessage').innerHTML = `
        <p class="mb-2">Are you sure you want to <strong class="text-${actionColor}">${actionText}</strong> this staff member?</p>
        <small class="text-muted">${status ? 'This will restore their access to the system.' : 'This staff member will not be able to log in.'}</small>
    `;
    document.getElementById('confirmModalBtn').className = `btn btn-${actionColor}`;
    document.getElementById('confirmModalBtn').innerHTML = `<i class="fas fa-${actionIcon} me-2"></i>Yes, ${actionText.charAt(0).toUpperCase() + actionText.slice(1)}`;
    
    // Show the modal
    const modal = new bootstrap.Modal(document.getElementById('staffConfirmModal'));
    modal.show();
}

// Execute the confirmed action
function executeStaffAction() {
    if (!window.pendingStaffAction) return;
    
    const { staffId, staffType, status } = window.pendingStaffAction;
    
    // Hide modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('staffConfirmModal'));
    modal.hide();
    
    // Show loading state
    const confirmBtn = document.getElementById('confirmModalBtn');
    const originalHtml = confirmBtn.innerHTML;
    confirmBtn.disabled = true;
    confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing...';
    
    fetch('toggle_staff_status.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            staff_id: staffId,
            staff_type: staffType,
            is_active: status
        })
    })
    .then(response => response.json())
    .then(data => {
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = originalHtml;
        
        if (data.success) {
            // Show success notification
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-success alert-dismissible fade show position-fixed';
            alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px; box-shadow: 0 4px 12px rgba(0,0,0,0.3);';
            alertDiv.innerHTML = `
                <i class="fas fa-check-circle me-2"></i>
                <strong>Success!</strong> ${data.message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            document.body.appendChild(alertDiv);
            
            // Reload page after 1 second
            setTimeout(() => location.reload(), 1000);
        } else {
            // Show error notification
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-danger alert-dismissible fade show position-fixed';
            alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px; box-shadow: 0 4px 12px rgba(0,0,0,0.3);';
            alertDiv.innerHTML = `
                <i class="fas fa-exclamation-circle me-2"></i>
                <strong>Error!</strong> ${data.message || 'Failed to update staff status'}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            document.body.appendChild(alertDiv);
        }
        
        // Clear pending action
        window.pendingStaffAction = null;
    })
    .catch(error => {
        console.error('Error:', error);
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = originalHtml;
        
        // Show error notification
        const alertDiv = document.createElement('div');
        alertDiv.className = 'alert alert-danger alert-dismissible fade show position-fixed';
        alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px; box-shadow: 0 4px 12px rgba(0,0,0,0.3);';
        alertDiv.innerHTML = `
            <i class="fas fa-exclamation-circle me-2"></i>
            <strong>Error!</strong> An error occurred while updating staff status
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        document.body.appendChild(alertDiv);
        
        window.pendingStaffAction = null;
    });
}

// Load barangays for rider dropdown
function loadBarangaysForRider(selectedBarangayId) {
    fetch('get_barangays.php')
        .then(response => response.json())
        .then(barangays => {
            const barangaySelect = document.getElementById('editBarangay');
            if (barangaySelect) {
                // Clear existing options except the first one
                barangaySelect.innerHTML = '<option value="">Select Barangay</option>';
                
                // Add barangay options
                barangays.forEach(barangay => {
                    const option = document.createElement('option');
                    option.value = barangay.id;
                    option.textContent = barangay.barangay_name;
                    
                    // Select the current barangay if it matches
                    if (selectedBarangayId && barangay.id == selectedBarangayId) {
                        option.selected = true;
                    }
                    
                    barangaySelect.appendChild(option);
                });
            }
        })
        .catch(error => {
            console.error('Error loading barangays:', error);
            // Show error message in the dropdown
            const barangaySelect = document.getElementById('editBarangay');
            if (barangaySelect) {
                barangaySelect.innerHTML = '<option value="">Error loading barangays</option>';
            }
        });
}

// Edit staff details function
function editStaffDetails(staffId, staffType) {
    // Create modal for editing staff details
    const modalHtml = `
        <div class="modal fade" id="editStaffModal" tabindex="-1" aria-labelledby="editStaffModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editStaffModalLabel">
                            <i class="fas fa-edit me-2"></i>Edit Staff - ${staffType.charAt(0).toUpperCase() + staffType.slice(1)}
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="text-center mb-3">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p class="mt-2">Loading staff details for editing...</p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" id="saveStaffChanges">
                            <i class="fas fa-save me-1"></i>Save Changes
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Remove existing modal if any
    const existingModal = document.getElementById('editStaffModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Add modal to page
    const modalContainer = document.createElement('div');
    modalContainer.innerHTML = modalHtml;
    document.body.appendChild(modalContainer);
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('editStaffModal'));
    modal.show();
    
    // Fetch staff details for editing
    fetch(`get_staff_details.php?staff_id=${staffId}&staff_type=${staffType}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const modalBody = document.querySelector('#editStaffModal .modal-body');
                // Build form based on staff type with improved design
                let formFields = `
                    <form id="editStaffForm">
                        <!-- Personal Information Section -->
                        <div class="card mb-4">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0"><i class="fas fa-user me-2"></i>Personal Information</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label for="editFirstName" class="form-label fw-bold">First Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control form-control-lg" id="editFirstName" value="${data.staff.first_name}" required>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="editLastName" class="form-label fw-bold">Last Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control form-control-lg" id="editLastName" value="${data.staff.last_name}" required>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="editMiddleName" class="form-label fw-bold">Middle Name</label>
                                        <input type="text" class="form-control form-control-lg" id="editMiddleName" value="${data.staff.middle_name || ''}">
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label for="editEmail" class="form-label fw-bold">Email Address <span class="text-danger">*</span></label>
                                        <input type="email" class="form-control form-control-lg" id="editEmail" value="${data.staff.email}" required>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="editPhone" class="form-label fw-bold">Phone Number</label>
                                        <input type="tel" class="form-control form-control-lg" id="editPhone" value="${data.staff.phone_number || data.staff.contact_info || ''}" placeholder="+63 912 345 6789">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="editAddress" class="form-label fw-bold">Home Address</label>
                                        <textarea class="form-control form-control-lg" id="editAddress" rows="2" placeholder="Enter complete home address">${data.staff.home_address || ''}</textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Security Section -->
                        <div class="card mb-4">
                            <div class="card-header bg-warning text-dark">
                                <h6 class="mb-0"><i class="fas fa-lock me-2"></i>Security</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-12 mb-3">
                                        <label for="editPassword" class="form-label fw-bold">New Password</label>
                                        <div class="input-group">
                                            <input type="password" class="form-control form-control-lg" id="editPassword" placeholder="Enter new password">
                                            <button class="btn btn-outline-secondary" type="button" id="togglePasswordBtn">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                        <small class="text-muted"><i class="fas fa-info-circle me-1"></i>Leave empty to keep current password</small>
                                    </div>
                                </div>
                            </div>
                        </div>`;
                
                // Add specific fields based on staff type with improved design
                if (staffType === 'mechanic') {
                    formFields += `
                        <!-- Vehicle & Professional Information -->
                        <div class="card mb-4">
                            <div class="card-header bg-success text-white">
                                <h6 class="mb-0"><i class="fas fa-motorcycle me-2"></i>Vehicle & Professional Information</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="editMotorType" class="form-label fw-bold">Motor Type</label>
                                        <input type="text" class="form-control form-control-lg" id="editMotorType" value="${data.staff.MotorType || ''}" placeholder="e.g., Honda XRM, Yamaha Mio">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="editPlateNumber" class="form-label fw-bold">Plate Number</label>
                                        <input type="text" class="form-control form-control-lg" id="editPlateNumber" value="${data.staff.PlateNumber || ''}" placeholder="e.g., ABC-1234">
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label for="editSpecialization" class="form-label fw-bold">Specialization</label>
                                    <select class="form-select form-select-lg" id="editSpecialization">
                                        <option value="">Select specialization</option>
                                        <option value="General Repair" ${data.staff.specialization === 'General Repair' ? 'selected' : ''}>General Repair</option>
                                        <option value="Engine Specialist" ${data.staff.specialization === 'Engine Specialist' ? 'selected' : ''}>Engine Specialist</option>
                                        <option value="Electrical Systems" ${data.staff.specialization === 'Electrical Systems' ? 'selected' : ''}>Electrical Systems</option>
                                        <option value="Brake Systems" ${data.staff.specialization === 'Brake Systems' ? 'selected' : ''}>Brake Systems</option>
                                        <option value="Transmission" ${data.staff.specialization === 'Transmission' ? 'selected' : ''}>Transmission</option>
                                        <option value="Other" ${data.staff.specialization && !['General Repair', 'Engine Specialist', 'Electrical Systems', 'Brake Systems', 'Transmission'].includes(data.staff.specialization) ? 'selected' : ''}>Other</option>
                                    </select>
                                    <input type="text" class="form-control form-control-lg mt-2" id="editSpecializationOther" placeholder="Specify other specialization" style="display: none;">
                                </div>
                            </div>
                        </div>`;
                } else if (staffType === 'rider') {
                    formFields += `
                        <!-- Vehicle & Location Information -->
                        <div class="card mb-4">
                            <div class="card-header bg-warning text-dark">
                                <h6 class="mb-0"><i class="fas fa-motorcycle me-2"></i>Vehicle & Location Information</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="editMotorType" class="form-label fw-bold">Motor Type</label>
                                        <input type="text" class="form-control form-control-lg" id="editMotorType" value="${data.staff.MotorType || ''}" placeholder="e.g., Honda XRM, Yamaha Mio">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="editPlateNumber" class="form-label fw-bold">Plate Number</label>
                                        <input type="text" class="form-control form-control-lg" id="editPlateNumber" value="${data.staff.PlateNumber || ''}" placeholder="e.g., ABC-1234">
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="editBarangay" class="form-label fw-bold">Barangay</label>
                                        <select class="form-select form-select-lg" id="editBarangay" required>
                                            <option value="">Select Barangay</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="editPurok" class="form-label fw-bold">Purok</label>
                                        <input type="text" class="form-control form-control-lg" id="editPurok" value="${data.staff.purok || ''}" placeholder="e.g., P-1, P-2, P-3">
                                    </div>
                                </div>
                            </div>
                        </div>`;
                }
                
                formFields += `
                        <!-- Profile Image Section -->
                        <div class="card mb-4">
                            <div class="card-header bg-secondary text-white">
                                <h6 class="mb-0"><i class="fas fa-camera me-2"></i>Profile Image</h6>
                            </div>
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-md-4 text-center mb-3">
                                        <label class="form-label fw-bold">Current Image</label>
                                        <div class="position-relative d-inline-block">
                                            <img src="${data.staff.profile_image || 'img/jandi.jpg'}" 
                                                 class="img-fluid rounded-circle border border-3 border-primary" 
                                                 style="width: 120px; height: 120px; object-fit: cover;">
                                            <div class="position-absolute top-0 end-0">
                                                <span class="badge bg-success rounded-circle p-2">
                                                    <i class="fas fa-check"></i>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-8">
                                        <label for="editProfileImage" class="form-label fw-bold">Upload New Image</label>
                                        <input type="file" class="form-control form-control-lg" id="editProfileImage" accept="image/*">
                                        <small class="text-muted"><i class="fas fa-info-circle me-1"></i>Leave empty to keep current image. Supported formats: JPG, PNG, GIF</small>
                                        <div class="mt-2">
                                            <div class="progress" style="display: none;" id="imageProgress">
                                                <div class="progress-bar" role="progressbar" style="width: 0%"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                `;
                
                modalBody.innerHTML = formFields;
                
                // Load barangays for riders
                if (staffType === 'rider') {
                    loadBarangaysForRider(data.staff.barangay_id);
                }
                
                // Add enhanced functionality
                // Password toggle functionality
                const togglePasswordBtn = document.getElementById('togglePasswordBtn');
                if (togglePasswordBtn) {
                    togglePasswordBtn.addEventListener('click', function() {
                        const passwordField = document.getElementById('editPassword');
                        const icon = this.querySelector('i');
                        
                        if (passwordField.type === 'password') {
                            passwordField.type = 'text';
                            icon.classList.remove('fa-eye');
                            icon.classList.add('fa-eye-slash');
                        } else {
                            passwordField.type = 'password';
                            icon.classList.remove('fa-eye-slash');
                            icon.classList.add('fa-eye');
                        }
                    });
                }
                
                // Specialization dropdown functionality for mechanics
                const specializationSelect = document.getElementById('editSpecialization');
                const specializationOther = document.getElementById('editSpecializationOther');
                if (specializationSelect && specializationOther) {
                    specializationSelect.addEventListener('change', function() {
                        if (this.value === 'Other') {
                            specializationOther.style.display = 'block';
                            specializationOther.required = true;
                        } else {
                            specializationOther.style.display = 'none';
                            specializationOther.required = false;
                            specializationOther.value = '';
                        }
                    });
                    
                    // Initialize on load
                    if (specializationSelect.value === 'Other') {
                        specializationOther.style.display = 'block';
                        specializationOther.required = true;
                    }
                }
                
                // Image preview functionality
                const imageInput = document.getElementById('editProfileImage');
                if (imageInput) {
                    imageInput.addEventListener('change', function(e) {
                        const file = e.target.files[0];
                        if (file) {
                            const reader = new FileReader();
                            reader.onload = function(e) {
                                const currentImage = document.querySelector('.position-relative img');
                                if (currentImage) {
                                    currentImage.src = e.target.result;
                                }
                            };
                            reader.readAsDataURL(file);
                        }
                    });
                }
                
                // Add save functionality
                document.getElementById('saveStaffChanges').addEventListener('click', function() {
                    const formData = new FormData();
                    formData.append('staff_id', staffId);
                    formData.append('staff_type', staffType);
                    formData.append('first_name', document.getElementById('editFirstName').value);
                    formData.append('last_name', document.getElementById('editLastName').value);
                    formData.append('middle_name', document.getElementById('editMiddleName').value);
                    formData.append('email', document.getElementById('editEmail').value);
                    formData.append('password', document.getElementById('editPassword').value);
                    formData.append('phone_number', document.getElementById('editPhone').value);
                    formData.append('home_address', document.getElementById('editAddress').value);
                    
                    // Add staff-specific fields
                    if (staffType === 'mechanic') {
                        formData.append('motor_type', document.getElementById('editMotorType').value);
                        formData.append('plate_number', document.getElementById('editPlateNumber').value);
                        
                        // Handle specialization
                        const specialization = document.getElementById('editSpecialization').value;
                        if (specialization === 'Other') {
                            const otherSpecialization = document.getElementById('editSpecializationOther').value;
                            formData.append('specialization', otherSpecialization);
                        } else {
                            formData.append('specialization', specialization);
                        }
                    } else if (staffType === 'rider') {
                        formData.append('motor_type', document.getElementById('editMotorType').value);
                        formData.append('plate_number', document.getElementById('editPlateNumber').value);
                        formData.append('barangay_id', document.getElementById('editBarangay').value);
                        formData.append('purok', document.getElementById('editPurok').value);
                    }
                    
                    const profileImage = document.getElementById('editProfileImage').files[0];
                    if (profileImage) {
                        formData.append('profile_image', profileImage);
                    }
                    
                    // Show loading state
                    this.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Saving...';
                    this.disabled = true;
                    
                    fetch('update_staff_details.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(result => {
                        if (result.success) {
                            showNotification({
                                type: 'success',
                                title: 'Staff Updated',
                                message: result.message || 'Staff details updated successfully'
                            });
                            modal.hide();
                            setTimeout(() => location.reload(), 1500);
                        } else {
                            showNotification({
                                type: 'danger',
                                title: 'Update Failed',
                                message: result.message || 'Failed to update staff details'
                            });
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showNotification({
                            type: 'danger',
                            title: 'Network Error',
                            message: 'Failed to update staff details'
                        });
                    })
                    .finally(() => {
                        this.innerHTML = '<i class="fas fa-save me-1"></i>Save Changes';
                        this.disabled = false;
                    });
                });
            } else {
                const modalBody = document.querySelector('#editStaffModal .modal-body');
                modalBody.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Error loading staff details: ${data.message || 'Unknown error'}
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            const modalBody = document.querySelector('#editStaffModal .modal-body');
            modalBody.innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Network error: Failed to load staff details
                </div>
            `;
        });
    
    // Clean up modal when hidden
    document.getElementById('editStaffModal').addEventListener('hidden.bs.modal', function() {
        this.remove();
    });
}

// View staff details function
function viewStaffDetails(staffId, staffType) {
    // Create modal for staff details
    const modalHtml = `
        <div class="modal fade" id="staffDetailsModal" tabindex="-1" aria-labelledby="staffDetailsModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="staffDetailsModalLabel">
                            <i class="fas fa-user me-2"></i>Staff Profile - ${staffType.charAt(0).toUpperCase() + staffType.slice(1)}
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-0">
                        <div class="text-center mb-3 p-4">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p class="mt-2">Loading staff profile...</p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>Close
                        </button>
                        <button type="button" class="btn btn-primary" id="editFromViewBtn" onclick="editStaffDetails(${staffId}, '${staffType}')">
                            <i class="fas fa-edit me-1"></i>Edit Profile
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Remove existing modal if any
    const existingModal = document.getElementById('staffDetailsModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Add modal to page
    const modalContainer = document.createElement('div');
    modalContainer.innerHTML = modalHtml;
    document.body.appendChild(modalContainer);
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('staffDetailsModal'));
    modal.show();
    
    // Fetch staff details
    fetch(`get_staff_details.php?staff_id=${staffId}&staff_type=${staffType}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const modalBody = document.querySelector('#staffDetailsModal .modal-body');
                modalBody.innerHTML = `
                    <div class="container-fluid p-4">
                        <!-- Profile Header -->
                        <div class="row mb-4">
                            <div class="col-12">
                                <div class="card border-0 shadow-sm">
                                    <div class="card-body text-center py-4">
                                        <div class="mb-3">
                                            <img src="${data.staff.ImagePath || data.staff.profile_image || 'img/jandi.jpg'}" 
                                                 class="img-fluid rounded-circle border border-4 border-${staffType === 'cashier' ? 'primary' : staffType === 'mechanic' ? 'success' : 'warning'}" 
                                                 style="width: 120px; height: 120px; object-fit: cover;">
                                        </div>
                                        <h4 class="mb-2">${data.staff.first_name} ${data.staff.middle_name ? data.staff.middle_name + ' ' : ''}${data.staff.last_name}</h4>
                                        <span class="badge bg-${staffType === 'cashier' ? 'primary' : staffType === 'mechanic' ? 'success' : 'warning'} fs-6 px-3 py-2">
                                            <i class="fas fa-${staffType === 'cashier' ? 'cash-register' : staffType === 'mechanic' ? 'tools' : 'motorcycle'} me-1"></i>
                                            ${staffType.charAt(0).toUpperCase() + staffType.slice(1)}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Profile Details -->
                        <div class="row">
                            <!-- Personal Information -->
                            <div class="col-md-${staffType === 'cashier' ? '12' : '6'} mb-4">
                                <div class="card h-100">
                                    <div class="card-header bg-${staffType === 'cashier' ? 'primary' : staffType === 'mechanic' ? 'success' : 'warning'} text-white">
                                        <h6 class="mb-0"><i class="fas fa-user me-2"></i>Personal Information</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row mb-3">
                                            <div class="col-5"><strong>Email:</strong></div>
                                            <div class="col-7">
                                                <i class="fas fa-envelope me-2 text-muted"></i>
                                                ${data.staff.email}
                                            </div>
                                        </div>
                                        <div class="row mb-3">
                                            <div class="col-5"><strong>Phone:</strong></div>
                                            <div class="col-7">
                                                <i class="fas fa-phone me-2 text-muted"></i>
                                                ${data.staff.phone_number || data.staff.contact_info || 'Not provided'}
                                            </div>
                                        </div>
                                        <div class="row mb-3">
                                            <div class="col-5"><strong>Address:</strong></div>
                                            <div class="col-7">
                                                <i class="fas fa-map-marker-alt me-2 text-muted"></i>
                                                ${staffType === 'rider' ? 
                                                    (data.staff.barangay_name && data.staff.purok ? 
                                                        `${data.staff.barangay_name}, ${data.staff.purok}` : 
                                                        data.staff.barangay_name || data.staff.purok || 'Not provided') :
                                                    (data.staff.home_address || 'Not provided')
                                                }
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Professional Information (for mechanics and riders only) -->
                            ${staffType !== 'cashier' ? `
                            <div class="col-md-6 mb-4">
                                <div class="card h-100">
                                    <div class="card-header bg-info text-white">
                                        <h6 class="mb-0"><i class="fas fa-briefcase me-2"></i>Professional Information</h6>
                                    </div>
                                    <div class="card-body">
                                        ${staffType === 'mechanic' ? `
                                        <div class="row mb-3">
                                            <div class="col-5"><strong>Specialization:</strong></div>
                                            <div class="col-7">
                                                <i class="fas fa-wrench me-2 text-muted"></i>
                                                ${data.staff.specialization || 'Not specified'}
                                            </div>
                                        </div>
                                        ` : ''}
                                        <div class="row mb-3">
                                            <div class="col-5"><strong>Motor Type:</strong></div>
                                            <div class="col-7">
                                                <i class="fas fa-motorcycle me-2 text-muted"></i>
                                                ${data.staff.MotorType || data.staff.motor_type || 'Not specified'}
                                            </div>
                                        </div>
                                        <div class="row mb-3">
                                            <div class="col-5"><strong>Plate Number:</strong></div>
                                            <div class="col-7">
                                                <i class="fas fa-id-card me-2 text-muted"></i>
                                                ${data.staff.PlateNumber || data.staff.plate_number || 'Not specified'}
                                            </div>
                                        </div>
                                        ${staffType === 'rider' ? `
                                        <div class="row mb-3">
                                            <div class="col-5"><strong>Barangay:</strong></div>
                                            <div class="col-7">
                                                <i class="fas fa-map me-2 text-muted"></i>
                                                ${data.staff.barangay_name || 'Not specified'}
                                            </div>
                                        </div>
                                        <div class="row mb-3">
                                            <div class="col-5"><strong>Purok:</strong></div>
                                            <div class="col-7">
                                                <i class="fas fa-home me-2 text-muted"></i>
                                                ${data.staff.purok || 'Not specified'}
                                            </div>
                                        </div>
                                        ` : ''}
                                    </div>
                                </div>
                            </div>
                            ` : ''}
                        </div>

                    </div>
                `;
            } else {
                const modalBody = document.querySelector('#staffDetailsModal .modal-body');
                modalBody.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Error loading staff details: ${data.message || 'Unknown error'}
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            const modalBody = document.querySelector('#staffDetailsModal .modal-body');
            modalBody.innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Network error: Failed to load staff details
                </div>
            `;
        });
    
    // Clean up modal when hidden
    document.getElementById('staffDetailsModal').addEventListener('hidden.bs.modal', function() {
        this.remove();
    });
}

// Show notification
function showNotification(notification) {
    const notificationElement = document.createElement('div');
    notificationElement.className = `alert alert-${notification.type} alert-dismissible fade show position-fixed`;
    notificationElement.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
    notificationElement.innerHTML = `
        <strong>${notification.title}</strong> ${notification.message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    document.body.appendChild(notificationElement);
    
    // Auto-remove after 5 seconds
    setTimeout(() => {
        if (notificationElement.parentNode) {
            notificationElement.remove();
        }
    }, 5000);
}
</script>

<!-- Modal for Regular Suspension Confirmation -->
<div class="modal fade" id="suspendModal" tabindex="-1" aria-labelledby="suspendModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="suspendModalLabel">
                    <i class="fas fa-ban me-2"></i>Suspend User
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <h6 class="alert-heading">⚠️ Confirm Suspension</h6>
                    <p class="mb-0">Are you sure you want to suspend this user's COD for 7 days?</p>
                </div>
                <div class="text-center">
                    <i class="fas fa-user-times fa-3x text-danger mb-3"></i>
                    <p class="text-muted">This action will prevent the user from using Cash on Delivery for 7 days.</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-danger" id="confirmSuspend">
                    <i class="fas fa-ban me-1"></i>Suspend User
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal for Lift Suspension Confirmation -->
<div class="modal fade" id="liftSuspensionModal" tabindex="-1" aria-labelledby="liftSuspensionModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="liftSuspensionModalLabel">
                    <i class="fas fa-unlock me-2"></i>Lift Suspension
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <h6 class="alert-heading">ℹ️ Lift COD Suspension</h6>
                    <p class="mb-2">Are you sure you want to lift the COD suspension for this user?</p>
                    <p class="mb-0"><strong>This will also reset their failed attempts counter to 0.</strong></p>
                </div>
                <div class="text-center">
                    <i class="fas fa-user-check fa-3x text-success mb-3"></i>
                    <p class="text-muted">The user will be able to use Cash on Delivery again immediately.</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-success" id="confirmLiftSuspension">
                    <i class="fas fa-unlock me-1"></i>Lift Suspension
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal for Clean Record Suspension Confirmation -->
<div class="modal fade" id="cleanRecordModal" tabindex="-1" aria-labelledby="cleanRecordModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title text-dark" id="cleanRecordModalLabel">
                    <i class="fas fa-exclamation-triangle me-2"></i>Clean Record Suspension
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <h6 class="alert-heading">⚠️ Warning: Suspending User with Clean Record</h6>
                    <p class="mb-2">You are about to suspend <strong id="cleanRecordUserName"></strong> who has:</p>
                    <ul class="mb-0">
                        <li><strong>0 COD Failed Attempts</strong> - Clean payment record</li>
                        <li>No previous suspension history</li>
                    </ul>
                </div>
                <div class="alert alert-info">
                    <h6 class="alert-heading">Please confirm the reason for suspension:</h6>
                    <p class="mb-0">This action should only be taken for personal/administrative reasons, not due to payment failures.</p>
                </div>
                <div class="form-group">
                    <label for="suspensionReason" class="form-label">Reason for Suspension <span class="text-danger">*</span>:</label>
                    <textarea class="form-control" id="suspensionReason" rows="3" placeholder="Enter reason for suspending this user with clean record..." required></textarea>
                    <div class="invalid-feedback" id="reasonError">
                        Please provide a reason for suspending this user with a clean record.
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmCleanRecordSuspension">
                    <i class="fas fa-ban me-1"></i>Suspend User
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal for Final Clean Record Suspension Confirmation -->
<div class="modal fade" id="finalCleanRecordModal" tabindex="-1" aria-labelledby="finalCleanRecordModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="finalCleanRecordModalLabel">
                    <i class="fas fa-exclamation-circle me-2"></i>Final Confirmation
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger">
                    <h6 class="alert-heading">🚨 Final Warning</h6>
                    <p class="mb-2">You are about to suspend a user with a <strong>clean record</strong>:</p>
                    <ul class="mb-3">
                        <li><strong>0 COD Failed Attempts</strong></li>
                        <li><strong>Clean payment history</strong></li>
                    </ul>
                </div>
                <div class="text-center mb-3">
                    <i class="fas fa-user-times fa-3x text-danger mb-3"></i>
                    <p class="text-muted">This action should only be taken for administrative reasons.</p>
                </div>
                <div class="alert alert-light">
                    <h6 class="alert-heading">Reason:</h6>
                    <p id="finalReasonText" class="mb-0 fst-italic">No reason provided</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-danger" id="finalConfirmCleanRecordSuspension">
                    <i class="fas fa-ban me-1"></i>Yes, Suspend User
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal for Admin Password Verification -->
<div class="modal fade" id="adminPasswordModal" tabindex="-1" aria-labelledby="adminPasswordModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title" id="adminPasswordModalLabel">
                    <i class="fas fa-shield-alt me-2"></i>Admin Password Required
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <h6 class="alert-heading">🔒 Security Verification Required</h6>
                    <p class="mb-2">To suspend a user with a clean record, you must verify your admin password:</p>
                </div>
                <div class="text-center mb-3">
                    <i class="fas fa-lock fa-3x text-warning mb-3"></i>
                    <p class="text-muted">This extra security measure protects users with clean records.</p>
                </div>
                <div class="form-group">
                    <label for="adminPassword" class="form-label">Enter Your Admin Password <span class="text-danger">*</span>:</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="adminPassword" placeholder="Enter your admin password..." required>
                        <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                            <i class="fas fa-eye" id="passwordToggleIcon"></i>
                        </button>
                    </div>
                    <div class="invalid-feedback" id="passwordError">
                        Please enter your admin password to proceed.
                    </div>
                    <div class="text-muted mt-2">
                        <small><i class="fas fa-info-circle me-1"></i>This action will be logged for security purposes.</small>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-warning" id="verifyAdminPassword">
                    <i class="fas fa-check me-1"></i>Verify & Suspend
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal for User Details -->
<div class="modal fade" id="userDetailsModal" tabindex="-1" aria-labelledby="userDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-dark w-100 text-center" id="userDetailsModalLabel">User Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <img id="modalImage" src="img/carlo HAHAHA.jpg" alt="User Image" class="img-fluid" style="width: 250px; height: 250px;">
                </div>
                <p><strong>Name:</strong> <span id="modalName">Carlo Estorque</span></p>
                <p><strong>Email:</strong> <span id="modalEmail">C.Estorque@example.com</span></p>
                <p><strong>Address:</strong> <span id="modalAddress">Brgy Sinayawan P-4, Estorque Residents</span></p>
                <p><strong>Contact Info:</strong> <span id="modalContact">(+63)-934-5678-239</span></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
      <!--Footer Start-->
      <div class="container-fluid pt-4 px-4">
        <div class="bg-secondary rounded-top p-4">
            <div class="row">
                <div class="col-12 col-sm-6 text-center text-sm-start">
                    &copy; <a href="#">Cj PowerHouse</a>, All Right Reserved.
                </div> 
                <div class="col-12 col-sm-6 text-center text-sm-end">
                    Design By: <a href="">Team Jandi</a>
                </div>
            </div>
        </div>
    </div>
     </div>
     <!--Footer End-->

     <!--Content End-->
 </div>







<!--javascript Libraries-->
<script src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="lib/chart/Chart.min.js"></script>
<script src="lib/easing/easing.min.js"></script>
<script src="lib/waypoints/waypoints.min.js"></script>
<script src="lib/owlcarousel/owl.carousel.min.js"></script>
<script src="lib/tempusdominus/js/moment.min.js"></script>
<script src="lib/tempusdominus/js/moment-timezone.min.js"></script>
<script src="lib/tempusdominus/js/tempusdominus-bootstrap-4.min.js"></script>

 <!-- Template Javascript -->
 <script src="js/main.js?v=<?= time() ?>">
 </script>
 <script>
 // Hide spinner when page loads
 window.onload = function() {
     document.getElementById("spinner").classList.remove("show");
 };
 
 // Hide spinner after 10 seconds as fallback
 setTimeout(function() {
     document.getElementById("spinner").classList.remove("show");
 }, 10000);
 
 // Add attention animation style for appeal button
(function(){
    var s = document.createElement('style');
    s.innerHTML = '.appeal-attn{position:relative;animation:pulseAppeal 1.6s infinite}@keyframes pulseAppeal{0%{box-shadow:0 0 0 0 rgba(13,110,253,.6)}70%{box-shadow:0 0 0 12px rgba(13,110,253,0)}100%{box-shadow:0 0 0 0 rgba(13,110,253,0)}}';
    document.head.appendChild(s);
})();

 function suspendUser(userId){
   showSuspendModal(userId);
 }
 function liftSuspension(userId){
   showLiftSuspensionModal(userId);
 }

 // View user details (read-only)
 function viewUserDetails(userId) {
     // Create modal for user details
     const modalHtml = `
         <div class="modal fade" id="userDetailsModal" tabindex="-1" aria-labelledby="userDetailsModalLabel" aria-hidden="true">
             <div class="modal-dialog modal-xl">
                 <div class="modal-content">
                     <div class="modal-header bg-primary text-white">
                         <h5 class="modal-title" id="userDetailsModalLabel">
                             <i class="fas fa-user me-2"></i>User Profile
                         </h5>
                         <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                     </div>
                     <div class="modal-body p-0">
                         <div class="text-center mb-3 p-4">
                             <div class="spinner-border text-primary" role="status">
                                 <span class="visually-hidden">Loading...</span>
                             </div>
                             <p class="mt-2">Loading user profile...</p>
                         </div>
                     </div>
                     <div class="modal-footer">
                         <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                             <i class="fas fa-times me-1"></i>Close
                         </button>
                     </div>
                 </div>
             </div>
         </div>
     `;
     
     // Remove existing modal if any
     const existingModal = document.getElementById('userDetailsModal');
     if (existingModal) {
         existingModal.remove();
     }
     
     // Add modal to page
     const modalContainer = document.createElement('div');
     modalContainer.innerHTML = modalHtml;
     document.body.appendChild(modalContainer);
     
     // Show modal
     const modal = new bootstrap.Modal(document.getElementById('userDetailsModal'));
     modal.show();
     
     // Fetch user details
     fetch(`get_user_details.php?user_id=${userId}`)
         .then(response => response.json())
         .then(data => {
             if (data.success) {
                 const modalBody = document.querySelector('#userDetailsModal .modal-body');
                 modalBody.innerHTML = `
                     <div class="container-fluid p-4">
                         <!-- Profile Header -->
                         <div class="row mb-4">
                             <div class="col-12">
                                 <div class="card border-0 shadow-sm">
                                     <div class="card-body text-center py-4">
                                         <div class="mb-3">
                                             <img src="${data.user.ImagePath || 'img/jandi.jpg'}" 
                                                  class="img-fluid rounded-circle border border-4 border-primary" 
                                                  style="width: 120px; height: 120px; object-fit: cover;">
                                         </div>
                                         <h4 class="mb-2">${data.user.first_name} ${data.user.middle_name ? data.user.middle_name + ' ' : ''}${data.user.last_name}</h4>
                                         <span class="badge bg-primary fs-6 px-3 py-2">
                                             <i class="fas fa-user me-1"></i>Customer
                                         </span>
                                     </div>
                                 </div>
                             </div>
                         </div>

                         <!-- Profile Details -->
                         <div class="row">
                             <!-- Personal Information -->
                             <div class="col-md-6 mb-4">
                                 <div class="card h-100">
                                     <div class="card-header bg-primary text-white">
                                         <h6 class="mb-0"><i class="fas fa-user me-2"></i>Personal Information</h6>
                                     </div>
                                     <div class="card-body">
                                         <div class="row mb-3">
                                             <div class="col-5"><strong>Email:</strong></div>
                                             <div class="col-7">
                                                 <i class="fas fa-envelope me-2 text-muted"></i>
                                                 ${data.user.email}
                                             </div>
                                         </div>
                                         <div class="row mb-3">
                                             <div class="col-5"><strong>Phone:</strong></div>
                                             <div class="col-7">
                                                 <i class="fas fa-phone me-2 text-muted"></i>
                                                 ${data.user.phone_number || 'Not provided'}
                                             </div>
                                         </div>
                                         <div class="row mb-3">
                                             <div class="col-5"><strong>Barangay:</strong></div>
                                             <div class="col-7">
                                                 <i class="fas fa-map me-2 text-muted"></i>
                                                 ${data.user.barangay_name || 'Not specified'}
                                             </div>
                                         </div>
                                         <div class="row mb-3">
                                             <div class="col-5"><strong>Purok:</strong></div>
                                             <div class="col-7">
                                                 <i class="fas fa-home me-2 text-muted"></i>
                                                 ${data.user.purok || 'Not specified'}
                                             </div>
                                         </div>
                                     </div>
                                 </div>
                             </div>

                             <!-- Account Information -->
                             <div class="col-md-6 mb-4">
                                 <div class="card h-100">
                                     <div class="card-header bg-info text-white">
                                         <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Account Information</h6>
                                     </div>
                                     <div class="card-body">
                                         <div class="row mb-3">
                                             <div class="col-5"><strong>Email Verified:</strong></div>
                                             <div class="col-7">
                                                 <i class="fas fa-${data.user.email_verified ? 'check-circle text-success' : 'times-circle text-danger'} me-2"></i>
                                                 ${data.user.email_verified ? 'Yes' : 'No'}
                                             </div>
                                         </div>
                                         <div class="row mb-3">
                                             <div class="col-5"><strong>Last Login:</strong></div>
                                             <div class="col-7">
                                                 <i class="fas fa-clock me-2 text-muted"></i>
                                                 ${data.user.last_login_at ? new Date(data.user.last_login_at).toLocaleString() : 'Never'}
                                             </div>
                                         </div>
                                         <div class="row mb-3">
                                             <div class="col-5"><strong>Account Created:</strong></div>
                                             <div class="col-7">
                                                 <i class="fas fa-calendar me-2 text-muted"></i>
                                                 ${data.user.created_at ? new Date(data.user.created_at).toLocaleString() : 'Unknown'}
                                             </div>
                                         </div>
                                         <div class="row mb-3">
                                             <div class="col-5"><strong>COD Status:</strong></div>
                                             <div class="col-7">
                                                 <i class="fas fa-${data.user.cod_suspended ? 'ban text-danger' : 'check-circle text-success'} me-2"></i>
                                                 ${data.user.cod_suspended ? 'Suspended' : 'Active'}
                                             </div>
                                         </div>
                                         ${data.user.cod_suspended ? `
                                         <div class="row mb-3">
                                             <div class="col-5"><strong>Suspension Until:</strong></div>
                                             <div class="col-7">
                                                 <i class="fas fa-calendar-times me-2 text-warning"></i>
                                                 ${data.user.cod_suspended_until ? new Date(data.user.cod_suspended_until).toLocaleString() : 'Unknown'}
                                             </div>
                                         </div>
                                         ` : ''}
                                         <div class="row mb-3">
                                             <div class="col-5"><strong>Failed COD Attempts:</strong></div>
                                             <div class="col-7">
                                                 <i class="fas fa-exclamation-triangle me-2 text-${data.user.cod_failed_attempts > 0 ? 'warning' : 'success'}"></i>
                                                 ${data.user.cod_failed_attempts || 0}
                                             </div>
                                         </div>
                                     </div>
                                 </div>
                             </div>
                         </div>
                     </div>
                 `;
             } else {
                 const modalBody = document.querySelector('#userDetailsModal .modal-body');
                 modalBody.innerHTML = `
                     <div class="alert alert-danger">
                         <i class="fas fa-exclamation-triangle me-2"></i>
                         Error loading user details: ${data.message || 'Unknown error'}
                     </div>
                 `;
             }
         })
         .catch(error => {
             console.error('Error:', error);
             const modalBody = document.querySelector('#userDetailsModal .modal-body');
             modalBody.innerHTML = `
                 <div class="alert alert-danger">
                     <i class="fas fa-exclamation-triangle me-2"></i>
                     Network error: Failed to load user details
                 </div>
             `;
         });
     
     // Clean up modal when hidden
     document.getElementById('userDetailsModal').addEventListener('hidden.bs.modal', function() {
         this.remove();
     });
 }

// Global variables to store current user IDs
let currentCleanRecordUserId = null;
let currentSuspendUserId = null;
let currentLiftSuspensionUserId = null;

// Show modal for regular suspension
function showSuspendModal(userId) {
    currentSuspendUserId = userId;
    const modal = new bootstrap.Modal(document.getElementById('suspendModal'));
    modal.show();
}

// Show modal for lift suspension
function showLiftSuspensionModal(userId) {
    currentLiftSuspensionUserId = userId;
    const modal = new bootstrap.Modal(document.getElementById('liftSuspensionModal'));
    modal.show();
}

// Show modal for suspending users with clean records
function showCleanRecordModal(userId, userName) {
    currentCleanRecordUserId = userId;
    document.getElementById('cleanRecordUserName').textContent = userName;
    const reasonField = document.getElementById('suspensionReason');
    reasonField.value = '';
    
    // Reset validation state
    reasonField.classList.remove('is-invalid', 'is-valid');
    document.getElementById('reasonError').style.display = 'none';
    
    // Add real-time validation
    reasonField.addEventListener('input', function() {
        const reason = this.value.trim();
        if (reason) {
            this.classList.remove('is-invalid');
            this.classList.add('is-valid');
            document.getElementById('reasonError').style.display = 'none';
        } else {
            this.classList.remove('is-valid');
            this.classList.remove('is-invalid');
            document.getElementById('reasonError').style.display = 'none';
        }
    });
    
    const modal = new bootstrap.Modal(document.getElementById('cleanRecordModal'));
    modal.show();
}

// Function to verify admin password and proceed with suspension
function verifyPasswordAndSuspend(password) {
    // Show loading state
    const verifyBtn = document.getElementById('verifyAdminPassword');
    const originalText = verifyBtn.innerHTML;
    verifyBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Verifying...';
    verifyBtn.disabled = true;
    
    // Send password verification request
    fetch('verify_admin_password.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'password=' + encodeURIComponent(password)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Password is correct, proceed with suspension
            const passwordModal = bootstrap.Modal.getInstance(document.getElementById('adminPasswordModal'));
            passwordModal.hide();
            
            // Proceed with suspension
            fetch('toggle_cod_suspension.php', {
                method: 'POST', 
                headers: {'Content-Type': 'application/x-www-form-urlencoded'}, 
                body: 'action=suspend&user_id=' + encodeURIComponent(currentCleanRecordUserId)
            })
            .then(r => {
                if (!r.ok) {
                    throw new Error('Network response was not ok: ' + r.status);
                }
                return r.json();
            })
            .then(d => { 
                if (d.success) { 
                    showNotification({
                        type: 'success',
                        title: 'Clean Record Suspension Successful',
                        message: d.message || 'User with clean record has been suspended successfully'
                    });
                    setTimeout(() => location.reload(), 2000);
                } else { 
                    showNotification({
                        type: 'danger',
                        title: 'Clean Record Suspension Failed',
                        message: d.message || 'Failed to suspend user with clean record'
                    });
                } 
            })
            .catch(error => {
                console.error('Clean record suspension error:', error);
                showNotification({
                    type: 'danger',
                    title: 'Network Error',
                    message: 'Failed to connect to server. Please try again.'
                });
            });
        } else {
            // Password is incorrect
            const passwordField = document.getElementById('adminPassword');
            passwordField.classList.add('is-invalid');
            passwordField.value = '';
            passwordField.focus();
            
            document.getElementById('passwordError').textContent = data.message || 'Incorrect password. Please try again.';
            document.getElementById('passwordError').style.display = 'block';
        }
    })
    .catch(error => {
        console.error('Error verifying password:', error);
        alert('Error verifying password. Please try again.');
    })
    .finally(() => {
        // Reset button state
        verifyBtn.innerHTML = originalText;
        verifyBtn.disabled = false;
    });
}

// Handle modal confirmations
document.addEventListener('DOMContentLoaded', function() {
    // Regular suspension confirmation
    const confirmSuspendBtn = document.getElementById('confirmSuspend');
    if (confirmSuspendBtn) {
        confirmSuspendBtn.addEventListener('click', function() {
            if (currentSuspendUserId) {
                // Close modal first
                const modal = bootstrap.Modal.getInstance(document.getElementById('suspendModal'));
                modal.hide();
                
                // Proceed with suspension
                fetch('toggle_cod_suspension.php', {
                    method: 'POST', 
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'}, 
                    body: 'action=suspend&user_id=' + encodeURIComponent(currentSuspendUserId)
                })
                .then(r => {
                    if (!r.ok) {
                        throw new Error('Network response was not ok: ' + r.status);
                    }
                    return r.json();
                })
                .then(d => { 
                    if (d.success) { 
                        showNotification({
                            type: 'success',
                            title: 'Suspension Successful',
                            message: d.message || 'User has been suspended successfully'
                        });
                        // After suspension, refresh user notifications so COD-fail warning disappears
                        if (typeof fetchUserNotifications === 'function') {
                            setTimeout(() => fetchUserNotifications(), 500);
                        }
                        setTimeout(() => location.reload(), 2000);
                    } else { 
                        showNotification({
                            type: 'danger',
                            title: 'Suspension Failed',
                            message: d.message || 'Failed to suspend user'
                        });
                    } 
                })
                .catch(error => {
                    console.error('Suspension error:', error);
                    showNotification({
                        type: 'danger',
                        title: 'Network Error',
                        message: 'Failed to connect to server. Please try again.'
                    });
                });
            }
        });
    }
    
    // Lift suspension confirmation
    const confirmLiftBtn = document.getElementById('confirmLiftSuspension');
    if (confirmLiftBtn) {
        confirmLiftBtn.addEventListener('click', function() {
            if (currentLiftSuspensionUserId) {
                // Close modal first
                const modal = bootstrap.Modal.getInstance(document.getElementById('liftSuspensionModal'));
                modal.hide();
                
                // Proceed with lift suspension
                fetch('toggle_cod_suspension.php', {
                    method: 'POST', 
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'}, 
                    body: 'action=lift&user_id=' + encodeURIComponent(currentLiftSuspensionUserId)
                })
                .then(r => {
                    if (!r.ok) {
                        throw new Error('Network response was not ok: ' + r.status);
                    }
                    return r.json();
                })
                .then(d => { 
                    if (d.success) { 
                        showNotification({
                            type: 'success',
                            title: 'Suspension Lifted',
                            message: d.message || 'User suspension has been lifted successfully'
                        });
                        // Refresh user notifications to reflect status changes
                        if (typeof fetchUserNotifications === 'function') {
                            setTimeout(() => fetchUserNotifications(), 500);
                        }
                        setTimeout(() => location.reload(), 2000);
                    } else { 
                        showNotification({
                            type: 'danger',
                            title: 'Failed to Lift Suspension',
                            message: d.message || 'Failed to lift suspension'
                        });
                    } 
                })
                .catch(error => {
                    console.error('Lift suspension error:', error);
                    showNotification({
                        type: 'danger',
                        title: 'Network Error',
                        message: 'Failed to connect to server. Please try again.'
                    });
                });
            }
        });
    }
    
    // Clean record suspension confirmation
    const confirmCleanRecordBtn = document.getElementById('confirmCleanRecordSuspension');
    if (confirmCleanRecordBtn) {
        confirmCleanRecordBtn.addEventListener('click', function() {
            if (currentCleanRecordUserId) {
                const reasonField = document.getElementById('suspensionReason');
                const reason = reasonField.value.trim();
                
                // Validate reason is provided
                if (!reason) {
                    // Add Bootstrap validation classes
                    reasonField.classList.add('is-invalid');
                    reasonField.focus();
                    
                    // Show error message
                    const errorDiv = document.getElementById('reasonError');
                    errorDiv.style.display = 'block';
                    
                    // Add shake animation
                    reasonField.style.animation = 'shake 0.5s';
                    setTimeout(() => {
                        reasonField.style.animation = '';
                    }, 500);
                    
                    return; // Stop execution
                }
                
                // Remove validation classes if reason is provided
                reasonField.classList.remove('is-invalid');
                reasonField.classList.add('is-valid');
                document.getElementById('reasonError').style.display = 'none';
                
                // Update the final confirmation modal with the reason
                document.getElementById('finalReasonText').textContent = reason;
                
                // Close the first modal
                const firstModal = bootstrap.Modal.getInstance(document.getElementById('cleanRecordModal'));
                firstModal.hide();
                
                // Show the final confirmation modal
                const finalModal = new bootstrap.Modal(document.getElementById('finalCleanRecordModal'));
                finalModal.show();
            }
        });
    }
    
    // Final clean record suspension confirmation
    const finalConfirmCleanRecordBtn = document.getElementById('finalConfirmCleanRecordSuspension');
    if (finalConfirmCleanRecordBtn) {
        finalConfirmCleanRecordBtn.addEventListener('click', function() {
            if (currentCleanRecordUserId) {
                // Close the final modal
                const finalModal = bootstrap.Modal.getInstance(document.getElementById('finalCleanRecordModal'));
                finalModal.hide();
                
                // Show password verification modal
                const passwordModal = new bootstrap.Modal(document.getElementById('adminPasswordModal'));
                passwordModal.show();
            }
        });
    }
    
    // Admin password verification
    const verifyAdminPasswordBtn = document.getElementById('verifyAdminPassword');
    if (verifyAdminPasswordBtn) {
        verifyAdminPasswordBtn.addEventListener('click', function() {
            const passwordField = document.getElementById('adminPassword');
            const password = passwordField.value.trim();
            
            // Validate password is provided
            if (!password) {
                passwordField.classList.add('is-invalid');
                passwordField.focus();
                document.getElementById('passwordError').style.display = 'block';
                return;
            }
            
            // Verify password and proceed with suspension
            verifyPasswordAndSuspend(password);
        });
    }
    
    // Password toggle functionality
    const togglePasswordBtn = document.getElementById('togglePassword');
    if (togglePasswordBtn) {
        togglePasswordBtn.addEventListener('click', function() {
            const passwordField = document.getElementById('adminPassword');
            const icon = document.getElementById('passwordToggleIcon');
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordField.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
    }
});

 function viewAppeal(btn){
  var text = btn.getAttribute('data-appeal') || '';
  var at = btn.getAttribute('data-appeal-at') || '';
  var html = '\n<div class="modal fade" id="appealModal" tabindex="-1" aria-hidden="true">\n  <div class="modal-dialog">\n    <div class="modal-content">\n      <div class="modal-header">\n        <h5 class="modal-title text-dark">User Appeal</h5>\n        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>\n      </div>\n      <div class="modal-body">\n        <div class="alert alert-secondary" style="white-space:pre-wrap; overflow-wrap:anywhere; word-break:break-word; padding:12px;">'+text+'</div>\n        <div class="text-muted" style="font-size:12px;">Submitted: '+at+'</div>\n      </div>\n      <div class="modal-footer">\n        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>\n      </div>\n    </div>\n  </div>\n</div>';
  var wrap = document.createElement('div');
  wrap.innerHTML = html;
  document.body.appendChild(wrap);
  var modal = new bootstrap.Modal(wrap.querySelector('#appealModal'));
  modal.show();
  wrap.addEventListener('hidden.bs.modal', function(){ wrap.remove(); });
}
 </script>

    <!-- Modern Confirmation Modal for Staff Activate/Deactivate -->
    <div class="modal fade" id="staffConfirmModal" tabindex="-1" aria-labelledby="staffConfirmModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-secondary border-0">
                <div class="modal-header border-0 pb-2">
                    <h5 class="modal-title text-white" id="confirmModalTitle">Confirm Action</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <div id="confirmModalIcon" class="mb-3">
                        <i class="fas fa-question-circle text-warning fa-3x"></i>
                    </div>
                    <h6 class="text-white mb-3" id="confirmModalMessage">Are you sure you want to proceed?</h6>
                </div>
                <div class="modal-footer border-0 pt-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-warning" id="confirmModalBtn" onclick="executeStaffAction()">
                        <i class="fas fa-check me-2"></i>Confirm
                    </button>
                </div>
            </div>
        </div>
    </div>

    <style>
        #staffConfirmModal .modal-content {
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5);
        }
        
        #staffConfirmModal .modal-header {
            background: linear-gradient(135deg, #343a40 0%, #212529 100%);
            border-radius: 12px 12px 0 0;
        }
        
        #staffConfirmModal .modal-footer {
            background: linear-gradient(135deg, #212529 0%, #343a40 100%);
            border-radius: 0 0 12px 12px;
        }
        
        #staffConfirmModal .btn-close-white {
            filter: invert(1) grayscale(100%) brightness(200%);
        }
        
        #confirmModalIcon i {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
                opacity: 1;
            }
            50% {
                transform: scale(1.1);
                opacity: 0.8;
            }
        }
    </style>
 
</body>
</html> 
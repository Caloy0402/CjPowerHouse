<?php
session_start();
require_once 'dbconn.php';

// Only admins
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'admin') {
    header('Location: signin.php');
    exit;
}

// Get user data for profile image
$user_id = $_SESSION['user_id'];
$user_query = $conn->prepare("SELECT first_name, last_name, profile_image FROM cjusers WHERE id = ?");
$user_query->bind_param("i", $user_id);
$user_query->execute();
$user_result = $user_query->get_result();
$user_data = $user_result->fetch_assoc();



// Function to calculate remaining duty hours for a staff member (per calendar day)
// Handles sessions spanning midnight and caps active sessions at 8h for completion
function calculateRemainingDutyHours($conn, $staffId, $role) {
    $requiredHours = 8; // daily requirement
    $requiredMinutes = $requiredHours * 60; // 480

    $todayStart = date('Y-m-d 00:00:00');
    $todayEnd   = date('Y-m-d 23:59:59');

    // Sum of minutes for all closed sessions that overlap today (compute overlap in SQL)
    $completedMinutes = 0;
    $sql = "SELECT SUM(
                GREATEST(0, TIMESTAMPDIFF(
                    MINUTE,
                    GREATEST(time_in, ?),
                    LEAST(time_out, ?)
                ))
            ) AS total_minutes
            FROM staff_logs
            WHERE staff_id = ? AND role = ?
              AND time_out IS NOT NULL
              AND time_in <= ? AND time_out >= ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('ssssss', $todayStart, $todayEnd, $staffId, $role, $todayEnd, $todayStart);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $completedMinutes = max(0, (int)($row['total_minutes'] ?? 0));
        $stmt->close();
    }

    // Active session minutes overlapping today (if any)
    $currentSessionMinutes = 0;
    $sql = "SELECT TIME_TO_SEC(TIMEDIFF(LEAST(NOW(), ?), GREATEST(time_in, ?)))/60 AS minutes_today
            FROM staff_logs
            WHERE staff_id = ? AND role = ? AND time_out IS NULL
            ORDER BY time_in DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('ssiss', $todayEnd, $todayStart, $staffId, $role);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $currentSessionMinutes = max(0, (int)round($row['minutes_today'] ?? 0));
        }
        $stmt->close();
    }

    // Cap today's work to the daily requirement for completeness logic
    $totalWorkedMinutes = min($requiredMinutes, max(0, $completedMinutes + $currentSessionMinutes));
    $remainingMinutes = max(0, $requiredMinutes - $totalWorkedMinutes);

    return [
        'completed_minutes' => $completedMinutes,
        'current_session_minutes' => $currentSessionMinutes,
        'total_worked_minutes' => $totalWorkedMinutes,
        'remaining_minutes' => $remainingMinutes,
        'remaining_hours' => floor($remainingMinutes / 60),
        'remaining_minutes_only' => $remainingMinutes % 60,
        'is_complete' => $totalWorkedMinutes >= $requiredMinutes
    ];
}

// Auto-logout any sessions that have been left open for 9+ hours
// Marks duty as complete up to 8 hours when auto-logging out
function enforceAutoLogoutForOverdueSessions($conn) {
    // Select candidates first to compute minutes safely
    $query = "SELECT id, time_in FROM staff_logs WHERE time_out IS NULL AND TIMESTAMPDIFF(HOUR, time_in, NOW()) >= 9";
    $result = $conn->query($query);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $id = (int)$row['id'];
            // Calculate minutes from time_in to now and cap duty minutes at 8h
            $calc = $conn->query("SELECT LEAST(TIMESTAMPDIFF(MINUTE, time_in, NOW()), 480) AS duty_minutes FROM staff_logs WHERE id = $id");
            $dutyMinutes = 480;
            if ($calc && $calc->num_rows > 0) {
                $dutyMinutes = (int)$calc->fetch_assoc()['duty_minutes'];
            }
            $upd = $conn->prepare("UPDATE staff_logs SET time_out = NOW(), duty_duration_minutes = ? WHERE id = ?");
            if ($upd) {
                $upd->bind_param('ii', $dutyMinutes, $id);
                $upd->execute();
                $upd->close();
            }
        }
    }
}

// Ensure staff_logs table exists
$conn->query("CREATE TABLE IF NOT EXISTS staff_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  staff_id INT NOT NULL,
  role VARCHAR(20) NOT NULL,
  action VARCHAR(20) NOT NULL,
  activity TEXT NULL,
  time_in DATETIME NOT NULL,
  time_out DATETIME DEFAULT NULL,
  duty_duration_minutes INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_staff_role (staff_id, role),
  INDEX idx_time_in (time_in)
)");

// Enforce auto-logout for overdue active sessions (9+ hours)
enforceAutoLogoutForOverdueSessions($conn);

// Filters
$from = isset($_GET['from']) ? $_GET['from'] : date('Y-m-d', strtotime('-7 days'));
$to   = isset($_GET['to']) ? $_GET['to']   : date('Y-m-d');
$roleFilter = isset($_GET['role']) ? trim($_GET['role']) : '';

// Validate date range - ensure "to" date is not earlier than "from" date
if ($from && $to && $to < $from) {
    $to = $from; // Set "to" date equal to "from" date if invalid
}

// Pagination
$itemsPerPage = 10;
$currentPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($currentPage - 1) * $itemsPerPage;

// Fetch logs joined with names
$logs = [];
$where = "WHERE DATE(l.time_in) BETWEEN ? AND ? AND l.role NOT IN ('Admin', 'Customer')";
if ($roleFilter !== '') { $where .= " AND l.role = ?"; }

$sql = "
    SELECT l.id, l.staff_id, l.role, l.action, l.activity, l.time_in, l.time_out, l.duty_duration_minutes,
           COALESCE(r.first_name, m.first_name, cj.first_name, '') AS first_name,
           COALESCE(r.last_name, m.last_name, cj.last_name, '') AS last_name
    FROM staff_logs l
    LEFT JOIN riders r ON l.role='Rider' AND r.id=l.staff_id
    LEFT JOIN mechanics m ON l.role='Mechanic' AND m.id=l.staff_id
    LEFT JOIN cjusers cj ON (l.role='Admin' OR l.role='Cashier') AND cj.id=l.staff_id
    $where
    ORDER BY l.time_in DESC
    LIMIT $itemsPerPage OFFSET $offset";
$stmt = $roleFilter === '' ? $conn->prepare($sql) : $conn->prepare($sql);
if ($stmt) {
    if ($roleFilter === '') {
        $stmt->bind_param('ss', $from, $to);
    } else {
        $stmt->bind_param('sss', $from, $to, $roleFilter);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) { $logs[] = $row; }
    $stmt->close();
}

// Get total count for pagination
$countSql = "SELECT COUNT(*) as total FROM staff_logs l $where";
$countStmt = $conn->prepare($countSql);
if ($countStmt) {
    if ($roleFilter === '') {
        $countStmt->bind_param('ss', $from, $to);
    } else {
        $countStmt->bind_param('sss', $from, $to, $roleFilter);
    }
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $totalRecords = $countResult->fetch_assoc()['total'];
    $countStmt->close();
}

$totalPages = ceil($totalRecords / $itemsPerPage);

// Calculate total duty hours and working days for pay computation
function calculateTotalDutyHours($conn, $from, $to, $roleFilter) {
    $where = "WHERE DATE(l.time_in) BETWEEN ? AND ? AND l.role NOT IN ('Admin', 'Customer') AND l.time_out IS NOT NULL";
    if ($roleFilter !== '') { $where .= " AND l.role = ?"; }
    
    $sql = "SELECT SUM(l.duty_duration_minutes) AS total_minutes 
            FROM staff_logs l $where";
    $stmt = $conn->prepare($sql);
    
    if ($stmt) {
        if ($roleFilter === '') {
            $stmt->bind_param('ss', $from, $to);
        } else {
            $stmt->bind_param('sss', $from, $to, $roleFilter);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $totalMinutes = (int)($row['total_minutes'] ?? 0);
        $stmt->close();
        return $totalMinutes / 60; // Convert to hours
    }
    return 0;
}

// Calculate working days (excluding Sundays)
function calculateWorkingDays($from, $to) {
    $startDate = new DateTime($from);
    $endDate = new DateTime($to);
    $endDate->modify('+1 day'); // Include end date
    
    $workingDays = 0;
    $current = clone $startDate;
    
    while ($current < $endDate) {
        // Check if day is not Sunday (0 = Sunday)
        if ($current->format('w') != '0') {
            $workingDays++;
        }
        $current->modify('+1 day');
    }
    
    return $workingDays;
}

// Fetch pay calculation data
$totalDutyHours = calculateTotalDutyHours($conn, $from, $to, $roleFilter);
$workingDays = calculateWorkingDays($from, $to);

// Fetch all staff for dropdown (excluding Admin and Customer)
$allStaff = [];
$staffSql = "
    SELECT DISTINCT l.staff_id, l.role,
           COALESCE(r.first_name, m.first_name, cj.first_name, '') AS first_name,
           COALESCE(r.last_name, m.last_name, cj.last_name, '') AS last_name
    FROM staff_logs l
    LEFT JOIN riders r ON l.role='Rider' AND r.id=l.staff_id
    LEFT JOIN mechanics m ON l.role='Mechanic' AND m.id=l.staff_id
    LEFT JOIN cjusers cj ON (l.role='Admin' OR l.role='Cashier') AND cj.id=l.staff_id
    WHERE l.role NOT IN ('Admin', 'Customer')
    ORDER BY l.role, first_name, last_name";
$staffResult = $conn->query($staffSql);
while ($row = $staffResult->fetch_assoc()) {
    $allStaff[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Admin - staff Logs</title>
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <link href="image/logo.png" rel="icon">
    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600&family=Roboto:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.10.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.4.1/font/bootstrap-icons.css">
    <link href="lib/owlcarousel/assets/owl.carousel.min.css" rel="stylesheet">
    <link href="lib/tempusdominus/css/tempusdominus-bootstrap-4.min.css" rel="stylesheet">
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Date input styling */
        .input-group-text {
            background-color: #6c757d;
            border-color: #6c757d;
            color: white;
        }
        
        .input-group-text i {
            color: white;
        }
        
        /* Date input specific styling */
        .date-input {
            cursor: pointer;
            background-color: #212529;
            border-color: #6c757d;
            color: white;
        }
        
        .date-input:focus {
            background-color: #212529;
            border-color: #0d6efd;
            color: white;
            box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
        }
        
        .date-input:hover {
            border-color: #0d6efd;
        }
        
        /* Calendar icon styling */
        .calendar-icon {
            cursor: pointer;
            background-color: #6c757d;
            border-color: #6c757d;
            transition: background-color 0.2s;
        }
        
        .calendar-icon:hover {
            background-color: #5a6268;
        }
        
        .calendar-icon i {
            font-size: 14px;
            color: #ffffff;
        }
        
        /* Hide default browser calendar icon */
        input[type="date"]::-webkit-calendar-picker-indicator {
            display: none;
        }
        
        input[type="date"]::-moz-calendar-picker-indicator {
            display: none;
        }
        
        input[type="date"]::-ms-calendar-picker-indicator {
            display: none;
        }
        
        /* Ensure input group works properly */
        .input-group .form-control {
            border-right: 0;
        }
        
        .input-group .input-group-text {
            border-left: 0;
        }
        .remaining-hours {
            font-size: 0.85em;
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: 500;
        }
        .remaining-hours.complete {
            background-color: #28a745;
            color: white;
        }
        .remaining-hours.pending {
            background-color: #ffc107;
            color: #212529;
        }
        .remaining-hours.overdue {
            background-color: #dc3545;
            color: white;
        }
    </style>
</head>
<body>
    <div class="container-fluid position-relative d-flex p-0">
        <div id="spinner" class="show bg-dark position-fixed translate-middle w-100 vh-100 top-50 start-50 d-flex align-items-center justify-content-center">
            <img src="img/Loading.gif" alt="Loading..." style="width: 200px; height: 200px;" />
        </div>

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
                    <a href="Admin-Dashboard.php" class="nav-item nav-link"><i class="fa fa-tachometer-alt me-2"></i>Dashboard</a>
                    <div class="nav-item dropdown">
                        <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown"><i class="fa fa-users me-2"></i>Users</a>
                        <div class="dropdown-menu bg-transparent border-0">
                            <a href="Admin-AddUser.php" class="dropdown-item">Add Users</a>
                            <a href="Admin-ManageUser.php" class="dropdown-item">Manage Users</a>
                        </div>
                    </div>
                    <div class="nav-item dropdown">
                        <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown"><i class="fa fa-th me-2"></i>Product</a>
                        <div class="dropdown-menu bg-transparent border-0">
                            <a href="Admin-Stockmanagement.php" class="dropdown-item">Stock Management</a>
                            <a href="Admin-buy-out-item.php" class="dropdown-item">Buy-out Item</a>
                            <a href="Admin-ReturnedItems.php" class="dropdown-item">Returned Item</a>
                        </div>
                    </div>
                    <a href="Admin-OrderLogs.php" class="nav-item nav-link"><i class="fa fa-shopping-cart me-2"></i>Order Logs</a>
                    <a href="Admin-Payment.php" class="nav-item nav-link"><i class="fa fa-file-alt me-2"></i>Sales Report</a>
                    <a href="Admin-StaffLogs.php" class="nav-item nav-link active"><i class="fa fa-user-clock me-2"></i>Staff Logs</a>
                    <a href="Admin-RescueLogs.php" class="nav-item nav-link"><i class="fa fa-ambulance me-2"></i>Rescue Logs</a>
                </div>
            </nav>
        </div>

        <div class="content">
            <nav class="navbar navbar-expand bg-secondary navbar-dark sticky-top px-4 py-0">
                <a href="Admin-Dashboard.php" class="navbar-brand d-flex d-lg-none me-4">
                    <h2 class="text-primary mb-0"><i class="fa fa-user-edit"></i></h2>
                </a>
                <a href="#" class="sidebar-toggler flex-shrink-0"><i class="fa fa-bars"></i></a>
                <div class="navbar-nav align-items-center ms-auto">
                <?php include 'admin_notifications.php'; ?>
                <?php include 'admin_rescue_notifications.php'; ?>
                <?php include 'admin_user_notifications.php'; ?>
                    <div class="nav-item dropdown"></div>
                    <div class="nav-item dropdown">
                        <a href="" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                        <img src="<?= $user_data['profile_image'] ? (strpos($user_data['profile_image'], 'uploads/') === 0 ? $user_data['profile_image'] : 'uploads/' . $user_data['profile_image']) : 'img/jandi.jpg' ?>" alt="" class="rounded-circle me-lg-2" style="width: 40px; height: 40px;">
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

            <div class="container-fluid pt-4 px-4">
                <!-- Staff Pay Calculator Section -->
                <div class="bg-secondary rounded p-4 mb-4">
                    <h6 class="mb-4 text-white">Staff Pay Calculator</h6>
                    <form id="payCalculatorForm" class="row g-3">
                        <div class="col-md-2">
                            <label class="form-label text-white">Select Staff</label>
                            <select id="selectedStaff" name="selectedStaff" class="form-select">
                                <option value="all">All Staff</option>
                                <?php foreach ($allStaff as $staff): ?>
                                    <option value="<?= $staff['staff_id'] ?>|<?= htmlspecialchars($staff['role']) ?>">
                                        <?= htmlspecialchars($staff['first_name'] . ' ' . $staff['last_name']) ?> - <?= htmlspecialchars($staff['role']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label text-white">Pay Type</label>
                            <select id="payType" name="payType" class="form-select">
                                <option value="Hourly">Hourly (₱43.75/hr)</option>
                                <option value="Fifteen Days">Fifteen Days (₱4,550)</option>
                                <option value="Monthly">Monthly (₱9,100)</option>
                            </select>
                            <input type="hidden" id="hourlyRate" value="43.75">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label text-white">From</label>
                            <div class="input-group">
                                <input type="date" id="calcFrom" name="calcFrom" class="form-control date-input" value="<?= htmlspecialchars(date('Y-m-d', strtotime('-7 days'))) ?>">
                                <span class="input-group-text calendar-icon" onclick="document.getElementById('calcFrom').showPicker()">
                                    <i class="fas fa-calendar-alt"></i>
                                </span>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label text-white">To</label>
                            <div class="input-group">
                                <input type="date" id="calcTo" name="calcTo" class="form-control date-input" value="<?= htmlspecialchars(date('Y-m-d')) ?>">
                                <span class="input-group-text calendar-icon" onclick="document.getElementById('calcTo').showPicker()">
                                    <i class="fas fa-calendar-alt"></i>
                                </span>
                            </div>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <div class="d-grid gap-2 w-100">
                                <button type="button" class="btn btn-success btn-sm" id="calculateBtn">
                                    <i class="fas fa-calculator me-1"></i>Calculate
                                </button>
                                <button type="button" class="btn btn-primary btn-sm" id="generateReportBtn">
                                    <i class="fas fa-file-export me-1"></i>Generate Pay Report
                                </button>
                            </div>
                        </div>
                    </form>
                    
                    <!-- Pay Summary Display -->
                    <div id="paySummary" class="mt-4" style="display: none;">
                        <div class="bg-dark rounded p-3">
                            <h6 class="text-primary mb-3">Pay Summary</h6>
                            <div class="row mb-3">
                                <div class="col-md-12">
                                    <p class="mb-0 text-white"><strong>Selected Staff:</strong> <span id="displayStaffName" class="text-info">-</span></p>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <p class="mb-2 text-white"><strong>Total Duty Hours:</strong> <span id="totalHours" class="text-success">0 hrs</span></p>
                                    <p class="mb-2 text-white"><strong>Pay Type:</strong> <span id="displayPayType" class="text-info">-</span></p>
                                    <p class="mb-2 text-white"><strong>Hourly Rate:</strong> ₱<span id="displayHourlyRate">0.00</span></p>
                                </div>
                                <div class="col-md-6">
                                    <p class="mb-2 text-white"><strong>Working Days:</strong> <span id="workingDays" class="text-info">0</span></p>
                                    <p class="mb-2 text-white"><strong>Average Hours/Day:</strong> <span id="avgHoursPerDay" class="text-info">0 hrs</span></p>
                                </div>
                            </div>
                            <div class="row mt-3" id="regularPaySection" style="display: none;">
                                <div class="col-md-6">
                                    <div class="border border-success border-2 rounded p-3 text-center bg-dark bg-opacity-50">
                                        <p class="mb-1 text-white"><strong class="text-success">Regular Pay:</strong></p>
                                        <p class="mb-0"><span class="text-white fw-bold fs-4">₱</span><span id="regularPay" class="text-success fw-bold fs-4">0.00</span></p>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="border border-warning border-2 rounded p-3 text-center bg-dark bg-opacity-50">
                                        <p class="mb-1 text-white"><strong class="text-warning">Overtime Hours:</strong> <span id="overtimeHours" class="text-warning fw-bold">0.00 hrs</span></p>
                                        <p class="mb-0"><strong class="text-warning">Overtime Pay:</strong> ₱<span id="overtimePay" class="text-warning fw-bold fs-4">0.00</span></p>
                                    </div>
                                </div>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-12">
                                    <div class="border border-primary border-3 rounded p-4 text-center bg-dark bg-opacity-50">
                                        <p class="mb-1 text-white"><strong class="text-info fs-5">Employee's Current Pay:</strong></p>
                                        <p class="mb-0"><span class="text-white fw-bold" style="font-size: 2rem;">₱</span><span id="totalPay" class="text-success fw-bold" style="font-size: 2rem;">0.00</span></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="bg-secondary text-center rounded p-4">
                                            <div class="d-flex align-items-center justify-content-between mb-4">
                            <h6 class="mb-0">Staff Logs</h6>
                        </div>
                    <form class="row g-3 text-start mb-3" method="get">
                        <div class="col-md-3">
                            <label class="form-label text-white">From</label>
                            <div class="input-group">
                                <input type="date" name="from" class="form-control date-input" value="<?= htmlspecialchars($from) ?>" id="fromDate">
                                <span class="input-group-text calendar-icon" onclick="document.getElementById('fromDate').showPicker()">
                                    <i class="fas fa-calendar-alt"></i>
                                </span>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label text-white">To</label>
                            <div class="input-group">
                                <input type="date" name="to" class="form-control date-input" value="<?= htmlspecialchars($to) ?>" id="toDate" min="<?= htmlspecialchars($from) ?>">
                                <span class="input-group-text calendar-icon" onclick="document.getElementById('toDate').showPicker()">
                                    <i class="fas fa-calendar-alt"></i>
                                </span>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label text-white">Role</label>
                            <select name="role" class="form-select">
                                <option value="" <?= $roleFilter===''?'selected':'' ?>>All</option>
                                <!-- Admin intentionally removed from filter -->
                                <option value="Cashier" <?= $roleFilter==='Cashier'?'selected':'' ?>>Cashier</option>
                                <option value="Rider" <?= $roleFilter==='Rider'?'selected':'' ?>>Rider</option>
                                <option value="Mechanic" <?= $roleFilter==='Mechanic'?'selected':'' ?>>Mechanic</option>
                            </select>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button class="btn btn-primary w-100" type="submit">Apply Filters</button>
                        </div>
                    </form>
                    <div class="table-responsive">
                        <table class="table text-center align-middle table-bordered table-hover mb-0" id="staffLogsTable">
                            <thead>
                                <tr class="text-white">
                                    <th>Staff</th>
                                    <th>Role</th>
                                    <th>Time In</th>
                                    <th>Time Out</th>
                                    <th>Duty Duration</th>
                                    <th>Remaining Hours</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="staffLogsTableBody">
                                <?php if (empty($logs)): ?>
                                    <tr><td colspan="7" class="text-center">No logs yet.</td></tr>
                                <?php else: foreach ($logs as $log): ?>
                                    <?php 
                                    // Calculate remaining duty based on this specific log entry (session-based)
                                    $requiredMinutes = 480; // 8 hours
                                    $workedMinutes = 0;
                                    if (!empty($log['time_out'])) {
                                        // Closed session: use stored duty_duration_minutes if available, else compute diff
                                        if ($log['duty_duration_minutes'] !== null && $log['duty_duration_minutes'] !== '') {
                                            $workedMinutes = (int)$log['duty_duration_minutes'];
                                        } else {
                                            $workedMinutes = max(0, (int)round((strtotime($log['time_out']) - strtotime($log['time_in'])) / 60));
                                        }
                                    } else {
                                        // Active session: compute from time_in to now
                                        $workedMinutes = max(0, (int)round((time() - strtotime($log['time_in'])) / 60));
                                    }
                                    // Cap at daily requirement for completion logic
                                    $workedMinutesCapped = min($requiredMinutes, $workedMinutes);
                                    $remainingMinutes = max(0, $requiredMinutes - $workedMinutesCapped);
                                    $isComplete = $workedMinutesCapped >= $requiredMinutes;
                                    $remainingHoursOnly = floor($remainingMinutes / 60);
                                    $remainingMinutesOnly = $remainingMinutes % 60;
                                    ?>
                                    <tr data-log-id="<?= $log['id'] ?>">
                                        <td><?= htmlspecialchars(trim(($log['first_name'] ?? '').' '.($log['last_name'] ?? ''))) ?: ('#'.$log['staff_id']) ?></td>
                                        <td><?= htmlspecialchars($log['role']) ?></td>
                                        <td><?= htmlspecialchars(date('Y-m-d g:i:s A', strtotime($log['time_in']))) ?></td>
                                        <td><?= htmlspecialchars($log['time_out'] ? date('Y-m-d g:i:s A', strtotime($log['time_out'])) : '-') ?></td>
                                        <td>
                                            <?php
                                                $mins = $log['duty_duration_minutes'];
                                                if ($mins === null || $mins === '' ) {
                                                    echo '-';
                                                } else {
                                                    $hours = intdiv((int)$mins, 60);
                                                    $minutes = ((int)$mins) % 60;
                                                    $seconds = 0; // Since we only store minutes, seconds will always be 0
                                                    echo sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
                                                }
                                            ?>
                                        </td>
                                        <td>
                                            <?php if ($isComplete): ?>
                                                <span class="remaining-hours complete">
                                                    <i class="fas fa-check-circle me-1"></i>Complete
                                                </span>
                                            <?php else: ?>
                                                <span class="remaining-hours pending">
                                                    <?= $remainingHoursOnly ?>h <?= $remainingMinutesOnly ?>m left
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                                // Check if staff is online (has recent login without logout)
                                                $isOnline = false;
                                                $checkOnlineSql = "SELECT id FROM staff_logs 
                                                                   WHERE staff_id = ? AND role = ? AND action = 'login' 
                                                                   AND time_out IS NULL 
                                                                   ORDER BY time_in DESC LIMIT 1";
                                                $checkStmt = $conn->prepare($checkOnlineSql);
                                                if ($checkStmt) {
                                                    $checkStmt->bind_param('is', $log['staff_id'], $log['role']);
                                                    $checkStmt->execute();
                                                    $checkResult = $checkStmt->get_result();
                                                    $isOnline = $checkResult->num_rows > 0;
                                                    $checkStmt->close();
                                                }
                                                
                                                // Determine status text based on role and online status
                                                $statusText = '';
                                                $statusClass = '';
                                                if ($isOnline) {
                                                    $statusText = '--Online--';
                                                    $statusClass = 'text-success';
                                                } else {
                                                    $statusText = '--Offline--';
                                                    $statusClass = 'text-danger';
                                                }
                                            ?>
                                            <span class="<?= $statusClass ?>"><?= $statusText ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                    <div class="d-flex justify-content-center mt-3">
                        <nav aria-label="Staff logs pagination">
                            <ul class="pagination">
                                <!-- Previous button -->
                                <?php if ($currentPage > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $currentPage - 1])) ?>">
                                            <i class="fas fa-chevron-left"></i> Previous
                                        </a>
                                    </li>
                                <?php else: ?>
                                    <li class="page-item disabled">
                                        <span class="page-link"><i class="fas fa-chevron-left"></i> Previous</span>
                                    </li>
                                <?php endif; ?>
                                
                                <!-- Page numbers -->
                                <?php
                                $startPage = max(1, $currentPage - 2);
                                $endPage = min($totalPages, $currentPage + 2);
                                
                                if ($startPage > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">1</a>
                                    </li>
                                    <?php if ($startPage > 2): ?>
                                        <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                    <li class="page-item <?= $i == $currentPage ? 'active' : '' ?>">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($endPage < $totalPages): ?>
                                    <?php if ($endPage < $totalPages - 1): ?>
                                        <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $totalPages])) ?>"><?= $totalPages ?></a>
                                    </li>
                                <?php endif; ?>
                                
                                <!-- Next button -->
                                <?php if ($currentPage < $totalPages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $currentPage + 1])) ?>">
                                            Next <i class="fas fa-chevron-right"></i>
                                        </a>
                                    </li>
                                <?php else: ?>
                                    <li class="page-item disabled">
                                        <span class="page-link">Next <i class="fas fa-chevron-right"></i></span>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                    
                    <!-- Page info -->
                    <div class="text-center text-white mt-2">
                        <small>Showing <?= ($offset + 1) ?> to <?= min($offset + $itemsPerPage, $totalRecords) ?> of <?= $totalRecords ?> entries</small>
                    </div>
                    <?php endif; ?>
                </div>
            </div>



            <div class="container-fluid pt-4 px-4">
                <div class="bg-secondary rounded-top p-4">
                    <div class="row">
                        <div class="col-12 col-sm-6 text-center text-sm-start">&copy; <a href="#">Cj PowerHouse</a>, All Right Reserved.</div>
                        <div class="col-12 col-sm-6 text-center text-sm-end">Design By: <a href="">Team Jandi</a></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="lib/chart/Chart.min.js"></script>
    <script src="lib/easing/easing.min.js"></script>
    <script src="lib/waypoints/waypoints.min.js"></script>
    <script src="lib/owlcarousel/owl.carousel.min.js"></script>
    <script src="lib/tempusdominus/js/moment.min.js"></script>
    <script src="lib/tempusdominus/js/moment-timezone.min.js"></script>
    <script src="lib/tempusdominus/js/tempusdominus-bootstrap-4.min.js"></script>
    <script src="js/main.js"></script>

    <!-- Staff Login Notification System -->
    <!-- Notification container removed - using unified notification system -->

    <!-- Smooth Real-time Updates Script -->
    <script>
        $(document).ready(function() {
            let updateInterval;
            let lastDataCount = 0;
            let staffEventSource = null;
            
            // Initialize automatic updates
            function initUpdates() {
                updateInterval = setInterval(fetchLatestData, 3000); // Update every 3 seconds for real-time duration
            }
            
            // Initialize SSE for real-time staff status updates
            function initStaffStatusSSE() {
                try {
                    if (staffEventSource) {
                        staffEventSource.close();
                    }
                    
                    staffEventSource = new EventSource('sse_staff_status.php');
                    
                    staffEventSource.onopen = function(event) {
                        console.log('Staff Status SSE connection established');
                    };
                    
                    staffEventSource.onmessage = function(event) {
                        try {
                            const data = JSON.parse(event.data);
                            console.log('SSE Message received:', data);
                            
                            if (data.type === 'staff_status_change' && data.staff_status_change) {
                                // Show staff status notification using unified system
                                if (typeof showUserActivityNotification === 'function') {
                                    const activity = data.activity || 'login';
                                    showUserActivityNotification(data.staff_name, data.role, data.status, activity);
                                }
                                
                                // Refresh the table data
                                fetchLatestData();
                            } else if (data.type === 'connection_established') {
                                console.log('SSE Connection established:', data.message);
                            } else if (data.type === 'error') {
                                console.error('SSE Error:', data.message);
                            }
                        } catch (error) {
                            console.error('Error parsing staff SSE message:', error);
                        }
                    };
                    
                    staffEventSource.onerror = function(event) {
                        console.error('Staff Status SSE Error:', event);
                        if (staffEventSource) {
                            staffEventSource.close();
                            staffEventSource = null;
                        }
                        
                        // Retry connection after 5 seconds
                        setTimeout(() => {
                            initStaffStatusSSE();
                        }, 5000);
                    };
                    
                } catch (error) {
                    console.error('Error initializing staff SSE:', error);
                    // Retry after 5 seconds
                    setTimeout(() => {
                        initStaffStatusSSE();
                    }, 5000);
                }
            }
            
            // Fetch latest data from server
            function fetchLatestData() {
                const currentFilters = getCurrentFilters();
                
                $.ajax({
                    url: 'get_staff_logs_ajax.php',
                    method: 'GET',
                    data: currentFilters,
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            // Check for new staff logins before updating table
                            checkForNewStaffLogins(response.logs);
                            
                            updateTableSmoothly(response.logs);
                            
                            lastDataCount = response.total_count;
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error fetching data:', error);
                    }
                });
            }
            
            // Check for new staff logins and show notifications
            function checkForNewStaffLogins(newLogs) {
                if (!window.previousLogs) {
                    window.previousLogs = newLogs;
                    return;
                }
                
                const previousLogIds = new Set(window.previousLogs.map(log => log.id));
                const newLogins = newLogs.filter(log => {
                    // Check if this is a new log entry and it's a login action
                    return !previousLogIds.has(log.id) && 
                           log.action === 'login' && 
                           (log.time_out === null || log.time_out === '');
                });
                
                newLogins.forEach(log => {
                    const staffName = log.full_name || `Staff #${log.staff_id}`;
                    let status = '';
                    
                    switch (log.role) {
                        case 'Cashier':
                            status = 'Online';
                            break;
                        case 'Mechanic':
                            status = 'Online';
                            break;
                        case 'Rider':
                            status = 'Online';
                            break;
                        default:
                            status = 'Online';
                    }
                    
                    // Use unified notification system
                    if (typeof showUserActivityNotification === 'function') {
                        const additionalData = {
                            login_time: new Date(log.time_in).toLocaleTimeString('en-US', { 
                                hour: 'numeric', 
                                minute: '2-digit', 
                                hour12: true 
                            })
                        };
                        showUserActivityNotification(staffName, log.role, status, 'login', '', additionalData);
                    }
                });
                
                window.previousLogs = newLogs;
            }
            
            // Get current filter values
            function getCurrentFilters() {
                const urlParams = new URLSearchParams(window.location.search);
                return {
                    from: $('input[name="from"]').val(),
                    to: $('input[name="to"]').val(),
                    role: $('select[name="role"]').val(),
                    page: urlParams.get('page') || 1
                };
            }
            
                            // Update the table smoothly without blinking
                function updateTableSmoothly(logs) {
                    const tbody = $('#staffLogsTableBody');

                    if (logs.length === 0) {
                        tbody.html('<tr><td colspan="7" class="text-center">No logs yet.</td></tr>');
                        return;
                    }

                    // Create new rows
                    let newRows = '';
                    logs.forEach(function(log) {
                        // Use the formatted duration from the server
                        newRows += `
                            <tr data-log-id="${log.id}">
                                <td>${escapeHtml(log.full_name)}</td>
                                <td>${escapeHtml(log.role)}</td>
                                <td>${escapeHtml(log.formatted_time_in)}</td>
                                <td>${escapeHtml(log.formatted_time_out)}</td>
                                <td>${log.formatted_duration || '--'}</td>
                                <td>${log.remaining_hours_html || ''}</td>
                                <td><span class="${log.status.class}">${log.status.text}</span></td>
                            </tr>
                        `;
                    });

                    // Update content without animation to prevent blinking
                    tbody.html(newRows);
                }
            

            
            // Notification functions removed - using unified notification system from admin_notifications.php
            
            // Escape HTML to prevent XSS
            function escapeHtml(text) {
                const map = {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                };
                return text.replace(/[&<>"']/g, function(m) { return map[m]; });
            }
            
            // Handle filter form submission
            $('form').submit(function() {
                // Clear interval and restart with new filters
                clearInterval(updateInterval);
                lastDataCount = 0;
                
                // After form submission, restart updates
                setTimeout(function() {
                    initUpdates();
                }, 1000);
            });
            
            // Initialize on page load
            initUpdates();
            initStaffStatusSSE();
            
            // Handle page visibility changes (pause updates when tab is not visible)
            document.addEventListener('visibilitychange', function() {
                if (document.hidden) {
                    clearInterval(updateInterval);
                } else {
                    initUpdates();
                }
            });
            
            // Clean up on page unload
            window.addEventListener('beforeunload', function() {
                if (staffEventSource) {
                    staffEventSource.close();
                }
                if (updateInterval) {
                    clearInterval(updateInterval);
                }
            });
            
            // Date validation functions
            function validateDateRange() {
                const fromDate = document.getElementById('fromDate');
                const toDate = document.getElementById('toDate');
                
                // Set minimum date for "To" field based on "From" date
                toDate.min = fromDate.value;
                
                // If "To" date is earlier than "From" date, reset it to "From" date
                if (toDate.value && fromDate.value && toDate.value < fromDate.value) {
                    toDate.value = fromDate.value;
                }
            }
            
            // Add event listeners for date validation
            document.getElementById('fromDate').addEventListener('change', validateDateRange);
            document.getElementById('toDate').addEventListener('change', validateDateRange);
            
            // Initialize date validation on page load
            validateDateRange();
            
            // Staff Pay Calculator Logic
            let currentPayData = {
                totalHours: <?= number_format($totalDutyHours, 2) ?>,
                workingDays: <?= $workingDays ?>
            };
            
            // Initialize hourly rate on page load (always 43.75)
            const initializeRate = function() {
                $('#hourlyRate').val('43.75');
            };
            
            // Initialize on page load
            initializeRate();
            
            // Calculate button handler
            $('#calculateBtn').on('click', function() {
                calculatePay();
            });
            
            // Auto-recalculate when pay type changes
            $('#payType').on('change', function() {
                // Keep hourly rate constant at 43.75
                $('#hourlyRate').val('43.75');
                
                // Auto-calculate if summary is visible
                if ($('#paySummary').is(':visible')) {
                    calculatePay();
                }
            });
            
            // Calculate pay based on pay type
            function calculatePay() {
                const payType = $('#payType').val();
                const hourlyRate = parseFloat($('#hourlyRate').val()) || 43.75;
                const from = $('#calcFrom').val();
                const to = $('#calcTo').val();
                const selectedStaff = $('#selectedStaff').val();
                
                if (!from || !to) {
                    alert('Please select a date range');
                    return;
                }
                
                // Parse staff selection
                let staffId = '';
                let staffRole = '';
                let staffName = '';
                
                if (selectedStaff !== 'all') {
                    const staffParts = selectedStaff.split('|');
                    staffId = staffParts[0];
                    staffRole = staffParts[1];
                    
                    // Get staff name from dropdown text
                    const optionText = $('#selectedStaff option:selected').text();
                    staffName = optionText;
                } else {
                    staffName = 'All Staff';
                }
                
                // Fetch updated data from server
                $.ajax({
                    url: 'get_staff_pay_data.php',
                    method: 'POST',
                    data: {
                        from: from,
                        to: to,
                        hourlyRate: hourlyRate,
                        payType: payType,
                        staffId: staffId,
                        staffRole: staffRole
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            currentPayData = response.data;
                            displayPaySummary(response.data, payType, hourlyRate, staffName);
                        } else {
                            alert('Error calculating pay: ' + (response.message || 'Unknown error'));
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error fetching pay data:', error);
                        console.error('Status:', status);
                        console.error('Response Text:', xhr.responseText);
                        let errorMsg = 'Error fetching pay data';
                        try {
                            const response = JSON.parse(xhr.responseText);
                            errorMsg = response.message || errorMsg;
                        } catch (e) {
                            errorMsg = xhr.responseText.substring(0, 100);
                        }
                        alert(errorMsg);
                    }
                });
            }
            
            // Display pay summary
            function displayPaySummary(data, payType, hourlyRate, staffName) {
                const totalHours = data.totalHours || 0;
                const workingDays = data.workingDays || 0;
                let totalPay = 0;
                
                // Calculate total pay based on pay type with overtime handling
                let regularPay = 0;
                let overtimePay = 0;
                let overtimeHours = 0;
                
                if (payType === 'Hourly') {
                    // For hourly pay: multiply total hours by hourly rate (43.75)
                    regularPay = totalHours * hourlyRate;
                    totalPay = regularPay;
                } else if (payType === 'Fifteen Days') {
                    // For fifteen days: Simple proportional calculation
                    // Full fifteen days (15 working days) pays ₱4,550
                    const fullPeriodDays = 15;
                    const actualDays = workingDays;
                    const fifteenDaySalary = 4550;
                    
                    // Calculate based on actual working days
                    // If worked more than expected hours, use hourly rate
                    const expectedHoursForActualDays = actualDays * 8;
                    
                    if (totalHours > expectedHoursForActualDays) {
                        // They worked overtime - pay hourly rate for all hours
                        regularPay = totalHours * hourlyRate; // ₱43.75
                        overtimeHours = 0; // Already included in regular pay
                        overtimePay = 0;
                        totalPay = regularPay;
                    } else {
                        // Proportional pay based on actual working days
                        regularPay = (actualDays / fullPeriodDays) * fifteenDaySalary;
                        totalPay = regularPay;
                    }
                } else if (payType === 'Monthly') {
                    // For monthly: Simple proportional calculation
                    // Full month (30 working days) pays ₱9,100
                    const fullPeriodDays = 30;
                    const actualDays = workingDays;
                    const monthlySalary = 9100;
                    
                    // Calculate based on actual working days
                    const expectedHoursForActualDays = actualDays * 8;
                    
                    if (totalHours > expectedHoursForActualDays) {
                        // Overtime calculation
                        overtimeHours = totalHours - expectedHoursForActualDays;
                        // Regular pay for expected hours
                        regularPay = (actualDays / fullPeriodDays) * monthlySalary;
                        // Overtime pay at hourly rate
                        overtimePay = overtimeHours * hourlyRate; // ₱43.75
                        totalPay = regularPay + overtimePay;
                    } else {
                        // Proportional pay based on actual working days
                        regularPay = (actualDays / fullPeriodDays) * monthlySalary;
                        totalPay = regularPay;
                    }
                }
                
                // Store overtime data for display
                currentPayData.overtimeHours = overtimeHours;
                currentPayData.regularPay = regularPay;
                currentPayData.overtimePay = overtimePay;
                
                // Update display
                $('#displayStaffName').text(staffName);
                $('#totalHours').text(totalHours.toFixed(2) + ' hrs');
                $('#displayPayType').text(payType);
                // Always display 43.75 as the hourly rate
                $('#displayHourlyRate').text('43.75');
                $('#workingDays').text(workingDays);
                
                // Calculate and display average hours per day
                const avgHours = workingDays > 0 ? totalHours / workingDays : 0;
                $('#avgHoursPerDay').text(avgHours.toFixed(2) + ' hrs');
                
                // Display regular pay and overtime if applicable
                $('#regularPay').text(regularPay.toFixed(2));
                $('#overtimeHours').text(overtimeHours.toFixed(2) + ' hrs');
                $('#overtimePay').text(overtimePay.toFixed(2));
                
                // Show/hide overtime section
                if (overtimeHours > 0) {
                    $('#regularPaySection').show();
                } else {
                    $('#regularPaySection').hide();
                }
                
                // Display total pay with proper formatting (with currency symbol)
                $('#totalPay').text(totalPay.toFixed(2));
                
                // Show summary
                $('#paySummary').slideDown();
            }
            
            // Helper function to calculate working days in a month (excluding Sundays)
            function getMonthWorkingDays(fromDate, toDate) {
                const start = new Date(fromDate);
                const end = new Date(toDate);
                
                let workingDays = 0;
                const current = new Date(start);
                
                while (current <= end) {
                    const dayOfWeek = current.getDay();
                    if (dayOfWeek !== 0) { // 0 is Sunday
                        workingDays++;
                    }
                    current.setDate(current.getDate() + 1);
                }
                
                return workingDays;
            }
            
            // Generate report button handler
            $('#generateReportBtn').on('click', function() {
                if (!$('#paySummary').is(':visible')) {
                    alert('Please calculate pay first');
                    return;
                }
                
                const payType = $('#payType').val();
                const hourlyRate = $('#hourlyRate').val();
                const from = $('#calcFrom').val();
                const to = $('#calcTo').val();
                const staffName = $('#displayStaffName').text();
                
                // Generate CSV report
                const reportData = {
                    from: from,
                    to: to,
                    payType: payType,
                    hourlyRate: hourlyRate,
                    totalHours: currentPayData.totalHours,
                    workingDays: currentPayData.workingDays,
                    totalPay: $('#totalPay').text(),
                    staffName: staffName
                };
                
                // Export to CSV
                exportPayReportToCSV(reportData);
            });
            
            // Export pay report to CSV
            function exportPayReportToCSV(data) {
                const avgHoursPerDay = data.workingDays > 0 ? (data.totalHours / data.workingDays).toFixed(2) : '0.00';
                
                const csvContent = 
                    'Staff Pay Report\n' +
                    'CJ PowerHouse\n\n' +
                    'Report Details:\n' +
                    '─────────────────────────────\n' +
                    'Staff Member: ' + data.staffName + '\n' +
                    'Date Range: ' + data.from + ' to ' + data.to + '\n' +
                    'Pay Type: ' + data.payType + '\n' +
                    'Hourly Rate: ₱' + parseFloat(data.hourlyRate).toFixed(2) + '\n\n' +
                    'Work Summary:\n' +
                    '─────────────────────────────\n' +
                    'Total Duty Hours: ' + parseFloat(data.totalHours).toFixed(2) + ' hrs\n' +
                    'Working Days: ' + data.workingDays + '\n' +
                    'Average Hours/Day: ' + avgHoursPerDay + ' hrs\n\n' +
                    'Pay Calculation:\n' +
                    '─────────────────────────────\n' +
                    'Total Pay: ₱' + data.totalPay + '\n\n' +
                    'Report Generated: ' + new Date().toLocaleString() + '\n' +
                    'Generated By: ' + '<?= htmlspecialchars($user_data["first_name"] . " " . $user_data["last_name"]) ?>';
                
                const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                const link = document.createElement('a');
                const url = URL.createObjectURL(blob);
                const staffNameForFilename = data.staffName.replace(/[^a-zA-Z0-9]/g, '_').substring(0, 30);
                link.setAttribute('href', url);
                link.setAttribute('download', 'pay_report_' + staffNameForFilename + '_' + data.from + '_to_' + data.to + '.csv');
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }

        });
    </script>
    <script src="js/script.js"></script>

</body>
</html>



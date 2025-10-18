<?php
session_start();
require_once 'dbconn.php';

// Check if user is logged in and has admin privileges
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header('Location: login.php');
    exit();
}

// Get user data for profile image
$user_id = $_SESSION['user_id'];
$user_query = $conn->prepare("SELECT first_name, last_name, profile_image FROM cjusers WHERE id = ?");
$user_query->bind_param("i", $user_id);
$user_query->execute();
$user_result = $user_query->get_result();
$user_data = $user_result->fetch_assoc();

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$date_filter = isset($_GET['date']) ? $_GET['date'] : '';
$search_filter = isset($_GET['search']) ? $_GET['search'] : '';

    // Build query
    $sql = "SELECT 
                hr.id as request_id,
                hr.name as requestor_name,
                hr.bike_unit,
                hr.problem_description,
                hr.location,
                hr.contact_info,
                hr.status,
                hr.created_at,
                hr.updated_at,
                hr.accepted_at,
                hr.completed_at,
                hr.cancelled_at,
                hr.declined_at,
                hr.decline_reason,
                hr.latitude,
                hr.longitude,
                hr.plate_number,
                hr.breakdown_barangay_id,
                bb.barangay_name as breakdown_barangay,
                m.first_name as mechanic_first_name,
                m.last_name as mechanic_last_name,
                m.phone_number as mechanic_phone,
                m.email as mechanic_email,
                m.specialization as mechanic_specialization
            FROM help_requests hr
            LEFT JOIN mechanics m ON hr.mechanic_id = m.id
            LEFT JOIN barangays bb ON hr.breakdown_barangay_id = bb.id
            WHERE 1=1";

$params = [];
$types = '';

if (!empty($status_filter)) {
    $sql .= " AND hr.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if (!empty($date_filter)) {
    $sql .= " AND DATE(hr.created_at) = ?";
    $params[] = $date_filter;
    $types .= 's';
}

if (!empty($search_filter)) {
    $sql .= " AND (hr.name LIKE ? OR hr.location LIKE ? OR m.first_name LIKE ? OR m.last_name LIKE ?)";
    $search_term = '%' . $search_filter . '%';
    $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
    $types .= 'ssss';
}

$sql .= " ORDER BY hr.created_at DESC LIMIT 100";

$stmt = $conn->prepare($sql);
if ($stmt && !empty($params)) {
    $stmt->bind_param($types, ...$params);
} elseif ($stmt) {
    // No parameters to bind
}

$rescue_requests = [];
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $request_date = date('M d, Y g:i A', strtotime($row['created_at']));
        
        $mechanic_name = 'Not Assigned';
        if ($row['mechanic_first_name'] && $row['mechanic_last_name']) {
            $mechanic_name = $row['mechanic_first_name'] . ' ' . $row['mechanic_last_name'];
        }
        
        $rescue_requests[] = [
            'request_id' => $row['request_id'],
            'requestor_name' => $row['requestor_name'],
            'bike_unit' => $row['bike_unit'],
            'problem_description' => $row['problem_description'],
            'location' => $row['location'],
            'contact_info' => $row['contact_info'],
            'status' => $row['status'],
            'request_date' => $request_date,
            'mechanic_name' => $mechanic_name,
            'mechanic_phone' => $row['mechanic_phone'],
            'mechanic_email' => $row['mechanic_email'],
            'mechanic_specialization' => $row['mechanic_specialization'],
            'accepted_at' => $row['accepted_at'],
            'completed_at' => $row['completed_at'],
            'cancelled_at' => $row['cancelled_at'],
            'declined_at' => $row['declined_at'],
            'decline_reason' => $row['decline_reason'],
            'latitude' => $row['latitude'],
            'longitude' => $row['longitude'],
            'plate_number' => $row['plate_number'],
            'breakdown_barangay' => $row['breakdown_barangay']
        ];
    }
    $stmt->close();
}

// Get statistics
$stats_sql = "SELECT 
                COUNT(*) as total_requests,
                SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) as in_progress_count,
                SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed_count,
                SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) as cancelled_count,
                SUM(CASE WHEN status = 'Declined' THEN 1 ELSE 0 END) as declined_count
               FROM help_requests";
$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Rescue Request Logs - Admin Dashboard</title>
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <meta content="" name="keywords">
    <meta content="" name="description">

    <!-- Favicon -->
    <link href="image/logo.png" rel="icon">

    <!-- Google Web Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600&family=Roboto:wght@500;700&display=swap" rel="stylesheet">

    <!-- Icon Font Stylesheet -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.10.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.4.1/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Libraries Stylesheet -->
    <link href="lib/owlcarousel/assets/owl.carousel.min.css" rel="stylesheet">
    <link href="lib/tempusdominus/css/tempusdominus-bootstrap-4.min.css" rel="stylesheet">

    <!-- Customized Bootstrap Stylesheet -->
    <link href="css/bootstrap.min.css" rel="stylesheet">

    <!-- Template Stylesheet -->
    <link href="css/style.css" rel="stylesheet">

    <style>
        .status-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
        }
        
        .status-pending {
            background-color: #ffc107;
            color: #000;
        }
        
        .status-in-progress {
            background-color: #0d6efd;
            color: #fff;
        }
        
        .status-completed {
            background-color: #198754;
            color: #fff;
        }
        
        .status-cancelled {
            background-color: #dc3545;
            color: #fff;
        }
        
        .status-declined {
            background-color: #6c757d;
            color: #fff;
        }
        
        .rescue-details-card {
            background: linear-gradient(135deg, #495057, #6c757d);
            border: 1px solid #495057;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        
        .rescue-details-card h6 {
            color: #0d6efd;
            font-weight: 600;
        }
        
        /* Custom scrollbar for table */
        .table-responsive::-webkit-scrollbar {
            width: 8px;
        }
        
        .table-responsive::-webkit-scrollbar-track {
            background: #2c3e50;
            border-radius: 4px;
        }
        
        .table-responsive::-webkit-scrollbar-thumb {
            background: #6c757d;
            border-radius: 4px;
        }
        
        .table-responsive::-webkit-scrollbar-thumb:hover {
            background: #495057;
        }
        
        /* Modal white background */
        .modal-content {
            background-color: #ffffff !important;
            color: #000000 !important;
        }
        
        .modal-header {
            background-color: #f8f9fa !important;
            border-bottom: 1px solid #dee2e6 !important;
        }
        
        .modal-header .modal-title {
            color: #000000 !important;
        }
        
        .modal-footer {
            background-color: #f8f9fa !important;
            border-top: 1px solid #dee2e6 !important;
        }
        
        .btn-close {
            filter: none !important;
        }
        
        /* Form controls visibility */
        .form-select {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23ffffff' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m1 6 7 7 7-7'/%3e%3c/svg%3e") !important;
        }
        
        .form-control[type="date"]::-webkit-calendar-picker-indicator {
            filter: invert(1);
            cursor: pointer;
        }
        
        .table-responsive {
            border-radius: 0.5rem;
            overflow: hidden;
        }
        
        .page-header {
            background: linear-gradient(135deg, #495057, #6c757d);
            border-radius: 0.5rem;
            padding: 2rem;
            margin: 2rem 1rem 2rem 1rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        
        .page-header h2 {
            color: #ffffff;
            margin: 0;
            font-weight: 600;
        }
        
        .page-header p {
            color: #adb5bd;
            margin: 0.5rem 0 0 0;
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
                    <a href="Admin-Dashboard.php" class="nav-item nav-link"><i class="fa fa-tachometer-alt me-2"></i>Dashboard</a>
                    <div class="nav-item dropdown">
                        <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown"><i class="fa fa-user me-2"></i>Users</a>
                        <div class="dropdown-menu bg-transparent border-0">
                            <a href="Admin-ManageUser.php" class="dropdown-item">Manage Users</a>
                            <a href="Admin-AddUser.php" class="dropdown-item">Add User</a>
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
                    <a href="Admin-SalesReport.php" class="nav-item nav-link"><i class="fa fa-chart-bar me-2"></i>Sales Report</a>
                    <a href="Admin-StaffLogs.php" class="nav-item nav-link"><i class="fa fa-user-clock me-2"></i>Staff Logs</a>
                    <a href="Admin-RescueLogs.php" class="nav-item nav-link active"><i class="fa fa-ambulance me-2"></i>Rescue Logs</a>
                </div>
            </nav>
        </div>
        <!-- Sidebar End -->

        <!-- Content Start -->
        <div class="content">
            <!-- Navbar Start -->
            <nav class="navbar navbar-expand bg-secondary navbar-dark sticky-top px-4 py-0">
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
                        <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                        <img class="rounded-circle me-lg-2" src="<?= $user_data['profile_image'] ? (strpos($user_data['profile_image'], 'uploads/') === 0 ? $user_data['profile_image'] : 'uploads/' . $user_data['profile_image']) : 'img/jandi.jpg' ?>" alt="" style="width: 40px; height: 40px;">
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
            <!-- Navbar End -->

            <!-- Page Header -->
            <div class="page-header">
                <h2><i class="fa fa-tools me-2"></i>Rescue Request Logs</h2>
                <p>View and manage all rescue request history</p>
            </div>

            <!-- Statistics Cards -->
            <div class="container-fluid pt-4 px-4" style="margin: 0 1rem;">
                <div class="row g-4">
                    <div class="col-sm-6 col-xl-2">
                        <div class="bg-secondary rounded d-flex align-items-center justify-content-between p-4">
                            <i class="fa fa-list fa-3x text-primary"></i>
                            <div class="ms-3">
                                <p class="mb-2">Total Requests</p>
                                <h6 class="mb-0"><?php echo $stats['total_requests']; ?></h6>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-xl-2">
                        <div class="bg-secondary rounded d-flex align-items-center justify-content-between p-4">
                            <i class="fa fa-clock fa-3x text-warning"></i>
                            <div class="ms-3">
                                <p class="mb-2">Pending</p>
                                <h6 class="mb-0"><?php echo $stats['pending_count']; ?></h6>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-xl-2">
                        <div class="bg-secondary rounded d-flex align-items-center justify-content-between p-4">
                            <i class="fa fa-tools fa-3x text-info"></i>
                            <div class="ms-3">
                                <p class="mb-2">In Progress</p>
                                <h6 class="mb-0"><?php echo $stats['in_progress_count']; ?></h6>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-xl-2">
                        <div class="bg-secondary rounded d-flex align-items-center justify-content-between p-4">
                            <i class="fa fa-check-circle fa-3x text-success"></i>
                            <div class="ms-3">
                                <p class="mb-2">Completed</p>
                                <h6 class="mb-0"><?php echo $stats['completed_count']; ?></h6>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-xl-2">
                        <div class="bg-secondary rounded d-flex align-items-center justify-content-between p-4">
                            <i class="fa fa-times-circle fa-3x text-danger"></i>
                            <div class="ms-3">
                                <p class="mb-2">Cancelled</p>
                                <h6 class="mb-0"><?php echo $stats['cancelled_count']; ?></h6>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-xl-2">
                        <div class="bg-secondary rounded d-flex align-items-center justify-content-between p-4">
                            <i class="fa fa-ban fa-3x text-danger"></i>
                            <div class="ms-3">
                                <p class="mb-2">Declined</p>
                                <h6 class="mb-0"><?php echo $stats['declined_count']; ?></h6>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="container-fluid pt-4 px-4" style="margin: 0 1rem;">
                <div class="bg-secondary rounded p-4">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label for="status" class="form-label">Filter by Status:</label>
                            <select class="form-select" name="status" id="status">
                                <option value="">All Status</option>
                                <option value="Pending" <?php echo $status_filter === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="In Progress" <?php echo $status_filter === 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                <option value="Completed" <?php echo $status_filter === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="Cancelled" <?php echo $status_filter === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                <option value="Declined" <?php echo $status_filter === 'Declined' ? 'selected' : ''; ?>>Declined</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="date" class="form-label">Filter by Date:</label>
                            <input type="date" class="form-control" name="date" id="date" value="<?php echo $date_filter; ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="search" class="form-label">Search:</label>
                            <input type="text" class="form-control" name="search" id="search" placeholder="Search by name or location..." value="<?php echo $search_filter; ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">Filter</button>
                                <a href="Admin-RescueLogs.php" class="btn btn-secondary">Clear</a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Rescue Requests Table -->
            <div class="container-fluid pt-4 px-4" style="margin: 0 1rem;">
                <div class="bg-secondary rounded p-4">
                    <div class="d-flex align-items-center justify-content-between mb-4">
                        <h6 class="mb-0">Rescue Request History</h6>
                        <span class="badge bg-primary"><?php echo count($rescue_requests); ?> Records</span>
                    </div>
                    
                    <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                        <table class="table table-dark table-hover">
                            <thead class="table-secondary">
                                <tr>
                                    <th>Request ID</th>
                                    <th>Requestor Name</th>
                                    <th>Status</th>
                                    <th>Assigned Mechanic</th>
                                    <th>Request Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($rescue_requests)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4">
                                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                            <h5 class="text-muted">No rescue requests found</h5>
                                            <p class="text-muted">There are no rescue requests matching your criteria.</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($rescue_requests as $request): ?>
                                        <tr>
                                            <td><strong>#<?php echo $request['request_id']; ?></strong></td>
                                            <td><?php echo htmlspecialchars($request['requestor_name']); ?></td>
                                            <td>
                                                <span class="badge status-badge status-<?php echo strtolower(str_replace(' ', '-', $request['status'])); ?>">
                                                    <?php echo $request['status']; ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($request['mechanic_name']); ?></td>
                                            <td><?php echo $request['request_date']; ?></td>
                                            <td>
                                                <button class="btn btn-sm btn-primary" onclick="showRescueDetails(<?php echo $request['request_id']; ?>)">
                                                    <i class="fas fa-eye me-1"></i>View Details
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Footer Start -->
            <div class="container-fluid pt-4 px-4">
                <div class="bg-secondary rounded-top p-4">
                    <div class="row">
                        <div class="col-12 col-sm-6 text-center text-sm-start">
                            &copy; <a href="#">Cj P'House</a>, All Right Reserved.
                        </div>
                        <div class="col-12 col-sm-6 text-center text-sm-end">
                            Design By: <a href="">Team Jandi</a>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Footer End -->
        </div>
        <!-- Content End -->
    </div>

    <!-- Rescue Request Details Modal -->
    <div class="modal fade" id="rescueDetailsModal" tabindex="-1" aria-labelledby="rescueDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content bg-dark text-white">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title" id="rescueDetailsModalLabel">
                        <i class="fas fa-info-circle me-2 text-primary"></i>Rescue Request Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="rescueDetailsContent">
                    <!-- Details will be loaded here -->
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript Libraries -->
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
    <script src="js/main.js"></script>

    <script>
        // Store rescue requests data for modal
        const rescueRequestsData = <?php echo json_encode($rescue_requests); ?>;
        
        function showRescueDetails(requestId) {
            const request = rescueRequestsData.find(r => r.request_id == requestId);
            if (!request) {
                alert('Request details not found');
                return;
            }
            
            const detailsContent = document.getElementById('rescueDetailsContent');
            detailsContent.innerHTML = `
                <div class="row">
                    <div class="col-md-6">
                        <div class="rescue-details-card">
                            <h6><i class="fas fa-user me-2"></i>Requestor Information</h6>
                            <p><strong>Name:</strong> ${request.requestor_name}</p>
                            <p><strong>Contact:</strong> ${request.contact_info || 'N/A'}</p>
                            <p><strong>Location:</strong> ${request.location}</p>
                            <p><strong>Request Date:</strong> ${request.request_date}</p>
                            ${request.accepted_at ? `<p><strong>Accepted At:</strong> ${request.accepted_at}</p>` : ''}
                            ${request.completed_at ? `<p><strong>Completed At:</strong> ${request.completed_at}</p>` : ''}
                            ${request.cancelled_at ? `<p><strong>Cancelled At:</strong> ${request.cancelled_at}</p>` : ''}
                            ${request.declined_at ? `<p><strong>Declined At:</strong> ${request.declined_at}</p>` : ''}
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="rescue-details-card">
                            <h6><i class="fas fa-tools me-2"></i>Mechanic Information</h6>
                            <p><strong>Name:</strong> ${request.mechanic_name}</p>
                            <p><strong>Phone:</strong> ${request.mechanic_phone || 'N/A'}</p>
                            <p><strong>Email:</strong> ${request.mechanic_email || 'N/A'}</p>
                            <p><strong>Specialization:</strong> ${request.mechanic_specialization || 'N/A'}</p>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-12">
                        <div class="rescue-details-card">
                            <h6><i class="fas fa-car me-2"></i>Vehicle Information</h6>
                            <p><strong>Bike Unit:</strong> ${request.bike_unit || 'N/A'}</p>
                            <p><strong>License Plate:</strong> ${request.plate_number || 'N/A'}</p>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-12">
                        <div class="rescue-details-card">
                            <h6><i class="fas fa-info-circle me-2"></i>Request Details</h6>
                            <p><strong>Status:</strong> <span class="badge status-badge status-${request.status.toLowerCase().replace(' ', '-')}">${request.status}</span></p>
                            <p><strong>Breakdown Description:</strong> ${request.problem_description || 'No description provided'}</p>
                            <p><strong>Where Barangay was Breakdown:</strong> ${request.breakdown_barangay || 'N/A'}</p>
                            ${request.decline_reason ? `<p><strong>Decline Reason:</strong> ${request.decline_reason}</p>` : ''}
                            ${request.latitude && request.longitude ? `
                                <p><strong>Coordinates:</strong> ${request.latitude}, ${request.longitude}</p>
                                <a href="https://www.google.com/maps?q=${request.latitude},${request.longitude}" target="_blank" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-map-marker-alt me-1"></i>View on Map
                                </a>
                            ` : ''}
                        </div>
                    </div>
                </div>
            `;
            
            const modal = new bootstrap.Modal(document.getElementById('rescueDetailsModal'));
            modal.show();
        }
    </script>
</body>
</html>

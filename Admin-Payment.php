<?php
session_start();
require_once 'dbconn.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: signin.php");
    exit();
}

// Get user data for profile image
$user_id = $_SESSION['user_id'];
$user_query = $conn->prepare("SELECT first_name, last_name, profile_image FROM cjusers WHERE id = ?");
$user_query->bind_param("i", $user_id);
$user_query->execute();
$user_result = $user_query->get_result();
$user_data = $user_result->fetch_assoc();

// Default date range: today
$from = isset($_GET['from']) ? $_GET['from'] : date('Y-m-d');
$to   = isset($_GET['to'])   ? $_GET['to']   : date('Y-m-d');
// Sales pagination
$salesPerPage = 10;
$salesPage = isset($_GET['sp']) ? max(1, (int)$_GET['sp']) : 1;
$salesOffset = ($salesPage - 1) * $salesPerPage;

// Normalize dates
if (strtotime($to) < strtotime($from)) { $to = $from; }

// Fetch sales within range
$sales = [];
$totals = [
    'count' => 0,
    'gross' => 0.0,
    'delivery' => 0.0,
    'with_delivery' => 0.0
];

$sql = "SELECT o.id, o.order_date, o.total_price, o.payment_method, o.order_status,
               COALESCE(o.delivery_fee, 0) AS delivery_fee,
               COALESCE(o.total_amount_with_delivery, o.total_price) AS total_with_delivery,
               u.first_name, u.last_name,
               t.transaction_number
        FROM orders o
        LEFT JOIN users u ON u.id = o.user_id
        LEFT JOIN transactions t ON t.order_id = o.id
        WHERE DATE(t.completed_date_transaction) BETWEEN ? AND ?
          AND LOWER(o.order_status) IN ('completed','paid')
        ORDER BY t.completed_date_transaction DESC
        LIMIT ? OFFSET ?";

if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param('ssii', $from, $to, $salesPerPage, $salesOffset);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $sales[] = $row;
        $totals['count'] += 1;
        $totals['gross'] += (float)$row['total_price'];
        $totals['delivery'] += (float)$row['delivery_fee'];
        $totals['with_delivery'] += (float)$row['total_with_delivery'];
    }
    $stmt->close();
}

// Total count for pagination
$salesTotalRows = 0;
$countSql = "SELECT COUNT(*) AS cnt
            FROM orders o
            JOIN transactions t ON t.order_id = o.id
            WHERE DATE(t.completed_date_transaction) BETWEEN ? AND ?
              AND LOWER(o.order_status) IN ('completed','paid')";
if ($cst = $conn->prepare($countSql)) {
    $cst->bind_param('ss', $from, $to);
    $cst->execute();
    $cr = $cst->get_result();
    if ($row = $cr->fetch_assoc()) { $salesTotalRows = (int)$row['cnt']; }
    $cst->close();
}
$salesTotalPages = max(1, (int)ceil($salesTotalRows / $salesPerPage));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Sales Report</title>
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <meta content="" name="keywords">
    <meta content="" name="description">

    <!-- Favicons -->
    <link href="Image/logo.png" rel="icon">

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
            <a href="Admin-Payment.php" class="nav-item nav-link"><i class="fa fa-file-alt me-2"></i>Sales Report</a>
            <a href="Admin-StaffLogs.php" class="nav-item nav-link"><i class="fa fa-user-clock me-2"></i>Staff Logs</a>
            <a href="Admin-RescueLogs.php" class="nav-item nav-link"><i class="fa fa-ambulance me-2"></i>Rescue Logs</a>
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
                <--?php include 'admin_notifications.php'; ?>
                <--?php include 'admin_rescue_notifications.php'; ?>
                <--?php include 'admin_user_notifications.php'; ?>
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

     <!-- Sales Report Start -->
<div class="container-fluid pt-4 px-4">
    <div class="bg-secondary text-center rounded p-4">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h6 class="mb-0">Sales Report</h6>
            <form class="d-flex align-items-center" method="get" action="Admin-SalesReport.php" style="gap:8px">
                <span class="text-white-50 small">From:</span>
                <div class="input-group input-group-sm date" id="fromPicker" data-target-input="nearest" style="width: 190px;">
                    <button class="input-group-text bg-dark text-white border-0" type="button" data-toggle="datetimepicker" data-target="#fromPicker"><i class="fa fa-calendar-alt"></i></button>
                    <input type="text" name="from" class="form-control form-control-sm bg-dark text-white border-0 datetimepicker-input" data-target="#fromPicker" placeholder="From" value="<?php echo htmlspecialchars($from); ?>"/>
                </div>
                <span class="text-white-50 small">To:</span>
                <div class="input-group input-group-sm date" id="toPicker" data-target-input="nearest" style="width: 190px;">
                    <button class="input-group-text bg-dark text-white border-0" type="button" data-toggle="datetimepicker" data-target="#toPicker"><i class="fa fa-calendar-alt"></i></button>
                    <input type="text" name="to" class="form-control form-control-sm bg-dark text-white border-0 datetimepicker-input" data-target="#toPicker" placeholder="To" value="<?php echo htmlspecialchars($to); ?>"/>
                </div>
                <button class="btn btn-primary btn-sm px-2 py-1" type="submit"><i class="fa fa-filter"></i></button>
                <a class="btn btn-success btn-sm px-2 py-1" href="export_sales_report_detailed_xls.php?from=<?php echo urlencode($from); ?>&to=<?php echo urlencode($to); ?>"><i class="fa fa-file-excel"></i></a>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered table-hover mb-0 text-center align-middle">
                <thead>
                    <tr class="text-white">
                        <th scope="col">Order ID</th>
                        <th scope="col">TRN#</th>
                        <th scope="col">Date</th>
                        <th scope="col">Customer</th>
                        <th scope="col">Payment</th>
                        <th scope="col">Status</th>
                        <th scope="col">Subtotal</th>
                        <th scope="col">Delivery Fee</th>
                        <th scope="col">Total</th>
                    </tr>
                </thead>
                <tbody id="salesTableBody">
                    <?php if (count($sales) === 0): ?>
                    <tr><td colspan="9" class="text-center">No completed sales in selected range.</td></tr>
                    <?php else: foreach ($sales as $s): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($s['id']); ?></td>
                        <td><?php echo htmlspecialchars($s['transaction_number'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars(date('Y/m/d h:i A', strtotime($s['order_date']))); ?></td>
                        <td><?php echo htmlspecialchars(trim(($s['first_name'] ?? '').' '.($s['last_name'] ?? ''))); ?></td>
                        <td><?php echo htmlspecialchars(strtoupper($s['payment_method'])); ?></td>
                        <td><?php echo htmlspecialchars(ucfirst($s['order_status'])); ?></td>
                        <td>₱<?php echo number_format((float)$s['total_price'], 2); ?></td>
                        <td>₱<?php echo number_format((float)$s['delivery_fee'], 2); ?></td>
                        <td>₱<?php echo number_format((float)$s['total_with_delivery'], 2); ?></td>
                    </tr>
                    <?php endforeach; endif; ?>              
                </tbody>
            </table>
        </div> 
        <div class="d-flex justify-content-center mt-3" id="salesPagination">
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php
                    $base = 'Admin-SalesReport.php?from=' . urlencode($from) . '&to=' . urlencode($to) . '&sp=';
                    $prev = max(1, $salesPage - 1);
                    $next = min($salesTotalPages, $salesPage + 1);
                    echo '<li class="page-item'.($salesPage==1?' disabled':'').'"><a class="page-link" href="'.$base.$prev.'">&laquo;</a></li>';
                    for ($p = 1; $p <= $salesTotalPages; $p++) {
                        echo '<li class="page-item'.($p==$salesPage?' active':'').'"><a class="page-link" href="'.$base.$p.'">'.$p.'</a></li>';
                    }
                    echo '<li class="page-item'.($salesPage==$salesTotalPages?' disabled':'').'"><a class="page-link" href="'.$base.$next.'">&raquo;</a></li>';
                    ?>
                </ul>
            </nav>
        </div>
        <div class="d-flex justify-content-end mt-3">
            <div class="text-end" id="salesTotals">
                <div class="text-white">Transactions: <?php echo (int)$totals['count']; ?></div>
                <div class="text-white">Subtotal: ₱<?php echo number_format($totals['gross'], 2); ?></div>
                <div class="text-white">Delivery Fees: ₱<?php echo number_format($totals['delivery'], 2); ?></div>
                <div class="fw-bold text-success">Total: ₱<?php echo number_format($totals['with_delivery'], 2); ?></div>
            </div>
        </div>
    </div>                
</div> 

<script>
// Initialize Tempus Dominus pickers with linked range behavior
document.addEventListener('DOMContentLoaded', function(){
    if (window.$ && $.fn.datetimepicker) {
        $('#fromPicker').datetimepicker({
            format: 'YYYY-MM-DD',
            useCurrent: false,
            icons: { time: 'fa fa-clock', previous: 'fa fa-chevron-left', next: 'fa fa-chevron-right' }
        });
        $('#toPicker').datetimepicker({
            format: 'YYYY-MM-DD',
            useCurrent: false,
            icons: { time: 'fa fa-clock', previous: 'fa fa-chevron-left', next: 'fa fa-chevron-right' }
        });
        $('#fromPicker').on('change.datetimepicker', function(e){
            $('#toPicker').datetimepicker('minDate', e.date);
        });
        $('#toPicker').on('change.datetimepicker', function(e){
            $('#fromPicker').datetimepicker('maxDate', e.date);
        });

        // Init log pickers
        $('#logFromPicker').datetimepicker({
            format: 'YYYY-MM-DD',
            useCurrent: false,
            icons: { time: 'fa fa-clock', previous: 'fa fa-chevron-left', next: 'fa fa-chevron-right' }
        });
        $('#logToPicker').datetimepicker({
            format: 'YYYY-MM-DD',
            useCurrent: false,
            icons: { time: 'fa fa-clock', previous: 'fa fa-chevron-left', next: 'fa fa-chevron-right' }
        });
        $('#logFromPicker').on('change.datetimepicker', function(e){
            $('#logToPicker').datetimepicker('minDate', e.date);
        });
        $('#logToPicker').on('change.datetimepicker', function(e){
            $('#logFromPicker').datetimepicker('maxDate', e.date);
        });
    }
});
</script>
<script>
// Lightweight polling to update tables every 5s
document.addEventListener('DOMContentLoaded', function(){
    function renderSales(data){
        const tbody = document.getElementById('salesTableBody');
        if (!tbody || !data) return;
        if (!data.rows || data.rows.length === 0){
            tbody.innerHTML = '<tr><td colspan="9" class="text-center">No completed sales in selected range.</td></tr>';
        } else {
            tbody.innerHTML = data.rows.map(r => `
                <tr>
                    <td>${r.id}</td>
                    <td>${r.trn}</td>
                    <td>${r.date}</td>
                    <td>${r.customer}</td>
                    <td>${r.payment}</td>
                    <td>${r.status}</td>
                    <td>₱${r.subtotal}</td>
                    <td>₱${r.delivery_fee}</td>
                    <td>₱${r.total}</td>
                </tr>`).join('');
        }
        const totals = document.getElementById('salesTotals');
        if (totals && data.totals){
            totals.innerHTML = `
                <div class="text-white">Transactions: ${data.totals.transactions}</div>
                <div class="text-white">Subtotal: ₱${data.totals.gross}</div>
                <div class="text-white">Delivery Fees: ₱${data.totals.delivery}</div>
                <div class="fw-bold text-success">Total: ₱${data.totals.with_delivery}</div>`;
        }
        const pag = document.getElementById('salesPagination');
        if (pag){
            const base = `Admin-SalesReport.php?from=${encodeURIComponent(getFrom())}&to=${encodeURIComponent(getTo())}&sp=`;
            let html = '<nav><ul class="pagination pagination-sm mb-0">';
            const prev = Math.max(1, data.page - 1);
            const next = Math.min(data.totalPages, data.page + 1);
            html += `<li class="page-item ${data.page==1?'disabled':''}"><a class="page-link" href="${base+prev}">&laquo;</a></li>`;
            for (let p=1;p<=data.totalPages;p++){
                html += `<li class="page-item ${p==data.page?'active':''}"><a class="page-link" href="${base+p}">${p}</a></li>`;
            }
            html += `<li class="page-item ${data.page==data.totalPages?'disabled':''}"><a class="page-link" href="${base+next}">&raquo;</a></li>`;
            html += '</ul></nav>';
            pag.innerHTML = html;
        }
    }

    function renderLogs(data){
        const tbody = document.getElementById('logsTableBody');
        if (!tbody || !data) return;
        if (!data.rows || data.rows.length === 0){
            tbody.innerHTML = '<tr><td colspan="5" class="text-center">No duty reports yet.</td></tr>';
        } else {
            tbody.innerHTML = data.rows.map(r => `
                <tr>
                    <td>${r.cashier}</td>
                    <td>${r.time_in}</td>
                    <td>${r.time_out}</td>
                    <td>${r.duration}</td>
                    <td><a class="btn btn-success btn-sm" href="export_sales_report_detailed_xls.php?from_dt=${encodeURIComponent(r.from_dt)}&to_dt=${encodeURIComponent(r.to_dt)}">Export Excel</a></td>
                </tr>`).join('');
        }
        const pag = document.getElementById('logsPagination');
        if (pag){
            const lf = document.querySelector('input[name="lf"]').value;
            const lt = document.querySelector('input[name="lt"]').value;
            const base = `Admin-SalesReport.php?lf=${encodeURIComponent(lf)}&lt=${encodeURIComponent(lt)}&lp=`;
            let html = '<nav><ul class="pagination pagination-sm mb-0">';
            const prev = Math.max(1, data.page - 1);
            const next = Math.min(data.totalPages, data.page + 1);
            html += `<li class="page-item ${data.page==1?'disabled':''}"><a class="page-link" href="${base+prev}">&laquo;</a></li>`;
            for (let p=1;p<=data.totalPages;p++){
                html += `<li class="page-item ${p==data.page?'active':''}"><a class="page-link" href="${base+p}">${p}</a></li>`;
            }
            html += `<li class="page-item ${data.page==data.totalPages?'disabled':''}"><a class="page-link" href="${base+next}">&raquo;</a></li>`;
            html += '</ul></nav>';
            pag.innerHTML = html;
        }
    }

    function getFrom(){ return document.querySelector('input[name="from"]').value; }
    function getTo(){ return document.querySelector('input[name="to"]').value; }

    function refresh(){
        const paramsSales = new URLSearchParams({from:getFrom(), to:getTo(), page: getParam('sp',1), perPage: 10});
        fetch('get_sales_report.php?'+paramsSales.toString())
            .then(r=>r.json()).then(renderSales).catch(()=>{});

        const lf = document.querySelector('input[name="lf"]').value;
        const lt = document.querySelector('input[name="lt"]').value;
        const paramsLogs = new URLSearchParams({from:lf, to:lt, page:getParam('lp',1), perPage: 5});
        fetch('get_cashier_duty_logs.php?'+paramsLogs.toString())
            .then(r=>r.json()).then(renderLogs).catch(()=>{});
    }

    function getParam(name, def){
        const url = new URL(window.location.href);
        return url.searchParams.get(name) ? parseInt(url.searchParams.get(name),10) : def;
    }

    refresh();
    setInterval(refresh, 5000);
});
</script>

<!-- Duty Reports Section: recent cashier sessions with exports -->
<div class="container-fluid pt-4 px-4">
    <div class="bg-secondary text-center rounded p-4">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h6 class="mb-0">Cashier Duty Reports</h6>
            <form class="d-flex align-items-center" method="get" action="Admin-SalesReport.php" style="gap:8px">
                <span class="text-white-50 small">From:</span>
                <div class="input-group input-group-sm date" id="logFromPicker" data-target-input="nearest" style="width: 190px;">
                    <button class="input-group-text bg-dark text-white border-0" type="button" data-toggle="datetimepicker" data-target="#logFromPicker"><i class="fa fa-calendar-alt"></i></button>
                    <input type="text" name="lf" class="form-control form-control-sm bg-dark text-white border-0 datetimepicker-input" data-target="#logFromPicker" placeholder="From" value="<?php echo htmlspecialchars(isset($_GET['lf'])?$_GET['lf']:date('Y-m-d')); ?>"/>
            </div>
                <span class="text-white-50 small">To:</span>
                <div class="input-group input-group-sm date" id="logToPicker" data-target-input="nearest" style="width: 190px;">
                    <button class="input-group-text bg-dark text-white border-0" type="button" data-toggle="datetimepicker" data-target="#logToPicker"><i class="fa fa-calendar-alt"></i></button>
                    <input type="text" name="lt" class="form-control form-control-sm bg-dark text-white border-0 datetimepicker-input" data-target="#logToPicker" placeholder="To" value="<?php echo htmlspecialchars(isset($_GET['lt'])?$_GET['lt']:date('Y-m-d')); ?>"/>
                </div>
                <button class="btn btn-primary btn-sm px-2 py-1" type="submit"><i class="fa fa-filter"></i></button>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered table-hover mb-0 text-center align-middle">
                    <thead>
                    <tr class="text-white">
                        <th>Cashier</th>
                        <th>Time In</th>
                        <th>Time Out</th>
                        <th>Duration</th>
                        <th>Export</th>
                        </tr>
                    </thead>
                <tbody id="logsTableBody">
                <?php
                // Filters and paging for logs
                $lf = isset($_GET['lf']) ? $_GET['lf'] : date('Y-m-d');
                $lt = isset($_GET['lt']) ? $_GET['lt'] : date('Y-m-d');
                if (strtotime($lt) < strtotime($lf)) { $lt = $lf; }
                $logPerPage = 5;
                $logPage = isset($_GET['lp']) ? max(1, (int)$_GET['lp']) : 1;
                $logOffset = ($logPage - 1) * $logPerPage;

                $sqlLogs = "SELECT l.id, l.staff_id, l.time_in, l.time_out, l.duty_duration_minutes,
                                   cj.first_name, cj.last_name
                            FROM staff_logs l
                            LEFT JOIN cjusers cj ON cj.id = l.staff_id
                            WHERE l.role = 'Cashier' AND l.time_out IS NOT NULL
                              AND DATE(l.time_in) BETWEEN ? AND ?
                            ORDER BY l.time_out DESC
                            LIMIT ? OFFSET ?";
                if ($logsStmt = $conn->prepare($sqlLogs)) {
                    $logsStmt->bind_param('ssii', $lf, $lt, $logPerPage, $logOffset);
                    $logsStmt->execute();
                    $logsRes = $logsStmt->get_result();
                    if ($logsRes->num_rows === 0) {
                        echo '<tr><td colspan="5" class="text-center">No duty reports yet.</td></tr>';
                    }
                    while ($log = $logsRes->fetch_assoc()) {
                        $cashierName = htmlspecialchars(trim(($log['first_name'] ?? '').' '.($log['last_name'] ?? '')));
                        $timeIn = htmlspecialchars($log['time_in']);
                        $timeOut = htmlspecialchars($log['time_out']);
                        $duration = (int)($log['duty_duration_minutes'] ?? 0);
                        $h = floor($duration/60); $m = $duration%60;
                        $exportUrl = 'export_sales_report_detailed_xls.php?from_dt=' . urlencode($log['time_in']) . '&to_dt=' . urlencode($log['time_out']);
                        echo '<tr>';
                        echo '<td>'.$cashierName.'</td>';
                        echo '<td>'.date('Y/m/d h:i A', strtotime($timeIn)).'</td>';
                        echo '<td>'.date('Y/m/d h:i A', strtotime($timeOut)).'</td>';
                        echo '<td>'.sprintf('%02dh %02dm', $h, $m).'</td>';
                        echo '<td><a class="btn btn-success btn-sm" href="'.$exportUrl.'">Export Excel</a></td>';
                        echo '</tr>';
                    }
                    $logsRes->close();
                    $logsStmt->close();
                }
                // Count logs for pagination
                $logCount = 0;
                if ($cntStmt = $conn->prepare("SELECT COUNT(*) AS c FROM staff_logs l WHERE l.role='Cashier' AND l.time_out IS NOT NULL AND DATE(l.time_in) BETWEEN ? AND ?")) {
                    $cntStmt->bind_param('ss', $lf, $lt);
                    $cntStmt->execute();
                    $r = $cntStmt->get_result()->fetch_assoc();
                    $logCount = (int)$r['c'];
                    $cntStmt->close();
                }
                $logTotalPages = max(1, (int)ceil($logCount / $logPerPage));
                ?>
                    </tbody>
                </table>
            </div>
        <div class="d-flex justify-content-center mt-3" id="logsPagination">
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php
                    $logBase = 'Admin-SalesReport.php?lf=' . urlencode($lf) . '&lt=' . urlencode($lt) . '&lp=';
                    $prevL = max(1, $logPage - 1);
                    $nextL = min($logTotalPages, $logPage + 1);
                    echo '<li class="page-item'.($logPage==1?' disabled':'').'"><a class="page-link" href="'.$logBase.$prevL.'">&laquo;</a></li>';
                    for ($p = 1; $p <= $logTotalPages; $p++) {
                        echo '<li class="page-item'.($p==$logPage?' active':'').'"><a class="page-link" href="'.$logBase.$p.'">'.$p.'</a></li>';
                    }
                    echo '<li class="page-item'.($logPage==$logTotalPages?' disabled':'').'"><a class="page-link" href="'.$logBase.$nextL.'">&raquo;</a></li>';
                    ?>
                </ul>
            </nav>
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
<!--Footer End-->


</div>
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
 <script src="js/main.js">
 </script>
</body>
</html> 
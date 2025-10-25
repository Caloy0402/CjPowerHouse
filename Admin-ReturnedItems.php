<?php
session_start();
require_once 'dbconn.php';

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

// Fetch returned orders with user, transaction, and aggregated totals
$sql = "SELECT 
            o.id AS order_id,
            o.order_date,
            o.order_status,
            o.payment_method,
            o.reason,
            o.user_id,
            u.first_name,
            u.last_name,
            u.ImagePath,
            t.transaction_number,
            COALESCE(o.total_amount_with_delivery, o.total_price) AS order_total
        FROM orders o
        LEFT JOIN users u ON u.id = o.user_id
        LEFT JOIN transactions t ON t.order_id = o.id
        WHERE o.order_status = 'Returned'
        ORDER BY o.order_date DESC";

$returnedResult = $conn->query($sql);
if (!$returnedResult) {
	$returnedResult = null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Returned Items</title>
    <link href="Image/logo.png" rel="icon">
    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.10.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.4.1/font/bootstrap-icons.css">
    <link href="lib/owlcarousel/assets/owl.carousel.min.css" rel="stylesheet">
    <link href="lib/tempusdominus/css/tempusdominus-bootstrap-4.min.css" rel="stylesheet">
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <style>
        .status-return { background-color: #dc3545; color: #fff; }
        .hidden-column { display:none; }
        @media (min-width: 769px) { .hidden-column { display: table-cell; } }
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
                    <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="fa fa-users me-2"></i>Users
                    </a>
                    <div class="dropdown-menu bg-transparent border-0">
                        <a href="Admin-AddUser.php" class="dropdown-item">Add Users</a>
                        <a href="Admin-ManageUser.php" class="dropdown-item">Manage Users</a>
                    </div>
                </div>

                <div class="nav-item dropdown">
                    <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="fa fa-th me-2"></i>Product
                    </a>
                    <div class="dropdown-menu bg-transparent border-0">
                        <a href="Admin-Stockmanagement.php" class="dropdown-item">Stock Management</a>
                        <a href="Admin-buy-out-item.php" class="dropdown-item">Buy-out Item</a>
                        <a href="Admin-ReturnedItems.php" class="dropdown-item active">Returned Item</a>
                    </div>
                </div>

                <a href="Admin-OrderLogs.php" class="nav-item nav-link"><i class="fa fa-shopping-cart me-2"></i>Order Logs</a>
                <a href="Admin-SalesReport.php" class="nav-item nav-link"><i class="fa fa-file-alt me-2"></i>Sales Report</a>
                <a href="Admin-StaffLogs.php" class="nav-item nav-link"><i class="fa fa-user-clock me-2"></i>Staff Logs</a>
                <a href="Admin-RescueLogs.php" class="nav-item nav-link"><i class="fa fa-tools me-2"></i>Rescue Logs</a>
            </div>
        </nav>
    </div>

    <div class="content">
        <nav class="navbar navbar-expand bg-secondary navbar-dark sticky-top px-4 py-0">
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

        <div class="container-fluid pt-4 px-4">
            <div class="bg-secondary text-center rounded p-4">
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <h6 class="mb-0">Returned Items</h6>
                </div>
                <div class="table-responsive">
                    <table class="table text-center align-middle table-bordered table-hover mb-0">
                        <thead>
                            <tr class="text-white">
                                <th class="hidden-column">Customer</th>
                                <th>Date</th>
                                <th>Transaction #</th>
                                <th>User ID</th>
                                <th>Reason</th>
                                <th>Payment</th>
                                <th>Status</th>
                                <th>Total</th>
                                <th>Items</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($returnedResult && $returnedResult->num_rows > 0): ?>
                            <?php while ($row = $returnedResult->fetch_assoc()): ?>
                                <tr>
                                    <td class="hidden-column">
                                        <img src="<?= htmlspecialchars($row['ImagePath'] ?? '') ?>" alt="Customer Image" class="img-fluid rounded-circle" style="width: 40px; height: 40px;">
                                        <div class="small mt-1"><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></div>
                                    </td>
                                    <td><?= date('M d, Y', strtotime($row['order_date'])) ?></td>
                                    <td><?= htmlspecialchars($row['transaction_number'] ?? 'N/A') ?></td>
                                    <td><?= (int)$row['user_id'] ?></td>
                                    <td class="text-start"><?= nl2br(htmlspecialchars($row['reason'] ?? 'N/A')) ?></td>
                                    <td><?= htmlspecialchars($row['payment_method']) ?></td>
                                    <td><span class="badge status-return">Returned</span></td>
                                    <td>₱<?= number_format((float)$row['order_total'], 2) ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#itemsModal" data-order-id="<?= (int)$row['order_id'] ?>">View</button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="9" class="text-center">No returned orders found</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="container-fluid pt-4 px-4">
            <div class="bg-secondary rounded-top p-4">
                <div class="row">
                    <div class="col-12 col-sm-6 text-center text-sm-start">
                        &copy; <a href="#">Cj PowerHouse</a>, All Right Reserved.
                    </div> 
                    <div class="col-12 col-sm-6 text-center text-sm-end">
                        Design By: <a href="#">Team Jandi</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Items Modal -->
<div class="modal fade" id="itemsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fa fa-box me-2"></i>Returned Order Items</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped text-center mb-0">
                        <thead>
                            <tr>
                                <th>Product ID</th>
                                <th>Name</th>
                                <th>Quantity</th>
                                <th>Price</th>
                                <th>Subtotal</th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody">
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="4" class="text-end">Total</th>
                                <th id="itemsTotal">₱0.00</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="d-flex justify-content-end mt-3">
                    <button id="restockBtn" class="btn btn-success">
                        <i class="fa fa-undo me-2"></i>Restock Item
                    </button>
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
<script>
document.addEventListener('DOMContentLoaded', function() {
    const itemsModal = document.getElementById('itemsModal');
    if (!itemsModal) return;

    itemsModal.addEventListener('show.bs.modal', function(event) {
        const button = event.relatedTarget;
        const orderId = button.getAttribute('data-order-id');
        const tbody = document.getElementById('itemsBody');
        const totalCell = document.getElementById('itemsTotal');
        const restockBtn = document.getElementById('restockBtn');
        tbody.innerHTML = '<tr><td colspan="5">Loading...</td></tr>';
        totalCell.textContent = '₱0.00';

        fetch('fetch_return_items.php?order_id=' + encodeURIComponent(orderId))
            .then(r => r.json())
            .then(data => {
                tbody.innerHTML = '';
                let total = 0;
                if (Array.isArray(data.items) && data.items.length) {
                    data.items.forEach(it => {
                        const subtotal = parseFloat(it.price) * parseFloat(it.quantity);
                        total += subtotal;
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td>${it.product_id}</td>
                            <td>${it.product_name}</td>
                            <td>${it.quantity}</td>
                            <td>₱${Number(it.price).toFixed(2)}</td>
                            <td>₱${subtotal.toFixed(2)}</td>
                        `;
                        tbody.appendChild(tr);
                    });
                } else {
                    tbody.innerHTML = '<tr><td colspan="5">No items found</td></tr>';
                }
                totalCell.textContent = '₱' + total.toFixed(2);
            })
            .catch(() => {
                tbody.innerHTML = '<tr><td colspan="5">Error loading items</td></tr>';
            });

        // Check if already restocked
        restockBtn.disabled = true;
        restockBtn.textContent = 'Checking...';
        fetch('restock_return_order.php?action=check&order_id=' + encodeURIComponent(orderId))
            .then(r => r.json())
            .then(d => {
                if (d && d.success) {
                    if (d.restocked) {
                        restockBtn.disabled = true;
                        restockBtn.textContent = 'Already Restocked';
                    } else {
                        restockBtn.disabled = false;
                        restockBtn.textContent = 'Restock Item';
                        restockBtn.onclick = function() {
                            restockBtn.disabled = true;
                            restockBtn.textContent = 'Restocking...';
                            fetch('restock_return_order.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: 'order_id=' + encodeURIComponent(orderId)
                            })
                            .then(r => r.json())
                            .then(res => {
                                if (res.success) {
                                    restockBtn.textContent = 'Restocked';
                                } else {
                                    restockBtn.disabled = false;
                                    restockBtn.textContent = 'Restock Item';
                                    alert(res.message || 'Failed to restock');
                                }
                            })
                            .catch(() => {
                                restockBtn.disabled = false;
                                restockBtn.textContent = 'Restock Item';
                                alert('Network error while restocking');
                            });
                        };
                    }
                } else {
                    restockBtn.disabled = false;
                    restockBtn.textContent = 'Restock Item';
                }
            })
            .catch(() => {
                restockBtn.disabled = false;
                restockBtn.textContent = 'Restock Item';
            });
    });
});
</script>
</body>
</html>



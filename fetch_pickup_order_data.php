<?php
require 'dbconn.php';

// Get filter and search parameters
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Base SQL query for pickup orders (exclude cancelled and completed orders)
$sql = "SELECT o.id, t.transaction_number, o.order_date,
               u.first_name, u.last_name, o.order_status,
               o.total_price, o.payment_method,
               u.ImagePath, u.phone_number, u.email
        FROM orders o
        LEFT JOIN transactions t ON o.id = t.order_id
        JOIN users u ON o.user_id = u.id
        WHERE o.delivery_method = 'pickup' AND o.order_status != 'Cancelled' AND o.order_status != 'Completed'";

// Add filter conditions
if ($filter === 'pending') {
    $sql .= " AND o.order_status = 'Pending'";
} elseif ($filter === 'ready') {
    $sql .= " AND o.order_status = 'Ready to Ship'";
}

// Add search conditions
if (!empty($search)) {
    $sql .= " AND (CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR o.id LIKE ? OR t.transaction_number LIKE ?)";
}

$sql .= " ORDER BY o.order_date DESC";

$stmt = $conn->prepare($sql);

if (!empty($search)) {
    $searchParam = '%' . $search . '%';
    $stmt->bind_param("sss", $searchParam, $searchParam, $searchParam);
} else {
    // No parameters needed if no search
}

$stmt->execute();
$result = $stmt->get_result();

$output = '';

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $statusClass = '';
        $statusBadge = '';
        
        switch ($row['order_status']) {
            case 'Pending':
                $statusClass = 'pickup-pending';
                $statusBadge = '<span class="badge bg-warning">Pending</span>';
                break;
            case 'Ready to Ship':
                $statusClass = 'pickup-ready';
                $statusBadge = '<span class="badge bg-success">Ready for Pickup</span>';
                break;
            default:
                $statusBadge = '<span class="badge bg-secondary">' . htmlspecialchars($row['order_status']) . '</span>';
        }
        
        $customerName = htmlspecialchars($row['first_name'] . ' ' . $row['last_name']);
        $totalAmount = '₱' . number_format($row['total_price'], 2);
        $orderTs = strtotime($row['order_date']);
        $orderDate = date('M d, Y g:i A', $orderTs);
        // Mark as new if pending and created today
        $isNew = ($row['order_status'] === 'Pending') && (date('Y-m-d', $orderTs) === date('Y-m-d'));
        
        $output .= '<tr class="' . $statusClass . '">
                        <td class="text-center order-id-cell">'
                        . htmlspecialchars($row['id'])
                        . ($isNew ? '<img src="uploads/New.gif" onerror="this.onerror=null;this.src=\'uploads/new.gif\';" alt="New" class="new-corner-badge">' : '')
                        . '</td>
                        <td class="text-center">' . htmlspecialchars($row['transaction_number']) . '</td>
                        <td class="text-center">' . $orderDate . '</td>
                        <td class="text-center">' . $customerName . '</td>
                        <td class="text-center">' . htmlspecialchars($row['payment_method']) . '</td>
                        <td class="text-center">' . $totalAmount . '</td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-danger"
                                   data-bs-toggle="modal"
                                   data-bs-target="#pickupOrderModal"
                                   data-order-id="' . htmlspecialchars($row['id']) . '">
                                <i class="fa fa-eye"></i> View Details
                            </button>
                        </td>
                    </tr>';
    }
} else {
    $output = '<tr><td colspan="7" class="text-center">No pickup orders found.</td></tr>';
}

echo $output;

$stmt->close();
$conn->close();
?>

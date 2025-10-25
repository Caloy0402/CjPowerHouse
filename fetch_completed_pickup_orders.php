<?php
require 'dbconn.php';

header('Content-Type: application/json');

// Get date range parameters
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';

if (empty($dateFrom) || empty($dateTo)) {
    echo json_encode(['success' => false, 'message' => 'Date range is required']);
    exit;
}

// Validate date format
if (!DateTime::createFromFormat('Y-m-d', $dateFrom) || !DateTime::createFromFormat('Y-m-d', $dateTo)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date format']);
    exit;
}

// SQL query to fetch completed pickup orders within date range
$sql = "SELECT o.id, t.transaction_number, o.order_date, t.completed_date_transaction,
               u.first_name, u.last_name, o.total_price, o.payment_method,
               u.ImagePath, u.phone_number, u.email
        FROM orders o
        LEFT JOIN transactions t ON o.id = t.order_id
        JOIN users u ON o.user_id = u.id
        WHERE o.delivery_method = 'pickup' 
              AND o.order_status = 'Completed'
              AND DATE(t.completed_date_transaction) BETWEEN ? AND ?
        ORDER BY t.completed_date_transaction DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $dateFrom, $dateTo);
$stmt->execute();
$result = $stmt->get_result();

$html = '';
$totalOrders = 0;
$totalRevenue = 0;

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $totalOrders++;
        $totalRevenue += $row['total_price'];
        
        $customerName = htmlspecialchars($row['first_name'] . ' ' . $row['last_name']);
        $totalAmount = '₱' . number_format($row['total_price'], 2);
        $orderDate = date('M d, Y g:i A', strtotime($row['order_date']));
        $completedDate = date('M d, Y g:i A', strtotime($row['completed_date_transaction']));
        
        $html .= '<tr>
                    <td class="text-center">' . htmlspecialchars($row['id']) . '</td>
                    <td class="text-center">' . htmlspecialchars($row['transaction_number']) . '</td>
                    <td class="text-center">' . $customerName . '</td>
                    <td class="text-center">' . $totalAmount . '</td>
                    <td class="text-center">' . $orderDate . '</td>
                    <td class="text-center">' . $completedDate . '</td>
                    <td class="text-center">
                        <button class="btn btn-sm btn-info"
                               data-order-id="' . htmlspecialchars($row['id']) . '"
                               onclick="viewCompletedOrderDetails(' . intval($row['id']) . ')">
                            <i class="fa fa-eye"></i> View Details
                        </button>
                    </td>
                  </tr>';
    }
    
    echo json_encode([
        'success' => true,
        'html' => $html,
        'total_orders' => $totalOrders,
        'total_revenue' => $totalRevenue
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'No completed orders found for the selected date range'
    ]);
}

$stmt->close();
$conn->close();
?>

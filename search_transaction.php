<?php
require 'dbconn.php';

header('Content-Type: application/json');

// Get transaction number parameter
$transactionNumber = isset($_GET['transaction_number']) ? trim($_GET['transaction_number']) : '';

if (empty($transactionNumber)) {
    echo json_encode(['success' => false, 'message' => 'Transaction number is required']);
    exit;
}

// SQL query to search for completed pickup orders by transaction number
$sql = "SELECT o.id, t.transaction_number, o.order_date, t.completed_date_transaction,
               u.first_name, u.last_name, o.total_price, o.payment_method,
               u.ImagePath, u.phone_number, u.email
        FROM orders o
        LEFT JOIN transactions t ON o.id = t.order_id
        JOIN users u ON o.user_id = u.id
        WHERE o.delivery_method = 'pickup' 
              AND o.order_status = 'Completed'
              AND t.transaction_number LIKE ?
        ORDER BY t.completed_date_transaction DESC";

$searchParam = '%' . $transactionNumber . '%';
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $searchParam);
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
        'message' => 'No completed orders found with transaction number: ' . htmlspecialchars($transactionNumber)
    ]);
}

$stmt->close();
$conn->close();
?>

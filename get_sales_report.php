<?php
session_start();
require_once 'dbconn.php';
header('Content-Type: application/json');

$from = isset($_GET['from']) ? $_GET['from'] : date('Y-m-d');
$to   = isset($_GET['to'])   ? $_GET['to']   : date('Y-m-d');
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['perPage']) ? max(1, (int)$_GET['perPage']) : 10;
$offset = ($page - 1) * $perPage;

$rows = [];
$totals = ['count'=>0,'gross'=>0.0,'delivery'=>0.0,'with_delivery'=>0.0];

$sql = "SELECT o.id, o.order_date, o.total_price, o.payment_method, o.order_status,
               COALESCE(o.delivery_fee, 0) AS delivery_fee,
               COALESCE(o.total_amount_with_delivery, o.total_price) AS total_with_delivery,
               u.first_name, u.last_name,
               t.transaction_number, t.completed_date_transaction
        FROM orders o
        LEFT JOIN users u ON u.id = o.user_id
        LEFT JOIN transactions t ON t.order_id = o.id
        WHERE DATE(t.completed_date_transaction) BETWEEN ? AND ?
          AND LOWER(o.order_status) IN ('completed','paid')
        ORDER BY t.completed_date_transaction DESC
        LIMIT ? OFFSET ?";

if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param('ssii', $from, $to, $perPage, $offset);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $rows[] = [
            'id' => (int)$r['id'],
            'trn' => $r['transaction_number'] ?? '',
            'date' => date('Y/m/d h:i A', strtotime($r['completed_date_transaction'] ?: $r['order_date'])),
            'customer' => trim(($r['first_name'] ?? '').' '.($r['last_name'] ?? '')),
            'payment' => strtoupper($r['payment_method']),
            'status' => ucfirst($r['order_status']),
            'subtotal' => number_format((float)$r['total_price'], 2),
            'delivery_fee' => number_format((float)$r['delivery_fee'], 2),
            'total' => number_format((float)$r['total_with_delivery'], 2)
        ];
        $totals['count'] += 1;
        $totals['gross'] += (float)$r['total_price'];
        $totals['delivery'] += (float)$r['delivery_fee'];
        $totals['with_delivery'] += (float)$r['total_with_delivery'];
    }
    $stmt->close();
}

$totalRows = 0;
if ($cst = $conn->prepare("SELECT COUNT(*) AS cnt FROM orders o JOIN transactions t ON t.order_id = o.id WHERE DATE(t.completed_date_transaction) BETWEEN ? AND ? AND LOWER(o.order_status) IN ('completed','paid')")) {
    $cst->bind_param('ss', $from, $to);
    $cst->execute();
    $cr = $cst->get_result();
    if ($row = $cr->fetch_assoc()) { $totalRows = (int)$row['cnt']; }
    $cst->close();
}

echo json_encode([
    'rows' => $rows,
    'totals' => [
        'transactions' => $totals['count'],
        'gross' => number_format($totals['gross'], 2),
        'delivery' => number_format($totals['delivery'], 2),
        'with_delivery' => number_format($totals['with_delivery'], 2)
    ],
    'page' => $page,
    'perPage' => $perPage,
    'totalRows' => $totalRows,
    'totalPages' => max(1, (int)ceil($totalRows / $perPage))
]);
exit;
?>

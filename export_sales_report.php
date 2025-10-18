<?php
session_start();
require_once 'dbconn.php';

// Optional: Only allow Admin or Cashier
if (!isset($_SESSION['role']) || !in_array(ucfirst(strtolower($_SESSION['role'])), ['Admin','Cashier'])) {
    http_response_code(403);
    echo 'Unauthorized';
    exit;
}

$from       = isset($_GET['from']) ? $_GET['from'] : null;       // YYYY-MM-DD
$to         = isset($_GET['to'])   ? $_GET['to']   : null;       // YYYY-MM-DD
$from_dt    = isset($_GET['from_dt']) ? $_GET['from_dt'] : null; // YYYY-MM-DD HH:MM:SS
$to_dt      = isset($_GET['to_dt'])   ? $_GET['to_dt']   : null; // YYYY-MM-DD HH:MM:SS

if ($from && $to && strtotime($to) < strtotime($from)) { $to = $from; }
if ($from_dt && $to_dt && strtotime($to_dt) < strtotime($from_dt)) { $to_dt = $from_dt; }

header('Content-Type: text/csv; charset=utf-8');
// Build filename
$filename = 'sales_report_';
if ($from_dt && $to_dt) {
    $filename .= str_replace([' ',':'], '-', $from_dt) . '_to_' . str_replace([' ',':'], '-', $to_dt);
} else {
    $from = $from ?: date('Y-m-d');
    $to = $to ?: date('Y-m-d');
    $filename .= $from . '_to_' . $to;
}
header('Content-Disposition: attachment; filename=' . $filename . '.csv');

$output = fopen('php://output', 'w');
// Write UTF-8 BOM so Excel/WPS detects encoding correctly
fwrite($output, "\xEF\xBB\xBF");

// Header row
fputcsv($output, ['Order ID','Transaction #','Date','Customer','Payment','Status','Subtotal','Delivery Fee','Total']);

// Use transaction completion date for consistency with sales report
$bindTypes = 'ss';
$bindValues = [$from ?: date('Y-m-d'), $to ?: date('Y-m-d')];

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
        ORDER BY t.completed_date_transaction DESC";

if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param($bindTypes, ...$bindValues);
    $stmt->execute();
    $result = $stmt->get_result();
    $txCount = 0;
    $sumSubtotal = 0.0;
    $sumDelivery = 0.0;
    $sumTotal = 0.0;
    while ($row = $result->fetch_assoc()) {
        $txCount++;
        $sumSubtotal += (float)$row['total_price'];
        $sumDelivery += (float)$row['delivery_fee'];
        $sumTotal += (float)($row['total_with_delivery'] ?? $row['total_price']);
        fputcsv($output, [
            $row['id'],
            $row['transaction_number'],
            date('Y/m/d h:i A', strtotime($row['order_date'])),
            trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
            strtoupper($row['payment_method']),
            ucfirst($row['order_status']),
            number_format((float)$row['total_price'], 2, '.', ''),
            number_format((float)$row['delivery_fee'], 2, '.', ''),
            number_format((float)($row['total_with_delivery'] ?? $row['total_price']), 2, '.', '')
        ]);
    }
    $stmt->close();
    // Append summary rows similar to on-screen totals
    fputcsv($output, []);
    fputcsv($output, ['', '', '', '', '', 'Transactions', $txCount]);
    fputcsv($output, ['', '', '', '', '', 'Subtotal', '₱' . number_format($sumSubtotal, 2, '.', '')]);
    fputcsv($output, ['', '', '', '', '', 'Delivery Fees', '₱' . number_format($sumDelivery, 2, '.', '')]);
    fputcsv($output, ['', '', '', '', '', 'Total', '₱' . number_format($sumTotal, 2, '.', '')]);
}

fclose($output);
exit;
?>

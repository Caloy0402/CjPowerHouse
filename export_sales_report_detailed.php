<?php
session_start();
require_once 'dbconn.php';

// Allow Admin or Cashier roles
if (!isset($_SESSION['role']) || !in_array(ucfirst(strtolower($_SESSION['role'])), ['Admin','Cashier'])) {
    http_response_code(403);
    echo 'Unauthorized';
    exit;
}

// Inputs: either date range (YYYY-MM-DD) or exact datetime window (YYYY-MM-DD HH:MM:SS)
$from       = isset($_GET['from']) ? $_GET['from'] : null;
$to         = isset($_GET['to'])   ? $_GET['to']   : null;
$from_dt    = isset($_GET['from_dt']) ? $_GET['from_dt'] : null;
$to_dt      = isset($_GET['to_dt'])   ? $_GET['to_dt']   : null;

if ($from && $to && strtotime($to) < strtotime($from)) { $to = $from; }
if ($from_dt && $to_dt && strtotime($to_dt) < strtotime($from_dt)) { $to_dt = $from_dt; }

header('Content-Type: text/csv; charset=utf-8');

// Build filename
$filename = 'sales_report_detailed_';
if ($from_dt && $to_dt) {
    $filename .= str_replace([' ',':'], '-', $from_dt) . '_to_' . str_replace([' ',':'], '-', $to_dt);
} else {
    $from = $from ?: date('Y-m-d');
    $to = $to ?: date('Y-m-d');
    $filename .= $from . '_to_' . $to;
}
header('Content-Disposition: attachment; filename=' . $filename . '.csv');

$out = fopen('php://output', 'w');
// UTF-8 BOM for Excel/WPS
fwrite($out, "\xEF\xBB\xBF");

// Header row (remove per-item order-level totals; keep only line totals)
fputcsv($out, [
    'Order ID',
    'Transaction #',
    'Date',
    'Customer',
    'Payment',
    'Product',
    'Category',
    'Quantity',
    'Unit Price'
]);

// Use transaction completion date for consistency with sales report
$bindTypes = 'ss';
$bindValues = [$from ?: date('Y-m-d'), $to ?: date('Y-m-d')];

$sql = "SELECT 
            o.id AS order_id,
            o.order_date,
            COALESCE(o.delivery_fee, 0) AS delivery_fee,
            COALESCE(o.total_amount_with_delivery, o.total_price) AS total_with_delivery,
            o.total_price,
            o.payment_method,
            o.order_status,
            u.first_name, u.last_name,
            t.transaction_number,
            COALESCE(oi.quantity, 0) AS quantity,
            COALESCE(oi.price, 0) AS unit_price,
            CASE 
                WHEN p.ProductName IS NOT NULL THEN p.ProductName
                WHEN oi.product_id IS NOT NULL THEN CONCAT('Product ID: ', oi.product_id)
                ELSE 'No Items'
            END AS product_name,
            CASE 
                WHEN p.Category IS NOT NULL THEN p.Category
                WHEN oi.product_id IS NOT NULL THEN 'Unknown Category'
                ELSE 'No Category'
            END AS category
        FROM orders o
        LEFT JOIN users u ON u.id = o.user_id
        LEFT JOIN transactions t ON t.order_id = o.id
        LEFT JOIN order_items oi ON oi.order_id = o.id
        LEFT JOIN products p ON p.ProductID = oi.product_id
        WHERE DATE(t.completed_date_transaction) BETWEEN ? AND ?
          AND LOWER(o.order_status) IN ('completed','paid')
        ORDER BY t.completed_date_transaction DESC, o.id ASC, oi.id ASC";

if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param($bindTypes, ...$bindValues);
    $stmt->execute();
    $res = $stmt->get_result();

    $txCount = 0;
    $sumSubtotal = 0.0;
    $sumDelivery = 0.0;
    $sumTotal = 0.0;

    // We will count unique orders for summary
    $seenOrders = [];

    while ($r = $res->fetch_assoc()) {
        $orderId = (int)$r['order_id'];
        if (!isset($seenOrders[$orderId])) {
            $seenOrders[$orderId] = true;
            $txCount++;
            $sumSubtotal += (float)$r['total_price'];
            $sumDelivery += (float)$r['delivery_fee'];
            $sumTotal += (float)$r['total_with_delivery'];
        }

        $lineTotal = (float)$r['unit_price'] * (int)$r['quantity'];

        fputcsv($out, [
            $orderId,
            $r['transaction_number'],
            date('Y/m/d h:i A', strtotime($r['order_date'])),
            trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
            strtoupper($r['payment_method']),
            $r['product_name'],
            $r['category'],
            (int)$r['quantity'],
            number_format((float)$r['unit_price'], 2, '.', '')
        ]);
    }
    $stmt->close();

    // Summary rows
    fputcsv($out, []);
    fputcsv($out, ['', '', '', '', '', 'Transactions', '', '', '', '', '', $txCount]);
    fputcsv($out, ['', '', '', '', '', 'Subtotal', '', '', '', '', '', '₱' . number_format($sumSubtotal, 2, '.', '')]);
    fputcsv($out, ['', '', '', '', '', 'Delivery Fees', '', '', '', '', '', '₱' . number_format($sumDelivery, 2, '.', '')]);
    fputcsv($out, ['', '', '', '', '', 'Total', '', '', '', '', '', '₱' . number_format($sumTotal, 2, '.', '')]);
}

fclose($out);
exit;
?>



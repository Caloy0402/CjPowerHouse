<?php
session_start();
require_once 'dbconn.php';

if (!isset($_SESSION['role']) || !in_array(ucfirst(strtolower($_SESSION['role'])), ['Admin','Cashier'])) {
    http_response_code(403);
    echo 'Unauthorized';
    exit;
}

$from       = isset($_GET['from']) ? $_GET['from'] : null;
$to         = isset($_GET['to'])   ? $_GET['to']   : null;
$from_dt    = isset($_GET['from_dt']) ? $_GET['from_dt'] : null;
$to_dt      = isset($_GET['to_dt'])   ? $_GET['to_dt']   : null;

if ($from && $to && strtotime($to) < strtotime($from)) { $to = $from; }
if ($from_dt && $to_dt && strtotime($to_dt) < strtotime($from_dt)) { $to_dt = $from_dt; }

$filename = 'sales_report_detailed_';
if ($from_dt && $to_dt) {
    $filename .= str_replace([' ',':'], '-', $from_dt) . '_to_' . str_replace([' ',':'], '-', $to_dt);
} else {
    $from = $from ?: date('Y-m-d');
    $to = $to ?: date('Y-m-d');
    $filename .= $from . '_to_' . $to;
}

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename=' . $filename . '.xls');
echo "\xEF\xBB\xBF"; // UTF-8 BOM

$where = '';
$bindTypes = '';
$bindValues = [];
if ($from_dt && $to_dt) {
    $where = 'WHERE o.order_date BETWEEN ? AND ?';
    $bindTypes = 'ss';
    $bindValues = [$from_dt, $to_dt];
} else {
    $where = 'WHERE DATE(o.order_date) BETWEEN ? AND ?';
    $bindTypes = 'ss';
    $bindValues = [$from ?: date('Y-m-d'), $to ?: date('Y-m-d')];
}

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
            oi.quantity,
            oi.price AS unit_price,
            p.ProductName AS product_name,
            p.Category AS category
        FROM orders o
        LEFT JOIN users u ON u.id = o.user_id
        LEFT JOIN transactions t ON t.order_id = o.id
        INNER JOIN order_items oi ON oi.order_id = o.id
        LEFT JOIN products p ON p.ProductID = oi.product_id
        $where
          AND LOWER(o.order_status) IN ('completed','paid')
        ORDER BY o.order_date DESC, o.id ASC";

$rows = [];
$txCount = 0; $sumSubtotal = 0.0; $sumDelivery = 0.0; $sumTotal = 0.0; $seenOrders = [];

if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param($bindTypes, ...$bindValues);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $orderId = (int)$r['order_id'];
        if (!isset($seenOrders[$orderId])) {
            $seenOrders[$orderId] = true;
            $txCount++;
            $sumSubtotal += (float)$r['total_price'];
            $sumDelivery += (float)$r['delivery_fee'];
            $sumTotal += (float)$r['total_with_delivery'];
        }
        $rows[] = [
            'order_id' => $orderId,
            'trn' => $r['transaction_number'],
            'date' => date('Y/m/d h:i A', strtotime($r['order_date'])),
            'customer' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
            'payment' => strtoupper($r['payment_method']),
            'product' => $r['product_name'],
            'category' => $r['category'],
            'quantity' => (int)$r['quantity'],
            'unit_price' => number_format((float)$r['unit_price'], 2, '.', '')
        ];
    }
    $stmt->close();
}

// Render minimal HTML table styled for Excel with centered text
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #999; padding: 4px 6px; text-align: center; }
        thead th { background: #efefef; font-weight: bold; }
        .right { text-align: right; }
        .no-border td { border: none; }
    </style>
    <title>Sales Report Detailed</title>
</head>
<body>
    <table>
        <thead>
            <tr>
                <th>Order ID</th>
                <th>Transaction #</th>
                <th>Date</th>
                <th>Customer</th>
                <th>Payment</th>
                <th>Product</th>
                <th>Category</th>
                <th>Quantity</th>
                <th>Unit Price</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><?php echo htmlspecialchars($r['order_id']); ?></td>
                <td><?php echo htmlspecialchars($r['trn']); ?></td>
                <td><?php echo htmlspecialchars($r['date']); ?></td>
                <td><?php echo htmlspecialchars($r['customer']); ?></td>
                <td><?php echo htmlspecialchars($r['payment']); ?></td>
                <td><?php echo htmlspecialchars($r['product']); ?></td>
                <td><?php echo htmlspecialchars($r['category']); ?></td>
                <td><?php echo (int)$r['quantity']; ?></td>
                <td class="right"><?php echo htmlspecialchars($r['unit_price']); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <br/>
    <table class="no-border">
        <tr>
            <td>Transactions</td>
            <td class="right"><?php echo (int)$txCount; ?></td>
        </tr>
        <tr>
            <td>Subtotal</td>
            <td class="right">₱<?php echo number_format($sumSubtotal, 2); ?></td>
        </tr>
        <tr>
            <td>Delivery Fees</td>
            <td class="right">₱<?php echo number_format($sumDelivery, 2); ?></td>
        </tr>
        <tr>
            <td>Total</td>
            <td class="right">₱<?php echo number_format($sumTotal, 2); ?></td>
        </tr>
    </table>
</body>
</html>
<?php exit; ?>



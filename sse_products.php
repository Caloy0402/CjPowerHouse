<?php
// Server-Sent Events for real-time product stock and rating updates
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

require_once __DIR__ . '/dbconn.php';

function sendEvent(array $data): void {
    echo 'data: ' . json_encode($data) . "\n\n";
    @ob_flush();
    @flush();
}

if ($conn->connect_error) {
    sendEvent(['type' => 'error', 'error' => 'DB connection failed']);
    exit;
}

// Helper to fetch current snapshot of products with quantity and rating
function fetchProductsSnapshot(mysqli $conn): array {
    $products = [];
    $sql = "SELECT ProductID, ProductName, Quantity, Price, ImagePath From Products";
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $pid = (int)$row['ProductID'];
            // Compute rating aggregate
            $stmt = $conn->prepare('SELECT rating FROM product_feedback WHERE product_id = ?');
            if ($stmt) {
                $stmt->bind_param('i', $pid);
                $stmt->execute();
                $rs = $stmt->get_result();
                $sum = 0; $count = 0;
                while ($r = $rs->fetch_assoc()) { $sum += (int)$r['rating']; $count++; }
                $stmt->close();
                $avg = $count ? round($sum / $count, 1) : 0.0;
            } else { $avg = 0.0; $count = 0; }

            $products[$pid] = [
                'ProductID' => $pid,
                'ProductName' => $row['ProductName'],
                'Quantity' => (int)$row['Quantity'],
                'Price' => (float)$row['Price'],
                'ImagePath' => $row['ImagePath'],
                'average_rating' => $avg,
                'rating_count' => $count,
            ];
        }
    }
    return $products;
}

function hashSnapshot(array $snapshot): string {
    // Order by product id for deterministic hash
    ksort($snapshot);
    return sha1(json_encode($snapshot));
}

// Send initial snapshot
$snapshot = fetchProductsSnapshot($conn);
sendEvent(['type' => 'products_snapshot', 'products' => array_values($snapshot), 'timestamp' => date('c')]);
$lastHash = hashSnapshot($snapshot);

// Poll loop
$iterations = 0;
while ($iterations < 1000) { // ~1000 * 5s = ~83 minutes
    if (connection_aborted()) { break; }
    sleep(5);
    $current = fetchProductsSnapshot($conn);
    $currentHash = hashSnapshot($current);
    if ($currentHash !== $lastHash) {
        // Compute minimal changes
        $changes = [];
        foreach ($current as $pid => $prod) {
            $prev = $snapshot[$pid] ?? null;
            if ($prev === null || $prev['Quantity'] !== $prod['Quantity'] || $prev['average_rating'] !== $prod['average_rating'] || $prev['rating_count'] !== $prod['rating_count']) {
                $changes[] = $prod;
            }
        }
        if (!empty($changes)) {
            sendEvent(['type' => 'products_update', 'changes' => $changes, 'timestamp' => date('c')]);
        }
        $snapshot = $current;
        $lastHash = $currentHash;
    } else {
        sendEvent(['type' => 'heartbeat', 'timestamp' => date('c')]);
    }
    $iterations++;
}

@$conn->close();
?>



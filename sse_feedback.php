<?php
// SSE for real-time product feedback (new reviews, reactions, deletes)
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

if (!isset($_GET['product_id'])) {
    sendEvent(['type' => 'error', 'error' => 'product_id required']);
    exit;
}

$productId = (int)$_GET['product_id'];

function fetchFeedbackSnapshot(mysqli $conn, int $productId): array {
    $items = [];
    $stmt = $conn->prepare("SELECT pf.*, u.first_name, u.last_name, u.ImagePath FROM product_feedback pf JOIN users u ON pf.user_id = u.id WHERE pf.product_id = ? ORDER BY pf.created_at DESC");
    if ($stmt) {
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($row = $rs->fetch_assoc()) {
            $fid = (int)$row['id'];
            // reaction counts
            $counts = [];
            $qr = $conn->prepare('SELECT reaction_type, COUNT(*) cnt FROM feedback_reactions WHERE feedback_id = ? GROUP BY reaction_type');
            if ($qr) {
                $qr->bind_param('i', $fid);
                $qr->execute();
                $rr = $qr->get_result();
                while ($r = $rr->fetch_assoc()) { $counts[$r['reaction_type']] = (int)$r['cnt']; }
                $qr->close();
            }
            $row['reactions'] = $counts;
            $items[] = $row;
        }
        $stmt->close();
    }
    return $items;
}

function compactFeedback(array $row): array {
    return [
        'id' => (int)$row['id'],
        'product_id' => (int)$row['product_id'],
        'user_id' => (int)$row['user_id'],
        'first_name' => $row['first_name'] ?? '',
        'last_name' => $row['last_name'] ?? '',
        'ImagePath' => $row['ImagePath'] ?? '',
        'comment' => $row['comment'],
        'rating' => (float)$row['rating'],
        'created_at' => $row['created_at'],
        'image_path' => $row['image_path'] ?? '',
        'reactions' => $row['reactions'] ?? [],
    ];
}

function snapshotHash(array $items): string {
    $norm = array_map(function($r){
        return [
            'id'=>$r['id'],
            'comment'=>$r['comment'],
            'rating'=>$r['rating'],
            'image_path'=>$r['image_path'],
            'reactions'=>$r['reactions'],
            'created_at'=>$r['created_at'],
        ];
    }, array_map('compactFeedback', $items));
    return sha1(json_encode($norm));
}

if ($conn->connect_error) {
    sendEvent(['type' => 'error', 'error' => 'DB connect failed']);
    exit;
}

$snapshot = fetchFeedbackSnapshot($conn, $productId);
$compacted = array_map('compactFeedback', $snapshot);
sendEvent(['type' => 'feedback_snapshot', 'items' => $compacted, 'timestamp' => date('c')]);
$lastHash = snapshotHash($snapshot);

$iterations = 0;
while ($iterations < 7200) { // ~6 hours @ 3s
    if (connection_aborted()) break;
    sleep(3);
    $current = fetchFeedbackSnapshot($conn, $productId);
    $currentHash = snapshotHash($current);
    if ($currentHash !== $lastHash) {
        // Determine diffs
        $byIdPrev = [];
        foreach ($snapshot as $r) { $byIdPrev[(int)$r['id']] = compactFeedback($r); }
        $byIdCurr = [];
        foreach ($current as $r) { $byIdCurr[(int)$r['id']] = compactFeedback($r); }

        $added = [];
        $changed = [];
        $deleted = [];

        foreach ($byIdCurr as $id => $row) {
            if (!isset($byIdPrev[$id])) {
                $added[] = $row;
            } else {
                $prev = $byIdPrev[$id];
                // Consider reaction count changes or text edits as change
                if (json_encode($prev) !== json_encode($row)) {
                    $changed[] = $row;
                }
            }
        }
        foreach ($byIdPrev as $id => $row) {
            if (!isset($byIdCurr[$id])) $deleted[] = $id;
        }

        sendEvent(['type' => 'feedback_updates', 'added' => $added, 'changed' => $changed, 'deleted' => $deleted, 'timestamp' => date('c')]);

        $snapshot = $current;
        $lastHash = $currentHash;
    } else {
        sendEvent(['type' => 'heartbeat', 'timestamp' => date('c')]);
    }
    $iterations++;
}

@$conn->close();
?>



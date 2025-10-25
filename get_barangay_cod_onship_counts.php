<?php
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Include database connection
require_once 'config.php';

try {
    // Get all barangays with their on-ship COD order counts
    $sql = "SELECT b.id, b.barangay_name, COUNT(o.id) as count
            FROM barangays b
            LEFT JOIN users u ON b.id = u.barangay_id
            LEFT JOIN orders o ON u.id = o.user_id 
                AND o.payment_method = 'COD' 
                AND o.order_status = 'On-Ship'
            GROUP BY b.id, b.barangay_name
            ORDER BY b.barangay_name";
    
    $result = $conn->query($sql);
    
    $barangays = [];
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $barangays[] = [
                'id' => (int)$row['id'],
                'name' => $row['barangay_name'],
                'count' => (int)$row['count']
            ];
        }
    }
    
    echo json_encode($barangays);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}

$conn->close();
?>

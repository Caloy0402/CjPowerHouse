<?php
require_once 'dbconn.php';

header('Content-Type: application/json');

try {
    $tables = ['orders', 'order_items', 'products'];

    $result = [];

    foreach ($tables as $table) {
        $columns_result = $conn->query("DESCRIBE $table");
        if ($columns_result) {
            $columns = $columns_result->fetch_all(MYSQLI_ASSOC);
            $result[$table] = array_column($columns, 'Field');
        } else {
            $result[$table] = 'Error: ' . $conn->error;
        }
    }

    echo json_encode($result, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}

$conn->close();
?>

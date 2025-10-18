<?php
// Completely clean version - no includes, no dependencies
error_reporting(0);
ini_set('display_errors', 0);

// Clear any output buffer
while (ob_get_level()) {
    ob_end_clean();
}

// Set JSON header
header('Content-Type: application/json; charset=utf-8');

// Simple test response
echo json_encode([
    'success' => true, 
    'message' => 'Clean JSON test successful',
    'timestamp' => date('Y-m-d H:i:s')
]);
exit();
?>

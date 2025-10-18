<?php
// Basic PHP test
echo "<h1>PHP Server Test</h1>";
echo "<p>PHP Version: " . phpversion() . "</p>";
echo "<p>Current Time: " . date('Y-m-d H:i:s') . "</p>";
echo "<p>Server: " . $_SERVER['SERVER_SOFTWARE'] . "</p>";

// Test if we can include files
echo "<h2>File Include Test</h2>";
if (file_exists('dbconn.php')) {
    echo "<p style='color: green;'>✓ dbconn.php exists</p>";
    try {
        require 'dbconn.php';
        echo "<p style='color: green;'>✓ dbconn.php loaded successfully</p>";
    } catch (Exception $e) {
        echo "<p style='color: red;'>✗ Error loading dbconn.php: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p style='color: red;'>✗ dbconn.php not found</p>";
}

// Test database connection
echo "<h2>Database Connection Test</h2>";
if (isset($conn) && $conn) {
    echo "<p style='color: green;'>✓ Database connection successful</p>";
    
    // Test simple query
    $result = $conn->query("SELECT 1 as test");
    if ($result) {
        echo "<p style='color: green;'>✓ Database query successful</p>";
    } else {
        echo "<p style='color: red;'>✗ Database query failed: " . $conn->error . "</p>";
    }
} else {
    echo "<p style='color: red;'>✗ Database connection failed</p>";
}

// Test JSON encoding
echo "<h2>JSON Test</h2>";
$test_data = ['success' => true, 'message' => 'test'];
$json = json_encode($test_data);
if ($json) {
    echo "<p style='color: green;'>✓ JSON encoding works</p>";
    echo "<p>Test JSON: " . htmlspecialchars($json) . "</p>";
} else {
    echo "<p style='color: red;'>✗ JSON encoding failed</p>";
}
?>

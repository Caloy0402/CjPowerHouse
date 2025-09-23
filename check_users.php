<?php
require_once 'dbconn.php';

echo "Checking users in database...\n\n";

// Check cjusers table
echo "=== CJUSERS TABLE ===\n";
$result = $conn->query("SELECT id, email, role FROM cjusers");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "ID: " . $row['id'] . " | Email: " . $row['email'] . " | Role: " . $row['role'] . "\n";
    }
} else {
    echo "No users found in cjusers table\n";
}

echo "\n=== RIDERS TABLE ===\n";
$result = $conn->query("SELECT id, email, first_name, last_name FROM riders LIMIT 5");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "ID: " . $row['id'] . " | Email: " . $row['email'] . " | Name: " . $row['first_name'] . " " . $row['last_name'] . "\n";
    }
} else {
    echo "No users found in riders table\n";
}

echo "\n=== MECHANICS TABLE ===\n";
$result = $conn->query("SELECT id, email, first_name, last_name FROM mechanics LIMIT 5");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "ID: " . $row['id'] . " | Email: " . $row['email'] . " | Name: " . $row['first_name'] . " " . $row['last_name'] . "\n";
    }
} else {
    echo "No users found in mechanics table\n";
}

$conn->close();
?>

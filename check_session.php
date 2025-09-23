<?php
session_start();

echo "<h2>Session Debug Information</h2>";
echo "<p>Session ID: " . session_id() . "</p>";
echo "<p>Session Status: " . (session_status() === PHP_SESSION_ACTIVE ? 'Active' : 'Inactive') . "</p>";

echo "<h3>Session Variables:</h3>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

echo "<h3>All Session Data:</h3>";
echo "<pre>";
var_dump($_SESSION);
echo "</pre>";

// Check if user is logged in as Mechanic
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    echo "<h3>User Status:</h3>";
    echo "<p>User ID: " . $_SESSION['user_id'] . "</p>";
    echo "<p>Role: " . $_SESSION['role'] . "</p>";
    echo "<p>Is Mechanic: " . ($_SESSION['role'] === 'Mechanic' ? 'YES' : 'NO') . "</p>";
} else {
    echo "<h3>User Status: NOT LOGGED IN</h3>";
}
?>

<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "cjpowerhouse";

// Create connection with timeout settings
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli();
$conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5); // 5 second connection timeout
$conn->real_connect($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    die("Connection failed: " . $conn->connect_error);
}

// Set query execution timeouts
$conn->query("SET SESSION wait_timeout = 30");
$conn->query("SET SESSION interactive_timeout = 30");

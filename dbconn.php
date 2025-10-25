<?php
$servername = "localhost";
$username = "user";
$password = "Secore@123";
$dbname = "cjpowerhouse";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

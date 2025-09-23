<?php
require_once 'dbconn.php';

echo "<h2>Updating Help Requests Table for Decline Functionality</h2>";

try {
    // Check if decline columns already exist
    $checkColumns = $conn->query("SHOW COLUMNS FROM help_requests LIKE 'declined_at'");
    
    if ($checkColumns->num_rows == 0) {
        echo "<p>Adding decline-related columns to help_requests table...</p>";
        
        // Add decline-related columns
        $alterQueries = [
            "ALTER TABLE help_requests ADD COLUMN declined_at TIMESTAMP NULL AFTER cancelled_at",
            "ALTER TABLE help_requests ADD COLUMN decline_reason VARCHAR(50) NULL AFTER declined_at",
            "ALTER TABLE help_requests ADD COLUMN decline_reason_text TEXT NULL AFTER decline_reason",
            "ALTER TABLE help_requests MODIFY COLUMN status ENUM('Pending', 'In Progress', 'Completed', 'Cancelled', 'Declined') DEFAULT 'Pending'"
        ];
        
        foreach ($alterQueries as $query) {
            if ($conn->query($query) === TRUE) {
                echo "<p style='color: green;'>✓ " . $query . "</p>";
            } else {
                echo "<p style='color: red;'>✗ Error: " . $conn->error . "</p>";
            }
        }
        
        echo "<p style='color: green; font-weight: bold;'>Help requests table updated successfully!</p>";
    } else {
        echo "<p style='color: blue;'>Decline columns already exist in help_requests table.</p>";
    }
    
    // Check if notifications table exists, create if not
    $checkNotificationsTable = $conn->query("SHOW TABLES LIKE 'notifications'");
    
    if ($checkNotificationsTable->num_rows == 0) {
        echo "<p>Creating notifications table...</p>";
        
        $createNotificationsTable = "CREATE TABLE notifications (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            type VARCHAR(50) DEFAULT 'general',
            related_id INT NULL,
            is_read BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            read_at TIMESTAMP NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )";
        
        if ($conn->query($createNotificationsTable) === TRUE) {
            echo "<p style='color: green;'>✓ Notifications table created successfully!</p>";
        } else {
            echo "<p style='color: red;'>✗ Error creating notifications table: " . $conn->error . "</p>";
        }
    } else {
        echo "<p style='color: blue;'>Notifications table already exists.</p>";
    }
    
    // Check if device_token column exists in users table
    $checkDeviceToken = $conn->query("SHOW COLUMNS FROM users LIKE 'device_token'");
    
    if ($checkDeviceToken->num_rows == 0) {
        echo "<p>Adding device_token column to users table...</p>";
        
        $addDeviceToken = "ALTER TABLE users ADD COLUMN device_token VARCHAR(255) NULL AFTER email";
        
        if ($conn->query($addDeviceToken) === TRUE) {
            echo "<p style='color: green;'>✓ Device token column added to users table!</p>";
        } else {
            echo "<p style='color: red;'>✗ Error adding device token column: " . $conn->error . "</p>";
        }
    } else {
        echo "<p style='color: blue;'>Device token column already exists in users table.</p>";
    }
    
    echo "<hr>";
    echo "<h3>Database Schema Update Complete!</h3>";
    echo "<p>The following features are now available:</p>";
    echo "<ul>";
    echo "<li>✓ Decline help requests with reasons</li>";
    echo "<li>✓ Store decline timestamps and reasons</li>";
    echo "<li>✓ Send notifications to customers</li>";
    echo "<li>✓ Support for push notifications (device tokens)</li>";
    echo "</ul>";
    
    echo "<p><a href='Mechanic-Dashboard.php'>Go to Mechanic Dashboard</a> to test the decline functionality.</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}

$conn->close();
?>

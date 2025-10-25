<?php
// Prevent any output before SSE
error_reporting(0);
ini_set('display_errors', 0);

// Set proper headers for SSE
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: true');

// Disable output buffering for SSE
if (ob_get_level()) ob_end_clean();

require 'dbconn.php';

// Function to send SSE events
function sendEvent($data) {
    echo "data: " . json_encode($data) . "\n\n";
    
    // Ensure output is sent immediately
    if (ob_get_level()) ob_flush();
    flush();
}

// Function to send keep-alive comment
function sendKeepAlive() {
    echo ": keep-alive\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

// Function to check if client is still connected
function isClientConnected() {
    return !connection_aborted();
}

// Initialize variables
$last_staff_logs = [];
$initialized = false; // Prevent duplicate notifications on initial connection snapshot
$last_keep_alive = time();
$connection_start = time();
$last_notifications = []; // Track last notification times to prevent spam
$processed_sessions = []; // Track processed login sessions to prevent duplicates

try {
    // Send initial connection message
    sendEvent([
        "type" => "connection_established",
        "message" => "Staff status monitoring started",
        "timestamp" => date('Y-m-d H:i:s')
    ]);
    
    // Main monitoring loop
    while (isClientConnected()) {
        // Check connection timeout (5 minutes)
        if (time() - $connection_start > 300) {
            sendEvent([
                "type" => "connection_timeout",
                "message" => "Connection timeout, reconnecting...",
                "timestamp" => date('Y-m-d H:i:s')
            ]);
            break;
        }
        
        // Send keep-alive every 30 seconds
        if (time() - $last_keep_alive >= 30) {
            sendKeepAlive();
            $last_keep_alive = time();
        }
        
        try {
            // Get current active staff logs (excluding customers and admins)
            $sql = "SELECT l.id, l.staff_id, l.role, l.time_in, l.time_out, l.duty_duration_minutes,
                           COALESCE(r.first_name, m.first_name, cj.first_name, '') AS first_name,
                           COALESCE(r.last_name, m.last_name, cj.last_name, '') AS last_name,
                           COALESCE(r.ImagePath, m.ImagePath, cj.profile_image, '') AS image_path
                    FROM staff_logs l
                    LEFT JOIN riders r ON l.role='Rider' AND r.id=l.staff_id
                    LEFT JOIN mechanics m ON l.role='Mechanic' AND m.id=l.staff_id
                    LEFT JOIN cjusers cj ON l.role='Cashier' AND cj.id=l.staff_id
                    WHERE l.time_out IS NULL AND l.role IN ('Cashier', 'Rider', 'Mechanic')
                    ORDER BY l.time_in DESC";
            
            $result = $conn->query($sql);
            $current_staff_logs = [];
            
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $staffName = trim(($row['first_name'] ?? '').' '.($row['last_name'] ?? '')) ?: ('#'.$row['staff_id']);
                    $current_staff_logs[] = [
                        'id' => $row['id'],
                        'staff_id' => $row['staff_id'],
                        'role' => $row['role'],
                        'name' => $staffName,
                        'time_in' => $row['time_in'],
                        'image_path' => $row['image_path'] ?? ''
                    ];
                }
            }
            
            // On first run, initialize snapshot and skip notifications to avoid page-load spam
            if ($initialized === false) {
                $last_staff_logs = $current_staff_logs;
                $initialized = true;
                // Short sleep to align next poll window
                usleep(250000);
            }

            // Check for new staff logins - only after initialization
            foreach ($current_staff_logs as $current) {
                $found = false;
                $isNewLogin = false;
                
                // Check if this staff member was in the previous logs
                foreach ($last_staff_logs as $last) {
                    if ($last['staff_id'] == $current['staff_id'] && $last['role'] == $current['role']) {
                        $found = true;
                        // Check if this is a new login (different time_in)
                        if ($last['time_in'] != $current['time_in']) {
                            $isNewLogin = true;
                        }
                        break;
                    }
                }
                
                // If staff member not found in previous logs OR it's a new login session
                if (!$found || $isNewLogin) {
                    // Create session key for this login
                    $sessionKey = $current['staff_id'] . '_' . $current['role'] . '_' . $current['time_in'];
                    
                    // Check if we've already processed this exact login session
                    if (isset($processed_sessions[$sessionKey])) {
                        continue; // Skip this notification, already processed
                    }
                    
                    // New staff login detected - always send login notification first
                    $status = 'Online';
                    $activity = 'login';
                    $currentTime = time();
                    $loginKey = $current['id'] . '_login';
                    
                    // Check if we've already sent a login notification recently (30 seconds cooldown)
                    if (!isset($last_notifications[$loginKey]) || 
                        ($currentTime - $last_notifications[$loginKey]) > 30) {
                        
                        // Send login notification for all roles
                        sendEvent([
                            "type" => "staff_status_change",
                            "staff_status_change" => true,
                            "staff_name" => $current['name'],
                            "role" => $current['role'],
                            "status" => $status,
                            "activity" => $activity,
                            "image_path" => $current['image_path'],
                            "timestamp" => date('Y-m-d H:i:s'),
                            "login_time" => date('g:i A', strtotime($current['time_in']))
                        ]);
                        
                        // Record this login notification time
                        $last_notifications[$loginKey] = $currentTime;
                        
                        // Mark this session as processed
                        $processed_sessions[$sessionKey] = $currentTime;
                    }
                    
                    // For riders, also check delivery status and send delivery notification if needed
                    if ($current['role'] === 'Rider') {
                        // Small delay to ensure login notification shows first
                        usleep(500000); // 0.5 second delay
                        
                        $riderSql = "SELECT CASE 
                                        WHEN EXISTS (
                                            SELECT 1 FROM orders o 
                                            WHERE o.rider_name = ? 
                                            AND o.order_status = 'On-Ship'
                                        ) THEN 'OnDelivery'
                                        ELSE 'Online'
                                    END AS status";
                        $riderStmt = $conn->prepare($riderSql);
                        if ($riderStmt) {
                            $riderStmt->bind_param('s', $current['name']);
                            $riderStmt->execute();
                            $riderResult = $riderStmt->get_result();
                            $riderStatus = $riderResult->fetch_assoc()['status'];
                            $riderStmt->close();
                            
                            // If rider is on delivery, send delivery notification
                            if ($riderStatus === 'OnDelivery') {
                                sendEvent([
                                    "type" => "staff_status_change",
                                    "staff_status_change" => true,
                                    "staff_name" => $current['name'],
                                    "role" => $current['role'],
                                    "status" => 'OnDelivery',
                                    "activity" => 'delivery',
                                    "image_path" => $current['image_path'],
                                    "timestamp" => date('Y-m-d H:i:s'),
                                    "delivery_time" => date('g:i A')
                                ]);
                            }
                        }
                    }
                }
            }
            
            // Check for staff logouts (staff that were in last_staff_logs but not in current)
            foreach ($last_staff_logs as $last) {
                $found = false;
                foreach ($current_staff_logs as $current) {
                    if ($last['staff_id'] == $current['staff_id'] && $last['role'] == $current['role']) {
                        $found = true;
                        break;
                    }
                }
                
                if (!$found) {
                    // Fetch the actual logout record to get correct duty duration
                    $logoutSql = "SELECT time_in, time_out, duty_duration_minutes FROM staff_logs 
                                 WHERE staff_id = ? AND role = ? AND time_out IS NOT NULL 
                                 ORDER BY time_out DESC LIMIT 1";
                    $logoutStmt = $conn->prepare($logoutSql);
                    $logoutStmt->bind_param('is', $last['staff_id'], $last['role']);
                    $logoutStmt->execute();
                    $logoutResult = $logoutStmt->get_result();
                    $logoutRecord = $logoutResult->fetch_assoc();
                    $logoutStmt->close();
                    
                    // Calculate duty duration from the actual logout record
                    $dutyDuration = '';
                    if ($logoutRecord) {
                        if ($logoutRecord['duty_duration_minutes'] !== null && $logoutRecord['duty_duration_minutes'] !== '') {
                            // Use stored duty_duration_minutes if available
                            $totalMinutes = (int)$logoutRecord['duty_duration_minutes'];
                            $hours = floor($totalMinutes / 60);
                            $minutes = $totalMinutes % 60;
                            $dutyDuration = sprintf('%02d:%02d:00', $hours, $minutes);
                        } else {
                            // Calculate from time_in to time_out
                            $timeInObj = new DateTime($logoutRecord['time_in']);
                            $timeOutObj = new DateTime($logoutRecord['time_out']);
                            $diff = $timeInObj->diff($timeOutObj);
                            
                            $hours = $diff->h;
                            $minutes = $diff->i;
                            $seconds = $diff->s;
                            
                            $dutyDuration = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
                        }
                    }
                    
                    // Staff logout detected
                    sendEvent([
                        "type" => "staff_status_change",
                        "staff_status_change" => true,
                        "staff_name" => $last['name'],
                        "role" => $last['role'],
                        "status" => 'Offline',
                        "activity" => 'logout',
                        "image_path" => $last['image_path'] ?? '',
                        "timestamp" => date('Y-m-d H:i:s'),
                        "logout_time" => $logoutRecord ? date('g:i A', strtotime($logoutRecord['time_out'])) : date('g:i A'),
                        "duty_duration" => $dutyDuration
                    ]);
                }
            }
            
            // Check for status changes (especially for riders) - with cooldown to prevent spam
            foreach ($current_staff_logs as $current) {
                if ($current['role'] === 'Rider') {
                    $riderSql = "SELECT CASE 
                                    WHEN EXISTS (
                                        SELECT 1 FROM orders o 
                                        WHERE o.rider_name = ? 
                                        AND o.order_status = 'On-Ship'
                                    ) THEN 'OnDelivery'
                                    ELSE 'Online'
                                END AS status";
                    $riderStmt = $conn->prepare($riderSql);
                    if ($riderStmt) {
                        $riderStmt->bind_param('s', $current['name']);
                        $riderStmt->execute();
                        $riderResult = $riderStmt->get_result();
                        $currentStatus = $riderResult->fetch_assoc()['status'];
                        $riderStmt->close();
                        
                        // Create a unique key for this rider's status (separate from login notifications)
                        $riderKey = $current['id'] . '_delivery_' . $currentStatus;
                        $currentTime = time();
                        
                        // Check if we've already sent a notification for this delivery status recently (30 seconds cooldown)
                        if (!isset($last_notifications[$riderKey]) || 
                            ($currentTime - $last_notifications[$riderKey]) > 30) {
                            
                        // Find previous status from last_staff_logs
                        $previousStatus = 'Online'; // Default
                        foreach ($last_staff_logs as $last) {
                            if ($last['staff_id'] == $current['staff_id'] && $last['role'] == $current['role']) {
                                if (isset($last['delivery_status'])) {
                                    $previousStatus = $last['delivery_status'];
                                }
                                break;
                            }
                        }
                            
                            // Only send notification if status actually changed
                            if ($currentStatus !== $previousStatus) {
                                $activity = 'delivery';
                                $status = $currentStatus === 'OnDelivery' ? 'OnDelivery' : 'Online';
                                
                            sendEvent([
                                "type" => "staff_status_change",
                                "staff_status_change" => true,
                                "staff_name" => $current['name'],
                                "role" => $current['role'],
                                "status" => $status,
                                "activity" => $activity,
                                "image_path" => $current['image_path'],
                                "timestamp" => date('Y-m-d H:i:s'),
                                "status_change_time" => date('g:i A')
                            ]);
                                
                                // Record this notification time
                                $last_notifications[$riderKey] = $currentTime;
                            }
                        }
                        
                        // Update current status for next iteration
                        $current['delivery_status'] = $currentStatus;
                    }
                }
            }
            
            $last_staff_logs = $current_staff_logs;
            
        } catch (Exception $e) {
            // Log error and send error event
            error_log("SSE Staff Status Error: " . $e->getMessage());
            sendEvent([
                "type" => "error",
                "message" => "Database error occurred",
                "timestamp" => date('Y-m-d H:i:s')
            ]);
        }
        
        // Check if client is still connected before sleeping
        if (!isClientConnected()) {
            break;
        }
        
        // Sleep for 3 seconds
        sleep(3);
    }
    
} catch (Exception $e) {
    // Log any critical errors
    error_log("SSE Staff Status Critical Error: " . $e->getMessage());
    
    // Try to send error event
    if (isClientConnected()) {
        sendEvent([
            "type" => "critical_error",
            "message" => "Critical error occurred",
            "timestamp" => date('Y-m-d H:i:s')
        ]);
    }
} finally {
    // Clean up
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
    
    // Send disconnect message if possible
    if (isClientConnected()) {
        sendEvent([
            "type" => "disconnect",
            "message" => "Staff status monitoring stopped",
            "timestamp" => date('Y-m-d H:i:s')
        ]);
    }
}
?>

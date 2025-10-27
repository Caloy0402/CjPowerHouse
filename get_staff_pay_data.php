<?php
// Completely suppress all output
ob_start();
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

session_start();

// Set JSON header immediately
header('Content-Type: application/json');

// Check admin authentication
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'admin') {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Include dbconn - if it fails, we'll handle it
include_once 'dbconn.php';

// Check if connection was successful
if (!isset($conn) || $conn->connect_error) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// Get POST data
$from = isset($_POST['from']) ? $_POST['from'] : date('Y-m-d', strtotime('-7 days'));
$to = isset($_POST['to']) ? $_POST['to'] : date('Y-m-d');
$roleFilter = '';
$staffId = isset($_POST['staffId']) ? trim($_POST['staffId']) : '';
$staffRole = isset($_POST['staffRole']) ? trim($_POST['staffRole']) : '';

// Validate staffId is numeric when provided
if ($staffId !== '' && !is_numeric($staffId)) {
    error_log("Invalid staffId: $staffId");
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid staff ID']);
    exit;
}

// Validate date range and format
if ($from && $to && $to < $from) {
    $to = $from;
}

// Validate date format
if ($from && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    error_log("Invalid from date format: $from");
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid date format']);
    exit;
}
if ($to && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    error_log("Invalid to date format: $to");
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid date format']);
    exit;
}

// Calculate total duty hours
function calculateTotalDutyHours($conn, $from, $to, $roleFilter = '', $staffId = '', $staffRole = '') {
    // Build WHERE clause
    $where = "WHERE DATE(l.time_in) BETWEEN ? AND ? AND l.role NOT IN ('Admin', 'Customer') AND l.time_out IS NOT NULL";
    $params = [];
    $types = '';
    
    // Add individual staff filter
    if ($staffId !== '' && $staffRole !== '') {
        $where .= " AND l.staff_id = ? AND l.role = ?";
        $params = [$from, $to, (int)$staffId, $staffRole];
        $types = 'ssis';
    } elseif ($roleFilter !== '') { 
        $where .= " AND l.role = ?";
        $params = [$from, $to, $roleFilter];
        $types = 'sss';
    } else {
        $params = [$from, $to];
        $types = 'ss';
    }
    
    $sql = "SELECT SUM(l.duty_duration_minutes) AS total_minutes 
            FROM staff_logs l $where";
    
    error_log("SQL Query: " . $sql);
    error_log("Params: " . print_r($params, true));
    error_log("Types: $types");
    
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        return 0;
    }
    
    // Bind parameters based on count
    $paramCount = count($params);
    if ($paramCount == 4) {
        $stmt->bind_param($types, $params[0], $params[1], $params[2], $params[3]);
    } elseif ($paramCount == 3) {
        $stmt->bind_param($types, $params[0], $params[1], $params[2]);
    } else {
        $stmt->bind_param($types, $params[0], $params[1]);
    }
    
    if (!$stmt->execute()) {
        error_log("SQL Error: " . $stmt->error);
        $stmt->close();
        return 0;
    }
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $totalMinutes = (int)($row['total_minutes'] ?? 0);
    $stmt->close();
    
    return $totalMinutes / 60; // Convert to hours
}

// Calculate working days (excluding Sundays)
function calculateWorkingDays($from, $to) {
    $startDate = new DateTime($from);
    $endDate = new DateTime($to);
    $endDate->modify('+1 day'); // Include end date
    
    $workingDays = 0;
    $current = clone $startDate;
    
    while ($current < $endDate) {
        // Check if day is not Sunday (0 = Sunday)
        if ($current->format('w') != '0') {
            $workingDays++;
        }
        $current->modify('+1 day');
    }
    
    return $workingDays;
}

// Calculate total duty hours and working days
try {
    $totalDutyHours = calculateTotalDutyHours($conn, $from, $to, $roleFilter, $staffId, $staffRole);
    $workingDays = calculateWorkingDays($from, $to);
} catch (Exception $e) {
    error_log("Error in pay calculation: " . $e->getMessage());
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}

// Prepare and send response
$response = [
    'success' => true,
    'data' => [
        'totalHours' => round($totalDutyHours, 2),
        'workingDays' => $workingDays
    ]
];

ob_end_clean();
echo json_encode($response);
exit;

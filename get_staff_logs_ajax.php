<?php
header('Content-Type: application/json');
header('Cache-Control: no-cache');

session_start();
require_once 'dbconn.php';

// Only admins
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// We only read session data in this endpoint; release the session lock early
// to avoid blocking other requests like logout while SSE/AJAX are active.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// Determine required minutes per role (default 8 hours)
function getRequiredMinutesByRole($role) {
    $role = strtolower((string)$role);
    // Customize per role if needed in the future
    // e.g., if ($role === 'mechanic') return 480;
    return 480; // 8 hours default
}

// Get filter parameters
$from = isset($_GET['from']) ? $_GET['from'] : date('Y-m-d', strtotime('-7 days'));
$to = isset($_GET['to']) ? $_GET['to'] : date('Y-m-d');
$roleFilter = isset($_GET['role']) ? trim($_GET['role']) : '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

// Validate date range
if ($from && $to && $to < $from) {
    $to = $from;
}

// Pagination
$itemsPerPage = 10;
$offset = ($page - 1) * $itemsPerPage;

// Build WHERE clause
$where = "WHERE DATE(l.time_in) BETWEEN ? AND ? AND l.role NOT IN ('Admin', 'Customer')";
if ($roleFilter !== '') { 
    $where .= " AND l.role = ?"; 
}

// Fetch logs
$sql = "
    SELECT l.id, l.staff_id, l.role, l.action, l.activity, l.time_in, l.time_out, l.duty_duration_minutes,
           COALESCE(r.first_name, m.first_name, cj.first_name, '') AS first_name,
           COALESCE(r.last_name, m.last_name, cj.last_name, '') AS last_name
    FROM staff_logs l
    LEFT JOIN riders r ON l.role='Rider' AND r.id=l.staff_id
    LEFT JOIN mechanics m ON l.role='Mechanic' AND m.id=l.staff_id
    LEFT JOIN cjusers cj ON (l.role='Admin' OR l.role='Cashier') AND cj.id=l.staff_id
    $where
    ORDER BY l.time_in DESC
    LIMIT $itemsPerPage OFFSET $offset";

$stmt = $conn->prepare($sql);
if ($stmt) {
    if ($roleFilter === '') {
        $stmt->bind_param('ss', $from, $to);
    } else {
        $stmt->bind_param('sss', $from, $to, $roleFilter);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $logs = [];
    while ($row = $result->fetch_assoc()) {
        $logs[] = $row;
    }
    $stmt->close();
    
    // Get total count for pagination
    $countSql = "SELECT COUNT(*) as total FROM staff_logs l $where";
    $countStmt = $conn->prepare($countSql);
    if ($countStmt) {
        if ($roleFilter === '') {
            $countStmt->bind_param('ss', $from, $to);
        } else {
            $countStmt->bind_param('sss', $from, $to, $roleFilter);
        }
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $totalRecords = $countResult->fetch_assoc()['total'];
        $countStmt->close();
    }
    
    $totalPages = ceil($totalRecords / $itemsPerPage);
    
    // Generate HTML for table rows
    $html = '';
    if (empty($logs)) {
        $html = '<tr><td colspan="7" class="text-center">No logs found for the selected criteria.</td></tr>';
    } else {
        foreach ($logs as $log) {
            $fullName = trim(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? '')) ?: ('Staff #' . $log['staff_id']);
            
            // Format time
            $timeIn = date('M d, Y g:i A', strtotime($log['time_in']));
            $timeOut = $log['time_out'] ? date('M d, Y g:i A', strtotime($log['time_out'])) : '--';
            
            // Calculate duty duration
            $duration = '';
            
            if ($log['time_out']) {
                // Completed duty
                if ($log['duty_duration_minutes'] !== null && $log['duty_duration_minutes'] !== '') {
                    // Use stored duty duration
                    $totalMinutes = (int)$log['duty_duration_minutes'];
                    $hours = floor($totalMinutes / 60);
                    $minutes = $totalMinutes % 60;
                    $duration = sprintf('%02d:%02d:%02d', $hours, $minutes, 0);
                } else {
                    // Calculate from time_in and time_out
                    $timeInObj = new DateTime($log['time_in']);
                    $timeOutObj = new DateTime($log['time_out']);
                    $diff = $timeInObj->diff($timeOutObj);
                    
                    $hours = $diff->h + ($diff->days * 24);
                    $minutes = $diff->i;
                    $seconds = $diff->s;
                    
                    $duration = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
                }
            } else {
                // Active duty - calculate from time_in to now
                $timeInObj = new DateTime($log['time_in']);
                $nowObj = new DateTime();
                $diff = $timeInObj->diff($nowObj);
                
                $hours = $diff->h + ($diff->days * 24);
                $minutes = $diff->i;
                $seconds = $diff->s;
                
                $duration = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
            }
            
            // Calculate remaining duty hours PER ROW (session-based)
            $requiredMinutes = getRequiredMinutesByRole($log['role']);
            $workedMinutes = 0;
            if (!empty($log['time_out'])) {
                if ($log['duty_duration_minutes'] !== null && $log['duty_duration_minutes'] !== '') {
                    $workedMinutes = (int)$log['duty_duration_minutes'];
                } else {
                    $workedMinutes = max(0, (int)round((strtotime($log['time_out']) - strtotime($log['time_in'])) / 60));
                }
            } else {
                $workedMinutes = max(0, (int)round((time() - strtotime($log['time_in'])) / 60));
            }
            $workedMinutesCapped = min($requiredMinutes, $workedMinutes);
            $remainingMinutes = max(0, $requiredMinutes - $workedMinutesCapped);
            $isComplete = $workedMinutesCapped >= $requiredMinutes;

            if ($isComplete) {
                $remainingHoursHtml = '<span class="remaining-hours complete"><i class="fas fa-check-circle me-1"></i>Complete</span>';
            } else {
                $remainingHoursHtml = '<span class="remaining-hours pending">' . floor($remainingMinutes / 60) . 'h ' . ($remainingMinutes % 60) . 'm left</span>';
            }
            
            // Check if staff is online
            $isOnline = false;
            $checkOnlineSql = "SELECT id FROM staff_logs 
                               WHERE staff_id = ? AND role = ? AND action = 'login' 
                               AND time_out IS NULL 
                               ORDER BY time_in DESC LIMIT 1";
            $checkStmt = $conn->prepare($checkOnlineSql);
            if ($checkStmt) {
                $checkStmt->bind_param('is', $log['staff_id'], $log['role']);
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result();
                $isOnline = $checkResult->num_rows > 0;
                $checkStmt->close();
            }
            
            $status = $isOnline ? '<span class="text-success">--Online--</span>' : '<span class="text-danger">--Offline--</span>';
            
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($fullName) . '</td>';
            $html .= '<td>' . htmlspecialchars($log['role']) . '</td>';
            $html .= '<td>' . htmlspecialchars($timeIn) . '</td>';
            $html .= '<td>' . htmlspecialchars($timeOut) . '</td>';
            $html .= '<td>' . $duration . '</td>';
            $html .= '<td>' . $remainingHoursHtml . '</td>';
            $html .= '<td>' . $status . '</td>';
            $html .= '</tr>';
        }
    }
    
    // Generate pagination HTML
    $pagination = '';
    if ($totalPages > 1) {
        $pagination .= '<div class="d-flex justify-content-center mt-3">';
        $pagination .= '<nav aria-label="Staff logs pagination">';
        $pagination .= '<ul class="pagination">';
        
        // Previous button
        if ($page > 1) {
            $prevPage = $page - 1;
            $pagination .= '<li class="page-item"><a class="page-link" href="?from=' . urlencode($from) . '&to=' . urlencode($to) . '&role=' . urlencode($roleFilter) . '&page=' . $prevPage . '">Previous</a></li>';
        }
        
        // Page numbers
        for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++) {
            $activeClass = $i == $page ? ' active' : '';
            $pagination .= '<li class="page-item' . $activeClass . '"><a class="page-link" href="?from=' . urlencode($from) . '&to=' . urlencode($to) . '&role=' . urlencode($roleFilter) . '&page=' . $i . '">' . $i . '</a></li>';
        }
        
        // Next button
        if ($page < $totalPages) {
            $nextPage = $page + 1;
            $pagination .= '<li class="page-item"><a class="page-link" href="?from=' . urlencode($from) . '&to=' . urlencode($to) . '&role=' . urlencode($roleFilter) . '&page=' . $nextPage . '">Next</a></li>';
        }
        
        $pagination .= '</ul>';
        $pagination .= '</nav>';
        $pagination .= '</div>';
    }
    
    // Prepare logs data for JavaScript processing
    $logsData = [];
    foreach ($logs as $log) {
        $fullName = trim(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? '')) ?: ('Staff #' . $log['staff_id']);
        
        // Format time
        $timeIn = date('M d, Y g:i A', strtotime($log['time_in']));
        $timeOut = $log['time_out'] ? date('M d, Y g:i A', strtotime($log['time_out'])) : '--';
        
        // Calculate duty duration
        $duration = '';
        $formattedDuration = '';
        
        if ($log['time_out']) {
            // Completed duty
            if ($log['duty_duration_minutes'] !== null && $log['duty_duration_minutes'] !== '') {
                // Use stored duty duration
                $totalMinutes = (int)$log['duty_duration_minutes'];
                $hours = floor($totalMinutes / 60);
                $minutes = $totalMinutes % 60;
                $duration = sprintf('%02d:%02d:%02d', $hours, $minutes, 0);
                $formattedDuration = $duration;
            } else {
                // Calculate from time_in and time_out
                $timeInObj = new DateTime($log['time_in']);
                $timeOutObj = new DateTime($log['time_out']);
                $diff = $timeInObj->diff($timeOutObj);
                
                $hours = $diff->h + ($diff->days * 24);
                $minutes = $diff->i;
                $seconds = $diff->s;
                
                $duration = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
                $formattedDuration = $duration;
            }
        } else {
            // Active duty - calculate from time_in to now
            $timeInObj = new DateTime($log['time_in']);
            $nowObj = new DateTime();
            $diff = $timeInObj->diff($nowObj);
            
            $hours = $diff->h + ($diff->days * 24);
            $minutes = $diff->i;
            $seconds = $diff->s;
            
            $duration = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
            $formattedDuration = $duration;
        }
        
        // Calculate remaining duty hours PER ROW (session-based)
        $requiredMinutes = getRequiredMinutesByRole($log['role']);
        $workedMinutes = 0;
        if (!empty($log['time_out'])) {
            if ($log['duty_duration_minutes'] !== null && $log['duty_duration_minutes'] !== '') {
                $workedMinutes = (int)$log['duty_duration_minutes'];
            } else {
                $workedMinutes = max(0, (int)round((strtotime($log['time_out']) - strtotime($log['time_in'])) / 60));
            }
        } else {
            $workedMinutes = max(0, (int)round((time() - strtotime($log['time_in'])) / 60));
        }
        $workedMinutesCapped = min($requiredMinutes, $workedMinutes);
        $remainingMinutes = max(0, $requiredMinutes - $workedMinutesCapped);
        $isComplete = $workedMinutesCapped >= $requiredMinutes;

        if ($isComplete) {
            $remainingHoursHtml = '<span class="remaining-hours complete"><i class="fas fa-check-circle me-1"></i>Complete</span>';
        } else {
            $remainingHoursHtml = '<span class="remaining-hours pending">' . floor($remainingMinutes / 60) . 'h ' . ($remainingMinutes % 60) . 'm left</span>';
        }
        
        // Check if staff is online
        $isOnline = false;
        $checkOnlineSql = "SELECT id FROM staff_logs 
                           WHERE staff_id = ? AND role = ? AND action = 'login' 
                           AND time_out IS NULL 
                           ORDER BY time_in DESC LIMIT 1";
        $checkStmt = $conn->prepare($checkOnlineSql);
        if ($checkStmt) {
            $checkStmt->bind_param('is', $log['staff_id'], $log['role']);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            $isOnline = $checkResult->num_rows > 0;
            $checkStmt->close();
        }
        
        $status = $isOnline ? ['text' => '--Online--', 'class' => 'text-success'] : ['text' => '--Offline--', 'class' => 'text-danger'];
        
        $logsData[] = [
            'id' => $log['id'],
            'staff_id' => $log['staff_id'],
            'role' => $log['role'],
            'full_name' => $fullName,
            'formatted_time_in' => $timeIn,
            'formatted_time_out' => $timeOut,
            'formatted_duration' => $formattedDuration,
            'remaining_hours_html' => $remainingHoursHtml,
            'status' => $status,
            'time_in' => $log['time_in'],
            'time_out' => $log['time_out']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'logs' => $logsData,
        'html' => $html,
        'pagination' => $pagination,
        'total_count' => $totalRecords,
        'current_page' => $page,
        'total_pages' => $totalPages
    ]);
    
} else {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}

$conn->close();
?>

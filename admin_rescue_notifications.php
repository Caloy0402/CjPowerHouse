<?php
// Prevent any output before HTML
error_reporting(0);
ini_set('display_errors', 0);

require 'dbconn.php';

// Rescue request notification functions
function getRescueRequestNotifications($conn) {
    $notifications = array();
    
    // Check for pending rescue requests
    $pendingQuery = "SELECT COUNT(*) as count FROM help_requests WHERE status = 'Pending'";
    $pendingResult = $conn->query($pendingQuery);
    if ($pendingResult) {
        $pendingCount = $pendingResult->fetch_assoc()['count'];
        if ($pendingCount > 0) {
            $notifications[] = array(
                'type' => 'pending_requests',
                'title' => 'Pending Rescue Requests',
                'message' => "$pendingCount rescue request" . ($pendingCount > 1 ? 's' : '') . " waiting for response",
                'count' => $pendingCount,
                'icon' => 'fa-exclamation-triangle',
                'color' => 'text-warning'
            );
        }
    }
    
    // Check for in-progress rescue requests
    $inProgressQuery = "SELECT COUNT(*) as count FROM help_requests WHERE status = 'In Progress'";
    $inProgressResult = $conn->query($inProgressQuery);
    if ($inProgressResult) {
        $inProgressCount = $inProgressResult->fetch_assoc()['count'];
        if ($inProgressCount > 0) {
            $notifications[] = array(
                'type' => 'in_progress_requests',
                'title' => 'Active Rescue Operations',
                'message' => "$inProgressCount rescue operation" . ($inProgressCount > 1 ? 's' : '') . " currently in progress",
                'count' => $inProgressCount,
                'icon' => 'fa-tools',
                'color' => 'text-info'
            );
        }
    }
    
    // Check for today's rescue requests
    $todayQuery = "SELECT COUNT(*) as count FROM help_requests WHERE DATE(created_at) = CURDATE()";
    $todayResult = $conn->query($todayQuery);
    if ($todayResult) {
        $todayCount = $todayResult->fetch_assoc()['count'];
        if ($todayCount > 0) {
            $notifications[] = array(
                'type' => 'today_requests',
                'title' => 'Today\'s Rescue Requests',
                'message' => "$todayCount rescue request" . ($todayCount > 1 ? 's' : '') . " received today",
                'count' => $todayCount,
                'icon' => 'fa-calendar-day',
                'color' => 'text-primary'
            );
        }
    }
    
    // Check for completed rescue requests today
    $completedQuery = "SELECT COUNT(*) as count FROM help_requests WHERE status = 'Completed' AND DATE(completed_at) = CURDATE()";
    $completedResult = $conn->query($completedQuery);
    if ($completedResult) {
        $completedCount = $completedResult->fetch_assoc()['count'];
        if ($completedCount > 0) {
            $notifications[] = array(
                'type' => 'completed_today',
                'title' => 'Completed Today',
                'message' => "$completedCount rescue request" . ($completedCount > 1 ? 's' : '') . " completed today",
                'count' => $completedCount,
                'icon' => 'fa-check-circle',
                'color' => 'text-success'
            );
        }
    }
    
    return $notifications;
}

function getTotalRescueNotificationCount($conn) {
    $total = 0;
    
    // Count pending requests
    $pendingQuery = "SELECT COUNT(*) as count FROM help_requests WHERE status = 'Pending'";
    $pendingResult = $conn->query($pendingQuery);
    if ($pendingResult) {
        $count = $pendingResult->fetch_assoc()['count'];
        if ($count > 0) $total += $count;
    }
    
    // Count in-progress requests
    $inProgressQuery = "SELECT COUNT(*) as count FROM help_requests WHERE status = 'In Progress'";
    $inProgressResult = $conn->query($inProgressQuery);
    if ($inProgressResult) {
        $count = $inProgressResult->fetch_assoc()['count'];
        if ($count > 0) $total += $count;
    }
    
    // Count today's requests
    $todayQuery = "SELECT COUNT(*) as count FROM help_requests WHERE DATE(created_at) = CURDATE()";
    $todayResult = $conn->query($todayQuery);
    if ($todayResult) {
        $count = $todayResult->fetch_assoc()['count'];
        if ($count > 0) $total += $count;
    }
    
    return $total;
}

// Get current notifications
$rescueNotifications = getRescueRequestNotifications($conn);
$totalRescueNotifications = getTotalRescueNotificationCount($conn);
?>

<!-- Rescue Request Notification System -->
<div class="nav-item dropdown">
    <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
        <i class="fa fa-tools me-lg-2"></i>
        <span class="d-none d-lg-inline">Rescue Status</span>
        <span class="notification-badge" id="rescueNotificationCount" style="display: <?php echo $totalRescueNotifications > 0 ? 'block' : 'none'; ?>;"><?php echo $totalRescueNotifications; ?></span>
    </a>
    <div class="dropdown-menu dropdown-menu-end bg-secondary border-0 rounded-0 rounded-bottom m-0" style="min-width: 300px; max-height: 400px; overflow-y: auto;">
        <!-- Notification Sound Controls -->
        <div class="dropdown-header d-flex justify-content-between align-items-center">
            <span>Rescue Notifications</span>
            <div class="btn-group btn-group-sm" role="group">
                <button type="button" class="btn btn-outline-light btn-sm" id="rescueMuteToggleBtn" title="Toggle notification sound">
                    <i class="fas fa-volume-up" id="rescueMuteIcon"></i>
                </button>
                <button type="button" class="btn btn-outline-light btn-sm" id="rescueTestSoundBtn" title="Test notification sound">
                    <i class="fas fa-play"></i>
                </button>
            </div>
        </div>
        <hr class="dropdown-divider">
        <div class="notification-items" id="rescueNotificationItems">
            <?php if (empty($rescueNotifications)): ?>
                <div class="dropdown-item text-center">No rescue notifications</div>
            <?php else: ?>
                <?php foreach ($rescueNotifications as $notification): ?>
                    <div class="dropdown-item notification-item">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <i class="fas <?php echo $notification['icon']; ?> <?php echo $notification['color']; ?> me-2"></i>
                            </div>
                            <div class="flex-grow-1">
                                <h6 class="fw-normal mb-0"><?php echo $notification['title']; ?></h6>
                                <p class="mb-0 small"><?php echo $notification['message']; ?></p>
                            </div>
                            <div class="flex-shrink-0">
                                <span class="badge bg-primary"><?php echo $notification['count']; ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Rescue Request Notification JavaScript -->
<script>
let lastRescueNotificationData = null;

function fetchRescueNotifications() {
    fetch('get_rescue_notifications.php')
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            const totalCount = data.total_count;
            const notifications = data.notifications;
            const notificationCount = document.getElementById('rescueNotificationCount');
            const notificationItems = document.getElementById('rescueNotificationItems');
            
            // Update notification count
            notificationCount.textContent = totalCount;
            notificationCount.style.display = totalCount > 0 ? 'block' : 'none';
            
            // Update notification items
            notificationItems.innerHTML = '';
            if (notifications.length === 0) {
                notificationItems.innerHTML = '<div class="dropdown-item text-center">No rescue notifications</div>';
            } else {
                notifications.forEach(notification => {
                    const item = document.createElement('div');
                    item.className = 'dropdown-item notification-item';
                    item.innerHTML = `
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <i class="fas ${notification.icon} ${notification.color} me-2"></i>
                            </div>
                            <div class="flex-grow-1">
                                <h6 class="fw-normal mb-0">${notification.title}</h6>
                                <p class="mb-0 small">${notification.message}</p>
                            </div>
                            <div class="flex-shrink-0">
                                <span class="badge bg-primary">${notification.count}</span>
                            </div>
                        </div>
                    `;
                    
                    // Add click handler for navigation
                    item.addEventListener('click', function() {
                        navigateToRescuePage(notification.type);
                    });
                    
                    notificationItems.appendChild(item);
                });
            }
            
            lastRescueNotificationData = data;
        })
        .catch(error => {
            console.error('Error fetching rescue notifications:', error);
        });
}

function navigateToRescuePage(notificationType) {
    const pageMap = {
        'pending_requests': 'Admin-RescueLogs.php',
        'in_progress_requests': 'Admin-RescueLogs.php',
        'today_requests': 'Admin-RescueLogs.php',
        'completed_today': 'Admin-RescueLogs.php'
    };
    
    if (pageMap[notificationType]) {
        window.location.href = pageMap[notificationType];
    }
}

// Initialize rescue notifications
document.addEventListener('DOMContentLoaded', function() {
    fetchRescueNotifications();
    // Refresh notifications every 10 seconds
    setInterval(fetchRescueNotifications, 10000);
    
    // Initialize notification sound controls
    initRescueNotificationSoundControls();
});

// Initialize notification sound controls
function initRescueNotificationSoundControls() {
    const muteToggleBtn = document.getElementById('rescueMuteToggleBtn');
    const testSoundBtn = document.getElementById('rescueTestSoundBtn');
    const muteIcon = document.getElementById('rescueMuteIcon');
    
    if (muteToggleBtn && testSoundBtn) {
        // Update mute button state
        function updateMuteButton() {
            if (typeof notificationSound !== 'undefined' && notificationSound) {
                const isMuted = notificationSound.getMuted();
                muteIcon.className = isMuted ? 'fas fa-volume-mute' : 'fas fa-volume-up';
                muteToggleBtn.title = isMuted ? 'Unmute notification sound' : 'Mute notification sound';
            }
        }
        
        // Mute toggle functionality
        muteToggleBtn.addEventListener('click', function() {
            if (typeof notificationSound !== 'undefined' && notificationSound) {
                notificationSound.toggleMute();
                updateMuteButton();
            } else {
                console.warn('Notification sound system not initialized');
            }
        });
        
        // Test sound functionality
        testSoundBtn.addEventListener('click', function() {
            if (typeof notificationSound !== 'undefined' && notificationSound) {
                notificationSound.testSound();
            } else {
                console.warn('Notification sound system not initialized');
            }
        });
        
        // Initial button state
        updateMuteButton();
    }
}
</script>

<style>
.notification-badge {
    position: absolute;
    top: -5px;
    right: -5px;
    padding: 2px 6px;
    border-radius: 50%;
    background-color: #dc3545;
    color: white;
    font-size: 10px;
    font-weight: bold;
    min-width: 18px;
    text-align: center;
}

.notification-item {
    cursor: pointer;
    transition: background-color 0.2s;
    border-bottom: 1px solid #495057;
}

.notification-item:hover {
    background-color: #495057;
}

.notification-item:last-child {
    border-bottom: none;
}

.notification-item h6 {
    font-size: 0.9rem;
    margin-bottom: 2px;
}

.notification-item p {
    font-size: 0.8rem;
    margin-bottom: 0;
    color: #adb5bd;
}

.notification-item .badge {
    font-size: 0.7rem;
}
</style>

<?php
// Prevent any output before HTML
error_reporting(0);
ini_set('display_errors', 0);

require 'dbconn.php';

// Staff status notification functions
function getStaffStatusNotifications($conn) {
    $notifications = array();
    
    // Check for staff currently on duty
    $onDutyQuery = "SELECT COUNT(*) as count FROM staff_logs WHERE time_out IS NULL";
    $onDutyResult = $conn->query($onDutyQuery);
    if ($onDutyResult) {
        $onDutyCount = $onDutyResult->fetch_assoc()['count'];
        if ($onDutyCount > 0) {
            $notifications[] = array(
                'type' => 'staff_on_duty',
                'title' => 'Staff On Duty',
                'message' => "$onDutyCount staff member" . ($onDutyCount > 1 ? 's' : '') . " currently working",
                'count' => $onDutyCount,
                'icon' => 'fa-users',
                'color' => 'text-success'
            );
        }
    }
    
    // Check for recent staff logins
    $recentLoginsQuery = "SELECT COUNT(*) as count FROM staff_logs 
                          WHERE DATE(time_in) = CURDATE() AND action = 'login'";
    $recentLoginsResult = $conn->query($recentLoginsQuery);
    if ($recentLoginsResult) {
        $recentLoginsCount = $recentLoginsResult->fetch_assoc()['count'];
        if ($recentLoginsCount > 0) {
            $notifications[] = array(
                'type' => 'recent_logins',
                'title' => 'Recent Staff Activity',
                'message' => "$recentLoginsCount staff login" . ($recentLoginsCount > 1 ? 's' : '') . " today",
                'count' => $recentLoginsCount,
                'icon' => 'fa-sign-in-alt',
                'color' => 'text-info'
            );
        }
    }
    
    return $notifications;
}

function getTotalStaffNotificationCount($conn) {
    $total = 0;
    
    // Count staff on duty
    $onDutyQuery = "SELECT COUNT(*) as count FROM staff_logs WHERE time_out IS NULL";
    $onDutyResult = $conn->query($onDutyQuery);
    if ($onDutyResult) {
        $count = $onDutyResult->fetch_assoc()['count'];
        if ($count > 0) $total += $count;
    }
    
    // Count recent logins
    $recentLoginsQuery = "SELECT COUNT(*) as count FROM staff_logs WHERE DATE(time_in) = CURDATE()";
    $recentLoginsResult = $conn->query($recentLoginsQuery);
    if ($recentLoginsResult) {
        $count = $recentLoginsResult->fetch_assoc()['count'];
        if ($count > 0) $total += $count;
    }
    
    return $total;
}

// Get current notifications
$staffNotifications = getStaffStatusNotifications($conn);
$totalStaffNotifications = getTotalStaffNotificationCount($conn);
?>

<!-- Staff Status Notification System -->
<div class="nav-item dropdown">
    <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
        <i class="fa fa-users me-lg-2"></i>
        <span class="d-none d-lg-inline">Staff Status</span>
        <span class="notification-badge" id="staffNotificationCount" style="display: <?php echo $totalStaffNotifications > 0 ? 'block' : 'none'; ?>;"><?php echo $totalStaffNotifications; ?></span>
    </a>
    <div class="dropdown-menu dropdown-menu-end bg-secondary border-0 rounded-0 rounded-bottom m-0" style="min-width: 300px; max-height: 400px; overflow-y: auto;">
        <!-- Notification Sound Controls -->
        <div class="dropdown-header d-flex justify-content-between align-items-center">
            <span>Staff Notifications</span>
            <div class="btn-group btn-group-sm" role="group">
                <button type="button" class="btn btn-outline-light btn-sm" id="muteToggleBtn" title="Toggle notification sound">
                    <i class="fas fa-volume-up" id="muteIcon"></i>
                </button>
                <button type="button" class="btn btn-outline-light btn-sm" id="testSoundBtn" title="Test notification sound">
                    <i class="fas fa-play"></i>
                </button>
            </div>
        </div>
        <hr class="dropdown-divider">
        <div class="notification-items" id="staffNotificationItems">
            <?php if (empty($staffNotifications)): ?>
                <div class="dropdown-item text-center">No staff notifications</div>
            <?php else: ?>
                <?php foreach ($staffNotifications as $notification): ?>
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

<!-- Staff Status Notification JavaScript -->
<script>
let lastStaffNotificationData = null;

function fetchStaffNotifications() {
    fetch('get_staff_notifications.php')
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            const totalCount = data.total_count;
            const notifications = data.notifications;
            const notificationCount = document.getElementById('staffNotificationCount');
            const notificationItems = document.getElementById('staffNotificationItems');
            
            // Update notification count
            notificationCount.textContent = totalCount;
            notificationCount.style.display = totalCount > 0 ? 'block' : 'none';
            
            // Check if there are new notifications - removed generic notification
            // if (lastStaffNotificationData && data.total_count > lastStaffNotificationData.total_count) {
            //     const newCount = data.total_count - lastStaffNotificationData.total_count;
            //     showStaffStatusNotification(newCount);
            // }
            
            // Update notification items
            notificationItems.innerHTML = '';
            if (notifications.length === 0) {
                notificationItems.innerHTML = '<div class="dropdown-item text-center">No staff notifications</div>';
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
                        navigateToStaffPage(notification.type);
                    });
                    
                    notificationItems.appendChild(item);
                });
            }
            
            lastStaffNotificationData = data;
        })
        .catch(error => {
            console.error('Error fetching staff notifications:', error);
        });
}

function navigateToStaffPage(notificationType) {
    const pageMap = {
        'staff_on_duty': 'Admin-StaffLogs.php',
        'recent_logins': 'Admin-StaffLogs.php'
    };
    
    if (pageMap[notificationType]) {
        window.location.href = pageMap[notificationType];
    }
}

// Track active notifications to prevent duplicates
window.activeNotifications = window.activeNotifications || new Set();
window.lastNotificationTime = window.lastNotificationTime || 0;

// Function to generate timestamp information based on activity type
function getTimestampInfo(activity, additionalData) {
    let timestampHtml = '';
    
    if (activity === 'login' && additionalData.login_time) {
        timestampHtml = `<div class="notification-timestamp" style="font-size: 11px; color: rgba(255,255,255,0.7); margin-top: 2px; text-align: center;">
            <i class="fas fa-clock" style="margin-right: 4px;"></i>Logged in at ${additionalData.login_time}
        </div>`;
    } else if (activity === 'logout' && additionalData.logout_time) {
        timestampHtml = `<div class="notification-timestamp" style="font-size: 11px; color: rgba(255,255,255,0.7); margin-top: 2px; text-align: center;">
            <i class="fas fa-clock" style="margin-right: 4px;"></i>Logged out at ${additionalData.logout_time}`;
        
        if (additionalData.duty_duration) {
            timestampHtml += `<br><i class="fas fa-hourglass-half" style="margin-right: 4px;"></i>Duty Duration: ${additionalData.duty_duration}`;
        }
        
        timestampHtml += `</div>`;
    } else if (activity === 'delivery' && additionalData.delivery_time) {
        timestampHtml = `<div class="notification-timestamp" style="font-size: 11px; color: rgba(255,255,255,0.7); margin-top: 2px; text-align: center;">
            <i class="fas fa-clock" style="margin-right: 4px;"></i>Started delivery at ${additionalData.delivery_time}
        </div>`;
    } else if (activity === 'delivery' && additionalData.status_change_time) {
        timestampHtml = `<div class="notification-timestamp" style="font-size: 11px; color: rgba(255,255,255,0.7); margin-top: 2px; text-align: center;">
            <i class="fas fa-clock" style="margin-right: 4px;"></i>Status changed at ${additionalData.status_change_time}
        </div>`;
    }
    
    return timestampHtml;
}

// Show specific user activity notification (e.g., "jandi Bonbon (Mechanic) is now Online")
function showUserActivityNotification(staffName, role, status, activity = 'login', imagePath = '', additionalData = {}) {
    // Check if jQuery is available
    if (typeof $ === 'undefined') {
        console.warn('jQuery not available, using vanilla JavaScript');
        return;
    }
    
    // Create unique key for this notification
    const notificationKey = `${staffName}_${role}_${status}_${activity}`;
    
    // Check if this exact notification is already active
    if (window.activeNotifications.has(notificationKey)) {
        console.log('Duplicate notification prevented:', notificationKey);
        return;
    }
    
    // Add cooldown to prevent rapid-fire notifications (minimum 1 second between notifications)
    const currentTime = Date.now();
    if (currentTime - window.lastNotificationTime < 1000) {
        console.log('Notification cooldown active, skipping:', notificationKey);
        return;
    }
    window.lastNotificationTime = currentTime;
    
    // Limit maximum notifications to 3
    const currentNotifications = $('.modern-notification').not('.removing').length;
    if (currentNotifications >= 3) {
        // Remove oldest notification
        const oldestNotification = $('.modern-notification').not('.removing').first();
        if (oldestNotification.length) {
            removeStaffNotification(oldestNotification);
        }
    }
    
    // Determine notification style based on activity
    let alertClass, iconClass, iconColor, textColor;
    let icon = 'fas fa-user';
    
    if (activity === 'logout') {
        alertClass = 'alert-danger';
        iconClass = 'fas fa-sign-out-alt';
        iconColor = '#721c24';
        textColor = '#721c24';
    } else if (activity === 'delivery') {
        alertClass = 'alert-warning';
        iconClass = 'fas fa-motorcycle';
        iconColor = '#856404';
        textColor = '#856404';
    } else {
        // Default login/online
        alertClass = 'alert-success';
        iconClass = 'fas fa-user';
        iconColor = '#155724';
        textColor = '#155724';
    }
    
    // Generate user image or fallback
    let userImageHtml = '';
    console.log('Processing image for:', staffName, 'Image path:', imagePath);
    
    if (imagePath && imagePath.trim() !== '') {
        // Construct full image URL - check if it already has a protocol
        let fullImagePath = imagePath;
        if (!imagePath.startsWith('http://') && !imagePath.startsWith('https://')) {
            // Get base URL from current page
            const baseURL = window.location.origin + window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/') + 1);
            fullImagePath = baseURL + imagePath;
        }
        console.log('Full image path:', fullImagePath);
        userImageHtml = `<img src="${fullImagePath}" alt="${staffName}" style="width: 100%; height: 100%; object-fit: cover; border-radius: 6px;">`;
    } else {
        // Fallback to UI Avatars with user initials
        console.log('Using fallback avatar for:', staffName);
        const initials = staffName.split(' ').map(n => n[0]).join('').toUpperCase();
        userImageHtml = `<img src="https://ui-avatars.com/api/?name=${encodeURIComponent(staffName)}&background=${iconColor.replace('#', '')}&color=fff&size=50" alt="${staffName}" style="width: 100%; height: 100%; object-fit: cover; border-radius: 6px;">`;
    }

    const notification = $(`
        <div class="modern-notification ${alertClass}" 
             style="position: fixed; left: 50%; transform: translateX(-50%); z-index: 9999; min-width: 380px; max-width: 500px; border-radius: 12px; margin-bottom: 10px; box-shadow: 0 8px 32px rgba(0,0,0,0.3); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.1); opacity: 0; transition: all 0.3s ease;">
            <div class="notification-content" style="padding: 16px 20px; position: relative;">
                <div class="d-flex align-items-center justify-content-center">
                    <div class="notification-icon" style="width: 50px; height: 50px; border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-right: 16px; background: rgba(255,255,255,0.1); overflow: hidden; border: 2px solid ${iconColor}40; flex-shrink: 0;">
                        ${userImageHtml}
                    </div>
                    <div class="flex-grow-1 text-center">
                        <div class="notification-title" style="font-weight: 600; font-size: 14px; color: #fff; margin-bottom: 2px;">
                            <strong>${staffName}</strong> (${role})
                        </div>
                        <div class="notification-message" style="font-size: 13px; color: rgba(255,255,255,0.8);">
                            is now <strong style="color: ${iconColor};">${status}</strong>
                        </div>
                        ${getTimestampInfo(activity, additionalData)}
                    </div>
                    <button type="button" class="notification-close" style="background: none; border: none; color: rgba(255,255,255,0.6); font-size: 18px; cursor: pointer; padding: 4px; border-radius: 4px; transition: all 0.2s ease; flex-shrink: 0;" onmouseover="this.style.color='#fff'; this.style.background='rgba(255,255,255,0.1)'" onmouseout="this.style.color='rgba(255,255,255,0.6)'; this.style.background='none'">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="notification-timer" style="position: absolute; bottom: 0; left: 0; height: 3px; background: linear-gradient(90deg, ${iconColor}, ${iconColor}80); border-radius: 0 0 12px 12px; width: 100%; transition: width 0.1s linear;"></div>
            </div>
        </div>
    `);
    
    // Add notification key to active set
    window.activeNotifications.add(notificationKey);
    
    // Store the key in the notification element for cleanup
    notification.data('notification-key', notificationKey);
    
    // Handle close button click
    notification.find('.notification-close').on('click', function() {
        removeStaffNotification(notification);
    });
    
    $('body').append(notification);
    
    // Position the notification in the stack
    positionNotification(notification);
    
    // Fade in animation
    setTimeout(() => {
        notification.css('opacity', '1');
    }, 10);
    
    // Start timer animation
    startNotificationTimer(notification, 8000);
}

// Start notification timer with visual countdown
function startNotificationTimer(notification, duration) {
    const timerBar = notification.find('.notification-timer');
    const startTime = Date.now();
    
    const updateTimer = () => {
        const elapsed = Date.now() - startTime;
        const remaining = Math.max(0, duration - elapsed);
        const progress = (remaining / duration) * 100;
        
        timerBar.css('width', progress + '%');
        
        if (remaining > 0) {
            requestAnimationFrame(updateTimer);
        } else {
            removeStaffNotification(notification);
        }
    };
    
    requestAnimationFrame(updateTimer);
}

// Position notification in the stack
function positionNotification(notification) {
    // Check if jQuery is available
    if (typeof $ === 'undefined') {
        console.warn('jQuery not available, using vanilla JavaScript');
        return;
    }
    
    const allNotifications = $('.modern-notification').not('.removing');
    const index = allNotifications.index(notification);
    const topPosition = 20 + (index * 80); // 20px from top, 80px spacing between notifications
    
    notification.css('top', topPosition + 'px');
}

function removeStaffNotification(notification) {
    // Check if jQuery is available
    if (typeof $ === 'undefined') {
        console.warn('jQuery not available, using vanilla JavaScript');
        return;
    }
    
    // Remove notification key from active set
    const notificationKey = notification.data('notification-key');
    if (notificationKey) {
        window.activeNotifications.delete(notificationKey);
    }
    
    // Add removing class for animation
    notification.addClass('removing');
    
    // Fade out animation
    notification.css({
        'opacity': '0',
        'transform': 'translateX(-50%) scale(0.95)'
    });
    
    // Remove after fade animation completes
    setTimeout(function() {
        notification.remove();
        // Reposition remaining notifications
        repositionStaffNotifications();
    }, 300);
}

function repositionStaffNotifications() {
    // Check if jQuery is available
    if (typeof $ === 'undefined') {
        console.warn('jQuery not available, using vanilla JavaScript');
        return;
    }
    
    $('.modern-notification').not('.removing').each(function(index) {
        const top = 20 + (index * 80);
        $(this).css('top', top + 'px');
    });
}

// Clear all notifications
function clearAllNotifications() {
    $('.modern-notification').each(function() {
        removeStaffNotification($(this));
    });
    window.activeNotifications.clear();
}

// Initialize staff notifications
document.addEventListener('DOMContentLoaded', function() {
    fetchStaffNotifications();
    // Refresh notifications every 10 seconds
    setInterval(fetchStaffNotifications, 10000);
    
    // Initialize SSE for real-time staff status notifications
    // Debounce initial welcome event to avoid showing online notifications on each page navigation
    setTimeout(() => {
        initStaffStatusSSE();
    }, 500);
    
    // Initialize notification sound controls
    initNotificationSoundControls();
});

// Initialize notification sound controls
function initNotificationSoundControls() {
    const muteToggleBtn = document.getElementById('muteToggleBtn');
    const testSoundBtn = document.getElementById('testSoundBtn');
    const muteIcon = document.getElementById('muteIcon');
    
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

// SSE for staff status notifications
function initStaffStatusSSE() {
    try {
        const staffEventSource = new EventSource('sse_staff_status.php');
        
        staffEventSource.onmessage = function(event) {
            try {
                const data = JSON.parse(event.data);
                
                if (data.staff_status_change) {
                    // Debug: Log the received data
                    console.log('Staff status change received:', data);
                    
                    // Show specific user activity notification
                    if (data.staff_name && data.role && data.status) {
                        console.log('Showing notification for:', data.staff_name, data.role, data.status, data.activity);
                        console.log('Image path received:', data.image_path);
                        const activity = data.activity || 'login';
                        const imagePath = data.image_path || '';
                        
                        // Prepare additional data for timestamps and duty duration
                        const additionalData = {};
                        if (data.login_time) additionalData.login_time = data.login_time;
                        if (data.logout_time) additionalData.logout_time = data.logout_time;
                        if (data.delivery_time) additionalData.delivery_time = data.delivery_time;
                        if (data.status_change_time) additionalData.status_change_time = data.status_change_time;
                        if (data.duty_duration) additionalData.duty_duration = data.duty_duration;
                        
                        showUserActivityNotification(data.staff_name, data.role, data.status, activity, imagePath, additionalData);
                    } else {
                        console.log('Missing data for notification:', data);
                    }
                    
                    // Play notification sound if available
                    if (typeof notificationSound !== 'undefined' && notificationSound) {
                        notificationSound.play();
                    }
                    
                    // Refresh notification list
                    fetchStaffNotifications();
                }
            } catch (error) {
                console.error('Error parsing staff SSE message:', error);
            }
        };
        
        staffEventSource.onerror = function(event) {
            console.error('Staff Status SSE Error:', event);
            staffEventSource.close();
            
            // Retry connection after 5 seconds
            setTimeout(() => {
                initStaffStatusSSE();
            }, 5000);
        };
    } catch (error) {
        console.error('Error initializing staff SSE:', error);
        // Retry after 5 seconds
        setTimeout(() => {
            initStaffStatusSSE();
        }, 5000);
    }
}
</script>


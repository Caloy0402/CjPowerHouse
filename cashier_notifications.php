<?php
require 'dbconn.php';

function getPendingOrdersCount($conn) {
    $sql = "SELECT COUNT(*) as count FROM orders WHERE order_status = 'pending'";
    $result = $conn->query($sql);
    if ($result) {
        $row = $result->fetch_assoc();
        return $row['count'];
    }
    return 0;
}

function getPendingOrders($conn) {
    $sql = "SELECT o.id as order_id, o.transaction_id, o.order_status, o.order_date, 
            o.total_price, o.payment_method, o.user_id, o.delivery_method, 
            o.rider_name, o.rider_motor_type, o.rider_plate_number,
            GROUP_CONCAT(CONCAT(p.ProductName, ' (', oi.quantity, ')') SEPARATOR ', ') as items
            FROM orders o
            LEFT JOIN order_items oi ON o.id = oi.order_id
            LEFT JOIN products p ON oi.product_id = p.ProductID
            WHERE o.order_status = 'pending'
            GROUP BY o.id
            ORDER BY o.order_date DESC";
            
    $result = $conn->query($sql);
    $notifications = array();
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $notifications[] = array(
                'order_id' => $row['order_id'],
                'transaction_id' => $row['transaction_id'],
                'order_status' => $row['order_status'],
                'order_date' => $row['order_date'],
                'total_price' => $row['total_price'],
                'payment_method' => $row['payment_method'],
                'delivery_method' => $row['delivery_method'],
                'rider_name' => $row['rider_name'],
                'rider_motor_type' => $row['rider_motor_type'],
                'rider_plate_number' => $row['rider_plate_number'],
                'items' => $row['items']
            );
        }
    }
    
    return $notifications;
}
?>

<!-- Notification Dropdown HTML -->
<div class="nav-item dropdown">
    <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
        <i class="fa fa-bell me-lg-2"></i>
        <span class="d-none d-lg-inline">Notifications</span>
        <span class="notification-badge" id="notificationCount" style="display: none;">0</span>
    </a>
    <div class="dropdown-menu dropdown-menu-end bg-secondary border-0 rounded-0 rounded-bottom m-0 notification-menu">
        <!-- Notification Sound Controls -->
        <div class="dropdown-header d-flex justify-content-between align-items-center">
            <span>Order Notifications</span>
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
        <div class="notification-items" id="notificationItems">
            <!-- Notifications will be populated here -->
        </div>
    </div>
</div>

<!-- Notification JavaScript -->
<script>
// Initialize notification sound system
let notificationSound = null;
let lastNotificationIds = new Set();
let isInitialLoad = true;
let lastSoundPlayTime = 0;
const SOUND_DEBOUNCE_TIME = 2000; // 2 seconds between sounds

function fetchNotifications() {
    fetch('get_cashier_notifications.php')
        .then(response => response.json())
        .then(data => {
            // Update notification count
            const count = data.notifications.length;
            const previousCount = parseInt(notificationCount.textContent) || 0;
            notificationCount.textContent = count;
            notificationCount.style.display = count > 0 ? 'block' : 'none';
            
            // Check for new notifications by comparing notification IDs
            const currentNotificationIds = new Set(data.notifications.map(n => n.order_id));
            const hasNewNotifications = [...currentNotificationIds].some(id => !lastNotificationIds.has(id));
            
            // Play notification sound and show banner for genuinely new notifications
            const currentTime = Date.now();
            const isPageVisible = !document.hidden;
            if (hasNewNotifications && !isInitialLoad && notificationSound && 
                (currentTime - lastSoundPlayTime) > SOUND_DEBOUNCE_TIME && isPageVisible) {
                console.log('Playing notification sound - new notification detected');
                notificationSound.play();
                lastSoundPlayTime = currentTime;
                
                // Show banner notification for new orders
                const newOrderCount = count - lastNotificationIds.size;
                if (newOrderCount > 0) {
                    showNewOrderNotification(newOrderCount);
                }
            }
            
            // Update the last known notification IDs
            lastNotificationIds = currentNotificationIds;
            isInitialLoad = false;
            
            // Update barangay filter counts in real-time
            updateBarangayCounts();

            // Update notification items
            notificationItems.innerHTML = '';
            if (count === 0) {
                notificationItems.innerHTML = '<div class="dropdown-item text-center">No pending orders</div>';
            } else {
                data.notifications.forEach(notification => {
                    const targetHref = (notification.payment_method || '').toUpperCase() === 'COD'
                        ? `Cashier-COD-Delivery.php?order_id=${notification.order_id}`
                        : `Cashier-GCASH-Delivery.php?order_id=${notification.order_id}`;
                    const item = document.createElement('a');
                    item.className = 'dropdown-item';
                    item.href = targetHref;
                    const orderIdLabel = (notification.order_id || '');
                    item.innerHTML = `
                        <div class="notification-item">
                            ${orderIdLabel ? `<h6 class=\"fw-normal mb-0\">Order #${orderIdLabel}</h6>` : ''}
                            <p><strong>Items:</strong> ${notification.items}</p>
                            <p><strong>Subtotal:</strong> ₱${notification.total_price}</p>
                            <p><strong>Delivery Fee:</strong> ₱${notification.delivery_fee}</p>
                            <p><strong>Total Amount:</strong> ₱${notification.total_with_delivery}</p>
                            <p><strong>Payment Method:</strong> ${notification.payment_method}</p>
                            <p><strong>Delivery Method:</strong> ${notification.delivery_method}</p>
                            ${notification.rider_name ? `
                                <p><strong>Rider:</strong> ${notification.rider_name}</p>
                                <p><strong>Vehicle:</strong> ${notification.rider_motor_type} (${notification.rider_plate_number})</p>
                            ` : ''}
                            <p><strong>Status:</strong> ${notification.order_status}</p>
                            <small>${notification.order_date}</small>
                        </div>
                        <hr class="dropdown-divider">
                    `;
                    notificationItems.appendChild(item);
                });
            }
        })
        .catch(error => {
            console.error('Error fetching notifications:', error);
            notificationItems.innerHTML = '<div class="dropdown-item text-center">Error loading notifications</div>';
        });
}

// Function to update barangay filter counts in real-time
function updateBarangayCounts() {
    // Get the current page type to determine which endpoint to call
    const currentPage = window.location.pathname;
    let endpoint = '';
    
    if (currentPage.includes('COD-Delivery')) {
        endpoint = 'get_barangay_cod_pending_counts.php';
    } else if (currentPage.includes('COD-Ready')) {
        endpoint = 'get_barangay_cod_ready_counts.php';
    } else if (currentPage.includes('COD-Onship')) {
        endpoint = 'get_barangay_cod_onship_counts.php';
    } else if (currentPage.includes('GCASH-Delivery')) {
        endpoint = 'get_barangay_gcash_pending_counts.php';
    } else if (currentPage.includes('GCASH-Ready')) {
        endpoint = 'get_barangay_gcash_ready_counts.php';
    } else if (currentPage.includes('GCASH-OnShip')) {
        endpoint = 'get_barangay_gcash_onship_counts.php';
    }
    
    if (endpoint) {
        fetch(endpoint)
            .then(response => response.json())
            .then(data => {
                // Update all barangay button badges
                data.forEach(barangay => {
                    const button = document.querySelector(`a[href*="barangay_id=${barangay.id}"]`);
                    if (button) {
                        let badge = button.querySelector('.badge');
                        if (barangay.count > 0) {
                            if (!badge) {
                                badge = document.createElement('span');
                                badge.className = 'badge';
                                button.appendChild(badge);
                            }
                            const oldCount = badge.textContent;
                            badge.textContent = barangay.count;
                            
                            // Add visual feedback for count changes
                            if (oldCount && oldCount !== barangay.count.toString()) {
                                badge.style.animation = 'pulse 0.5s ease-in-out';
                                setTimeout(() => {
                                    badge.style.animation = '';
                                }, 500);
                            }
                        } else if (badge) {
                            badge.remove();
                        }
                    }
                });
            })
            .catch(error => {
                console.error('Error updating barangay counts:', error);
            });
    }
}

// Function to show banner notification for new orders
function showNewOrderNotification(count) {
    // Create a banner notification
    const notification = document.createElement('div');
    notification.className = 'alert alert-success alert-dismissible fade show position-fixed';
    notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);';
    notification.innerHTML = `
        <i class="fa fa-shopping-cart me-2"></i>
        <strong>New Order!</strong> ${count} new order${count > 1 ? 's' : ''} received.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    `;
    
    document.body.appendChild(notification);
    
    // Auto-remove after 5 seconds
    setTimeout(() => {
        if (notification.parentNode) {
            notification.style.opacity = '0';
            notification.style.transition = 'opacity 0.3s ease';
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 300);
        }
    }, 5000);
}

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

// Initial fetch of notifications
document.addEventListener('DOMContentLoaded', function() {
    // Initialize notification sound
    notificationSound = new NotificationSound({
        soundFile: 'uploads/NofiticationCash.mp3',
        volume: 1.0,
        enableMute: true,
        enableTest: true,
        storageKey: 'cashierNotificationSoundSettings'
    });
    
    // Initialize notification sound controls
    initNotificationSoundControls();
    
    // Reset tracking on page load
    lastNotificationIds.clear();
    isInitialLoad = true;
    lastSoundPlayTime = 0;
    
    fetchNotifications();
    // Refresh notifications every 5 seconds (synced with table updates)
    setInterval(fetchNotifications, 5000);
});
</script>

<style>
.notification-badge {
    position: absolute;
    top: -5px;
    right: -5px;
    padding: 2px 5px;
    border-radius: 50%;
    background-color: red;
    color: white;
    font-size: 10px;
}

.notification-menu { min-width: 320px; max-height: 420px; overflow-y: auto; padding: 10px; }
.notification-items .dropdown-item { display:block; text-decoration:none; }
.notification-item { padding: 10px; background: #f9f9f9; border-radius: 8px; margin-bottom: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.08); }
.notification-item h6 { color: #333; margin-bottom: 6px; }
.notification-item p { margin: 4px 0; font-size: 14px; color: #666; }
.notification-item small { color: #999; font-size: 12px; }

/* Banner notification styles */
.alert.position-fixed {
    animation: slideInRight 0.3s ease-out;
    border-left: 4px solid #28a745;
}

@keyframes slideInRight {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

.alert .btn-close {
    padding: 0.5rem 0.5rem;
    margin: -0.5rem -0.5rem -0.5rem auto;
}

/* Badge pulse animation for real-time updates */
@keyframes pulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.2); background-color: #dc3545; }
    100% { transform: scale(1); }
}
</style> 
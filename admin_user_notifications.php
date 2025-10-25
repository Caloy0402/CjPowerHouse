<?php
// Silent errors for UI safety
error_reporting(0);
ini_set('display_errors', 0);

require 'dbconn.php';

// Ensure column exists (idempotent)
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS cod_failed_attempts INT DEFAULT 0");

function getUserCODFailNotifications(mysqli $conn) {
    $notifications = [];

    $q = "SELECT id, first_name, last_name, cod_failed_attempts
          FROM users
          WHERE cod_failed_attempts >= 2
            AND NOT (cod_suspended = 1 AND cod_suspended_until IS NOT NULL AND cod_suspended_until > NOW())
          ORDER BY cod_failed_attempts DESC, id DESC";
    if ($res = $conn->query($q)) {
        while ($row = $res->fetch_assoc()) {
            $fullName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            $attempts = (int)$row['cod_failed_attempts'];
            $notifications[] = [
                'type' => 'user_cod_failed',
                'title' => 'User COD Failure',
                'message' => $fullName . ' failed to receive COD ' . $attempts . ' times',
                'count' => $attempts,
                'icon' => 'fa-user-times',
                'color' => 'text-danger',
                'user_id' => (int)$row['id'],
            ];
        }
        $res->free();
    }

    return $notifications;
}

function getTotalUserCODFailCount(mysqli $conn) {
    $total = 0;
    $q = "SELECT COUNT(*) AS c FROM users WHERE cod_failed_attempts >= 2";
    if ($res = $conn->query($q)) {
        $row = $res->fetch_assoc();
        $total = (int)$row['c'];
        $res->free();
    }
    return $total;
}

// Function to output JavaScript for user notifications
function outputUserNotificationJS() {
    echo '<script>
function fetchUserNotifications() {
    fetch("get_user_notifications.php")
        .then(r => r.json())
        .then(data => {
            const countEl = document.getElementById("userNotificationCount");
            const itemsEl = document.getElementById("userNotificationItems");
            if (!countEl || !itemsEl) return;

            countEl.textContent = data.total_count || 0;
            countEl.style.display = (data.total_count || 0) > 0 ? "block" : "none";

            itemsEl.innerHTML = "";
            if (!data.notifications || data.notifications.length === 0) {
                itemsEl.innerHTML = "<div class=\"dropdown-item text-center\">No user COD issues</div>";
                return;
            }
            data.notifications.forEach(n => {
                const item = document.createElement("div");
                item.className = "dropdown-item notification-item";
                item.setAttribute("data-user-id", n.user_id);
                item.innerHTML = `
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0"><i class="fas ${n.icon} ${n.color} me-2"></i></div>
                        <div class="flex-grow-1">
                            <h6 class="fw-normal mb-0">${n.title}</h6>
                            <p class="mb-0 small">${n.message}</p>
                        </div>
                        <div class="flex-shrink-0"><span class="badge bg-danger">${n.count}</span></div>
                    </div>`;
                item.addEventListener("click", function(){
                    window.location.href = "Admin-ManageUser.php";
                });
                itemsEl.appendChild(item);
            });

            try { 
                maybeShowUserCODFailBanners(data.notifications || []); 
            } catch (e) { 
                console.warn("User banner error", e); 
            }
        })
        .catch(err => console.error("User notifications error:", err));
}

document.addEventListener("DOMContentLoaded", function(){
    fetchUserNotifications();
    setInterval(fetchUserNotifications, 15000);
});

// Track which users we have already notified in this session
window.userCODNotifiedIds = window.userCODNotifiedIds || new Set();

function maybeShowUserCODFailBanners(notifications){
    notifications.forEach(n => {
        const id = n.user_id;
        if (!window.userCODNotifiedIds.has(id)) {
            showUserCODFailNotification(n);
            window.userCODNotifiedIds.add(id);
        }
    });
}

// Use the staff notification visual system to display a red banner for user COD failures
function showUserCODFailNotification(n){
    if (typeof $ === "undefined") return;

    const iconColor = "#dc3545";
    const message = n.message || "A user has failed COD 2+ times";
    const title = n.title || "User COD Failure";
    const userImage = (n.image_path || "").trim();
    const userName = n.name || "";

    let userImageHtml = "";
    if (userImage) {
        let fullImagePath = userImage;
        if (!userImage.startsWith("http://") && !userImage.startsWith("https://")) {
            const baseURL = window.location.origin + window.location.pathname.substring(0, window.location.pathname.lastIndexOf("/") + 1);
            fullImagePath = baseURL + userImage;
        }
        userImageHtml = `<img src="${fullImagePath}" alt="${userName}" style="width: 100%; height: 100%; object-fit: cover; border-radius: 6px;">`;
    } else {
        userImageHtml = `<i class="fas fa-user-times" style="color:${iconColor}; font-size:22px;"></i>`;
    }

    const notification = $(`
        <div class="modern-notification alert-danger" 
             style="position: fixed; left: 50%; transform: translateX(-50%); z-index: 9999; min-width: 380px; max-width: 500px; border-radius: 12px; margin-bottom: 10px; box-shadow: 0 8px 32px rgba(0,0,0,0.3); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.1); opacity: 0; transition: all 0.3s ease;">
            <div class="notification-content" style="padding: 16px 20px; position: relative;">
                <div class="d-flex align-items-center justify-content-center">
                    <div class="notification-icon" style="width: 50px; height: 50px; border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-right: 16px; background: rgba(255,255,255,0.1); overflow: hidden; border: 2px solid ${iconColor}40; flex-shrink: 0;">${userImageHtml}</div>
                    <div class="flex-grow-1 text-center">
                        <div class="notification-title" style="font-weight: 600; font-size: 14px; color: #fff; margin-bottom: 2px;">${title}</div>
                        <div class="notification-message" style="font-size: 13px; color: rgba(255,255,255,0.85);">${message}</div>
                    </div>
                    <button type="button" class="notification-close" style="background: none; border: none; color: rgba(255,255,255,0.6); font-size: 18px; cursor: pointer; padding: 4px; border-radius: 4px; transition: all 0.2s ease; flex-shrink: 0;" onmouseover="this.style.color=\'#fff\'; this.style.background=\'rgba(255,255,255,0.1)\'" onmouseout="this.style.color=\'rgba(255,255,255,0.6)\'; this.style.background=\'none\'">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="notification-timer" style="position: absolute; bottom: 0; left: 0; height: 3px; background: linear-gradient(90deg, ${iconColor}, ${iconColor}80); border-radius: 0 0 12px 12px; width: 100%; transition: width 0.1s linear;"></div>
            </div>
        </div>
    `);

    if (typeof positionNotification === "function") {
        $("body").append(notification);
        positionNotification(notification);
        setTimeout(() => { notification.css("opacity", "1"); }, 10);
        if (typeof startNotificationTimer === "function") {
            startNotificationTimer(notification, 8000);
        }
        notification.find(".notification-close").on("click", function(){
            if (typeof removeStaffNotification === "function") {
                removeStaffNotification(notification);
            } else {
                notification.remove();
            }
        });
    } else {
        $("body").append(notification);
        setTimeout(() => notification.css("opacity","1"), 10);
        setTimeout(() => notification.remove(), 5000);
    }
}
</script>';
}

$userNotifications = getUserCODFailNotifications($conn);
$totalUserNotifications = getTotalUserCODFailCount($conn);
?>

<!-- User Status Notification System -->
<div class="nav-item dropdown">
    <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
        <i class="fa fa-user-times me-lg-2"></i>
        <span class="d-none d-lg-inline">User Status</span>
        <span class="notification-badge" id="userNotificationCount" style="display: <?php echo $totalUserNotifications > 0 ? 'block' : 'none'; ?>;"><?php echo $totalUserNotifications; ?></span>
    </a>
    <div class="dropdown-menu dropdown-menu-end bg-secondary border-0 rounded-0 rounded-bottom m-0" style="min-width: 300px; max-height: 400px; overflow-y: auto;">
        <div class="dropdown-header d-flex justify-content-between align-items-center">
            <span>Users With 2+ COD Failures</span>
        </div>
        <hr class="dropdown-divider">
        <div class="notification-items" id="userNotificationItems">
            <?php if (empty($userNotifications)): ?>
                <div class="dropdown-item text-center">No user COD issues</div>
            <?php else: ?>
                <?php foreach ($userNotifications as $n): ?>
                    <div class="dropdown-item notification-item" data-user-id="<?php echo (int)$n['user_id']; ?>">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <i class="fas <?php echo $n['icon']; ?> <?php echo $n['color']; ?> me-2"></i>
                            </div>
                            <div class="flex-grow-1">
                                <h6 class="fw-normal mb-0"><?php echo htmlspecialchars($n['title']); ?></h6>
                                <p class="mb-0 small"><?php echo htmlspecialchars($n['message']); ?></p>
                            </div>
                            <div class="flex-shrink-0">
                                <span class="badge bg-danger"><?php echo (int)$n['count']; ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
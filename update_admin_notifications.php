<?php
// Script to update all admin pages with the new notification system

$adminFiles = [
    'Admin-AddUser.php',
    'Admin-AddRider.php', 
    'Admin-ManageUser.php',
    'Admin-Stockmanagement.php',
    'Admin-buy-out-item.php',
    'Admin-ReturnedItems.php',
    'Admin-OrderLogs.php',
    'Admin-SalesReport.php'
];

$oldNotificationCode = '<div class="nav-item dropdown">
                    <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="fa fa-bell me-lg-2"></i>
                        <span class="d-none d-lg-inline">Notifications</span>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end bg-secondary 
                    border-0 rounded-0 rounded-bottom m-0">
                        <a href="#" class="dropdown-item">
                            <h6 class="fw-normal mb-0">profile updated</h6>

                            <small>10 minutes ago</small>
                        </a>
                        <hr class="dropdown-divider">
                        <a href="#" class="dropdown-item">
                            <h6 class="fw-normal mb-0">Password Changed</h6>
                            <small>15 minutes ago</small>
                        </a>
                        <a hr class="dropdown-divider">
                        <a href="#" class="dropdown-item">
                            <h6 class="fw-normal mb-0">User Added</h6>
                            <small>20 minutes ago</small>
                        </a>
                        <a hr class="dropdown-divider">
                        <a href="#" class="dropdown-item text-center">See all 
                        Notification</a>
                 </div>        
            </div>';

$newNotificationCode = '<?php include \'admin_notifications.php\'; ?>';

foreach ($adminFiles as $file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        $updatedContent = str_replace($oldNotificationCode, $newNotificationCode, $content);
        
        if ($content !== $updatedContent) {
            file_put_contents($file, $updatedContent);
            echo "Updated: $file\n";
        } else {
            echo "No changes needed: $file\n";
        }
    } else {
        echo "File not found: $file\n";
    }
}

echo "\nAll admin pages have been updated with the new notification system!\n";
?>

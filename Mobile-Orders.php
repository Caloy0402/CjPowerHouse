<?php
session_start();
require 'dbconn.php'; // Ensure this file contains your database connection logic

// Dynamically determine the base URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$path = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');  // Get directory of the current script
$baseURL = $protocol . '://' . $host . $path . '/';

if (!isset($_SESSION['user_id'])) {
    header("Location: {$baseURL}signin.php"); // Redirect to login if user is not logged in
    exit();
}

$user_id = $_SESSION['user_id'];

// Handle AJAX cancel order request FIRST before any HTML output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel') {
    // Clear any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Set JSON header
    header('Content-Type: application/json; charset=utf-8');
    
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    
    if ($order_id > 0) {
        // Check if the order exists and belongs to the user
        $sql = "SELECT order_status FROM orders WHERE id = ? AND user_id = ?";
        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            echo json_encode(['success' => false, 'message' => 'Database prepare error: ' . $conn->error]);
            $conn->close();
            exit();
        }
        
        $stmt->bind_param("ii", $order_id, $user_id);
        $stmt->execute();
        $stmt->store_result();
        
        if ($stmt->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'Order not found or does not belong to you.']);
            $stmt->close();
            $conn->close();
            exit();
        }
        
        $stmt->bind_result($order_status);
        $stmt->fetch();
        $stmt->close();

        if ($order_status === 'Pending') {
            // Update the order status to "Canceled"
            $update_sql = "UPDATE orders SET order_status = 'Canceled' WHERE id = ? AND user_id = ?";
            $update_stmt = $conn->prepare($update_sql);
            
            if (!$update_stmt) {
                echo json_encode(['success' => false, 'message' => 'Database prepare error: ' . $conn->error]);
                $conn->close();
                exit();
            }
            
            $update_stmt->bind_param("ii", $order_id, $user_id);
            
            if ($update_stmt->execute() && $update_stmt->affected_rows > 0) {
                echo json_encode(['success' => true, 'message' => 'Order canceled successfully.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to cancel order. Please try again.']);
            }
            
            $update_stmt->close();
        } else {
            echo json_encode(['success' => false, 'message' => 'Only pending orders can be canceled. Current status: ' . $order_status]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid order ID.']);
    }
    
    $conn->close();
    exit();
}

// Fetch orders from the database with delivery fee and distance if available
// Detect schema features
$ordersHasDeliveryCols = false;
$checkOrderCols = $conn->query("SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'orders' AND COLUMN_NAME IN ('delivery_fee','total_amount_with_delivery')");
if ($checkOrderCols) {
    $present = [];
    while ($c = $checkOrderCols->fetch_assoc()) { $present[] = $c['COLUMN_NAME']; }
    if (in_array('delivery_fee', $present) && in_array('total_amount_with_delivery', $present)) {
        $ordersHasDeliveryCols = true;
    }
}

$hasFareTable = false;
$barangaysHasDistanceColumn = false;
$checkFareTableSql = "SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'barangay_fares'";
$checkFareTable = $conn->query($checkFareTableSql);
if ($checkFareTable && ($row = $checkFareTable->fetch_assoc()) && (int)$row['cnt'] > 0) {
    $hasFareTable = true;
}
$checkBarangayCols = $conn->query("SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'barangays' AND COLUMN_NAME IN ('distance_km')");
if ($checkBarangayCols) {
    while ($col = $checkBarangayCols->fetch_assoc()) {
        if ($col['COLUMN_NAME'] === 'distance_km') { $barangaysHasDistanceColumn = true; }
    }
}

$selectDeliveryCols = $ordersHasDeliveryCols
    ? "o.delivery_fee, o.total_amount_with_delivery,"
    : "0 AS delivery_fee, o.total_price AS total_amount_with_delivery,";

$joinFare = '';
$selectDistance = '0 AS distance_km';
if ($hasFareTable) {
    $joinFare = 'LEFT JOIN barangay_fares bf ON b.id = bf.barangay_id';
    $selectDistance = 'COALESCE(bf.distance_km, 0) AS distance_km';
} else if ($barangaysHasDistanceColumn) {
    $selectDistance = 'COALESCE(b.distance_km, 0) AS distance_km';
}

// Build SQL
$sql = "SELECT o.id, o.total_price, o.order_status, o.order_date,
               oi.product_id, oi.quantity, oi.price,
               p.ProductName, p.category, p.ImagePath,
               t.transaction_number,
               o.total_weight,
               o.delivery_method,
               o.rider_name,
               o.rider_contact,
               o.rider_motor_type,
               o.rider_plate_number,
               o.payment_method,
               o.reason,
               u.purok AS purok,   
               b.barangay_name AS barangay_name,  
               u.first_name AS user_first_name,  
               u.last_name AS user_last_name,
               u.email AS user_email,
               u.phone_number AS user_phone,
               o.home_description,   
               gt.reference_number,          
               gt.client_transaction_date_str,
               gt.transaction_date AS gcash_server_transaction_date,
               $selectDeliveryCols
               $selectDistance
        FROM orders o
        JOIN order_items oi ON o.id = oi.order_id
        JOIN products p ON oi.product_id = p.ProductID
        JOIN transactions t ON o.id = t.order_id
        JOIN users u ON o.user_id = u.id
        JOIN barangays b ON u.barangay_id = b.id
        $joinFare
        LEFT JOIN gcash_transactions gt ON o.id = gt.order_id 
        WHERE o.user_id = ?
        ORDER BY o.order_date DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$orders = [];
while ($row = $result->fetch_assoc()) {
    $order_id = $row['id'];
    if (!isset($orders[$order_id])) {
        $orders[$order_id] = [
            'id' => $order_id,
            'total' => $row['total_price'],
            'delivery_fee' => $row['delivery_fee'],
            'total_with_delivery' => $row['total_amount_with_delivery'],
            'status' => $row['order_status'],
            'date' => $row['order_date'],
            'transaction_number' => $row['transaction_number'],
            'total_weight' => $row['total_weight'],
            'delivery_method' => $row['delivery_method'],
            'rider_name' => $row['rider_name'],
            'rider_contact' => $row['rider_contact'],
            'rider_motor_type' => $row['rider_motor_type'],
            'rider_plate_number' => $row['rider_plate_number'],
            'payment_method' => $row['payment_method'],
            'reason' => $row['reason'],
            'user_first_name' => $row['user_first_name'],
            'user_last_name' => $row['user_last_name'],
            'user_email' => $row['user_email'],
            'user_phone' => $row['user_phone'],
            'purok' => $row['purok'],
            'barangay_name' => $row['barangay_name'],
            'distance_km' => $row['distance_km'],
            'home_description' => $row['home_description'],
            'gcash_ref_no' => $row['reference_number'],             
            'gcash_client_transaction_date' => $row['client_transaction_date_str'], // Renamed for clarity
            'gcash_server_transaction_date' => $row['gcash_server_transaction_date'], // ADDED
            'items' => []
        ];
    }
        $orders[$order_id]['items'][] = [
            'name' => $row['ProductName'],
            'image' => $row['ImagePath'],
            'price' => $row['price'],
            'quantity' => $row['quantity'],
            'category' => $row['category']
        ];
}

$stmt->close();

// Function to calculate estimated delivery time based on current time
function calculateEstimatedDeliveryTime($orderStatus, $orderDate = null) {
    // Only calculate for Ready to Ship and On-Ship statuses
    if ($orderStatus !== 'Ready to Ship' && $orderStatus !== 'On-Ship') {
        return ['time' => null, 'date' => null];
    }
    
    $now = new DateTime();
    $currentHour = (int)$now->format('H');
    
    // Check if current time is between 5 PM (17:00) and 5 AM (05:00) next day
    // This covers: 5 PM today to 11:59 PM today, and 12:00 AM to 5 AM next day
    if ($currentHour >= 17 || $currentHour < 5) {
        // Set estimated time to 9 AM - 5 PM of next day
        $estimatedDate = clone $now;
        if ($currentHour >= 17) {
            // It's between 5 PM and 11:59 PM, so next day
            $estimatedDate->modify('+1 day');
        } else {
            // It's between 12 AM and 5 AM, so today is already the next day
            // Use today's date
        }
        return [
            'time' => '9:00 AM - 5:00 PM',
            'date' => $estimatedDate->format('F j, Y')
        ];
    } else {
        // Regular hours (5 AM to 5 PM), set to end of current day
        $endOfDay = clone $now;
        $endOfDay->setTime(23, 59, 59);
        return [
            'time' => $endOfDay->format('g:i A'),
            'date' => $endOfDay->format('F j, Y')
        ];
    }
}

// Calculate estimated time for orders that are Ready to Ship or On-Ship
foreach ($orders as $order_id => $order) {
    if ($order['status'] === 'Ready to Ship' || $order['status'] === 'On-Ship') {
        $estimated = calculateEstimatedDeliveryTime($order['status'], $order['date']);
        $orders[$order_id]['estimated_time'] = $estimated['time'];
        $orders[$order_id]['estimated_date'] = $estimated['date'];
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Orders</title>
    <link rel="icon" type="image/png" href="<?= $baseURL ?>Image/logo.png">
    <link href="<?= $baseURL ?>css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= $baseURL ?>css/styles.css">
    <link rel="stylesheet" href="<?= $baseURL ?>css/modal-styles.css">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
        /* Add some basic styling for the modal */
        .modal-body p {
            color: black; /* Ensures text is readable */
        }

        /* Add style for table-responsive */
        .table-responsive {
            overflow-x: auto;
        }

        /* Add style for smaller screens to limit modal height */
        @media (max-width: 768px) {
            .modal-dialog {
                max-height: 80vh;
                overflow-y: auto;
            }
        }
    </style>
</head>
<body>
    <div class="app">
        <header class="cart-header">
            <a href="<?= $baseURL ?>Mobile-Dashboard.php" class="back-button">
                <span class="material-icons">arrow_back</span>
            </a>
            <h1 style="text-align: center; color: black; font-weight: bold;">My Orders</h1>
        </header>

        <!-- Status Filter -->
        <div style="padding: 10px 12px; background:#fff; border-bottom:1px solid #eee;">
            <label for="statusFilter" style="color:#333; font-weight:600; font-size:14px; margin-right:8px;">Filter by status:</label>
            <select id="statusFilter" style="padding:8px 10px; border-radius:8px; border:1px solid #ddd; background:#fff; color:#333;">
                <option value="all" selected>All</option>
                <option value="Pending">Pending</option>
                <option value="Pending Payment">Pending Payment</option>
                <option value="Processing">Processing</option>
                <option value="Ready to Ship">Ready to Ship</option>
                <option value="Ready for Pickup">Ready for Pickup</option>
                <option value="On-Ship">On-Ship</option>
                <option value="Completed">Completed</option>
                <option value="Canceled">Canceled</option>
            </select>
        </div>

        <div class="orders-container" id="ordersContainer">
            <!-- Orders will be loaded here by JavaScript -->
        </div>

 
       <div class="modal fade" id="emergencyReportModal" tabindex="-1" aria-labelledby="emergencyReportModalLabel" aria-hidden="true">
           <div class="modal-dialog modal-dialog-centered">
               <div class="modal-content">
                   <div class="modal-header">
                       <h5 class="modal-title" id="emergencyReportModalLabel">Report Emergency</h5>
                       <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                   </div>
                   <div class="modal-body">
                       <form id="emergencyReportForm">
                           <div class="mb-3">
                               <label for="name" class="form-label">Your Name</label>
                               <input type="text" class="form-control" id="name" name="name" required>
                           </div>
                           <div class="mb-3">
                               <label for="bikeUnit" class="form-label">Bike Unit</label>
                               <input type="text" class="form-control" id="bikeUnit" name="bikeUnit" placeholder="e.g., XRM 125, Click 150" required>
                           </div>
                           <div class="mb-3">
                               <label for="plateNumber" class="form-label">Plate Number</label>
                               <input type="text" class="form-control" id="plateNumber" name="plateNumber">
                           </div>
                           <div class="mb-3">
                               <label for="problem" class="form-label">What seems to be the problem?</label>
                               <textarea class="form-control" id="problem" name="problem" rows="3" required></textarea>
                           </div>
                           <div class="mb-3">
                               <label for="location" class="form-label">Your Location</label>
                               <textarea class="form-control" id="location" name="location" rows="3" placeholder="Please provide specific details about your location" required></textarea>
                           </div>
                           <div class="mb-3">
                               <label for="contactInfo" class="form-label">Contact Number</label>
                               <input type="tel" class="form-control" id="contactInfo" name="contactInfo" placeholder="e.g., 09XXXXXXXXX" pattern="09\d{9}" maxlength="11" oninput="this.value = this.value.replace(/[^0-9]/g, '');" required>
                               <small class="form-text text-muted">Please enter a valid 11-digit Philippine mobile number.</small>
                           </div>
                           <button type="submit" class="btn btn-danger">Submit Emergency Report</button>
                       </form>
                   </div>
               </div>
           </div>
       </div>
        <!-- The Modal -->
        <div id="orderSuccessModal" class="modal">
            <div class="modal-content">
                <div class="checkmark-circle">
                    <div class="checkmark draw"></div>
                </div>
                <h2 style="color: black">Order Placed Successfully!</h2>
                <p>Your order has been received and is being processed.</p>
            </div>
        </div>

    <!-- Order Details Modal -->
    <div class="modal fade" id="orderDetailsModal" tabindex="-1" aria-labelledby="orderDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="orderDetailsModalLabel">Order Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="orderDetailsModalBody">
                    <!-- Order details will be dynamically populated here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    </div>
    <script>
        const orders = <?php echo json_encode(array_values($orders)); ?>;
        const baseURL = "<?= $baseURL ?>"; // Pass the base URL to JavaScript
        const userId = <?php echo $user_id; ?>; // Pass user ID for SSE
    </script>
    <script src="<?= $baseURL ?>js/orders.js?v=<?= time() ?>"></script>
    <script>
      // Safety: remove any leftover refresh button if cached script added it
      document.addEventListener('DOMContentLoaded', function() {
        var btn = document.querySelector('.refresh-orders-btn');
        if (btn && btn.parentNode) {
          btn.parentNode.removeChild(btn);
        }
      });

      // Real-time order updates with SSE
      let eventSource = null;
      let lastNotificationTime = 0;
      const NOTIFICATION_COOLDOWN = 3000; // 3 seconds cooldown between notifications

      function initializeSSE() {
        if (eventSource) {
          eventSource.close();
        }

        eventSource = new EventSource(`sse_mobile_orders.php?user_id=${userId}`);

        eventSource.onopen = function(event) {
          console.log('SSE connection opened for orders');
        };

        eventSource.onmessage = function(event) {
          try {
            const data = JSON.parse(event.data);
            
            if (data.type === 'order_status_update') {
              // Check cooldown to prevent spam
              const currentTime = Date.now();
              if (currentTime - lastNotificationTime < NOTIFICATION_COOLDOWN) {
                return;
              }
              lastNotificationTime = currentTime;

              // Show notification with sound
              showOrderUpdateNotification(data);
              
              // Update the orders array and refresh display
              updateOrderInArray(data.order_id, data.new_status, data.estimated_time, data.estimated_date);
              refreshOrdersDisplay();
            } else if (data.type === 'heartbeat') {
              console.log('Orders SSE heartbeat received');
            } else if (data.type === 'connection_established') {
              console.log('Orders SSE connection established');
            }
          } catch (e) {
            console.error('Error parsing SSE data:', e);
          }
        };

        eventSource.onerror = function(event) {
          console.error('SSE error for orders:', event);
          // Reconnect after 10 seconds
          setTimeout(function() {
            if (eventSource.readyState === EventSource.CLOSED) {
              console.log('Reconnecting to orders SSE...');
              initializeSSE();
            }
          }, 10000);
        };
      }

      function showOrderUpdateNotification(data) {
        // Create notification element
        const notification = document.createElement('div');
        notification.className = 'order-update-notification';
        notification.style.cssText = `
          position: fixed;
          top: 20px;
          right: 20px;
          background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
          color: white;
          padding: 15px 20px;
          border-radius: 10px;
          box-shadow: 0 4px 15px rgba(0,0,0,0.2);
          z-index: 9999;
          max-width: 300px;
          font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
          animation: slideInRight 0.3s ease-out;
        `;

        // Add CSS animation
        if (!document.getElementById('notification-styles')) {
          const style = document.createElement('style');
          style.id = 'notification-styles';
          style.textContent = `
            @keyframes slideInRight {
              from { transform: translateX(100%); opacity: 0; }
              to { transform: translateX(0); opacity: 1; }
            }
            @keyframes slideOutRight {
              from { transform: translateX(0); opacity: 1; }
              to { transform: translateX(100%); opacity: 0; }
            }
          `;
          document.head.appendChild(style);
        }

        let estimatedHtml = '';
        if (data.estimated_time && data.estimated_date && (data.new_status === 'Ready to Ship' || data.new_status === 'On-Ship')) {
          const statusText = data.new_status === 'Ready to Ship' ? 'Estimated Delivery' : 'Estimated Arrival';
          estimatedHtml = `
            <div style="font-size: 11px; opacity: 0.9; margin-top: 8px; padding-top: 8px; border-top: 1px solid rgba(255,255,255,0.2);">
              <i class="fas fa-calendar-check" style="margin-right: 4px;"></i>
              <strong>${statusText}:</strong> ${data.estimated_date} at ${data.estimated_time}
            </div>
          `;
        }
        
        notification.innerHTML = `
          <div style="display: flex; align-items: center; margin-bottom: 8px;">
            <div style="width: 8px; height: 8px; background: #4CAF50; border-radius: 50%; margin-right: 8px; animation: pulse 2s infinite;"></div>
            <strong style="font-size: 14px;">Order Update</strong>
          </div>
          <div style="font-size: 13px; line-height: 1.4;">
            ${data.message}
          </div>
          <div style="font-size: 11px; opacity: 0.8; margin-top: 5px;">
            Transaction: ${data.order_data.transaction_number}
          </div>
          ${estimatedHtml}
        `;

        // Add pulse animation for the dot
        if (!document.getElementById('pulse-styles')) {
          const pulseStyle = document.createElement('style');
          pulseStyle.id = 'pulse-styles';
          pulseStyle.textContent = `
            @keyframes pulse {
              0% { transform: scale(1); opacity: 1; }
              50% { transform: scale(1.2); opacity: 0.7; }
              100% { transform: scale(1); opacity: 1; }
            }
          `;
          document.head.appendChild(pulseStyle);
        }

        document.body.appendChild(notification);

        // Play notification sound
        playNotificationSound();

        // Auto remove after 5 seconds
        setTimeout(function() {
          notification.style.animation = 'slideOutRight 0.3s ease-in';
          setTimeout(function() {
            if (notification.parentNode) {
              notification.parentNode.removeChild(notification);
            }
          }, 300);
        }, 5000);
      }

      function playNotificationSound() {
        // Create audio context for notification sound
        try {
          const audioContext = new (window.AudioContext || window.webkitAudioContext)();
          
          // Create a pleasant notification sound
          const oscillator = audioContext.createOscillator();
          const gainNode = audioContext.createGain();
          
          oscillator.connect(gainNode);
          gainNode.connect(audioContext.destination);
          
          // Set frequency and type for a pleasant sound
          oscillator.frequency.setValueAtTime(800, audioContext.currentTime);
          oscillator.frequency.setValueAtTime(1000, audioContext.currentTime + 0.1);
          oscillator.type = 'sine';
          
          // Set volume envelope
          gainNode.gain.setValueAtTime(0, audioContext.currentTime);
          gainNode.gain.linearRampToValueAtTime(0.3, audioContext.currentTime + 0.05);
          gainNode.gain.linearRampToValueAtTime(0, audioContext.currentTime + 0.3);
          
          oscillator.start(audioContext.currentTime);
          oscillator.stop(audioContext.currentTime + 0.3);
        } catch (e) {
          console.log('Audio not supported or blocked');
        }
      }

      function updateOrderInArray(orderId, newStatus, estimatedTime = null, estimatedDate = null) {
        // Update the order status in the orders array
        for (let i = 0; i < orders.length; i++) {
          if (orders[i].id == orderId) {
            orders[i].status = newStatus;
            if (estimatedTime && estimatedDate) {
              orders[i].estimated_time = estimatedTime;
              orders[i].estimated_date = estimatedDate;
            }
            break;
          }
        }
      }

      function refreshOrdersDisplay() {
        // Trigger the existing filter function to refresh the display
        if (typeof filterOrders === 'function') {
          const currentFilter = document.getElementById('statusFilter').value;
          filterOrders(currentFilter);
        }
      }

      // Initialize SSE when page loads
      document.addEventListener('DOMContentLoaded', function() {
        setTimeout(function() {
          initializeSSE();
        }, 1000); // Small delay to ensure page is fully loaded
      });

      // Clean up SSE connection when page unloads
      window.addEventListener('beforeunload', function() {
        if (eventSource) {
          eventSource.close();
        }
      });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
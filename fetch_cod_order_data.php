<?php
require 'dbconn.php';

// Optional barangay filter
$selectedBarangayId = isset($_GET['barangay_id']) ? (int)$_GET['barangay_id'] : null;

// Simple check if delivery fee columns exist by trying to select them
$ordersHasDeliveryCols = false;
try {
    $testQuery = "SELECT delivery_fee, total_amount_with_delivery FROM orders LIMIT 1";
    $conn->query($testQuery);
    $ordersHasDeliveryCols = true;
} catch (Exception $e) {
    // Columns don't exist, use fallback
    $ordersHasDeliveryCols = false;
}

$selectDeliveryCols = $ordersHasDeliveryCols
    ? ", o.delivery_fee, o.total_amount_with_delivery"
    : ", 0 AS delivery_fee, o.total_price AS total_amount_with_delivery";

// Always join barangay_fares and compute effective fee/total from fare when order fields are empty
$selectFareFallback = ", 
    CASE 
        WHEN o.delivery_method = 'staff' THEN COALESCE(NULLIF(o.delivery_fee, 0), bf.staff_fare_amount, 0)
        ELSE COALESCE(NULLIF(o.delivery_fee, 0), bf.fare_amount, 0) 
    END AS delivery_fee_effective,
    CASE WHEN (o.total_amount_with_delivery IS NULL OR o.total_amount_with_delivery = 0)
         THEN (o.total_price + 
            CASE 
                WHEN o.delivery_method = 'staff' THEN COALESCE(NULLIF(o.delivery_fee, 0), bf.staff_fare_amount, 0)
                ELSE COALESCE(NULLIF(o.delivery_fee, 0), bf.fare_amount, 0) 
            END)
         ELSE o.total_amount_with_delivery END AS total_with_delivery_effective";

$sql = "SELECT o.id, t.transaction_number, o.order_date,
               u.first_name, u.last_name, o.order_status,
               u.barangay_id, u.purok, b.barangay_name,
               o.rider_name, o.rider_contact,
               o.rider_motor_type, o.rider_plate_number,
               o.total_price, o.payment_method, o.total_weight, o.delivery_method, o.home_description,
               u.ImagePath, bf.fare_amount AS barangay_fare, bf.staff_fare_amount AS barangay_staff_fare
               $selectDeliveryCols $selectFareFallback
        FROM orders o
        LEFT JOIN transactions t ON o.id = t.order_id
        JOIN users u ON o.user_id = u.id
        JOIN barangays b ON u.barangay_id = b.id
        LEFT JOIN barangay_fares bf ON b.id = bf.barangay_id
        WHERE o.payment_method = 'COD' AND o.order_status = 'Pending'";

if ($selectedBarangayId !== null) {
    $sql .= " AND u.barangay_id = " . $selectedBarangayId;
}

$result = $conn->query($sql);
$output = '';

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $output .= '<tr>
            <td class="text-center">' . htmlspecialchars($row['id']) . '</td>
            <td class="text-center">' . htmlspecialchars($row['transaction_number']) . '</td>
            <td class="text-center">' . htmlspecialchars(date('M d, Y g:i A', strtotime($row['order_date']))) . '</td>
            <td class="text-center">' . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . '</td>
            <td class="text-center">' . htmlspecialchars($row['purok'] . ', ' . $row['barangay_name']) . '</td>
            <td class="text-center">
                <a href="#" class="btn btn-sm btn-primary"
                   data-bs-toggle="modal"
                   data-bs-target="#orderDetailsModal"
                   data-order-id="' . htmlspecialchars($row['id']) . '"
                   data-transaction-number="' . htmlspecialchars($row['transaction_number']) . '"
                   data-order-date="' . htmlspecialchars(date('M d, Y g:i A', strtotime($row['order_date']))) . '"
                   data-customer-name="' . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . '"
                   data-barangay="' . htmlspecialchars($row['barangay_name']) . '"
                   data-purok="' . htmlspecialchars($row['purok']) . '"
                   data-rider-name="' . htmlspecialchars($row['rider_name']) . '"
                   data-rider-contact="' . htmlspecialchars($row['rider_contact']) . '"
                   data-motor-type="' . htmlspecialchars($row['rider_motor_type']) . '"
                   data-plate-number="' . htmlspecialchars($row['rider_plate_number']) . '"
                   data-total-price="' . htmlspecialchars($row['total_price']) . '"
                   data-payment-method="' . htmlspecialchars($row['payment_method']) . '"
                   data-total-weight="' . htmlspecialchars($row['total_weight']) . '"
                   data-delivery-method="' . htmlspecialchars($row['delivery_method']) . '"
                   data-home-description="' . htmlspecialchars($row['home_description']) . '"
                   data-delivery-fee="' . htmlspecialchars($row['delivery_fee_effective']) . '"
                   data-total-with-delivery="' . htmlspecialchars($row['total_with_delivery_effective']) . '"
                   data-barangay-fare="' . htmlspecialchars($row['barangay_fare']) . '"
                   data-image-path="' . htmlspecialchars($row['ImagePath']) . '">
                   <i class="fas fa-edit me-1"></i>Update
                </a>
            </td>
        </tr>';
    }
} else {
    $output = '<tr><td colspan="6">No pending COD orders found.</td></tr>';
}

echo $output;
$conn->close(); 
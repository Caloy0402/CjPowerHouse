document.addEventListener('DOMContentLoaded', function () {
    const statusFilter = document.getElementById('statusFilter');
    if (statusFilter) {
        statusFilter.addEventListener('change', function() {
            loadOrders(this.value);
        });
    }
    loadOrders(statusFilter ? statusFilter.value : 'all');
});

function loadOrders(statusFilterValue = 'all') {
    const ordersContainer = document.getElementById('ordersContainer');

    if (!ordersContainer) {
        console.error('Orders container not found.');
        return;
    }

    ordersContainer.innerHTML = '';

    if (!orders || orders.length === 0) {
        ordersContainer.innerHTML = `
            <div style="text-align: center; padding: 2rem; color: #666;">
                <span class="material-icons" style="font-size: 48px; margin-bottom: 1rem;">shopping_bag</span>
                <p>No orders yet</p>
            </div>
        `;
        return;
    }

    let html = '';

    orders
    .filter(order => {
        if (!statusFilterValue || statusFilterValue === 'all') return true;
        return (order.status || '').toLowerCase() === statusFilterValue.toLowerCase();
    })
    .forEach(order => {
        const statusClass = getStatusClass(order.status);
        const canCancel = order.status === 'Pending';
        const canViewDetails = true;

        let deliveryMethodText = '';
        if (order.delivery_method === 'local') {
            deliveryMethodText = 'Local Rider';
        } else if (order.delivery_method === 'staff') {
            deliveryMethodText = 'Staff Delivery';
        } else {
            deliveryMethodText = order.delivery_method || 'N/A';
        }

        const deliveryFee = parseFloat(order.delivery_fee || 0) || 0;
        const subtotal = parseFloat(order.total || 0) || 0;
        const totalWithDelivery = parseFloat(order.total_with_delivery || (subtotal + deliveryFee)) || 0;

        html += `
            <div class="order-card">
                <div class="order-header">
                    <span class="order-id">Transaction #${order.transaction_number || 'N/A'}</span>
                    <span class="order-status ${statusClass}">${order.status || 'N/A'}</span>
                </div>

                <div class="order-items">
        `;

        order.items.forEach(item => {
            const imageUrl = baseURL + `uploads/${item.image || 'default.png'}`;
            html += `
                <div class="order-item">
                    <img src="${imageUrl}" alt="${item.name || 'Product'}">
                    <div class="order-item-details">
                        <h4 style="color: #333;">${item.name || 'N/A'}</h4>
                        <p class="category">Category: ${item.category || 'N/A'}</p>
                        <p class="price">₱${(parseFloat(item.price) || 0).toLocaleString(undefined, {minimumFractionDigits: 2})}</p>
                        <small>Quantity: ${item.quantity || 0}</small>
                    </div>
                </div>
            `;
        });

        html += `
                </div>
                <div class="order-details">
                   <p>Total Weight: ${(parseFloat(order.total_weight) || 0).toFixed(2)} kg</p>
                   <p>Delivery Method: ${deliveryMethodText}</p>
                   <p>Barangay: ${order.barangay_name || 'N/A'} ${order.distance_km ? `- ${parseFloat(order.distance_km).toFixed(1)} km` : ''}</p>
                   <p>Delivery Fee: ₱${deliveryFee.toLocaleString(undefined, {minimumFractionDigits: 2})}</p>
                </div>

                <div class="order-total" style="display: grid; gap: 6px;">
                    <div style="display:flex; justify-content: space-between; color:#333;">
                        <span>Subtotal (Products)</span>
                        <span>₱${subtotal.toLocaleString(undefined, {minimumFractionDigits: 2})}</span>
                    </div>
                    <div style="display:flex; justify-content: space-between; color:#333;">
                        <span>Delivery Fee</span>
                        <span>₱${deliveryFee.toLocaleString(undefined, {minimumFractionDigits: 2})}</span>
                    </div>
                    <hr style="margin: 4px 0; border-color:#28a745;">
                    <div style="display:flex; justify-content: space-between; font-weight:700; color:#28a745;">
                        <span>Total Amount</span>
                        <span>₱${totalWithDelivery.toLocaleString(undefined, {minimumFractionDigits: 2})}</span>
                    </div>
                </div>
                <div class="order-actions">
                    ${canCancel && order.status !== 'Pending Payment' ? `<button class="cancel-btn" data-order-id="${order.id}">Cancel Order</button>` : ''}
                    <button class="view-details-btn" data-order-id="${order.id}">View Details</button>
                </div>
            </div>
        `;
    });

    ordersContainer.innerHTML = html;

    document.querySelectorAll('.cancel-btn').forEach(button => {
        button.addEventListener('click', function () {
            const orderId = this.getAttribute('data-order-id');
            cancelOrder(orderId);
        });
    });

    document.querySelectorAll('.view-details-btn').forEach(button => {
        button.addEventListener('click', function () {
            const orderId = this.getAttribute('data-order-id');
            showOrderDetails(orderId);
        });
    });
}

function getStatusClass(status) {
    switch (status) {
        case 'Pending':
            return 'status-pending';
        case 'Pending Payment':
            return 'status-pending-payment';
        case 'Ready to Ship':
            return 'status-ready-to-ship';
        case 'On-Ship':
            return 'status-on-ship';
        case 'Delivered':
            return 'status-delivered';
        case 'Completed':
            return 'status-completed';
        case 'Canceled':
            return 'status-canceled';
        case 'Processing':
            return 'status-processing';
        default:
            return '';
    }
}

function cancelOrder(orderId) {
    if (confirm('Are you sure you want to cancel this order?')) {
        // Find the cancel button and disable it immediately
        const cancelBtn = document.querySelector(`[data-order-id="${orderId}"].cancel-btn`);
        if (cancelBtn) {
            cancelBtn.disabled = true;
            cancelBtn.textContent = 'Canceling...';
            cancelBtn.style.opacity = '0.6';
        }
        
        fetch('mobile-orders.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www/form-urlencoded',
            },
            body: `action=cancel&order_id=${orderId}`,
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update the order status in the local orders array
                    const orderIndex = orders.findIndex(order => order.id === parseInt(orderId));
                    if (orderIndex !== -1) {
                        orders[orderIndex].status = 'Canceled';
                    }
                    
                    // Show success message
                    alert(data.message);
                    
                    // Reload orders display with updated data
                    loadOrders();
                } else {
                    // Re-enable the button if cancellation failed
                    if (cancelBtn) {
                        cancelBtn.disabled = false;
                        cancelBtn.textContent = 'Cancel Order';
                        cancelBtn.style.opacity = '1';
                    }
                    alert(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                // Re-enable the button if there was an error
                if (cancelBtn) {
                    cancelBtn.disabled = false;
                    cancelBtn.textContent = 'Cancel Order';
                    cancelBtn.style.opacity = '1';
                }
                alert('An error occurred while canceling the order. Please try again.');
            });
    }
}

function showOrderDetails(orderId) {
    const order = orders.find(order => order.id === parseInt(orderId));

    if (!order) {
        console.error('Order not found');
        return;
    }

    const modalBody = document.getElementById('orderDetailsModalBody');
    modalBody.innerHTML = ''; // Clear previous content

    // If this is a test payment, show a special test ticket
    if (order.payment_method && order.payment_method.toUpperCase() === 'TESTPAY') {
        modalBody.innerHTML = `
            <div style="text-align:center; padding: 1.5em 0;">
                <h2 style="color: #b00; margin-bottom: 0.5em;">TEST PAYMENT TICKET</h2>
                <div style="border: 2px dashed #b00; border-radius: 10px; padding: 1em; background: #fff8f8; display: inline-block;">
                    <p><strong>Order ID:</strong> ${order.id || 'N/A'}</p>
                    <p><strong>Transaction #:</strong> ${order.transaction_number || 'N/A'}</p>
                    <p><strong>Name:</strong> ${order.user_first_name || 'N/A'} ${order.user_last_name || 'N/A'}</p>
                    <p><strong>Shipping Address:</strong> Purok: ${order.purok || 'N/A'}, Brgy: ${order.barangay_name || 'N/A'}, Valencia City, Bukidnon</p>
                    <p><strong>Total:</strong> ₱${(parseFloat(order.total) || 0).toLocaleString(undefined, {minimumFractionDigits: 2})}</p>
                    <p><strong>Date:</strong> ${order.date || 'N/A'}</p>
                    <p style="color: #b00; font-weight: bold;">This is a DEMO ticket. No real payment was made.</p>
                </div>
            </div>
        `;
        const orderDetailsModal = new bootstrap.Modal(document.getElementById('orderDetailsModal'));
        orderDetailsModal.show();
        return;
    }

    // --- MODIFIED: Payment Method Row with GCash Details and Order Items Table Headers Removed ---
    let paymentMethodDisplay = order.payment_method || 'N/A';
    if (order.payment_method && order.payment_method.toLowerCase() === 'gcash') {
        paymentMethodDisplay = `GCash`;
        if (order.gcash_ref_no) {
            paymentMethodDisplay += ` - RFN#: ${order.gcash_ref_no}`;
        } else {
            paymentMethodDisplay += ` - RFN#: N/A`;
        }
        
        // --- ADDED: Date formatting for GCash transaction date ---
        let displayDate = 'N/A';
        if (order.gcash_client_transaction_date) {
            displayDate = order.gcash_client_transaction_date; // Prioritize client-side string
        } else if (order.gcash_server_transaction_date) {
            // Convert server UTC timestamp to PHT (UTC+8) and format
            try {
                const date = new Date(order.gcash_server_transaction_date + 'Z'); // Assume UTC, append 'Z' for correct parsing
                displayDate = date.toLocaleString('en-PH', {
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit',
                    hour12: true,
                    timeZone: 'Asia/Manila' // Philippine Standard Time
                });
            } catch (e) {
                console.error("Error formatting date:", e);
                displayDate = order.gcash_server_transaction_date; // Fallback to raw if formatting fails
            }
        }
        paymentMethodDisplay += ` - Date: ${displayDate}`;
    }
    // --- END MODIFIED ---

    const deliveryFee = parseFloat(order.delivery_fee || 0) || 0;
    const subtotal = parseFloat(order.total || 0) || 0;
    const totalWithDelivery = parseFloat(order.total_with_delivery || (subtotal + deliveryFee)) || 0;

    modalBody.innerHTML = `
        <h5 style="color: black;">User Information</h5>
        <div class="table-responsive">
            <table class="table table-bordered" style="background-color: white; color: black; font-size: 0.9rem;">
                <tbody>
                    <tr>
                        <th>Name</th>
                        <td>${order.user_first_name || 'N/A'} ${order.user_last_name || 'N/A'}</td>
                    </tr>
                    <tr>
                        <th>Email</th>
                        <td>${order.user_email || 'N/A'}</td>
                    </tr>
                    <tr>
                        <th>Phone</th>
                        <td>+(63)${order.user_phone || 'N/A'}</td>
                    </tr>
                    <tr>
                        <th>Shipping Address</th>
                        <td>Purok: ${order.purok || 'N/A'}, Brgy: ${order.barangay_name || 'N/A'}, Valencia City, Bukidnon</td>
                    </tr>
                    <tr>
                        <th>Home Description</th>
                        <td>${order.home_description || 'N/A'}</td>
                    </tr>
                    <tr>
                        <th>Payment Method</th>
                        <td>${paymentMethodDisplay}</td>
                    </tr>
                    ${order.rider_name ? `
                    <tr>
                        <th>Rider Information</th>
                        <td>
                            <p><strong>Name:</strong> ${order.rider_name || 'N/A'}</p>
                            <p><strong>Contact:</strong> ${order.rider_contact || 'N/A'}</p>
                            <p><strong>Motor Type:</strong> ${order.rider_motor_type || 'N/A'}</p>
                            <p><strong>Plate Number:</strong> ${order.rider_plate_number || 'N/A'}</p>
                        </td>
                    </tr>
                    ` : `<tr><th>Rider Information</th><td>Rider information will be available once the order is ready for shipping.</td></tr>`}
                </tbody>
            </table>
        </div>

        <h6 style="margin-top: 10px;">Delivery Information</h6>
        <div class="table-responsive">
            <table class="table table-bordered" style="background-color: white; color: black; font-size: 0.9rem;">
                <tbody>
                    <tr>
                        <th>Barangay</th>
                        <td>${order.barangay_name || 'N/A'}</td>
                    </tr>
                    <tr>
                        <th>Distance</th>
                        <td>${order.distance_km ? `${parseFloat(order.distance_km).toFixed(1)} km` : 'N/A'}</td>
                    </tr>
                    <tr>
                        <th>Delivery Fee</th>
                        <td>₱${deliveryFee.toLocaleString(undefined, {minimumFractionDigits: 2})}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <h6 style="margin-top: 10px;">Order Summary</h6>
        <div class="table-responsive">
            <table class="table table-bordered" style="background-color: white; color: black; font-size: 0.9rem;">
                <tbody>
                    <tr>
                        <th>Subtotal (Products)</th>
                        <td>₱${subtotal.toLocaleString(undefined, {minimumFractionDigits: 2})}</td>
                    </tr>
                    <tr>
                        <th>Delivery Fee</th>
                        <td>₱${deliveryFee.toLocaleString(undefined, {minimumFractionDigits: 2})}</td>
                    </tr>
                    <tr>
                        <th>Total Amount</th>
                        <td><strong>₱${totalWithDelivery.toLocaleString(undefined, {minimumFractionDigits: 2})}</strong></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <h6>Order Items:</h6>
        <div class="table-responsive">
            <table class="table table-sm">
                <tbody>
                    ${order.items.map(item => `
                        <tr>
                            <td>${item.name || 'N/A'}</td>
                            <td>${item.quantity || 0}</td>
                            <td>₱${(parseFloat(item.price) || 0).toLocaleString(undefined, {minimumFractionDigits: 2})}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        </div>
    `;

    const orderDetailsModalElement = document.getElementById('orderDetailsModal');
    const orderDetailsModal = bootstrap.Modal.getInstance(orderDetailsModalElement) || new bootstrap.Modal(orderDetailsModalElement);
    orderDetailsModal.show();
}

// Get the modal
var modal = document.getElementById("orderSuccessModal");

// Check if the order_success parameter is present in the URL
const urlParams = new URLSearchParams(window.location.search);
if (urlParams.get('order_success') === 'true') {
    // Show the modal
    modal.style.display = "block";

    // Hide the modal after 3 seconds (adjust as needed)
    setTimeout(function () {
        modal.style.display = "none";
        // Optionally, remove the order_success parameter from the URL
        window.history.replaceState({}, document.title, window.location.pathname);
    }, 3000);
}
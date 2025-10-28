document.addEventListener('DOMContentLoaded', function () {
    const statusFilter = document.getElementById('statusFilter');
    if (statusFilter) {
        statusFilter.addEventListener('change', function() {
            loadOrders(this.value);
        });
    }
    loadOrders(statusFilter ? statusFilter.value : 'all');
});

function filterOrders(statusFilterValue = 'all') {
    loadOrders(statusFilterValue);
}

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
        
        // Special handling for "Ready for Pickup" filter
        if (statusFilterValue === 'Ready for Pickup') {
            return order.delivery_method === 'pickup' && order.status === 'Ready to Ship';
        }
        
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
                    <span class="order-status ${statusClass}">${getDisplayStatus(order.status, order.delivery_method) || 'N/A'}</span>
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

function getDisplayStatus(status, deliveryMethod) {
    // For pickup orders, map database statuses to user-friendly display text
    if (deliveryMethod === 'pickup') {
        switch (status) {
            case 'Ready to Ship':
                return 'Ready for Pickup';
            case 'On-Ship':
                return 'Ready for Pickup'; // This shouldn't happen for pickup orders, but just in case
            default:
                return status; // Return original status for other cases
        }
    }
    
    // For delivery orders, return the original status
    return status;
}

function cancelOrder(orderId) {
    // Show professional confirmation modal instead of default confirm
    showCancelConfirmation(orderId);
}

function showCancelConfirmation(orderId) {
    // Create modal HTML
    const modalHTML = `
        <div class="modal fade" id="cancelConfirmModal" tabindex="-1" aria-labelledby="cancelConfirmModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content" style="border-radius: 15px; border: none; box-shadow: 0 10px 40px rgba(0,0,0,0.15);">
                    <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-top-left-radius: 15px; border-top-right-radius: 15px; border-bottom: none; padding: 1.5rem;">
                        <h5 class="modal-title text-white fw-bold" id="cancelConfirmModalLabel">
                            <i class="fas fa-exclamation-circle me-2"></i>Cancel Order Confirmation
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body text-center" style="padding: 2rem;">
                        <div class="mb-3">
                            <i class="fas fa-times-circle" style="font-size: 4rem; color: #dc3545;"></i>
                        </div>
                        <h5 class="mb-3" style="color: #333; font-weight: 600;">Are you sure you want to cancel this order?</h5>
                        <p class="text-muted mb-0" style="font-size: 0.95rem;">This action cannot be undone. Your order will be permanently canceled.</p>
                    </div>
                    <div class="modal-footer" style="border-top: 1px solid #f0f0f0; padding: 1rem 1.5rem; justify-content: center; gap: 10px;">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 10px; padding: 0.6rem 1.5rem; font-weight: 600;">
                            <i class="fas fa-arrow-left me-2"></i>No, Keep Order
                        </button>
                        <button type="button" class="btn btn-danger" id="confirmCancelBtn" style="border-radius: 10px; padding: 0.6rem 1.5rem; font-weight: 600;">
                            <i class="fas fa-check me-2"></i>Yes, Cancel Order
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Remove existing modal if present
    const existingModal = document.getElementById('cancelConfirmModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Add modal to body
    document.body.insertAdjacentHTML('beforeend', modalHTML);
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('cancelConfirmModal'));
    modal.show();
    
    // Handle confirm button click
    document.getElementById('confirmCancelBtn').addEventListener('click', function() {
        modal.hide();
        processCancelOrder(orderId);
    });
    
    // Clean up modal after it's hidden
    document.getElementById('cancelConfirmModal').addEventListener('hidden.bs.modal', function() {
        this.remove();
    });
}

function processCancelOrder(orderId) {
    console.log('Processing cancel for order ID:', orderId, 'type:', typeof orderId);
    
    if (true) {
        // Find the cancel button and disable it immediately
        const cancelBtn = document.querySelector(`[data-order-id="${orderId}"].cancel-btn`);
        if (cancelBtn) {
            cancelBtn.disabled = true;
            cancelBtn.textContent = 'Canceling...';
            cancelBtn.style.opacity = '0.6';
        }
        
        fetch('Mobile-Orders.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=cancel&order_id=${orderId}`,
        })
            .then(response => {
                console.log('Response status:', response.status);
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                // Clone response to read it as text for debugging
                return response.clone().text().then(text => {
                    console.log('Raw response:', text);
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('JSON parse error. Response was:', text);
                        throw new Error('Invalid JSON response from server');
                    }
                });
            })
            .then(data => {
                console.log('Parsed cancel order response:', data);
                if (data.success) {
                    // Update the order status in the local orders array
                    const orderIndex = orders.findIndex(order => order.id === parseInt(orderId));
                    if (orderIndex !== -1) {
                        orders[orderIndex].status = 'Canceled';
                    }
                    
                    // Show professional success notification
                    showSuccessNotification(data.message);
                    
                    // Reload orders display with updated data
                    setTimeout(() => {
                        loadOrders();
                    }, 1500);
                } else {
                    // Re-enable the button if cancellation failed
                    if (cancelBtn) {
                        cancelBtn.disabled = false;
                        cancelBtn.textContent = 'Cancel Order';
                        cancelBtn.style.opacity = '1';
                    }
                    showErrorNotification(data.message);
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
                showErrorNotification('An error occurred while canceling the order. Please try again.');
            });
    }
}

function showSuccessNotification(message) {
    const notification = document.createElement('div');
    notification.className = 'alert alert-success alert-dismissible fade show position-fixed';
    notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); border-left: 4px solid #28a745;';
    notification.innerHTML = `
        <i class="fas fa-check-circle me-2"></i>
        <strong>Success!</strong> ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    `;
    
    document.body.appendChild(notification);
    
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

function showErrorNotification(message) {
    const notification = document.createElement('div');
    notification.className = 'alert alert-danger alert-dismissible fade show position-fixed';
    notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); border-left: 4px solid #dc3545;';
    notification.innerHTML = `
        <i class="fas fa-exclamation-circle me-2"></i>
        <strong>Error!</strong> ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    `;
    
    document.body.appendChild(notification);
    
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
                        <td>${order.payment_method === 'pickup' ? 'Pickup Order - No home description needed' : (order.home_description || 'N/A')}</td>
                    </tr>
                    <tr>
                        <th>Payment Method</th>
                        <td>${paymentMethodDisplay}</td>
                    </tr>
                    ${order.reason ? `
                    <tr style="background-color: #fff3cd;">
                        <th>Cancellation Reason</th>
                        <td style="color: #856404; font-weight: 600;">${order.reason}</td>
                    </tr>
                    ` : ''}
                    ${order.payment_method === 'pickup' ? '' : (order.delivery_method === 'staff' ? `
                    <tr>
                        <th>Delivery Information</th>
                        <td>
                            <p><strong>Service:</strong> CJ PowerHouse Staff Delivery</p>
                            <p><strong>Type:</strong> Staff delivery service</p>
                            <p><strong>Contact:</strong> <a href="https://www.messenger.com/e2ee/t/1062895265918024" target="_blank" style="color: #0066cc; text-decoration: none;">Message us on Messenger</a></p>
                            <p><strong>Note:</strong> This order will be delivered by our staff team because it exceeded the 14kg required for our rider to deliver. Their safety is our top priority.</p>
                        </td>
                    </tr>
                    ` : order.rider_name ? `
                    <tr>
                        <th>Rider Information</th>
                        <td>
                            <p><strong>Name:</strong> ${order.rider_name || 'N/A'}</p>
                            <p><strong>Contact:</strong> ${order.rider_contact || 'N/A'}</p>
                            <p><strong>Motor Type:</strong> ${order.rider_motor_type || 'N/A'}</p>
                            <p><strong>Plate Number:</strong> ${order.rider_plate_number || 'N/A'}</p>
                        </td>
                    </tr>
                    ` : `<tr><th>Rider Information</th><td>Rider information will be available once the order is ready for shipping.</td></tr>`)}
                </tbody>
            </table>
        </div>

        ${order.payment_method === 'pickup' ? '' : `
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
        `}

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
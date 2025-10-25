document.addEventListener('DOMContentLoaded', function() {
    loadOrders();
});

function loadOrders() {
    const ordersContainer = document.querySelector('.orders-container');
    const orders = JSON.parse(localStorage.getItem('orders')) || [];

    if (orders.length === 0) {
        ordersContainer.innerHTML = `
            <div style="text-align: center; padding: 2rem; color: #666;">
                <span class="material-icons" style="font-size: 48px; margin-bottom: 1rem;">shopping_bag</span>
                <p>No orders yet</p>
            </div>
        `;
        return;
    }

    orders.forEach(order => {
        const orderCard = document.createElement('div');
        orderCard.className = 'order-card';
        orderCard.innerHTML = `
            <div class="order-header">
                <span class="order-id">Order #${order.id}</span>
                <span class="order-status ${order.status === 'Delivered' ? 'status-delivered' : 'status-pending'}">
                    ${order.status}
                </span>
            </div>
            <div class="order-items">
                ${order.items.map(item => `
                    <div class="order-item">
                        <img src="${item.image}" alt="${item.name}">
                        <div class="order-item-details">
                            <h4>${item.name}</h4>
                            <p class="price">₱${parseFloat(item.price).toLocaleString()}</p>
                            <small>Quantity: ${item.quantity}</small>
                        </div>
                    </div>
                `).join('')}
            </div>
            <div class="order-total">
                <span>Total</span>
                <span>₱${order.total.toLocaleString()}</span>
            </div>
        `;
        ordersContainer.appendChild(orderCard);
    });
} 
document.addEventListener('DOMContentLoaded', function() {
    // Load cart total from localStorage
    const cart = JSON.parse(localStorage.getItem('cart')) || [];
    
    // Calculate total by properly parsing the price string
    let total = cart.reduce((sum, item) => {
        const price = parseFloat(item.price.toString().replace(/[₱,]/g, ''));
        return sum + (price * item.quantity);
    }, 0);
    
    // Update total amount display
    const totalAmount = document.querySelector('.total-amount');
    totalAmount.textContent = `₱${total.toLocaleString()}`;

    // Handle payment method changes
    const paymentOptions = document.querySelectorAll('input[name="payment"]');
    const placeOrderBtn = document.querySelector('.place-order-btn');
    
    paymentOptions.forEach(option => {
        option.addEventListener('change', function() {
            if (this.value === 'gcash') {
                placeOrderBtn.innerHTML = `
                    <span class="material-icons">account_balance_wallet</span>
                    Pay with GCash (₱${total.toLocaleString()})
                `;
            } else {
                placeOrderBtn.innerHTML = `
                    <span class="material-icons">payments</span>
                    Place Order (COD)
                `;
            }
        });
    });

    // Handle form submission
    const form = document.getElementById('deliveryForm');
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const cart = JSON.parse(localStorage.getItem('cart')) || [];
        
        // Format cart items properly and calculate total
        const formattedCart = cart.map(item => {
            const price = parseFloat(item.price.toString().replace(/[₱,]/g, ''));
            return {
                name: item.name,
                price: price,
                quantity: item.quantity,
                image: item.image
            };
        });
        
        // Calculate total correctly
        const orderTotal = formattedCart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
        
        // Create new order with formatted data
        const order = {
            id: Date.now(),
            items: formattedCart,
            total: orderTotal,
            status: 'Pending',
            date: new Date().toISOString(),
            address: {
                street: document.getElementById('street').value,
                city: document.getElementById('city').value,
                postal: document.getElementById('postal').value,
                state: document.getElementById('state').value
            },
            paymentMethod: document.querySelector('input[name="payment"]:checked').value
        };

        // Save order to localStorage
        const orders = JSON.parse(localStorage.getItem('orders')) || [];
        orders.push(order);
        localStorage.setItem('orders', JSON.stringify(orders));

        // Clear cart
        localStorage.removeItem('cart');
        
        // Show success message based on payment method
        if (order.paymentMethod === 'gcash') {
            alert('Order placed! Redirecting to GCash payment...');
        } else {
            alert('Order placed successfully! You will pay on delivery.');
        }
        
        // Redirect to orders page
        window.location.href = 'orders.html';
    });
}); 
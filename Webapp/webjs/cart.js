function loadCart() {
    const cart = JSON.parse(localStorage.getItem('cart')) || [];
    const cartItemsContainer = document.querySelector('.cart-items');
    const subtotalElement = document.querySelector('.summary-row:first-child span:last-child');
    const totalElement = document.querySelector('.summary-row.total span:last-child');
    const checkoutButton = document.querySelector('.checkout-button');
    
    // Clear existing items
    cartItemsContainer.innerHTML = '';
    
    let subtotal = 0;
    
    cart.forEach(item => {
        // Make sure price is a number
        const price = typeof item.price === 'string' ? parseFloat(item.price.replace(/[₱,]/g, '')) : item.price;
        subtotal += price * item.quantity;
        
        cartItemsContainer.innerHTML += `
            <div class="cart-item" data-name="${item.name}">
                <img src="${item.image}" alt="${item.name}" class="cart-item-image">
                <div class="cart-item-details">
                    <h3>${item.name}</h3>
                    <p class="price">₱${price.toLocaleString()}</p>
                </div>
                <div class="cart-item-actions">
                    <button class="delete-btn" onclick="removeFromCart('${item.name}')">
                        <span class="material-icons">delete</span>
                    </button>
                    <div class="quantity-controls">
                        <button class="quantity-btn minus" onclick="updateQuantity('${item.name}', -1)">-</button>
                        <span>${item.quantity}</span>
                        <button class="quantity-btn plus" onclick="updateQuantity('${item.name}', 1)">+</button>
                    </div>
                </div>
            </div>
        `;
    });
    
    // Update summary with formatted numbers
    subtotalElement.textContent = `₱${subtotal.toLocaleString()}`;
    totalElement.textContent = `₱${subtotal.toLocaleString()}`;
    checkoutButton.innerHTML = `<span class="material-icons">lock_outline</span>Proceed to Checkout (₱${subtotal.toLocaleString()})`;
    
    // Update cart count in header
    const itemCount = cart.reduce((sum, item) => sum + item.quantity, 0);
    document.querySelector('.cart-header h1').textContent = `Shopping Cart (${itemCount})`;
    
    // Add click handler for checkout button
    checkoutButton.addEventListener('click', function() {
        window.location.href = 'delivery.html';
    });
}

function removeFromCart(productName) {
    let cart = JSON.parse(localStorage.getItem('cart')) || [];
    cart = cart.filter(item => item.name !== productName);
    localStorage.setItem('cart', JSON.stringify(cart));
    loadCart();
}

function updateQuantity(productName, change) {
    let cart = JSON.parse(localStorage.getItem('cart')) || [];
    const item = cart.find(item => item.name === productName);
    
    if (item) {
        item.quantity += change;
        if (item.quantity < 1) {
            removeFromCart(productName);
            return;
        }
    }
    
    localStorage.setItem('cart', JSON.stringify(cart));
    loadCart();
}

function updateCart(product) {
    // Get existing cart or initialize empty array
    let cart = JSON.parse(localStorage.getItem('cart')) || [];
    
    // Convert price string to number by removing '₱' and any commas
    const price = parseFloat(product.price.replace(/[₱,]/g, ''));
    
    // Check if product already exists in cart
    const existingProduct = cart.find(item => item.name === product.name);
    
    if (existingProduct) {
        existingProduct.quantity += 1;
    } else {
        cart.push({
            name: product.name,
            price: price, // Store as number
            image: product.image,
            quantity: 1
        });
    }
    
    // Save updated cart
    localStorage.setItem('cart', JSON.stringify(cart));
}

// Load cart when page loads
document.addEventListener('DOMContentLoaded', loadCart); 
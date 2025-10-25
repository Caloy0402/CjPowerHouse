// Global variable to store cart items
let cartItems = [];

document.addEventListener('DOMContentLoaded', function () {
    loadCart();
});

function loadCart() {
    fetch('get_cart_items.php')
        .then(response => response.json())
        .then(data => {
            const cartItemsContainer = document.querySelector('.cart-items');
            const subtotalElement = document.querySelector('.summary-row:first-child span:last-child');
            const totalWeightElement = document.querySelector('.order-summary .summary-row:nth-child(2) span:nth-child(2)');
            const totalElement = document.querySelector('.summary-row.total span:last-child');
            const checkoutButton = document.querySelector('.checkout-button');
            const emptyCartMessage = document.getElementById('empty-cart-message');

            cartItemsContainer.innerHTML = '';

            let subtotal = 0;
            let totalWeight = 0;

            if (data.success && data.cartItems.length > 0) {
                cartItems = data.cartItems; // Store cart items globally
                data.cartItems.forEach(item => {
                    subtotal += item.Price * item.Quantity;
                    totalWeight += item.Weight * item.Quantity; // Calculate total weight

                    // Construct the full image URL using baseURL
                    const imageUrl = baseURL + 'uploads/' + item.ImagePath;
                    console.log('Image URL:', imageUrl); // Debugging

                    cartItemsContainer.innerHTML += `
                        <div class="cart-item" data-name="${item.ProductName}">
                            <img src="${imageUrl}" alt="${item.ProductName}" class="cart-item-image">
                            <div class="cart-item-details">
                                <h3>${item.ProductName}</h3>
                                <p class="price">₱${item.Price.toLocaleString()}</p>
                            </div>
                            <div class="cart-item-actions">
                                <button class="delete-btn" data-cart-id="${item.CartID}">
                                    <span class="material-icons">delete</span>
                                </button>
                                <div class="quantity-controls">
                                    <button class="quantity-btn plus" data-cart-id="${item.CartID}">+</button>
                                    <span>${item.Quantity}</span>
                                    <button class="quantity-btn minus" data-cart-id="${item.CartID}">-</button>
                                </div>
                            </div>
                        </div>
                    `;
                });

                // Update summary with formatted numbers
                subtotalElement.textContent = `₱${subtotal.toLocaleString()}`;
                totalWeightElement.textContent = `${totalWeight.toFixed(2)} kg`; // Display total weight
                totalElement.textContent = `₱${subtotal.toLocaleString()}`;

                checkoutButton.innerHTML = `<span class="material-icons">lock_outline</span>Proceed to Checkout (₱${subtotal.toLocaleString()})`;

                // Update cart count in header
                const itemCount = data.cartItems.reduce((sum, item) => sum + item.Quantity, 0);
                const headerTitle = document.querySelector('.cart-header h1');
                if (headerTitle) {
                    headerTitle.textContent = `Shopping Cart (${itemCount})`;
                }

                // **IMPORTANT:  Attach event listeners here, after the cart items are loaded**
                attachCartItemEventListeners();

                // Enable Checkout Button
                checkoutButton.classList.remove('disabled');
                checkoutButton.disabled = false;
                emptyCartMessage.style.display = 'none';

            } else {
                // Cart is empty
                cartItems = []; // Clear cart items
                console.log("Cart is empty");
                cartItemsContainer.innerHTML = '<p style="text-align: center;">Cart is Empty</p>'; // Display "Cart is Empty" label
                subtotalElement.textContent = '₱0.00';
                totalWeightElement.textContent = '-- kg'; // Reset weight to zero
                totalElement.textContent = '₱0.00';
                checkoutButton.innerHTML = `<span class="material-icons">lock_outline</span>Proceed to Checkout (₱0.00)`;
                emptyCartMessage.style.display = 'none';
                
                // Update cart count in header to 0
                const headerTitle = document.querySelector('.cart-header h1');
                if (headerTitle) {
                    headerTitle.textContent = 'Shopping Cart (0)';
                }

                // Disable Checkout Button
                checkoutButton.classList.add('disabled');
                checkoutButton.disabled = true;

            }

            //Update the hidden input with the total weight
            document.getElementById('total-weight').value = totalWeight.toFixed(2);


        })
        .catch(error => {
            console.error('Error:', error);
        });
}

function attachCartItemEventListeners() {
    // Increase or decrease quantity (for server-side cart)
    document.querySelectorAll('.quantity-btn').forEach(button => {
        button.addEventListener('click', () => {
            const cartID = button.getAttribute('data-cart-id');
            const action = button.classList.contains('plus') ? 'increase' : 'decrease';

            updateQuantity(cartID, action); // Call the updateQuantity function
        });
    });

    // Delete item from cart (for server-side cart)
    document.querySelectorAll('.delete-btn').forEach(button => {
        button.addEventListener('click', () => {
            const cartID = button.getAttribute('data-cart-id');
            removeItem(cartID); // Call the removeItem function
        });
    });
}

function updateQuantity(cartID, action) {
    fetch('cart_actions.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ action: 'update', cartID: cartID, change: action })
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                loadCart(); // Reload the cart to reflect changes
            } else {
                showFancyBanner('warning', data.message || 'Not enough Stock Available');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showFancyBanner('error', 'An error occurred while updating the quantity.');
        });
}

function removeItem(cartID) {
    // Remove the 'if (confirm(...))' block entirely.
    fetch('cart_actions.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ action: 'remove', cartID: cartID })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            loadCart(); // Reload the cart to reflect changes
        } else {
            showFancyBanner('error', data.message || 'Failed to remove item');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showFancyBanner('error', 'An error occurred while removing the item.');
    });
}


function addToCart(productID) {
    fetch('add_to_cart.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ productID: productID })
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showFancyBanner('success', 'Product added to cart');
                updateCartCounter();
                loadCart(); // Refresh the cart to reflect changes
            } else {
                showFancyBanner('error', data.message || 'Failed to add product to cart.');
            }
        })
        .catch(error => {
            console.error('Error:', error);
        });
}

function updateCartCounter() {
    fetch('get_cart_count.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update the cart count in the header
                const cartCountElement = document.querySelector('.cart-header h1');
                if (cartCountElement) {
                    cartCountElement.textContent = `Shopping Cart (${data.count})`;
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
        });
}

const checkoutButton = document.querySelector('.checkout-button');
checkoutButton.addEventListener('click', function (event) {
    if (checkoutButton.classList.contains('disabled')) {
        event.preventDefault(); // Prevent navigation if disabled
        return;
    }
    
    // Check if cart is empty
    if (cartItems.length === 0) {
        showFancyBanner('warning', 'Your cart is empty. Please add items before proceeding to checkout.');
        return;
    }
    
    // Check if PIN protection is enabled
    checkPinStatusAndProceed();
});

// Check PIN status and proceed accordingly
function checkPinStatusAndProceed() {
    fetch('pin_management.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_status'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (data.pin_enabled) {
                // Show PIN modal
                const pinModal = new bootstrap.Modal(document.getElementById('pinModal'));
                pinModal.show();
                
                // Focus on PIN input
                document.getElementById('checkoutPin').focus();
            } else {
                // No PIN protection, proceed directly
                window.location.href = 'Mobile-Delivery.php';
            }
        } else {
            // Error checking PIN status, proceed anyway
            window.location.href = 'Mobile-Delivery.php';
        }
    })
    .catch(error => {
        console.error('Error checking PIN status:', error);
        // Network error, proceed anyway
        window.location.href = 'Mobile-Delivery.php';
    });
}

// PIN verification handler
document.addEventListener('DOMContentLoaded', function() {
    const verifyPinBtn = document.getElementById('verifyPinBtn');
    const checkoutPin = document.getElementById('checkoutPin');
    
    if (verifyPinBtn) {
        verifyPinBtn.addEventListener('click', function() {
            const pin = checkoutPin.value;
            
            if (pin.length !== 4 || !/^\d{4}$/.test(pin)) {
                showFancyBanner('error', 'Please enter a valid 4-digit PIN');
                return;
            }
            
            // Verify PIN
            fetch('pin_management.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=verify_pin&pin=${pin}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // PIN verified, close modal and proceed
                    const pinModal = bootstrap.Modal.getInstance(document.getElementById('pinModal'));
                    pinModal.hide();
                    window.location.href = 'Mobile-Delivery.php';
                } else {
                    showFancyBanner('error', data.message || 'Invalid PIN');
                    checkoutPin.value = '';
                    checkoutPin.focus();
                }
            })
            .catch(error => {
                showFancyBanner('error', 'Network error. Please try again.');
            });
        });
    }
    
    // Auto-submit PIN when 4 digits are entered
    if (checkoutPin) {
        checkoutPin.addEventListener('input', function() {
            if (this.value.length === 4) {
                verifyPinBtn.click();
            }
        });
    }
    
    // Handle Forgot PIN button
    const forgotPinBtn = document.getElementById('forgotPinBtn');
    if (forgotPinBtn) {
        forgotPinBtn.addEventListener('click', function() {
            // Disable button to prevent double clicks
            forgotPinBtn.disabled = true;
            forgotPinBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Sending...';
            
            fetch('pin_management.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=send_pin_email'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showFancyBanner('success', data.message);
                    // Close the modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('pinModal'));
                    modal.hide();
                } else {
                    showFancyBanner('error', data.message);
                }
            })
            .catch(error => {
                showFancyBanner('error', 'Network error. Please try again.');
            })
            .finally(() => {
                // Re-enable button
                forgotPinBtn.disabled = false;
                forgotPinBtn.innerHTML = '<span class="material-icons" style="vertical-align: middle; margin-right: 4px; font-size: 16px;">email</span>Forgot PIN code?';
            });
        });
    }
});
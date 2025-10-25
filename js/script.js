document.addEventListener('DOMContentLoaded', function () {
    setupPromoCopy();
    setupCategoryButtons();
    setupCategoryScroll();
    setupCartButtons();
    setupWishlist();
    setupProfilePopup();
    setupSearchFunctionality();
    setupCategoryFiltering();
    setupSaleBanners();
    setupDragToScroll();
    setupAutoCarousel();
    implementsAutoCarousel(false);
    setupProductDetailsModal();
    setupStarRating();

    // Load wishlist items from database on page load
    loadWishlistFromDatabase();
});


// Build an absolute URL for assets relative to the current page location
function toAbsoluteUrl(path) {
    try {
        if (!path) return '';
        if (/^https?:\/\//i.test(path)) return path; // already absolute
        const base = window.location.origin + window.location.pathname.replace(/[^\/]*$/, '');
        return base + String(path).replace(/^\/+/, '');
    } catch (_) {
        return path;
    }
}

// Sidebar Toggler
$('.sidebar-toggler').on('click', function() {
    $('.sidebar .content').toggleClass('open');
    return false;
});

// Category Filtering
function setupCategoryFiltering() {
    const categoryButtons = document.querySelectorAll(".category-btn");
    const productCards = document.querySelectorAll(".product-card");

    categoryButtons.forEach(button => {
        button.addEventListener("click", () => {
            categoryButtons.forEach(btn => btn.classList.remove("active"));
            button.classList.add("active");

            const selectedCategory = button.getAttribute("data-category");

            productCards.forEach(card => {
                card.style.display = (selectedCategory === "all" || card.getAttribute("data-category") === selectedCategory)
                    ? "block"
                    : "none";
            });
        });
    });
}

// Copy Promo Code
function setupPromoCopy() {
    const copyButton = document.querySelector('.copy-btn');
    if (!copyButton) return;

    copyButton.addEventListener('click', function () {
        navigator.clipboard.writeText('SUMMER20');
        this.textContent = 'Copied!';
        setTimeout(() => (this.textContent = 'Copy'), 2000);
    });
}

// Category Selection & Scrolling
function setupCategoryButtons() {
    const categoryButtons = document.querySelectorAll('.categories button');
    categoryButtons.forEach(button => {
        button.addEventListener('click', function () {
            categoryButtons.forEach(btn => btn.classList.remove('active'));
            this.classList.add('active');
            this.scrollIntoView({ behavior: 'smooth', inline: 'center' });
        });
    });
}

function setupCategoryScroll() {
    const categoriesContainer = document.querySelector('.categories');
    if (!categoriesContainer) return;

    let isDown = false, startX, scrollLeft;

    categoriesContainer.addEventListener('mousedown', (e) => {
        isDown = true;
        categoriesContainer.style.cursor = 'grabbing';
        startX = e.pageX - categoriesContainer.offsetLeft;
        scrollLeft = categoriesContainer.scrollLeft;
    });

    ['mouseleave', 'mouseup'].forEach(event =>
        categoriesContainer.addEventListener(event, () => {
            isDown = false;
            categoriesContainer.style.cursor = 'grab';
        })
    );

    categoriesContainer.addEventListener('mousemove', (e) => {
        if (!isDown) return;
        e.preventDefault();
        const x = e.pageX - categoriesContainer.offsetLeft;
        categoriesContainer.scrollLeft = scrollLeft - (x - startX) * 2;
    });
}

// Add to Cart Functionality
function setupCartButtons() {
    document.body.addEventListener('click', function (e) {
        if (!e.target.classList.contains('add-to-cart-btn') && !e.target.closest('.add-to-cart-btn')) return;

        const button = e.target.classList.contains('add-to-cart-btn') ? e.target : e.target.closest('.add-to-cart-btn');
        
        // Check if button is disabled
        if (button.disabled || button.classList.contains('disabled')) {
            e.preventDefault();
            e.stopPropagation();
            return;
        }
        
        const productID = button.getAttribute('data-product-id');

        // Send a request to the server to add the product to the cart
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
                // Update the UI to show the product was added
                const originalHTML = button.innerHTML;
                button.innerHTML = '<span class="material-icons">check</span>Added to Cart';
                setTimeout(() => (button.innerHTML = originalHTML), 2000);

                // Update the cart counter
                updateCartCounter();
            } else {
                alert('Failed to add product to cart.');
            }
        })
        .catch(error => {
            console.error('Error:', error);
        });
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

function updateCart(product) {
    let cart = JSON.parse(localStorage.getItem('cart')) || [];
    const existingProduct = cart.find(item => item.name === product.name);

    if (existingProduct) {
        existingProduct.quantity += 1;
    } else {
        cart.push({ ...product, quantity: 1 });
    }

    localStorage.setItem('cart', JSON.stringify(cart));
}

// Wishlist Functionality
function setupWishlist() {
    // Close wishlist popup when clicking the close button
    const closeWishlistBtn = document.querySelector('.close-wishlist');
    if (closeWishlistBtn) {
        closeWishlistBtn.addEventListener('click', () => {
            document.getElementById('wishlistPopup').style.display = 'none';
        });
    }

    // Handle wishlist button clicks
    document.body.addEventListener('click', function (e) {
        // Toggle wishlist item
        if (e.target.closest('.wishlist-btn')) {
            toggleWishlist(e.target.closest('.wishlist-btn'));
            return;
        }

        // Remove from wishlist
        if (e.target.classList.contains('remove-from-wishlist')) {
            removeWishlistItem(e.target.getAttribute('data-product-id'));
            return;
        }

        // Add from wishlist to cart
        if (e.target.closest('.wishlist-add-to-cart')) {
            const button = e.target.closest('.wishlist-add-to-cart');
            addWishlistToCart(button.getAttribute('data-product-id'));
            return;
        }

        // Open wishlist popup
        if (e.target.closest('.wishlist-header-btn')) {
            toggleWishlistPopup();
            return;
        }
    });
}

// Load wishlist items from database
function loadWishlistFromDatabase() {
    fetch("get_wishlist.php")
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateWishlistUI(data.items);
                // Update heart icons on products that are in wishlist
                updateWishlistButtonsState(data.items);
            }
        })
        .catch(error => console.error("Error loading wishlist:", error));
}

// Toggle wishlist item (add/remove)
function toggleWishlist(button) {
    const productId = button.getAttribute("data-product-id");

    fetch("add_to_wishlist.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: `product_id=${productId}`
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update the button to show filled heart
                button.innerHTML = '<span class="material-icons">favorite</span>';
                // Reload wishlist to show latest items
                loadWishlistFromDatabase();
            } else {
                if (data.message === "Already in wishlist") {
                    // If already in wishlist, remove it
                    removeWishlistItem(productId);
                } else {
                    alert(data.message || "Failed to add to wishlist. Try again.");
                }
            }
        })
        .catch(error => console.error("Error:", error));
}

// Remove item from wishlist
function removeWishlistItem(productId) {
    fetch("remove_from_wishlist.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: `product_id=${productId}`
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Reset the heart icon on the product card
                const wishlistBtn = document.querySelector(`.wishlist-btn[data-product-id="${productId}"]`);
                if (wishlistBtn) {
                    wishlistBtn.innerHTML = '<span class="material-icons">favorite_border</span>';
                }
                // Reload wishlist
                loadWishlistFromDatabase();
            } else {
                alert(data.message || "Failed to remove from wishlist. Try again.");
            }
        })
        .catch(error => console.error("Error:", error));
}

// Add wishlist item to cart
function addWishlistToCart(productId) {
    // First get product details from the wishlist item
    const wishlistItem = document.querySelector(`.wishlist-item[data-product-id="${productId}"]`);
    if (!wishlistItem) return;

    // Send a request to the server to add the product to the cart
    fetch('add_to_cart.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ productID: productId })
    })
        .then(response => response.json())
        .then(data => {
            console.log('Response from add_to_cart.php:', data); // Debugging: Check the response

            if (data.success) {
                // Update the UI to show the product was added
                const button = wishlistItem.querySelector('.wishlist-add-to-cart');
                const originalHTML = button.innerHTML;
                button.innerHTML = '<span class="material-icons">check</span>Added';
                setTimeout(() => (button.innerHTML = originalHTML), 2000);

                // Update the cart counter
                updateCartCounter();
            } else {
                alert('Failed to add product to cart: ' + data.message); // Show the server's error message
            }
        })
        .catch(error => {
            console.error('Error:', error);
        });
}

// Update wishlist UI with items from database
function updateWishlistUI(items) {
    const wishlistContainer = document.querySelector('.wishlist-items');
    if (!wishlistContainer) return;

    if (items.length === 0) {
        wishlistContainer.innerHTML = '<div class="empty-wishlist">Your wishlist is empty</div>';
        return;
    }

    let html = '';
    items.forEach(item => {
        html += `
            <div class="wishlist-item" data-product-id="${item.ProductID}">
                <img src="${'uploads/' + item.ImagePath}" alt="${item.ProductName}">
                <div class="wishlist-item-details">
                    <h4>${item.ProductName}</h4>
                    <div class="price">₱${parseFloat(item.Price).toFixed(2)}</div>
                </div>
                <div class="wishlist-item-actions">
                    <button class="wishlist-add-to-cart" data-product-id="${item.ProductID}">
                        <span class="material-icons">shopping_cart</span>Add to Cart
                    </button>
                    <span class="material-icons remove-from-wishlist" data-product-id="${item.ProductID}">delete</span>
                </div>
            </div>`;
    });

    wishlistContainer.innerHTML = html;
}

// Update heart icons for products that are in the wishlist
function updateWishlistButtonsState(wishlistItems) {
    // Reset all wishlist buttons to empty hearts
    document.querySelectorAll('.wishlist-btn').forEach(btn => {
        btn.innerHTML = '<span class="material-icons">favorite_border</span>';
    });

    // Fill hearts for items in wishlist
    wishlistItems.forEach(item => {
        const btn = document.querySelector(`.wishlist-btn[data-product-id="${item.ProductID}"]`);
        if (btn) {
            btn.innerHTML = '<span class="material-icons">favorite</span>';
        }
    });
}

// Toggle wishlist popup visibility
function toggleWishlistPopup() {
    const wishlistPopup = document.getElementById('wishlistPopup');
    wishlistPopup.style.display = wishlistPopup.style.display === 'flex' ? 'none' : 'flex';

    // Refresh wishlist data when opening popup
    if (wishlistPopup.style.display === 'flex') {
        loadWishlistFromDatabase();
    }
}

// Profile Popup Handling
function setupProfilePopup() {
    const profileButton = document.querySelector('.bottom-nav a:last-child');
    const profilePopup = document.getElementById('profilePopup');
    const mainContent = document.querySelector('.products');

    if (!profileButton || !profilePopup || !mainContent) return;

    profileButton.addEventListener('click', function (e) {
        e.preventDefault();
        profilePopup.style.display = 'block';
        mainContent.style.display = 'none';
    });

    document.querySelector('.close-profile').addEventListener('click', function () {
        profilePopup.style.display = 'none';
        mainContent.style.display = 'grid';
    });
}

// Search Functionality
function setupSearchFunctionality() {
    const searchInput = document.querySelector('.search-bar input');
    const searchIcon = document.querySelector('.search-bar .material-icons');

    if (!searchInput || !searchIcon) return;

    searchInput.addEventListener('input', () => performSearch(searchInput.value));
    searchIcon.addEventListener('click', () => performSearch(searchInput.value));
    searchInput.addEventListener('keypress', e => e.key === 'Enter' && performSearch(searchInput.value));
}

// Perform search on products
function performSearch(query) {
    query = query.toLowerCase().trim();
    const productCards = document.querySelectorAll('.product-card');

    productCards.forEach(card => {
        const productName = card.querySelector('h3').textContent.toLowerCase();
        card.style.display = productName.includes(query) || query === '' ? 'flex' : 'none';
    });
}

// Load profile data
function loadProfileData() {
    fetch("profile-data.php")
        .then(response => response.json())
        .then(data => {
            if (!data.error) {
                document.getElementById("profileName").textContent = data.name;
                document.getElementById("profileEmail").textContent = data.email;
                document.getElementById("profileImage").src = data.profile_image;
            }
        })
        .catch(error => console.error("Error fetching profile data:", error));
}

// Enhanced logout function with duty hour checks and password confirmation
function logout() {
    console.log('🚀 Enhanced logout function called');
    
    // First, get duty status
    fetch('enhanced_logout.php')
        .then(response => {
            console.log('📡 Response status:', response.status);
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            console.log('📊 Enhanced logout response:', data);
            
            if (!data.success) {
                // If not authenticated, redirect to login
                console.log('❌ Not authenticated, redirecting to signin');
                window.location.href = 'signin.php';
                return;
            }
            
            // If not a staff member, proceed with normal logout
            if (!['Admin', 'Cashier', 'Rider', 'Mechanic'].includes(data.role)) {
                console.log('❌ Not a staff member, redirecting to signin. Role:', data.role);
                window.location.href = 'signin.php';
                return;
            }
            
            const dutyStatus = data.duty_status;
            console.log('⏰ Duty status:', dutyStatus);
            
            // If no active session, proceed with logout
            if (!dutyStatus.has_session) {
                console.log('❌ No active session, redirecting to signin');
                window.location.href = 'signin.php';
                return;
            }
            
            // Check if duty hours are completed
            if (dutyStatus.met_requirement) {
                // Duty completed - show completion notification
                console.log('✅ Duty completed, showing completion notification');
                showDutyCompletionNotification(dutyStatus);
            } else {
                // Duty not completed - show warning and ask for password
                console.log('⚠️ Duty not completed, showing early logout warning');
                showEarlyLogoutWarning(dutyStatus);
            }
        })
        .catch(error => {
            console.error('💥 Error checking duty status:', error);
            console.log('🔄 Fallback to normal logout due to error');
            // Fallback to normal logout
            window.location.href = 'signin.php';
        });
}

// Helper functions for custom modals
function closeCustomModal(modalId) {
    console.log('🔴 Closing modal:', modalId);
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.remove();
    }
}

function proceedWithEarlyLogout() {
    console.log('🚀 Proceed with early logout clicked!');
    closeCustomModal('earlyLogoutModal');
    requestPasswordConfirmation();
}

function showDutyCompletionNotification(dutyStatus) {
    const hours = Math.floor(dutyStatus.minutes / 60);
    const minutes = dutyStatus.minutes % 60;
    
    // Create modern modal for duty completion notification
    const modalHTML = `
        <div class="modal fade" id="completionModal" tabindex="-1" aria-labelledby="completionModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
            <div class="modal-dialog modal-dialog-centered" style="max-width: 350px;">
                <div class="modal-content" style="border-radius: 12px; border: none; box-shadow: 0 8px 25px rgba(0,0,0,0.2);">
                    <div class="modal-header" style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%); border-top-left-radius: 12px; border-top-right-radius: 12px; border-bottom: none; padding: 0.75rem 1rem;">
                        <h6 class="modal-title text-white fw-bold d-flex align-items-center" id="completionModalLabel" style="font-size: 0.9rem;">
                            <i class="fas fa-check-circle me-2" style="font-size: 1rem;"></i>
                            Duty Completed Successfully!
                        </h6>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close" style="font-size: 0.8rem;"></button>
                    </div>
                    <div class="modal-body text-center" style="padding: 1.25rem;">
                        <div class="mb-2">
                            <div class="success-icon-container" style="width: 50px; height: 50px; background: linear-gradient(135deg, #28a745 0%, #20c997 100%); border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 0.75rem;">
                                <i class="fas fa-trophy text-white" style="font-size: 1.25rem;"></i>
                            </div>
                        </div>
                        
                        <h6 class="mb-2" style="color: #333; font-weight: 600; font-size: 0.95rem;">Your hourly duty is completed this day!</h6>
                        
                        <div class="alert alert-success border-0" style="background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%); border-radius: 8px; padding: 0.75rem;">
                            <div class="d-flex align-items-center justify-content-center mb-1">
                                <i class="fas fa-clock me-2" style="color: #155724; font-size: 0.9rem;"></i>
                                <span style="color: #155724; font-weight: 600; font-size: 0.8rem;">Total Duty Time</span>
                            </div>
                            <h6 class="mb-1" style="color: #155724; font-weight: 700; font-size: 1.1rem;">${hours}h ${minutes}m</h6>
                            <p class="mb-0" style="color: #155724; font-size: 0.75rem;">Required: ${Math.floor(dutyStatus.required_minutes / 60)} hours daily</p>
                        </div>
                        
                        <p class="text-muted mb-2" style="font-size: 0.8rem; line-height: 1.3;">
                            Congratulations! You have successfully completed your required duty hours for today. 
                            <strong>You can now safely log out.</strong>
                        </p>
                        
                        <div class="d-flex align-items-center justify-content-center text-success mb-2">
                            <i class="fas fa-star me-2" style="font-size: 0.7rem;"></i>
                            <small style="font-size: 0.7rem;">Great job completing your shift!</small>
                        </div>
                    </div>
                    <div class="modal-footer" style="border-top: 1px solid #f0f0f0; padding: 0.75rem 1rem; justify-content: center;">
                        <button type="button" class="btn btn-success" id="confirmLogoutBtn" style="border-radius: 6px; padding: 0.4rem 1rem; font-weight: 500; background: linear-gradient(135deg, #28a745 0%, #20c997 100%); border: none; font-size: 0.8rem;">
                            <i class="fas fa-sign-out-alt me-1"></i>Logout Now
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Remove existing modal if present
    const existingModal = document.getElementById('completionModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Add modal to body
    document.body.insertAdjacentHTML('beforeend', modalHTML);
    
    // Show modal with simplified initialization to avoid Bootstrap errors
    const modalElement = document.getElementById('completionModal');
    const modal = new bootstrap.Modal(modalElement);
    
    // Add custom CSS to reduce backdrop opacity and ensure button clickability
    const style = document.createElement('style');
    style.textContent = `
        .modal-backdrop {
            background-color: rgba(0, 0, 0, 0.3) !important;
        }
        .modal-backdrop.show {
            opacity: 0.3 !important;
        }
        .modal-content {
            z-index: 1060 !important;
        }
        .modal-content button {
            z-index: 1061 !important;
            position: relative !important;
            pointer-events: auto !important;
        }
        .modal-content .btn {
            cursor: pointer !important;
        }
    `;
    document.head.appendChild(style);
    
    modal.show();
    
    // Handle confirm button click - use event delegation for better reliability
    document.addEventListener('click', function(e) {
        if (e.target && e.target.id === 'confirmLogoutBtn' && e.target.closest('#completionModal')) {
            e.preventDefault();
            e.stopPropagation();
            modal.hide();
            // Proceed with logout
            fetch('enhanced_logout.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=confirm_logout'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    window.location.href = 'signin.php';
            } else {
                    alert('Error during logout: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Logout error:', error);
                window.location.href = 'signin.php';
            });
        }
    });
    
    // Clean up modal after it's hidden
    document.getElementById('completionModal').addEventListener('hidden.bs.modal', function() {
        this.remove();
    });
}

function showEarlyLogoutWarning(dutyStatus) {
    const remainingHours = dutyStatus.remaining_hours;
    const remainingMinutes = dutyStatus.remaining_minutes;
    
    let remainingText = '';
    if (remainingHours > 0) {
        remainingText = `${remainingHours}h ${remainingMinutes}m`;
    } else {
        remainingText = `${remainingMinutes}m`;
    }
    
    // Create custom modal without Bootstrap dependency
    const modalHTML = `
        <div id="earlyLogoutModal" style="
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.3);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        ">
            <div style="
                background: white;
                border-radius: 12px;
                box-shadow: 0 8px 25px rgba(0,0,0,0.2);
                max-width: 350px;
                width: 100%;
                max-height: 90vh;
                overflow-y: auto;
            ">
                <div style="
                    background: linear-gradient(135deg, #ff6b35 0%, #f7931e 100%);
                    color: white;
                    padding: 0.75rem 1rem;
                    border-radius: 12px 12px 0 0;
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                ">
                    <h6 style="margin: 0; font-size: 0.9rem; font-weight: 600;">
                        <i class="fas fa-exclamation-triangle" style="margin-right: 8px;"></i>
                        Early Logout Warning
                    </h6>
                    <button onclick="closeCustomModal('earlyLogoutModal')" style="
                        background: none;
                        border: none;
                        color: white;
                        font-size: 1.2rem;
                        cursor: pointer;
                        padding: 0;
                        width: 24px;
                        height: 24px;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                    ">×</button>
                </div>
                
                <div style="padding: 1.25rem; text-align: center;">
                    <div style="margin-bottom: 0.75rem;">
                        <div style="
                            width: 50px;
                            height: 50px;
                            background: linear-gradient(135deg, #ff6b35 0%, #f7931e 100%);
                            border-radius: 50%;
                            display: inline-flex;
                            align-items: center;
                            justify-content: center;
                            margin-bottom: 0.75rem;
                        ">
                            <i class="fas fa-clock" style="color: white; font-size: 1.25rem;"></i>
                        </div>
                    </div>
                    
                    <h6 style="color: #333; font-weight: 600; font-size: 0.95rem; margin-bottom: 0.75rem;">
                        You have remaining duty hours!
                    </h6>
                    
                    <div style="
                        background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
                        border-radius: 8px;
                        padding: 0.75rem;
                        margin-bottom: 0.75rem;
                    ">
                        <div style="display: flex; align-items: center; justify-content: center; margin-bottom: 0.5rem;">
                            <i class="fas fa-hourglass-half" style="color: #856404; font-size: 0.9rem; margin-right: 8px;"></i>
                            <span style="color: #856404; font-weight: 600; font-size: 0.8rem;">Remaining Time</span>
                        </div>
                        <h6 style="color: #856404; font-weight: 700; font-size: 1.1rem; margin-bottom: 0.25rem;">${remainingText}</h6>
                        <p style="color: #856404; font-size: 0.75rem; margin: 0;">Required: ${Math.floor(dutyStatus.required_minutes / 60)} hours daily</p>
                    </div>
                    
                    <p style="color: #666; font-size: 0.8rem; line-height: 1.3; margin-bottom: 0.75rem;">
                        You are about to log out before completing your required duty hours. 
                        <strong>Do you want to proceed with early logout?</strong>
                    </p>
                    
                    <div style="display: flex; align-items: center; justify-content: center; margin-bottom: 0.75rem;">
                        <i class="fas fa-info-circle" style="color: #666; font-size: 0.7rem; margin-right: 8px;"></i>
                        <small style="color: #666; font-size: 0.7rem;">You will need to enter your password to confirm early logout.</small>
                    </div>
                </div>
                
                <div style="
                    border-top: 1px solid #f0f0f0;
                    padding: 0.75rem 1rem;
                    display: flex;
                    justify-content: center;
                    gap: 8px;
                ">
                    <button onclick="closeCustomModal('earlyLogoutModal')" style="
                        border-radius: 6px;
                        padding: 0.4rem 1rem;
                        font-weight: 500;
                        border: 2px solid #e9ecef;
                        background: white;
                        color: #333;
                        font-size: 0.8rem;
                        cursor: pointer;
                        display: flex;
                        align-items: center;
                        gap: 4px;
                    ">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button onclick="proceedWithEarlyLogout()" style="
                        border-radius: 6px;
                        padding: 0.4rem 1rem;
                        font-weight: 500;
                        background: linear-gradient(135deg, #ff6b35 0%, #f7931e 100%);
                        border: none;
                        color: white;
                        font-size: 0.8rem;
                        cursor: pointer;
                        display: flex;
                        align-items: center;
                        gap: 4px;
                    ">
                        <i class="fas fa-sign-out-alt"></i> Proceed
                    </button>
                </div>
            </div>
        </div>
    `;
    
    // Remove existing modal if present
    const existingModal = document.getElementById('earlyLogoutModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Add modal to body
    document.body.insertAdjacentHTML('beforeend', modalHTML);
    
    console.log('✅ Custom modal created and displayed');
}

function requestPasswordConfirmation() {
    // Create custom password confirmation modal without Bootstrap dependency
    const modalHTML = `
        <div id="passwordConfirmModal" style="
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.3);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        ">
            <div style="
                background: white;
                border-radius: 12px;
                box-shadow: 0 8px 25px rgba(0,0,0,0.2);
                max-width: 350px;
                width: 100%;
                max-height: 90vh;
                overflow-y: auto;
            ">
                <div style="
                    background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
                    color: white;
                    padding: 0.75rem 1rem;
                    border-radius: 12px 12px 0 0;
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                ">
                    <h6 style="margin: 0; font-size: 0.9rem; font-weight: 600;">
                        <i class="fas fa-shield-alt" style="margin-right: 8px;"></i>
                        Password Confirmation Required
                    </h6>
                    <button onclick="closeCustomModal('passwordConfirmModal')" style="
                        background: none;
                        border: none;
                        color: white;
                        font-size: 1.2rem;
                        cursor: pointer;
                        padding: 0;
                        width: 24px;
                        height: 24px;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                    ">×</button>
                </div>
                
                <div style="padding: 1.25rem;">
                    <div style="text-align: center; margin-bottom: 0.75rem;">
                        <div style="
                            width: 50px;
                            height: 50px;
                            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
                            border-radius: 50%;
                            display: inline-flex;
                            align-items: center;
                            justify-content: center;
                            margin-bottom: 0.75rem;
                        ">
                            <i class="fas fa-lock" style="color: white; font-size: 1.25rem;"></i>
                        </div>
                    </div>
                    
                    <h6 style="color: #333; font-weight: 600; font-size: 0.95rem; margin-bottom: 0.75rem; text-align: center;">
                        Early Logout Confirmation
                    </h6>
                    
                    <p style="color: #666; font-size: 0.8rem; line-height: 1.3; margin-bottom: 0.75rem; text-align: center;">
                        You are logging out before completing your duty hours. 
                        <strong>Please enter your password to confirm this action.</strong>
                    </p>
                    
                    <div style="margin-bottom: 0.75rem;">
                        <label style="color: #333; font-size: 0.8rem; font-weight: 600; margin-bottom: 0.5rem; display: block;">
                            <i class="fas fa-key" style="margin-right: 8px;"></i>Password
                        </label>
                        <div style="display: flex;">
                            <span style="
                                background: #f8f9fa;
                                border: 1px solid #ced4da;
                                border-right: none;
                                padding: 0.4rem;
                                display: flex;
                                align-items: center;
                                border-radius: 6px 0 0 6px;
                            ">
                                <i class="fas fa-lock" style="color: #6c757d;"></i>
                            </span>
                            <input type="password" id="logoutPassword" placeholder="Enter your password" required style="
                                border: 1px solid #ced4da;
                                border-left: none;
                                border-radius: 0 6px 6px 0;
                                padding: 0.4rem;
                                font-size: 0.8rem;
                                flex: 1;
                                outline: none;
                            ">
                        </div>
                        <div id="passwordError" style="color: #dc3545; font-size: 0.75rem; margin-top: 0.25rem; display: none;"></div>
                    </div>
                    
                    <div style="
                        background: linear-gradient(135deg, #d1ecf1 0%, #bee5eb 100%);
                        border-radius: 6px;
                        padding: 0.5rem;
                        margin-bottom: 0.75rem;
                    ">
                        <div style="display: flex; align-items: center;">
                            <i class="fas fa-info-circle" style="color: #0c5460; font-size: 0.7rem; margin-right: 8px;"></i>
                            <small style="color: #0c5460; font-size: 0.7rem;">This action will be logged for security purposes.</small>
                        </div>
                    </div>
                </div>
                
                <div style="
                    border-top: 1px solid #f0f0f0;
                    padding: 0.75rem 1rem;
                    display: flex;
                    justify-content: center;
                    gap: 8px;
                ">
                    <button onclick="closeCustomModal('passwordConfirmModal')" style="
                        border-radius: 6px;
                        padding: 0.4rem 1rem;
                        font-weight: 500;
                        border: 2px solid #e9ecef;
                        background: white;
                        color: #333;
                        font-size: 0.8rem;
                        cursor: pointer;
                        display: flex;
                        align-items: center;
                        gap: 4px;
                    ">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button onclick="confirmPasswordLogout()" style="
                        border-radius: 6px;
                        padding: 0.4rem 1rem;
                        font-weight: 500;
                        background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
                        border: none;
                        color: white;
                        font-size: 0.8rem;
                        cursor: pointer;
                        display: flex;
                        align-items: center;
                        gap: 4px;
                    ">
                        <i class="fas fa-sign-out-alt"></i> Confirm Logout
                    </button>
                </div>
            </div>
        </div>
    `;
    
    // Remove existing modal if present
    const existingModal = document.getElementById('passwordConfirmModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Add modal to body
    document.body.insertAdjacentHTML('beforeend', modalHTML);
    
    // Focus on password input
    setTimeout(() => {
        const passwordInput = document.getElementById('logoutPassword');
        if (passwordInput) {
            passwordInput.focus();
        }
    }, 100);
    
    console.log('✅ Password confirmation modal created and displayed');
}

function confirmPasswordLogout() {
    console.log('🔐 Confirm password logout clicked!');
    
    const password = document.getElementById('logoutPassword').value;
    
    if (!password) {
        showPasswordError('Password is required');
        return;
    }
    
    // Disable button and show loading
    const button = event.target;
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';
    
    // Verify password and logout
    fetch('enhanced_logout.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=verify_password&password=${encodeURIComponent(password)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closeCustomModal('passwordConfirmModal');
            // Show success message briefly before redirect
            showLogoutSuccess(data.message);
            setTimeout(() => {
                window.location.href = 'signin.php';
            }, 1500);
        } else {
            // Re-enable button and show error
            button.disabled = false;
            button.innerHTML = '<i class="fas fa-sign-out-alt"></i> Confirm Logout';
            showPasswordError(data.message);
        }
    })
    .catch(error => {
        console.error('Password verification error:', error);
        // Re-enable button and show error
        button.disabled = false;
        button.innerHTML = '<i class="fas fa-sign-out-alt"></i> Confirm Logout';
        showPasswordError('An error occurred. Please try again.');
    });
}

function showPasswordError(message) {
    const passwordInput = document.getElementById('logoutPassword');
    const errorDiv = document.getElementById('passwordError');
    
    if (passwordInput) {
        passwordInput.style.borderColor = '#dc3545';
    }
    if (errorDiv) {
        errorDiv.textContent = message;
        errorDiv.style.display = 'block';
    }
    
    // Clear error when user starts typing
    if (passwordInput) {
        passwordInput.addEventListener('input', function() {
            this.style.borderColor = '#ced4da';
            if (errorDiv) {
                errorDiv.textContent = '';
                errorDiv.style.display = 'none';
            }
        }, { once: true });
    }
}

function showLogoutSuccess(message) {
    const notification = document.createElement('div');
    notification.className = 'alert alert-success alert-dismissible fade show position-fixed';
    notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); border-left: 4px solid #28a745;';
    notification.innerHTML = `
        <i class="fas fa-check-circle me-2"></i>
        <strong>Success!</strong> ${message}
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
    }, 2000);
}

// Intercept logout links to use enhanced logout function
document.addEventListener('DOMContentLoaded', function() {
    // Check if this is an admin dashboard - if so, don't intercept logout at all
    const isAdminDashboard = window.location.pathname.includes('Admin-Dashboard.php');
    
    if (isAdminDashboard) {
        console.log('Admin dashboard detected - logout interception disabled');
        return; // Don't intercept logout links on admin dashboard
    }
    
    // Find all logout links and replace their behavior (only for non-admin pages)
    const logoutLinks = document.querySelectorAll('a[href*="logout.php"]');
    logoutLinks.forEach(link => {
        // Check if the link already has an onclick handler
        if (link.hasAttribute('onclick')) {
            console.log('Logout link already has onclick handler - skipping interception');
            return;
        }
        
        link.addEventListener('click', function(e) {
            // For other pages, use enhanced logout
            e.preventDefault();
            console.log('Non-admin logout clicked - using enhanced logout');
            logout();
        });
    });
});

function setupSaleBanners() {
    const banners = document.querySelectorAll('.sale-banner');
    const dots = document.querySelectorAll('.dot');
    let currentBannerIndex = 0;
    let intervalId;

    // Function to show the next banner
    function showNextBanner() {
        // Hide the current banner
        banners[currentBannerIndex].classList.remove('active');
        dots[currentBannerIndex].classList.remove('active');

        // Move to the next banner
        currentBannerIndex = (currentBannerIndex + 1) % banners.length;

        // Show the next banner
        banners[currentBannerIndex].classList.add('active');
        dots[currentBannerIndex].classList.add('active');
    }

    // Function to show a specific banner
    function showBanner(index) {
        // Hide the current banner
        banners[currentBannerIndex].classList.remove('active');
        dots[currentBannerIndex].classList.remove('active');

        // Update the current banner index
        currentBannerIndex = index;

        // Show the selected banner
        banners[currentBannerIndex].classList.add('active');
        dots[currentBannerIndex].classList.add('active');
    }

    // Initialize the first banner and dot
    if (banners.length > 0) {
        banners[currentBannerIndex].classList.add('active');
        dots[currentBannerIndex].classList.add('active');

        // Set interval to change banners every 5 seconds
        intervalId = setInterval(showNextBanner, 6000);
    }

    // Add click event listeners to dots for manual navigation
    dots.forEach((dot, index) => {
        dot.addEventListener('click', () => {
            clearInterval(intervalId); // Stop auto-sliding
            showBanner(index);
            intervalId = setInterval(showNextBanner, 6000); // Restart auto-sliding
        });
    });
}


function setupDragToScroll() {
    const container = document.querySelector('.recent-order-items');
    if (!container) return; // Exit if the container doesn't exist

    let isDown = false;
    let startX;
    let scrollLeft;

    container.addEventListener('mousedown', (e) => {
        isDown = true;
        container.classList.add('active');
        startX = e.pageX - container.offsetLeft;
        scrollLeft = container.scrollLeft;
    });
    container.addEventListener('mouseleave', () => {
        isDown = false;
        container.classList.remove('active');
    });
    container.addEventListener('mouseup', () => {
        isDown = false;
        container.classList.remove('active');
    });
    container.addEventListener('mousemove', (e) => {
        if (!isDown) return;
        e.preventDefault();
        const x = e.pageX - container.offsetLeft;
        const walk = (x - startX) * 1.5; //scroll-fast
        container.scrollLeft = scrollLeft - walk;
    });
}
function setupAutoCarousel() {
    const container = document.querySelector('.recent-order-items');
    if (!container) return;

    let items = Array.from(container.querySelectorAll('.recent-order-item'));
    let currentIndex = 0;
    let intervalId;
    let isAnimating = false; // Flag to prevent overlapping animations

    // Function to scroll the container to the specified index with smooth animation
    function scrollToItem(index) {
        if (isAnimating) return; // Prevent animation if one is already in progress
        if (!items[index]) return;

        isAnimating = true;

        const item = items[index];
        const itemWidth = item.offsetWidth;
        const containerWidth = container.offsetWidth;
        let scrollPosition = item.offsetLeft - container.offsetLeft;

        // Adjust scroll position if the item is near the end of the container to keep it centered
        if (scrollPosition > container.scrollWidth - containerWidth) {
            scrollPosition = container.scrollWidth - containerWidth;
        }

        container.scrollTo({
            left: scrollPosition,
            behavior: 'smooth' // Use smooth scrolling
        });

        // After the scrolling animation is complete, reset the animation flag
        setTimeout(() => {
            isAnimating = false;
        }, 500); // Adjust timeout to match transition duration
    }

    // Function to advance the carousel
    function advanceCarousel() {
        if (!container || items.length === 0) {
            clearInterval(intervalId);
            return;
        }

        currentIndex = (currentIndex + 1) % items.length;
        scrollToItem(currentIndex);
    }

    // Initialize carousel if there are items
    if (items.length > 0) {
        intervalId = setInterval(advanceCarousel, 4000);

        // Listen for transition end to update items array
        container.addEventListener('transitionend', () => {
            items = Array.from(container.querySelectorAll('.recent-order-item'));
        });
    } else {
        clearInterval(intervalId);
        console.warn("No items to scroll.");
        if (container) {
            container.innerHTML = "<p>No recent orders found.</p>";
        }
    }


    // Stop the interval if the container is no longer visible
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (!entry.isIntersecting) {
                clearInterval(intervalId);
                console.log('Carousel stopped as container is no longer visible.');
            } else {
                // Restart the interval if it becomes visible again
                if (!intervalId) {
                    intervalId = setInterval(advanceCarousel, 4000);
                    console.log('Carousel restarted as container is visible.');
                }
            }
        });
    });

    if (container) {
        observer.observe(container);
    }

    // Cleanup on page unload
    window.addEventListener('beforeunload', () => clearInterval(intervalId));
}

function implementsAutoCarousel(card3D) {
    if (!card3D) {
        setupAutoCarousel();
    }
}
function setupProductDetailsModal() {
    const detailsButtons = document.querySelectorAll('.details-btn');
    const modalElement = document.getElementById('productDetailsModal');
    const modal = new bootstrap.Modal(modalElement);

    detailsButtons.forEach(button => {
        button.addEventListener('click', function (event) {
            event.preventDefault();

            // Find the closest product card
            const productCard = button.closest('.product-card');
            if (!productCard) {
                console.error('Could not find .product-card parent.');
                return;
            }

            // Retrieve the product_id from the button's data attribute
            const productId = button.getAttribute('data-product-id'); // Updated: Get from the button itself
            console.log("Product ID:", productId); // Debugging: Log the product_id

            // Retrieve other product details
            const productName = productCard.querySelector('h3').innerText;
            const productPrice = productCard.querySelector('.price').innerText;
            const productStock = productCard.querySelector('.stock').innerText;
            const productImage = productCard.querySelector('img').src;

            // Update the modal content
            document.getElementById('modalProductImage').src = productImage;
            document.getElementById('modalProductName').innerText = productName;
            document.getElementById('modalProductPrice').innerText = productPrice;
            document.getElementById('modalProductStock').innerText = productStock;

            // Set the productId in the hidden input field
            document.getElementById('productId').value = productId;

            // Fetch and display feedback for this product
            fetchFeedback(productId);

            // Initialize Feedback SSE for realtime updates
            initFeedbackSSE(productId);

            // Show the modal
            modal.show();
        });
    });

    // Handle feedback form submission
    const feedbackForm = document.getElementById('feedbackForm');
    if (feedbackForm) {
        feedbackForm.addEventListener('submit', function (e) {
            e.preventDefault();

            // Retrieve form data
            const productId = document.getElementById('productId').value;
            const comment = document.getElementById('feedbackComment').value;
            let rating = parseInt(document.getElementById('feedbackRating').value, 10);
            if (isNaN(rating)) rating = 0; // default to 0 when not selected
            const imageFile = document.getElementById('feedbackImage').files[0];

            // Debugging: Log the form data
            console.log("Submitting feedback for product ID:", productId);
            console.log("Comment:", comment);
            console.log("Rating:", rating);
            console.log("Image File:", imageFile ? imageFile.name : "No image");

            // Prepare FormData for submission
            const formData = new FormData();
            formData.append('product_id', productId);
            formData.append('comment', comment);
            formData.append('rating', rating);
            if (imageFile) {
                formData.append('image', imageFile);
            }

            // Submit feedback to the server
            fetch('submit_feedback.php', {
                method: 'POST',
                body: formData
            })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! Status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        showFancyAlert('success', 'Feedback submitted successfully!');
                        // Reset the form
                        document.getElementById('feedbackComment').value = '';
                        document.getElementById('feedbackRating').value = '0';
                        document.getElementById('feedbackImage').value = '';

                        // Reset star ratings
                        const stars = document.querySelectorAll('.star-rating .star');
                        stars.forEach(s => {
                            s.classList.remove('selected');
                            s.classList.add('unselected');
                        });

                        // Let SSE deliver the new review to avoid duplicates
                    } else {
                        showFancyAlert('error', data.message || 'Failed to submit feedback.');
                    }
                })
                .catch(error => {
                    console.error('Error submitting feedback:', error);
                    showFancyAlert('error', 'An error occurred while submitting feedback. Please try again.');
                });
        });
    } else {
        console.warn("Feedback form not found. Ensure it exists in the DOM.");
    }
}

function fetchFeedback(productId) {
    fetch(`get_feedback.php?product_id=${productId}`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! Status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            const feedbackList = document.getElementById('feedbackList');
            if (!document.getElementById('feedbackList')) {
                console.error("Feedback list element not found.");
                return;
            }

            if (data.error) {
                console.error('Error fetching feedback:', data.error);
                const fl = document.getElementById('feedbackList');
                if (fl) fl.innerHTML = `<p class="text-danger">${data.error}</p>`;
                return;
            }

            // Use unified renderer so SSE and initial fetch share the same DOM shape
            const list = document.getElementById('feedbackList');
            if (list) list.innerHTML = '';
            data.forEach(feedback => addOrUpdateFeedbackItem(feedback, false, false));
        })
        .catch(error => {
            console.error('Error fetching feedback:', error);
            const feedbackList = document.getElementById('feedbackList');
            if (feedbackList) {
                feedbackList.innerHTML = `<p class="text-danger">Failed to load feedback. Please try again later.</p>`;
            }
        });
}

let feedbackSSE;
function initFeedbackSSE(productId) {
    try { if (feedbackSSE) { feedbackSSE.close(); } } catch(_){}
    try {
        feedbackSSE = new EventSource(`sse_feedback.php?product_id=${encodeURIComponent(productId)}`);
        feedbackSSE.onmessage = function(ev){
            try {
                const data = JSON.parse(ev.data);
                if (data.type === 'feedback_snapshot') {
                    // Initial list render
                    renderFeedbackList(data.items || []);
                } else if (data.type === 'feedback_updates') {
                    applyFeedbackUpdates(data);
                }
            } catch(e) { console.log('Feedback SSE parse error', e); }
        };
        feedbackSSE.onerror = function(){ try{ feedbackSSE.close(); }catch(_){}; setTimeout(()=>initFeedbackSSE(productId), 4000); };
    } catch(e) { console.log('Feedback SSE init failed', e); }
}

function renderFeedbackList(items){
    const feedbackList = document.getElementById('feedbackList');
    if (!feedbackList) return;
    feedbackList.innerHTML = '';
    items.forEach(f => addOrUpdateFeedbackItem(f, false));
}

function applyFeedbackUpdates(payload){
    const added = payload.added || [];
    const changed = payload.changed || [];
    const deleted = payload.deleted || [];

    // Handle deletions
    deleted.forEach(id => {
        const el = document.querySelector(`.feedback-item[data-feedback-id="${id}"]`);
        if (el) {
            el.style.transition = 'opacity .25s ease, transform .25s ease';
            el.style.opacity = '0';
            el.style.transform = 'translateY(10px)';
            setTimeout(()=>{ if (el.parentNode) el.parentNode.removeChild(el); }, 250);
        }
    });

    // Apply changes (skip ones that are also in added to avoid duplicates)
    const addedIds = new Set(added.map(a => String(a.id)));
    changed.forEach(f => { if (!addedIds.has(String(f.id))) addOrUpdateFeedbackItem(f, true); });

    // New items at top with fade-in and push old down
    added.forEach(f => addOrUpdateFeedbackItem(f, false, true));
}

function addOrUpdateFeedbackItem(feedback, isUpdate, isPrepend){
    const feedbackList = document.getElementById('feedbackList');
    if (!feedbackList) return;
    let item = document.querySelector(`.feedback-item[data-feedback-id="${feedback.id}"]`);
    const html = buildFeedbackHTML(feedback);
    if (item) {
        item.outerHTML = html; // replace
    } else {
        if (isUpdate) { return; } // don't create new on update path
        const wrapper = document.createElement('div');
        wrapper.innerHTML = html;
        const el = wrapper.firstElementChild;
        if (isPrepend) {
            el.style.opacity = '0';
            el.style.transform = 'translateY(-8px)';
            feedbackList.prepend(el);
            requestAnimationFrame(()=>{
                el.style.transition = 'opacity .35s ease, transform .35s ease';
                el.style.opacity = '1';
                el.style.transform = 'translateY(0)';
            });
        } else {
            feedbackList.appendChild(el);
        }
    }
}

function buildFeedbackHTML(feedback){
    const userImage = feedback.ImagePath
        ? `<img src="${toAbsoluteUrl(feedback.ImagePath)}" alt="${feedback.first_name}" class="feedback-user-image">`
        : `<div class="feedback-user-image default-image">${feedback.first_name.charAt(0)}</div>`;
    const deleteBtnHTML = (typeof window.CURRENT_USER_ID !== 'undefined' && Number(feedback.user_id) === Number(window.CURRENT_USER_ID))
        ? `<div class="feedback-delete" style="margin-top:10px;"><button class="delete-feedback-btn btn btn-outline-danger btn-sm" data-feedback-id="${feedback.id}" title="Delete"><i class="fas fa-trash"></i></button></div>`
        : '';
    const countsHtml = buildReactionsCountsHtml(feedback.reactions || {});
    return `
    <div class="feedback-item" data-feedback-id="${feedback.id}">
        <div class="feedback-user">
            ${userImage}
            <div class="feedback-user-details">
                <strong>${feedback.first_name} ${feedback.last_name}</strong>
                <p>${new Date(feedback.created_at).toLocaleDateString()}</p>
            </div>
        </div>
        <div class="feedback-content">
            <p class="rating">${generateStarRating(feedback.rating)}</p>
            <p>${feedback.comment}</p>
            ${feedback.image_path ? `<div class="feedback-image-wrap" data-feedback-id="${feedback.id}">
                <img src="${toAbsoluteUrl(feedback.image_path)}" alt="Feedback Image" class="feedback-image">
                <div class="reactions-bar">
                    <button class="react-btn" data-reaction="like" title="Like">👍</button>
                    <button class="react-btn" data-reaction="love" title="Love">❤️</button>
                    <button class="react-btn" data-reaction="care" title="Care">🤗</button>
                    <button class="react-btn" data-reaction="haha" title="Haha">😄</button>
                    <button class="react-btn" data-reaction="wow" title="Wow">😮</button>
                    <button class="react-btn" data-reaction="sad" title="Sad">😢</button>
                    <button class="react-btn" data-reaction="angry" title="Angry">😡</button>
                </div>
                <div class="reactions-counts">${countsHtml}</div>
            </div>` : ''}
            ${deleteBtnHTML}
        </div>
    </div>`;
}

function buildReactionsCountsHtml(counts){
    const order = ['like','love','care','haha','wow','sad','angry'];
    const icons = { like:'👍', love:'❤️', care:'🤗', haha:'😄', wow:'😮', sad:'😢', angry:'😡' };
    let html = '';
    order.forEach(k => { const c = counts && counts[k] ? counts[k] : 0; if (c > 0) html += `<span class="rc">${icons[k]} ${c}</span>`; });
    return html || '<span class="rc" style="opacity:.6;">No reactions yet</span>';
}

// Event delegation for reactions
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.react-btn');
    if (!btn) return;
    const wrap = btn.closest('.feedback-image-wrap');
    if (!wrap) return;
    const fid = wrap.getAttribute('data-feedback-id');
    const reaction = btn.getAttribute('data-reaction');
    const form = new URLSearchParams();
    form.set('feedback_id', fid);
    form.set('reaction', reaction);
    fetch('add_feedback_reaction.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: form.toString() })
    .then(r => r.json())
    .then(data => {
        if (data && data.success) {
            updateReactionCountsUI(wrap, data.counts || {});
            showFancyAlert('success', data.user_reaction ? ('Reacted: ' + data.user_reaction) : 'Reaction removed');
        } else {
            showFancyAlert('error', data.message || 'Failed to react.');
        }
    })
    .catch(() => showFancyAlert('error', 'Network error.'));
});

// Event delegation for deleting own feedback
document.addEventListener('click', function(e) {
    const del = e.target.closest('.delete-feedback-btn');
    if (!del) return;
    const fid = del.getAttribute('data-feedback-id');
    if (!fid) return;
    showConfirm('Delete this feedback? This cannot be undone.', { title: 'Delete Feedback', okText: 'Delete', cancelText: 'Cancel' })
    .then(confirmed => {
        if (!confirmed) return;
        const form = new URLSearchParams();
        form.set('feedback_id', fid);
        fetch('delete_feedback.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: form.toString() })
        .then(r => r.json())
        .then(data => {
            if (data && data.success) {
                // Remove the feedback item from DOM
                const item = del.closest('.feedback-item');
                if (item && item.parentNode) item.parentNode.removeChild(item);
                showFancyAlert('success', 'Feedback deleted');
            } else {
                showFancyAlert('error', data.message || 'Failed to delete feedback');
            }
        })
        .catch(() => showFancyAlert('error', 'Network error.'));
    });
});

function updateReactionCountsUI(wrap, counts) {
    const countsDiv = wrap.querySelector('.reactions-counts');
    if (!countsDiv) return;
    const order = ['like','love','care','haha','wow','sad','angry'];
    const icons = { like:'👍', love:'❤️', care:'🤗', haha:'😄', wow:'😮', sad:'😢', angry:'😡' };
    let html = '';
    order.forEach(k => { const c = counts[k] || 0; if (c > 0) html += `<span class="rc">${icons[k]} ${c}</span>`; });
    countsDiv.innerHTML = html || '<span class="rc" style="opacity:.6;">No reactions yet</span>';
}
function generateStarRating(rating) {
    const starIcons = {
        full: '<i class="fas fa-star"></i>',
        half: '<i class="fas fa-star-half-alt"></i>',
        empty: '<i class="far fa-star"></i>',
    };

    let output = '';
    const fullStars = Math.floor(rating);
    const hasHalfStar = rating % 1 !== 0;

    for (let i = 0; i < fullStars; i++) {
        output += starIcons.full;
    }

    if (hasHalfStar) {
        output += starIcons.half;
    }

    const emptyStars = 5 - fullStars - (hasHalfStar ? 1 : 0);

    for (let i = 0; i < emptyStars; i++) {
        output += starIcons.empty;
    }

    return output;
}

function setupStarRating() {
    const stars = document.querySelectorAll('.star-rating .star');
    const ratingInput = document.getElementById('feedbackRating');

    stars.forEach(star => {
        star.addEventListener('click', function () {
            const rating = parseInt(this.dataset.rating);

            ratingInput.value = rating;

            stars.forEach(s => {
                if (parseInt(s.dataset.rating) <= rating) {
                    s.classList.add('selected');
                    s.classList.remove('unselected');
                } else {
                    s.classList.remove('selected');
                    s.classList.add('unselected');
                }
            });
        });
    });
}

// Modern toast/modal replacement for window.alert
function showFancyAlert(type, message) {
    // Type: 'success' | 'error' | 'info'
    const colors = {
        success: { bg: '#198754', icon: 'check_circle' },
        error: { bg: '#dc3545', icon: 'error' },
        info: { bg: '#0d6efd', icon: 'info' }
    };
    const cfg = colors[type] || colors.info;

    const toast = document.createElement('div');
    toast.style.position = 'fixed';
    toast.style.top = '20px';
    toast.style.right = '20px';
    toast.style.zIndex = '1055';
    toast.style.display = 'flex';
    toast.style.alignItems = 'center';
    toast.style.gap = '8px';
    toast.style.padding = '12px 14px';
    toast.style.borderRadius = '10px';
    toast.style.color = '#fff';
    toast.style.background = cfg.bg;
    toast.style.boxShadow = '0 8px 20px rgba(0,0,0,0.2)';
    toast.style.fontSize = '14px';

    const icon = document.createElement('span');
    icon.className = 'material-icons';
    icon.textContent = cfg.icon;
    icon.style.fontSize = '20px';
    icon.style.opacity = '0.9';

    const msg = document.createElement('div');
    msg.textContent = message;

    const close = document.createElement('span');
    close.textContent = '×';
    close.style.cursor = 'pointer';
    close.style.marginLeft = '8px';
    close.style.fontWeight = 'bold';
    close.onclick = () => toast.remove();

    toast.appendChild(icon);
    toast.appendChild(msg);
    toast.appendChild(close);
    document.body.appendChild(toast);

    setTimeout(() => { if (toast.parentNode) toast.remove(); }, 4000);
}

// Modern confirm dialog using Bootstrap modal, returns a Promise<boolean>
function showConfirm(message, options) {
    options = options || {};
    const title = options.title || 'Are you sure?';
    const okText = options.okText || 'OK';
    const cancelText = options.cancelText || 'Cancel';

    const modal = document.createElement('div');
    modal.className = 'modal fade';
    modal.tabIndex = -1;
    modal.innerHTML = `
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">${title}</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p style="margin:0;">${message}</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">${cancelText}</button>
          <button type="button" class="btn btn-danger" id="confirmModalOkBtn">${okText}</button>
        </div>
      </div>
    </div>`;

    document.body.appendChild(modal);
    const bsModal = new bootstrap.Modal(modal);

    return new Promise(resolve => {
        let resolved = false;
        modal.addEventListener('shown.bs.modal', () => {
            const okBtn = modal.querySelector('#confirmModalOkBtn');
            okBtn.addEventListener('click', () => {
                resolved = true;
                resolve(true);
                bsModal.hide();
            }, { once: true });
        }, { once: true });

        modal.addEventListener('hidden.bs.modal', () => {
            if (!resolved) resolve(false);
            modal.parentNode && modal.parentNode.removeChild(modal);
        }, { once: true });

        bsModal.show();
    });
}
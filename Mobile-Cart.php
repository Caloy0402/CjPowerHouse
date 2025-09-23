<?php
session_start(); // Start the session
require_once 'dbconn.php';

// Dynamically determine the base URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$path = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');  // Get directory of the current script
$baseURL = $protocol . '://' . $host . $path . '/';

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    // Redirect to login page or handle unauthorized access
    header("Location: {$baseURL}signin.php");  // Corrected path
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shopping Cart</title>
    <link rel="icon" type="image/png" href="Image/logo.png">
    <link rel="stylesheet" href="css/styles.css">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</head>
<body>
    <div class="container-fluid" style="max-width:540px; margin:0 auto; padding:0 8px;">
        <header class="cart-header">
            <a href="Mobile-Dashboard.php" class="back-button">
                <span class="material-icons">arrow_back</span>
            </a>
            <h1 style="margin: 0; color: #333;">Shopping Cart (0)</h1>
        </header>
        <div class="progress-bar">
            <div class="progress-step current">
                <span class="material-icons">shopping_cart</span>
                <span>Cart</span>
            </div>
            <div class="progress-line"></div>
            <div class="progress-step">
                <span class="material-icons">local_shipping</span>
                <span>Delivery</span>
            </div>
            <div class="progress-line"></div>
            <div class="progress-step">
                <span class="material-icons">check_circle</span>
                <span>Complete</span>
            </div>
        </div>


         <!-- Cart Items Section -->
         <div class="cart-items" id="cart-items">
            <!-- Cart items will be dynamically inserted here -->
             <p id="empty-cart-message" style="text-align: center; display: none;">Your cart is empty.</p>  <!-- Display message when cart is empty -->
        </div>

        <!-- Order Summary Section -->

        <div class="order-summary">
            <div class="summary-row">
                <span>Subtotal</span>
                <span id="subtotal">₱0.00</span>
            </div>
            <div class="summary-row">
                <span>Total Weight(kg)</span>
                <span id="total-weight-display">--kg</span>
            </div>
            <div class="summary-row total">
                <span>Total</span>
                <span id="total">₱0.00</span>
            </div>
        </div>

        <!-- Checkout Button -->
        <input type="hidden" id="total-weight" name="total_weight" value="0">
        <button class="checkout-button" id="checkout-button">
            <span class="material-icons">lock_outline</span>
            Proceed to Checkout (₱<span id="checkout-total">0.00</span>)
        </button>
    </div>

    <!-- PIN Verification Modal -->
    <div class="modal fade" id="pinModal" tabindex="-1" aria-labelledby="pinModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="pinModalLabel">Enter PIN to Continue</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="mb-3">
                        <span class="material-icons" style="font-size: 48px; color: #666;">lock</span>
                    </div>
                    <p class="mb-3">Please enter your 4-digit PIN to proceed with checkout</p>
                    <div class="pin-input-container">
                        <input type="password" id="checkoutPin" maxlength="4" pattern="[0-9]{4}" 
                               placeholder="0000" inputmode="numeric" class="form-control text-center" 
                               style="font-size: 24px; letter-spacing: 8px; padding: 15px;">
                    </div>
                    <div class="mt-3">
                        <button type="button" class="btn btn-primary" id="verifyPinBtn">Verify PIN</button>
                        <button type="button" class="btn btn-secondary ms-2" id="forgotPinBtn">
                            <span class="material-icons" style="vertical-align: middle; margin-right: 4px; font-size: 16px;">email</span>
                            Forgot PIN code?
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const baseURL = "<?= $baseURL ?>";
    </script>
    <script>
    // Lightweight banner for inline messages in mobile cart
    function showFancyBanner(type, message) {
        const existing = document.getElementById('mc-inline-banner');
        if (existing) existing.remove();
        const colors = { success:'#198754', warning:'#ffc107', error:'#dc3545', info:'#0d6efd' };
        const banner = document.createElement('div');
        banner.id = 'mc-inline-banner';
        banner.style.position = 'fixed';
        banner.style.top = '12px';
        banner.style.left = '50%';
        banner.style.transform = 'translateX(-50%)';
        banner.style.background = colors[type] || '#333';
        banner.style.color = '#fff';
        banner.style.padding = '10px 14px';
        banner.style.borderRadius = '10px';
        banner.style.boxShadow = '0 8px 20px rgba(0,0,0,.2)';
        banner.style.fontSize = '14px';
        banner.style.zIndex = '1055';
        banner.style.maxWidth = '90%';
        banner.style.textAlign = 'center';
        banner.textContent = message || 'Not enough Stock Available';
        document.body.appendChild(banner);
        setTimeout(()=>{ banner.style.transition = 'opacity .35s'; banner.style.opacity = '0'; }, 1800);
        setTimeout(()=>{ banner.remove(); }, 2300);
    }
    </script>
    <script src="js/cart.js"></script>
</body>
</html>
<?php
session_start();
require 'dbconn.php'; 

$total_price = 0.00; // subtotal
$delivery_fee = 0.00;
$total_with_delivery = 0.00;
$user_phone_number = ''; 

// Get order data from session (order hasn't been created yet)
if (isset($_SESSION['pending_gcash_order'])) {
    $order_data = $_SESSION['pending_gcash_order'];
    $total_price = (float)$order_data['total_price'];
    $delivery_fee = (float)$order_data['delivery_fee'];
    $total_with_delivery = (float)$order_data['total_amount_with_delivery'];
    
    // Get user phone number
    $user_id = $_SESSION['user_id'];
    $sql_user_phone = "SELECT phone_number FROM users WHERE id = ?";
    $stmt_user_phone = $conn->prepare($sql_user_phone);
    if ($stmt_user_phone) {
        $stmt_user_phone->bind_param("i", $user_id);
        $stmt_user_phone->execute();
        $stmt_user_phone->bind_result($fetched_phone_number);
        $stmt_user_phone->fetch();
        $stmt_user_phone->close();
        
        if ($fetched_phone_number !== null) {
            $user_phone_number = htmlspecialchars($fetched_phone_number);
        }
    }
} else {
    // Fallback if session data is missing
    $total_price = 100.00;
    $delivery_fee = 0.00;
    $total_with_delivery = 100.00; 
}

if (empty($user_phone_number)) {
    $user_phone_number = '9171234567'; 
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>GCash Payment</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="img/gcash-seeklogo.png" sizes="32x32" />
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Roboto', sans-serif; margin: 0; padding: 0; background-color: #ffffff; display: flex; flex-direction: column; align-items: center; }
        .top-header { background-color: #007FFF; width: 100%; padding: 40px 0 80px 0; display: flex; justify-content: center; }
        .top-header img { max-width: 340px; width: clamp(200px, 55vw, 340px); height: auto; object-fit: contain; }
        .payment-container { background: white; border-radius: 5px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1); width: 100%; max-width: 360px; margin-top: -60px; padding: 24px; box-sizing: border-box; text-align: center; transition: all 0.3s ease-in-out; }
        .screen { display: none; animation: fadeIn 0.3s ease-in-out; }
        .screen.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .details p { display: flex; justify-content: space-between; margin: 8px 0; font-size: 14px; color: #888; }
        .details span:last-child { color: #007FFF; font-weight: 500; }
        .login-form h3, .mpin-form h3, .summary-form h3 { font-size: 16px; color: #333; margin: 24px 0 16px; }
        .input-group { position: relative; margin: 20px 0; }
        .input-group span { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #888; font-size: 15px; }
        .input-group input { width: 100%; padding: 14px 14px 14px 50px; font-size: 16px; border-radius: 8px; border: 1px solid #ccc; }
        .btn { background-color: #007FFF; color: white; width: 100%; padding: 14px; border-radius: 8px; font-size: 16px; border: none; cursor: pointer; margin-top: 12px; }
        .btn:disabled { background-color: #a0c7ff; cursor: not-allowed; }
        .mpin-dots { display: flex; justify-content: center; gap: 15px; margin: 20px 0; }
        .mpin-dots span { width: 15px; height: 15px; background-color: #e0e0e0; border-radius: 50%; }
        .summary-box { text-align: left; border: 1px solid #eee; padding: 16px; border-radius: 8px; margin: 20px 0; }
        .summary-box .row { display: flex; justify-content: space-between; margin: 8px 0; color: #333; }
        .receipt-header { text-align: center; padding: 20px 0; }
        .receipt-header .icon { font-size: 48px; color: #28a745; }
        .receipt-details { text-align: left; font-size: 14px; margin: 20px 0; }
        .receipt-details .row { display: flex; justify-content: space-between; margin: 8px 0; }
        .modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); display: none; justify-content: center; align-items: center; }
        .modal-content { background: white; padding: 30px; border-radius: 12px; text-align: center; }
        .modal-content .icon { font-size: 48px; color: #28a745; }
        .footer-text { text-align: center; margin: 20px 0; font-size: 14px; color: #888; }
        .footer-text a { color: #007FFF; text-decoration: none; }
    </style>
</head>
<body>

<div class="top-header">
    <img src="https://serious-studio.com/wp-content/uploads/Gcash-Brand-Identity-01.png" alt="GCash Logo">
</div>

<div class="payment-container">
    <!-- Screen 1: Login -->
    <div class="screen active" id="loginScreen">
        <div class="details">
            <p><span>Merchant</span> <span>CjPowerhouse</span></p>
            <p><span>Amount Due</span> <span>PHP <?php echo number_format($total_with_delivery, 2); ?></span></p>
        </div>
        <div class="login-form">
            <h3>Login to pay with GCash</h3>
            <div class="input-group">
                <span>+63</span>
                <input type="tel" id="mobileNumber" placeholder="Mobile number" 
                       value="<?php echo $user_phone_number; ?>" 
                       maxlength="10" 
                       oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 10);">
            </div>
            <button class="btn" id="loginNextBtn" disabled>NEXT</button>
        </div>
    </div>

    <!-- Screen 2: MPIN -->
    <div class="screen" id="mpinScreen">
        <div class="mpin-form">
            <h3>Enter your 4-digit MPIN</h3>
            <div class="mpin-dots" id="mpinDots">
                <span></span><span></span><span></span><span></span>
            </div>
            <input type="password" maxlength="4" id="mpinInput" style="position: absolute; left: -9999px;">
            <button class="btn" id="mpinNextBtn">NEXT</button>
        </div>
    </div>

    <!-- Screen 3: Summary -->
    <div class="screen" id="summaryScreen">
        <div class="summary-form">
            <h3>CjPowerhouse</h3>
            <div class="summary-box">
                <div class="row"><span>Subtotal</span><span>PHP <?php echo number_format($total_price, 2); ?></span></div>
                <div class="row"><span>Delivery Fee</span><span>PHP <?php echo number_format($delivery_fee, 2); ?></span></div>
                <hr>
                <div class="row" style="font-weight:bold;"><span>Total</span><span>PHP <?php echo number_format($total_with_delivery, 2); ?></span></div>
            </div>
            <button class="btn" id="confirmPaymentBtn">CONFIRM PAYMENT</button>
        </div>
    </div>

    <!-- Screen 4: Receipt -->
    <div class="screen" id="receiptScreen">
        <div class="receipt-header">
            <div class="icon">✔</div>
            <h3>PHP <?php echo number_format($total_with_delivery, 2); ?></h3>
            <p>Paid via GCash</p>
        </div>
        <div class="receipt-details">
            <div class="row"><span>Amount Paid</span> <span id="receiptAmount"></span></div>
            <div class="row"><span>Ref. No.</span> <span id="receiptRef"></span></div>
            <div class="row"><span>Date</span> <span id="receiptDate"></span></div>
        </div>
        <button class="btn" id="doneBtn">Done</button>
    </div>
</div>

<p class="footer-text">
    Don’t have a GCash account? <a href="#">Register now</a>
</p>

<div class="modal" id="successModal">
    <div class="modal-content">
        <div class="icon">✔</div>
        <h3>Payment Confirmed!</h3>
        <p>Your order is now being processed.</p>
    </div>
</div>

<script>
    const orderData = {
        total: <?php echo $total_with_delivery; ?>,
        ref_number: `REF${Date.now()}`
    };

    const screens = {
        login: document.getElementById('loginScreen'),
        mpin: document.getElementById('mpinScreen'),
        summary: document.getElementById('summaryScreen'),
        receipt: document.getElementById('receiptScreen')
    };

    function showScreen(name) {
        Object.values(screens).forEach(s => s.classList.remove('active'));
        screens[name].classList.add('active');
    }

    const mobileNumberInput = document.getElementById('mobileNumber');
    const loginNextBtn = document.getElementById('loginNextBtn');
    const mpinInput = document.getElementById('mpinInput');
    const mpinDots = document.querySelectorAll('#mpinDots span');

    function updateLoginBtn() {
        const digits = mobileNumberInput.value.replace(/\D/g, '');
        loginNextBtn.disabled = digits.length !== 10;
    }
    // Initialize state for prefilled number
    updateLoginBtn();
    mobileNumberInput.addEventListener('input', updateLoginBtn);

    loginNextBtn.addEventListener('click', () => {
        // Reset MPIN input and dots
        mpinInput.value = '';
        mpinDots.forEach(dot => dot.style.backgroundColor = '#e0e0e0');

        showScreen('mpin');
        mpinInput.focus();
    });

    mpinInput.addEventListener('input', () => {
        const pinLength = mpinInput.value.length;
        mpinDots.forEach((dot, index) => {
            dot.style.backgroundColor = index < pinLength ? '#0052cc' : '#e0e0e0';
        });
        if (pinLength === 4) {
            setTimeout(() => showScreen('summary'), 300);
        }
    });

    document.getElementById('mpinNextBtn').addEventListener('click', () => {
        mpinInput.focus();
    });

    document.getElementById('confirmPaymentBtn').addEventListener('click', () => {
        // Populate the receipt details dynamically
        document.getElementById('receiptAmount').textContent = `PHP ${orderData.total.toFixed(2)}`;
        document.getElementById('receiptRef').textContent = orderData.ref_number;
        document.getElementById('receiptDate').textContent = new Date().toLocaleString(); // Get current client-side date/time
        
        showScreen('receipt');
    });

    document.getElementById('doneBtn').addEventListener('click', () => {
        // This 'done' button is now the final confirmation step
        const doneButton = document.getElementById('doneBtn');
        doneButton.disabled = true;
        doneButton.textContent = 'Processing...';

        fetch('confirm_payment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                reference_number: orderData.ref_number,
                amount_paid: orderData.total,
                // --- ADDED: Send the client-side formatted date string ---
                client_transaction_date_str: document.getElementById('receiptDate').textContent 
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                document.getElementById('successModal').style.display = 'flex';
                setTimeout(() => {
                    window.location.href = 'Mobile-Orders.php';
                }, 2000);
            } else {
                alert('Confirmation failed: ' + data.message);
                doneButton.disabled = false;
                doneButton.textContent = 'Done';
            }
        })
        .catch(error => {
            alert('An error occurred: ' + error);
            doneButton.disabled = false;
            doneButton.textContent = 'Done';
        });
    });
</script>
</body>
</html>
<?php
// Close the database connection at the very end of the script
$conn->close(); 
?>
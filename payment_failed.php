<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Failed</title>
    <style>
        body { font-family: sans-serif; text-align: center; padding-top: 50px; }
        .container { max-width: 600px; margin: auto; border: 1px solid #ccc; padding: 30px; border-radius: 8px; }
        h1 { color: #dc3545; }
        a { display: inline-block; margin-top: 20px; padding: 10px 20px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Payment Failed</h1>
        <p>Unfortunately, your payment could not be processed at this time.</p>
        <p>Please try again or select a different payment method.</p>
        <a href="delivery.php">Return to Checkout</a>
    </div>
</body>
</html>
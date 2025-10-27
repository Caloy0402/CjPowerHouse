<?php
/**
 * PayMongo API Configuration Template
 * 
 * INSTRUCTIONS:
 * 1. Copy this file and rename it to 'secrets.php'
 * 2. Replace the placeholder keys with your actual PayMongo API keys
 * 3. DO NOT commit secrets.php to Git (it's in .gitignore)
 * 
 * Get your API keys from: https://dashboard.paymongo.com/developers
 */

// TEST Environment Keys (for development/staging)
// Get these from your PayMongo dashboard under "TEST API keys"
$PAYMONGO_PUBLIC_KEY_TEST = 'pk_test_YOUR_TEST_PUBLIC_KEY_HERE';
$PAYMONGO_SECRET_KEY_TEST = 'sk_test_YOUR_TEST_SECRET_KEY_HERE';

// LIVE Environment Keys (for production)
// Get these from your PayMongo dashboard under "LIVE API keys"
$PAYMONGO_PUBLIC_KEY_LIVE = 'pk_live_YOUR_LIVE_PUBLIC_KEY_HERE';
$PAYMONGO_SECRET_KEY_LIVE = 'sk_live_YOUR_LIVE_SECRET_KEY_HERE';

// Set which environment to use (true = LIVE, false = TEST)
// For development/testing: set to false
// For production: set to true
$USE_LIVE_KEYS = false;

// Automatically select keys based on environment
if ($USE_LIVE_KEYS) {
    $PAYMONGO_PUBLIC_KEY = $PAYMONGO_PUBLIC_KEY_LIVE;
    $PAYMONGO_SECRET_KEY = $PAYMONGO_SECRET_KEY_LIVE;
} else {
    $PAYMONGO_PUBLIC_KEY = $PAYMONGO_PUBLIC_KEY_TEST;
    $PAYMONGO_SECRET_KEY = $PAYMONGO_SECRET_KEY_TEST;
}
?>


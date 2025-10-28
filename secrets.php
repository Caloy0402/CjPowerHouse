<?php
/**
 * CAUTION: This file is committed for deployment convenience with placeholders only.
 * Replace the values on the server, and ensure the repo remains private.
 * Prefer setting environment variables instead of editing this file.
 */

// TEST keys (use for sandbox)
$PAYMONGO_PUBLIC_KEY_TEST = 'pk_test_7ScNXkeAikpV9wGs82tMPyhR';
$PAYMONGO_SECRET_KEY_TEST = 'sk_test_f5E3uv8kftzHSUgy1nnTTAGY';

// LIVE keys (use for production)
$PAYMONGO_PUBLIC_KEY_LIVE = 'pk_live_cFmzMFU3gcGg8FVyeBoLGqRV';
$PAYMONGO_SECRET_KEY_LIVE = 'sk_live_dRLHgBVJBhzwRonVLYr27i2C';

// Switch between environments. true = LIVE, false = TEST.
$USE_LIVE_KEYS = false;

// Convenience variables for consumers that expect consolidated keys
if ($USE_LIVE_KEYS) {
	$PAYMONGO_PUBLIC_KEY = $PAYMONGO_PUBLIC_KEY_LIVE;
	$PAYMONGO_SECRET_KEY = $PAYMONGO_SECRET_KEY_LIVE;
} else {
	$PAYMONGO_PUBLIC_KEY = $PAYMONGO_PUBLIC_KEY_TEST;
	$PAYMONGO_SECRET_KEY = $PAYMONGO_SECRET_KEY_TEST;
}

?>



# PayMongo Deployment Instructions

## Why the secrets.php file appears grayed out

The `secrets.php` file is intentionally **grayed out** in your IDE because it's listed in `.gitignore`. This is a **security best practice** to prevent sensitive API keys from being committed to Git repositories.

## Why you're getting the error

The error occurs because `secrets.php` is not being tracked by Git (it's in .gitignore), so it hasn't been uploaded to your production server.

## Solution: Manual Deployment

Since this file contains sensitive API keys, you must **manually upload it** to your production server.

### Steps to Deploy

1. **Locate the file** on your local machine:
   - File path: `C:\Users\MY PC\Desktop\Git Upload\CjPowerHouse\secrets.php`

2. **Upload to production server:**
   - Use FTP client (FileZilla, WinSCP, etc.)
   - Or use cPanel File Manager
   - Upload the file to the same directory as your other PHP files (wherever `create_paymongo_payment.php` is located)

3. **Verify file exists on server:**
   - The file should be in the root of your web application

## File Contents

The file currently contains:
- ✅ TEST API keys (for development/testing)
- ✅ LIVE API keys (for production)
- 🔄 Environment switcher to toggle between TEST and LIVE

## To Use Different Environments

Edit `secrets.php` on the server and change line 17:
- `$USE_LIVE_KEYS = false;` ← For TEST/Development
- `$USE_LIVE_KEYS = true;` ← For LIVE/Production

## Security Notes

- ✅ The file is correctly in `.gitignore`
- ✅ Never commit this file to Git
- ✅ Keep it private and secure
- ✅ Only upload it directly to your server

## Testing

After uploading, test the PayMongo payment flow - you should no longer see the "missing PayMongo secret key" error.


# Email Verification System for CJ PowerHouse

This system adds email verification to your user registration process, requiring users to verify their email addresses before they can log in and access their accounts.

## Features

- ✅ Email verification required for new user accounts
- ✅ Secure verification tokens with expiration (24 hours)
- ✅ Professional email templates with CJ PowerHouse branding
- ✅ Resend verification functionality
- ✅ Rate limiting to prevent spam
- ✅ Comprehensive error handling
- ✅ Mobile-responsive verification pages

## Setup Instructions

### 1. Database Setup

First, run the database setup script to add the required fields:

```bash
# Visit this URL in your browser:
http://localhost/Motorshop/add_email_verification_fields.php
```

This will:
- Add `email_verified` field to users table
- Add `email_verification_token` field to users table
- Add `email_verification_expires` field to users table
- Add `verification_sent_at` field to users table
- Create `email_verification_logs` table for tracking

### 2. Install PHPMailer

You have two options for installing PHPMailer:

#### Option A: Using Composer (Recommended)
```bash
cd /path/to/your/project
composer require phpmailer/phpmailer
```

#### Option B: Manual Installation
1. Download PHPMailer from: https://github.com/PHPMailer/PHPMailer/releases
2. Extract the files
3. Create a `lib` folder in your project root
4. Copy the `src` folder contents to `lib/PHPMailer/`

### 3. Configure Email Settings

Edit `email_config.php` and update these settings:

```php
// Replace with your actual Gmail credentials
define('SMTP_USERNAME', 'your-email@gmail.com');
define('SMTP_PASSWORD', 'your-app-password');

// Update website URL to your actual domain
define('WEBSITE_URL', 'http://yourdomain.com');
```

#### Gmail App Password Setup
1. Go to your Google Account settings
2. Enable 2-Factor Authentication
3. Generate an App Password for "Mail"
4. Use this password in `SMTP_PASSWORD`

### 4. Test the System

1. Visit your landing page
2. Try to register a new account
3. Check your email for verification link
4. Click the verification link
5. Try logging in with the verified account

## How It Works

### Registration Flow
1. User fills out signup form
2. Account is created but marked as unverified
3. Verification email is sent automatically
4. User receives email with verification link
5. User clicks link to verify email
6. Account is activated and user can log in

### Login Flow
1. User attempts to log in
2. System checks if email is verified
3. If verified: User logs in normally
4. If not verified: Shows verification required message with resend option

### Verification Process
1. User clicks verification link in email
2. System validates the token
3. If valid: Email is marked as verified
4. If expired/invalid: User can request new verification

## Files Overview

- `add_email_verification_fields.php` - Database setup script
- `email_config.php` - Email configuration and templates
- `send_verification_email.php` - Core email functionality
- `verify_email.php` - Email verification page
- `resend_verification.php` - Handle resend requests
- `clear_verification_session.php` - Clear session data
- `LandingPage.php` - Updated with verification modals
- `ajax_signin.php` - Updated to check email verification

## Customization

### Email Templates
Edit the email templates in `email_config.php`:
- `VERIFICATION_EMAIL_TEMPLATE` - Initial verification email
- `RESEND_VERIFICATION_EMAIL_TEMPLATE` - Resend verification email

### Token Expiry
Change `VERIFICATION_TOKEN_EXPIRY_HOURS` in `email_config.php` to adjust how long verification links are valid.

### Rate Limiting
Modify `MAX_VERIFICATION_ATTEMPTS` in `email_config.php` to control how many verification emails can be sent per day.

## Troubleshooting

### Common Issues

1. **Emails not sending**
   - Check Gmail credentials in `email_config.php`
   - Ensure 2FA is enabled and app password is correct
   - Check server logs for SMTP errors

2. **Verification links not working**
   - Verify `WEBSITE_URL` is correct in `email_config.php`
   - Check if `verify_email.php` is accessible
   - Ensure database fields were added correctly

3. **Users can't log in after verification**
   - Check if `email_verified` field is being updated
   - Verify the verification process completed successfully

### Debug Mode
Add this to your PHP files for debugging:
```php
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

## Security Features

- **Secure Tokens**: 32-byte random tokens for verification
- **Token Expiration**: Links expire after 24 hours
- **Rate Limiting**: Maximum 3 verification attempts per day
- **SQL Injection Protection**: Prepared statements throughout
- **XSS Protection**: HTML escaping on all user inputs
- **CSRF Protection**: Session-based verification

## Email Templates

The system includes professional email templates with:
- CJ PowerHouse branding and colors
- Clear call-to-action buttons
- Mobile-responsive design
- Professional formatting
- Clear instructions for users

## Support

If you encounter issues:
1. Check the error logs in your server
2. Verify all configuration settings
3. Test with a simple email first
4. Ensure PHPMailer is properly installed

## Future Enhancements

Potential improvements:
- SMS verification as backup
- Admin panel for managing verifications
- Bulk verification for existing users
- Integration with email marketing tools
- Advanced analytics and reporting

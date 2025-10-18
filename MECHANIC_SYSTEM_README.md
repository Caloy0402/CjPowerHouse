# Mechanic System Setup Guide

## Overview
This system adds mechanic functionality to the existing Motorshop application, allowing mechanics to handle help requests from customers with Google Maps integration for pinpointed locations.

## Features Added

### 1. **Mechanic Dashboard** (`Mechanic-Dashboard.php`)
- View pending help requests
- Filter requests by barangay
- Request status tracking
- Recent request history

### 2. **Mechanic Profile** (`Mechanic-Profile.php`)
- Personal information display
- Vehicle details (motor type, plate number, specialization)
- Profile management

### 3. **Mechanic Transactions** (`Mechanic-Transaction.php`)
- Active request management
- Google Maps integration for customer locations
- Request status updates (Accept, Complete, Cancel)
- Real-time coordinates display

### 4. **Admin Integration**
- Updated `Admin-AddUser.php` to include mechanic role
- Mechanic-specific fields (motor type, plate number, specialization)
- Dynamic form that shows/hides mechanic fields based on role selection

### 5. **Login System**
- Updated `ajax_signin.php` to handle mechanic authentication
- Redirects to appropriate dashboard based on role

## Database Setup

### 1. Run the SQL Script
Execute the `create_mechanic_tables.sql` file in your MySQL database:

```sql
-- This will create the necessary tables
source create_mechanic_tables.sql;
```

### 2. Tables Created
- **mechanics**: Stores mechanic information
- **help_requests**: Stores customer help requests

## Setup Instructions

### Step 1: Database Setup
1. Open your MySQL client (phpMyAdmin, MySQL Workbench, etc.)
2. Select your database (cjpowerhouse)
3. Run the SQL commands from `create_mechanic_tables.sql`

### Step 2: File Upload
1. Upload all the new PHP files to your web server
2. Ensure the `css/mechanic-styles.css` file is in the correct location
3. Make sure all files have proper permissions (644 for files, 755 for directories)

### Step 3: Google Maps API Key
1. Get a Google Maps API key from [Google Cloud Console](https://console.cloud.google.com/)
2. Replace `YOUR_GOOGLE_MAPS_API_KEY` in `Mechanic-Transaction.php` with your actual API key

### Step 4: Test the System

#### Test 1: Add a Mechanic
1. Go to Admin Dashboard
2. Navigate to Users → Add Users
3. Select "Mechanic" role
4. Fill in all required fields including motor type, plate number, and specialization
5. Submit the form

#### Test 2: Login as Mechanic
1. Go to the login page
2. Use the mechanic's email and password
3. Should redirect to `Mechanic-Dashboard.php`

#### Test 3: Submit Help Request
1. Use `test_help_request.php` to simulate customer requests
2. Or use the mobile app's "Seek Help" feature
3. Verify requests appear on mechanic dashboard

#### Test 4: Handle Requests
1. Login as mechanic
2. View pending requests
3. Accept a request
4. View customer location on map
5. Update request status

## File Structure

```
├── Mechanic-Dashboard.php          # Main mechanic dashboard
├── Mechanic-Profile.php            # Mechanic profile page
├── Mechanic-Transaction.php        # Request management with maps
├── css/mechanic-styles.css        # Mechanic-specific styles
├── update_request_status.php       # API for status updates
├── submit_help_request.php         # Handle help request submissions
├── create_mechanic_tables.sql     # Database setup script
├── test_mechanic_login.php        # Test mechanic login
├── test_help_request.php          # Test help request system
└── MECHANIC_SYSTEM_README.md      # This file
```

## Updated Files

```
├── Admin-AddUser.php              # Now includes mechanic role
├── ajax_signin.php                # Updated to handle mechanic login
└── Mobile-Dashboard.php           # Updated with help request form handling
```

## Role-Based Access

### Mechanic Role Features:
- View pending help requests
- Accept/decline requests
- View customer locations on Google Maps
- Update request status
- View request history
- Manage profile information

### Admin Role Features:
- Add mechanics through the existing user management system
- View all help requests
- Manage mechanic accounts

## API Endpoints

### 1. Update Request Status
- **URL**: `update_request_status.php`
- **Method**: POST
- **Data**: JSON with `request_id`, `status`, `mechanic_id`
- **Response**: JSON success/error message

### 2. Submit Help Request
- **URL**: `submit_help_request.php`
- **Method**: POST
- **Data**: Form data with customer information
- **Response**: JSON success/error message

## Troubleshooting

### Common Issues:

1. **Mechanic can't login**
   - Check if the mechanics table exists
   - Verify the mechanic was added correctly
   - Check the role value in the database

2. **Google Maps not loading**
   - Verify API key is correct
   - Check if billing is enabled for Google Maps API
   - Ensure the API key has the necessary permissions

3. **Help requests not appearing**
   - Check if help_requests table exists
   - Verify the form submission is working
   - Check database connection

4. **CSS styles not loading**
   - Verify `css/mechanic-styles.css` exists
   - Check file permissions
   - Clear browser cache

### Debug Tools:
- `test_mechanic_login.php`: Test mechanic authentication
- `test_help_request.php`: Test help request submission
- Check browser console for JavaScript errors
- Check server error logs for PHP errors

## Security Considerations

1. **Password Hashing**: All passwords are hashed using PHP's `password_hash()`
2. **SQL Injection Prevention**: All queries use prepared statements
3. **Session Management**: Proper session handling for authentication
4. **Input Validation**: All user inputs are validated and sanitized
5. **Role-Based Access**: Strict role checking for all mechanic pages

## Future Enhancements

1. **Real-time Notifications**: WebSocket implementation for instant updates
2. **Push Notifications**: Mobile app notifications for new requests
3. **Payment Integration**: Handle mechanic payments for completed jobs
4. **Rating System**: Customer ratings for mechanics
5. **Advanced Mapping**: Route planning and ETA calculations
6. **Photo Upload**: Allow customers to upload photos of their bike problems

## Support

For issues or questions:
1. Check the troubleshooting section above
2. Verify all files are uploaded correctly
3. Check database connectivity
4. Review server error logs
5. Test with the provided test files

## Version Information

- **Version**: 1.0
- **Last Updated**: Current Date
- **Compatibility**: PHP 7.4+, MySQL 5.7+
- **Dependencies**: Bootstrap 5, Google Maps API, Font Awesome 
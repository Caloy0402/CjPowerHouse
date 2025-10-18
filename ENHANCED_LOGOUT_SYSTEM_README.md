# Enhanced Logout System with Duty Hour Validation

## Overview
This system implements duty hour validation for all staff members (Admin, Cashier, Rider, Mechanic) when they attempt to log out. It ensures proper tracking of work hours and requires password confirmation for early logout.

## Features

### 1. Duty Hour Completion Check
- **Completed Duty**: Shows completion notification with total duty time
- **Incomplete Duty**: Shows warning with remaining hours and requires password confirmation

### 2. Password Confirmation for Early Logout
- Professional modal dialog for password verification
- Secure password validation against user's current password
- Prevents unauthorized early logouts

### 3. Role-Based Requirements
- **All Staff Roles**: 8 hours (480 minutes) daily requirement
- Configurable per role in `getRequiredMinutesByRole()` function

## Files Modified/Created

### New Files
- `enhanced_logout.php` - Main logout handler with duty validation

### Modified Files
- `js/script.js` - Enhanced logout function with duty checks
- `Rider-Profile.php` - Updated logout button
- `Mechanic-Profile.php` - Updated logout button

## How It Works

### 1. Logout Process Flow
```
User clicks logout → Check duty status → Show appropriate dialog → Process logout
```

### 2. Duty Status Check
- Queries `staff_logs` table for active session
- Calculates elapsed time from `time_in` to current time
- Determines if 8-hour requirement is met

### 3. User Experience

#### Completed Duty Hours
- Shows success notification: "✅ Your hourly duty is completed this day!"
- Displays total duty time and required hours
- Allows immediate logout after confirmation

#### Incomplete Duty Hours
- Shows warning: "⚠️ You have remaining duty hours!"
- Displays remaining time (e.g., "2h 30m left")
- Requires password confirmation for early logout
- Professional modal with password field and validation

### 4. Security Features
- Password verification using `password_verify()`
- Secure session handling
- Proper error handling and user feedback
- Prevents unauthorized early logouts

## Integration

### Automatic Integration
- All existing logout links (`logout.php`) are automatically intercepted
- No changes needed to existing HTML templates
- Works across all staff dashboards and pages

### Manual Integration
For custom logout buttons, use:
```javascript
onclick="logout()"
```

## Configuration

### Required Hours by Role
Edit `getRequiredMinutesByRole()` in `enhanced_logout.php`:
```php
case 'cashier':
    return 480; // 8 hours
case 'mechanic':
    return 480; // 8 hours
// etc.
```

### Customization
- Modify notification messages in JavaScript functions
- Adjust modal styling in `requestPasswordConfirmation()`
- Change required hours per role as needed

## Database Requirements
- `staff_logs` table with columns: `id`, `staff_id`, `role`, `time_in`, `time_out`, `duty_duration_minutes`
- `cjusers` table with `password` column for verification

## Error Handling
- Graceful fallback to normal logout if duty check fails
- Clear error messages for invalid passwords
- Proper session cleanup on logout

## Testing
1. Login as any staff member
2. Try logging out before completing 8 hours
3. Verify password confirmation is required
4. Try logging out after completing 8 hours
5. Verify completion notification appears

## Benefits
- Ensures proper duty hour tracking
- Prevents unauthorized early logouts
- Professional user experience
- Maintains security standards
- Easy to configure and maintain

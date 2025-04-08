# CharterHub Authentication Fix

## Problem Identified

The diagnostic tool has identified critical schema issues in the production database:

1. **Missing Columns in `wp_charterhub_users` Table:**
   - `password` column - Required for storing and validating user passwords
   - `token_version` column - Required for JWT token validation

2. **Code/Database Mismatch:**
   - The authentication code expects these columns to exist
   - Without them, user login and registration fail with 500 errors

## Schema Differences Between Local and Production

### Local Development (MAMP)
The local database has a complete schema:
```
wp_charterhub_users
├── id
├── email
├── password <--- MISSING IN PRODUCTION
├── username
├── display_name
├── first_name
├── last_name
├── phone_number
├── company
├── country
├── address
├── notes
├── role
├── token_version <--- MISSING IN PRODUCTION
├── verified
└── ... other columns
```

### Production (Render)
The production database is missing essential columns:
```
wp_charterhub_users
├── id
├── wp_user_id
├── role
├── refresh_token
├── token_expires
├── verified
├── created_at
├── updated_at
├── email
├── first_name
├── last_name
├── display_name
├── phone_number
└── company
```

## Solution Files

Two files have been created to fix the issue:

1. **`update-schema.sql`**
   - SQL script that adds the missing columns
   - Sets temporary passwords for existing users

2. **`db-schema-fix.php`**
   - Web-based tool that runs the schema updates
   - Provides detailed output of the changes
   - Can be accessed directly via the browser

## How to Apply the Fix

### Option 1: Run the PHP Script (Recommended)

1. Deploy the `db-schema-fix.php` to the production server
2. Visit `https://charterhub-api.onrender.com/db-schema-fix.php` in your browser
3. The script will:
   - Check for missing columns
   - Add them if needed
   - Set temporary passwords for all users
   - Display results in real-time

### Option 2: Run the SQL Script Manually

If you prefer to run the SQL directly against the database:

1. Connect to the production database
2. Run the contents of `update-schema.sql`

## Post-Fix Steps

After applying the fix:

1. **Reset User Passwords:**
   - All users will have the temporary password: `TemporaryPassword123!`
   - Users should be instructed to reset their passwords

2. **Verify the Fix:**
   - Run the diagnostic tool again (`/db-diagnostic.php`)
   - Test the login functionality
   - Check for error logs

## Security Note

The temporary password is set for ALL users. In a production environment with real users, you should:

1. Immediately implement a forced password reset for all users
2. Generate unique reset tokens
3. Notify users to set new passwords

## Preventative Measures

To prevent similar issues in the future:

1. Add a schema version check to the application startup
2. Create a database migration system
3. Test authentication during deployment 
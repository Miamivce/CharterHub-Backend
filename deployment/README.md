# CharterHub Login Fix Deployment Guide

This guide explains how to deploy the login fixes to the production server on Render.com.

## Overview of Fixes

The script addresses multiple issues that were causing the 500 Internal Server Error during login:

1. Missing `last_login` column in users table
2. Incorrect column types in the auth_logs table
3. Missing AUTO_INCREMENT on the auth_logs id column
4. Missing refresh tokens table
5. Missing generate_jwt function alias

## Deployment Instructions

### Option 1: Direct Server Access (Preferred)

If you have SSH access to the production server:

1. Connect to the server
2. Upload the `fix-login-issues.php` script to the CharterHub backend directory
3. Run the script:
   ```
   cd /path/to/charterhub-backend
   php deployment/fix-login-issues.php
   ```
4. Check the output for any errors
5. Restart the server if necessary

### Option 2: Using Render.com Dashboard

If you don't have direct SSH access:

1. Log into the Render.com dashboard
2. Navigate to your CharterHub API service
3. Go to the "Shell" tab
4. Create the deployment directory if it doesn't exist:
   ```
   mkdir -p deployment
   ```
5. Copy the script content to a new file:
   ```
   cat > deployment/fix-login-issues.php << 'EOF'
   <?php
   // Copy the entire content of fix-login-issues.php here
   EOF
   ```
6. Run the script:
   ```
   php deployment/fix-login-issues.php
   ```
7. Check the output for any errors
8. Restart the service using the "Manual Deploy" button in the Render dashboard

### Option 3: Using Git Deployment

If you deploy via Git:

1. Add the `fix-login-issues.php` script to your repository in the `deployment` directory
2. Commit and push the changes
3. After Render deploys the updated code, run the script:
   ```
   php deployment/fix-login-issues.php
   ```
4. You can either use the Render shell or add a one-time script to run during deployment

## Verifying the Fix

After deployment:

1. Try logging in with the test account (test102@me.com / Test1234567!!!)
2. Check the server logs for any errors
3. If login fails, look for specific error messages in the logs

## Troubleshooting

If issues persist:

1. Make sure all database changes were applied successfully
2. Check for PHP errors in the server logs
3. Verify that the JWT token generation is working correctly
4. Ensure all required tables and columns exist in the database

## Rolling Back

If needed, you can roll back specific changes:

1. For schema changes, restore from database backups if available
2. For the generate_jwt function, manually edit the jwt-core.php file 
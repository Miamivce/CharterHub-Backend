# CharterHub Authentication System

This directory contains the implementation of the custom authentication system for CharterHub, including user registration, verification, and invitation functionality.

## Table of Contents

1. [Database Setup](#database-setup)
2. [Configuration](#configuration)
3. [API Endpoints](#api-endpoints)
4. [Testing](#testing)
5. [Security Considerations](#security-considerations)

## Database Setup

Before using the authentication system, you need to set up the database tables. Run the following SQL script to create or modify the tables:

```bash
mysql -u root -p charterhub_local < ../local-dev/auth-schema.sql
```

Alternatively, you can use a database management tool like phpMyAdmin to run the SQL script directly.

### Authentication Tables

The authentication system uses the following tables:

1. **wp_users** - Extended with additional fields for authentication
2. **wp_charterhub_invitations** - Stores invitation tokens and related information
3. **wp_charterhub_auth_logs** - Logs authentication activities for security monitoring

## Configuration

The authentication system configuration is defined in `config.php`. Before using the system, review and update the following settings:

1. **Database Configuration**: Update the database credentials if needed
2. **JWT Secret**: Change the JWT secret key to a secure random string
3. **Frontend URLs**: Update the URLs to match your frontend application
4. **Email Configuration**: Configure email sending for production use

Example:

```php
// JWT Secret - Change this to a secure random string in production
$auth_config['jwt_secret'] = 'your-jwt-secret-key';

// Frontend URLs
$frontend_urls['base_url'] = 'http://your-frontend-url.com';
$frontend_urls['login_url'] = 'http://your-frontend-url.com/login';
$frontend_urls['verification_url'] = 'http://your-frontend-url.com/verify-email';
$frontend_urls['password_reset_url'] = 'http://your-frontend-url.com/reset-password';

// Email Configuration
$email_config['smtp_host'] = 'smtp.your-email-provider.com';
$email_config['smtp_username'] = 'your-smtp-username';
$email_config['smtp_password'] = 'your-smtp-password';
```

## API Endpoints

### User Registration

- **URL**: `/auth/register.php`
- **Method**: `POST`
- **Description**: Register a new user account
- **Request Body**:
  ```json
  {
    "email": "user@example.com",
    "password": "SecurePassword123",
    "firstName": "John",
    "lastName": "Doe",
    "phoneNumber": "123-456-7890", // Optional
    "company": "Company Name", // Optional
    "inviteToken": "invitation-token" // Optional
  }
  ```
- **Response**: 
  ```json
  {
    "success": true,
    "message": "Registration successful. Please check your email to verify your account.",
    "user_id": 123
  }
  ```

### Email Verification

- **URL**: `/auth/verify-email.php?token=verification-token`
- **Method**: `GET`
- **Description**: Verify a user's email address
- **Response**:
  ```json
  {
    "success": true,
    "message": "Email verification successful. You can now log in to your account.",
    "login_url": "http://localhost:3004/login"
  }
  ```

### User Login

- **URL**: `/auth/login.php`
- **Method**: `POST`
- **Description**: Authenticate a user and get JWT token
- **Request Body**:
  ```json
  {
    "email": "user@example.com",
    "password": "SecurePassword123"
  }
  ```
- **Response**:
  ```json
  {
    "success": true,
    "message": "Login successful",
    "token": "jwt_token_string",
    "refresh_token": "refresh_token_string",
    "expires_in": 3600,
    "user": {
      "ID": 123,
      "user_email": "user@example.com",
      "first_name": "John",
      "last_name": "Doe",
      "role": "charter_client"
    }
  }
  ```

### User Logout

- **URL**: `/auth/logout.php`
- **Method**: `POST`
- **Headers**: 
  ```
  Authorization: Bearer {jwt_token}
  ```
- **Description**: Invalidate a user's refresh token
- **Response**:
  ```json
  {
    "success": true,
    "message": "Logged out successfully"
  }
  ```

### Password Reset Request

- **URL**: `/auth/request-password-reset.php`
- **Method**: `POST`
- **Description**: Request a password reset email
- **Request Body**:
  ```json
  {
    "email": "user@example.com"
  }
  ```
- **Response**:
  ```json
  {
    "success": true,
    "message": "If your email exists in our system, you will receive a password reset link shortly."
  }
  ```

### Password Reset

- **URL**: `/auth/reset-password.php`
- **Method**: `POST`
- **Description**: Reset a password using a valid reset token
- **Request Body**:
  ```json
  {
    "token": "reset_token_string",
    "newPassword": "NewSecurePassword123"
  }
  ```
- **Response**:
  ```json
  {
    "success": true,
    "message": "Password has been reset successfully. You can now log in with your new password.",
    "login_url": "http://localhost:3004/login"
  }
  ```

### Token Refresh

- **URL**: `/auth/refresh-token.php`
- **Method**: `POST`
- **Description**: Get a new JWT token using a refresh token
- **Request Body**:
  ```json
  {
    "refreshToken": "refresh_token_string"
  }
  ```
- **Response**:
  ```json
  {
    "success": true,
    "message": "Token refreshed successfully",
    "token": "new_jwt_token_string",
    "refresh_token": "new_refresh_token_string",
    "expires_in": 3600
  }
  ```

### Invitation Creation (Admin Only)

- **URL**: `/auth/invite.php`
- **Method**: `POST`
- **Headers**: 
  ```
  Authorization: Bearer {jwt_token}
  ```
- **Description**: Send an invitation to a new or existing user
- **Request Body**:
  ```json
  {
    "email": "newuser@example.com",
    "bookingId": 123 // Optional
  }
  ```
- **Response**:
  ```json
  {
    "success": true,
    "message": "Invitation sent successfully",
    "invitation_id": 456,
    "invitation_url": "http://localhost:3004/register?token=invitation-token"
  }
  ```

## Testing

To test the authentication system, you can use the following tools:

### 1. cURL for API Testing

```bash
# Register a new user
curl -X POST \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"Test123456","firstName":"Test","lastName":"User"}' \
  http://localhost/charterhub/backend/auth/register.php

# Log in
curl -X POST \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"Test123456"}' \
  http://localhost/charterhub/backend/auth/login.php

# Request password reset
curl -X POST \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com"}' \
  http://localhost/charterhub/backend/auth/request-password-reset.php

# Reset password
curl -X POST \
  -H "Content-Type: application/json" \
  -d '{"token":"reset_token","newPassword":"NewTest123456"}' \
  http://localhost/charterhub/backend/auth/reset-password.php

# Refresh token
curl -X POST \
  -H "Content-Type: application/json" \
  -d '{"refreshToken":"refresh_token_string"}' \
  http://localhost/charterhub/backend/auth/refresh-token.php

# Log out
curl -X POST \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer JWT_TOKEN" \
  http://localhost/charterhub/backend/auth/logout.php
```

### 2. Postman Collection

A Postman collection is available to test all authentication endpoints. Import the collection from `backend/auth/postman/charterhub-auth.json`.

## Security Considerations

1. **JWT Secret**: Use a strong, random JWT secret key in production
2. **Password Storage**: Passwords are securely hashed using PHP's password_hash() function
3. **Rate Limiting**: Implement rate limiting for production to prevent brute force attacks
4. **HTTPS**: Always use HTTPS in production to secure data transmission
5. **Email Verification**: Email verification is required to prevent fake accounts
6. **Authorization**: Admin endpoints require a valid JWT token with administrator role
7. **Logging**: All authentication actions are logged for security monitoring
8. **Token Management**: Refresh tokens are invalidated on logout and secure token rotation is implemented
9. **Password Policies**: Strong password requirements are enforced 
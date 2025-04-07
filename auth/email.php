<?php
/**
 * CharterHub Email System
 * 
 * This file contains functions for sending emails in the CharterHub system.
 */

if (!defined('CHARTERHUB_LOADED')) {
    die('Direct access not permitted.');
}

/**
 * Send an email
 * 
 * @param string $to Email recipient
 * @param string $subject Email subject
 * @param string $message Email body (HTML)
 * @param array $headers Additional headers
 * @param array $attachments Attachments
 * @return bool Whether the email was sent
 */
function charterhub_send_email($to, $subject, $message, $headers = [], $attachments = []) {
    // Set default headers if not provided
    if (empty($headers)) {
        $headers = [
            'From: CharterHub <noreply@charterhub.com>',
            'Reply-To: support@charterhub.com',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8'
        ];
    }
    
    // Use SendGrid if available, otherwise fall back to PHP mail()
    if (function_exists('send_via_sendgrid')) {
        return send_via_sendgrid($to, $subject, $message, $headers, $attachments);
    }
    
    // Fall back to PHP mail()
    $headers_string = implode("\r\n", $headers);
    $result = mail($to, $subject, $message, $headers_string);
    
    // Log the email attempt
    $log_message = $result ? "Email sent to $to" : "Failed to send email to $to";
    error_log($log_message);
    
    return $result;
}

/**
 * Send a verification email
 * 
 * @param string $to Email recipient
 * @param string $verification_link Verification link
 * @return bool Whether the email was sent
 */
function send_verification_email($to, $verification_link) {
    $subject = 'Verify Your CharterHub Account';
    
    $message = '
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #0047AB; color: white; padding: 15px; text-align: center; }
            .content { padding: 20px; background-color: #f9f9f9; }
            .button { display: inline-block; padding: 10px 20px; background-color: #0047AB; color: white; text-decoration: none; border-radius: 4px; }
            .footer { padding: 15px; text-align: center; font-size: 0.8em; color: #666; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>Welcome to CharterHub</h1>
            </div>
            <div class="content">
                <p>Thank you for signing up with CharterHub. To complete your registration, please verify your email address.</p>
                <p style="text-align: center;">
                    <a href="' . $verification_link . '" class="button">Verify Email</a>
                </p>
                <p>Or copy and paste this link into your browser:</p>
                <p>' . $verification_link . '</p>
                <p>This link will expire in 24 hours.</p>
            </div>
            <div class="footer">
                <p>&copy; ' . date('Y') . ' CharterHub. All rights reserved.</p>
                <p>If you did not create this account, please ignore this email.</p>
            </div>
        </div>
    </body>
    </html>';
    
    return charterhub_send_email($to, $subject, $message);
}

/**
 * Send a password reset email
 * 
 * @param string $to Email recipient
 * @param string $reset_link Password reset link
 * @return bool Whether the email was sent
 */
function send_password_reset_email($to, $reset_link) {
    $subject = 'Reset Your CharterHub Password';
    
    $message = '
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #0047AB; color: white; padding: 15px; text-align: center; }
            .content { padding: 20px; background-color: #f9f9f9; }
            .button { display: inline-block; padding: 10px 20px; background-color: #0047AB; color: white; text-decoration: none; border-radius: 4px; }
            .footer { padding: 15px; text-align: center; font-size: 0.8em; color: #666; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>CharterHub Password Reset</h1>
            </div>
            <div class="content">
                <p>You have requested to reset your password. Click the button below to create a new password:</p>
                <p style="text-align: center;">
                    <a href="' . $reset_link . '" class="button">Reset Password</a>
                </p>
                <p>Or copy and paste this link into your browser:</p>
                <p>' . $reset_link . '</p>
                <p>This link will expire in 1 hour.</p>
                <p>If you did not request a password reset, please ignore this email.</p>
            </div>
            <div class="footer">
                <p>&copy; ' . date('Y') . ' CharterHub. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>';
    
    return charterhub_send_email($to, $subject, $message);
}

/**
 * Log an email event
 * 
 * @param string $type Type of email (verification, reset, invite, etc.)
 * @param string $to Recipient email
 * @param bool $success Whether the email was sent successfully
 * @param array $details Additional details
 * @return void
 */
function log_email_event($type, $to, $success, $details = []) {
    if (function_exists('log_auth_action')) {
        $status = $success ? 'success' : 'failure';
        $action = 'email_' . $type;
        $log_details = [
            'recipient' => $to,
            'details' => $details
        ];
        
        log_auth_action($action, 0, $status, $log_details);
    } else {
        $status = $success ? 'Success' : 'Failed';
        error_log("Email $type to $to: $status");
    }
} 
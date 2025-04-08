-- Update Schema SQL Script for CharterHub
-- This script adds missing columns to the wp_charterhub_users table
-- Date: 2023-04-08

-- Check if columns exist before adding them to avoid errors
SET @column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = 'wp_charterhub_users'
    AND COLUMN_NAME = 'password'
);

-- Add password column if it doesn't exist
SET @add_password_sql = IF(
    @column_exists = 0,
    'ALTER TABLE wp_charterhub_users ADD COLUMN password VARCHAR(255) NOT NULL AFTER email',
    'SELECT "Password column already exists"'
);

PREPARE stmt FROM @add_password_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check if token_version column exists
SET @column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = 'wp_charterhub_users'
    AND COLUMN_NAME = 'token_version'
);

-- Add token_version column if it doesn't exist
SET @add_token_version_sql = IF(
    @column_exists = 0,
    'ALTER TABLE wp_charterhub_users ADD COLUMN token_version INT DEFAULT 0 AFTER verified',
    'SELECT "token_version column already exists"'
);

PREPARE stmt FROM @add_token_version_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- After adding columns, we need to set up passwords for existing users
-- This sets a temporary password of 'TemporaryPassword123!' for all users
-- In a production environment, you'd want to generate random passwords or
-- implement a password reset flow for all users
UPDATE wp_charterhub_users 
SET password = '$2y$10$YJaRMg/kRQJgzcgZu.6XHu8fBpB5FSHqZCFQfQVjg5HuL3vf9Mx4u' 
WHERE password IS NULL OR password = '';

-- Verify the updates
SELECT 
    column_name, 
    data_type,
    is_nullable
FROM 
    information_schema.columns
WHERE 
    table_name = 'wp_charterhub_users' 
    AND column_name IN ('password', 'token_version'); 
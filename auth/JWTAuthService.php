<?php

require_once __DIR__ . '/../jwt-fix.php';

class JWTAuthService {
    /**
     * Generate a JWT token given user data.
     * Adds standard claims and calls token generation function from jwt-fix.php.
     * 
     * @param array $userData
     * @return string The JWT token
     */
    public static function generateToken(array $userData): string {
        global $db_config;
        $pdo = get_db_connection();
        
        // Start transaction
        $pdo->beginTransaction();
        
        try {
            // Ensure we have a valid user ID
            $userId = $userData['sub'] ?? null;
            if (!$userId) {
                throw new Exception("User ID is required for token generation");
            }
            
            // Generate token with proper role
            $payload = [
                'iss' => 'CharterHub',
                'aud' => 'CharterHub Users',
                'iat' => time(),
                'exp' => time() + 3600,
                'sub' => $userId,
                'email' => $userData['email'] ?? '',
                'firstName' => $userData['firstName'] ?? '',
                'lastName' => $userData['lastName'] ?? '',
                'role' => $userData['role'] ?? 'client',
                'verified' => $userData['verified'] ?? false
            ];
            
            // Generate token using improved function
            $token = improved_generate_jwt_token($payload);
            
            // Store token in database
            $stmt = $pdo->prepare("
                INSERT INTO {$db_config['table_prefix']}charterhub_jwt_tokens 
                (user_id, token_hash, expires_at) 
                VALUES (?, ?, ?)
            ");
            
            $stmt->execute([
                $userId,
                hash('sha256', $token),
                date('Y-m-d H:i:s', $payload['exp'])
            ]);
            
            // Log successful token generation
            $stmt = $pdo->prepare("
                INSERT INTO {$db_config['table_prefix']}charterhub_auth_logs 
                (user_id, action, status, ip_address, user_agent, details) 
                VALUES (?, 'login', 'success', ?, ?, ?)
            ");
            
            $stmt->execute([
                $userId,
                $_SERVER['REMOTE_ADDR'] ?? '::1',
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                json_encode([
                    'token_id' => $pdo->lastInsertId(),
                    'expires_at' => date('Y-m-d H:i:s', $payload['exp'])
                ])
            ]);
            
            $pdo->commit();
            return $token;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Token generation failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Validate a JWT token using improved_verify_jwt_token function.
     * Returns the decoded token payload or false if invalid.
     *
     * @param string $token
     * @return mixed
     */
    public static function validateToken(string $token) {
        try {
            return improved_verify_jwt_token($token);
        } catch (Exception $e) {
            error_log("Token validation failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Refresh an existing valid JWT token by generating a new one with updated expiration.
     * Returns null if the token is invalid.
     *
     * @param string $token
     * @return string|null
     */
    public static function refreshToken(string $token): ?string {
        global $db_config;
        $pdo = get_db_connection();
        
        try {
            $decoded = self::validateToken($token);
            if (!$decoded) {
                return null;
            }
            
            // Start transaction
            $pdo->beginTransaction();
            
            // Revoke old token
            $stmt = $pdo->prepare("
                UPDATE {$db_config['table_prefix']}charterhub_jwt_tokens 
                SET revoked = 1 
                WHERE token_hash = ?
            ");
            $stmt->execute([hash('sha256', $token)]);
            
            // Generate new token
            $userData = [
                'sub' => $decoded->sub,
                'email' => $decoded->email,
                'role' => 'client'
            ];
            
            $newToken = self::generateToken($userData);
            
            $pdo->commit();
            return $newToken;
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Token refresh failed: " . $e->getMessage());
            return null;
        }
    }
}

?> 
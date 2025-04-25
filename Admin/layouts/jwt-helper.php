<?php
/**
 * JWT Helper for Rental Management System
 * 
 * This file contains functions for JWT token generation, validation, and management
 */

require_once __DIR__ . "/../../vendor/autoload.php";
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Class JWTHelper
 * 
 * Handles JWT token operations
 */
class JWTHelper {
    // Secret key used for signing tokens - should be stored in .env or similar in production
    private $secret_key;
    
    // Token expiration time in seconds (default: 1 hour)
    private $token_expiry = 3600;
    
    // Token issuer
    private $issuer = 'rental-management-system';
    
    /**
     * Constructor
     */
    public function __construct() {
        // In production, use a more secure method to store/retrieve this key
        require_once __DIR__ . "/config.php";
        
        // Use existing secret key or generate a new one
        if (defined('JWT_SECRET_KEY')) {
            $this->secret_key = JWT_SECRET_KEY;
        } else {
            // Generate a secure key if one doesn't exist
            // In production, this should be a fixed value stored securely
            $this->secret_key = bin2hex(random_bytes(32));
            error_log("WARNING: JWT_SECRET_KEY not defined in config.php. Using temporary key.");
        }
    }
    
    /**
     * Generate a JWT token
     * 
     * @param array $user_data User data to include in the token
     * @return string The generated JWT token
     */
    public function generateToken($user_data) {
        $issuedAt = time();
        $expire = $issuedAt + $this->token_expiry;
        
        // Token payload
        $payload = [
            'iat' => $issuedAt,     // Issued at time
            'iss' => $this->issuer, // Issuer
            'exp' => $expire,       // Expiration time
            'nbf' => $issuedAt,     // Not valid before
            'user_id' => $user_data['user_id'] ?? null,
            'email' => $user_data['email'] ?? null,
            'role_id' => $user_data['role_id'] ?? null,
            'dataset_id' => isset($user_data['active_dataset']) ? $user_data['active_dataset']['id'] : null
        ];
        
        // Create token
        $jwt = JWT::encode($payload, $this->secret_key, 'HS256');
        
        return $jwt;
    }
    
    /**
     * Validate and decode a JWT token
     * 
     * @param string $token The JWT token to validate
     * @return object|bool The decoded token payload or false if invalid
     */
    public function validateToken($token) {
        try {
            $decoded = JWT::decode($token, new Key($this->secret_key, 'HS256'));
            return $decoded;
        } catch (\Exception $e) {
            error_log("JWT validation error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if a JWT token is valid and not expired
     * 
     * @param string $token The JWT token to check
     * @return bool Whether the token is valid
     */
    public function isTokenValid($token) {
        $decoded = $this->validateToken($token);
        return ($decoded !== false);
    }
    
    /**
     * Get user data from a JWT token
     * 
     * @param string $token The JWT token
     * @return array|bool The user data from the token or false if invalid
     */
    public function getUserFromToken($token) {
        $decoded = $this->validateToken($token);
        
        if ($decoded === false) {
            return false;
        }
        
        return [
            'user_id' => $decoded->user_id,
            'email' => $decoded->email,
            'role_id' => $decoded->role_id,
            'dataset_id' => $decoded->dataset_id
        ];
    }
    
    /**
     * Get the token from HTTP Authorization header
     * 
     * @return string|bool The token or false if not found
     */
    public function getTokenFromHeader() {
        $headers = getallheaders();
        
        if (isset($headers['Authorization']) && preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
            return $matches[1];
        }
        
        return false;
    }
    
    /**
     * Set a JWT token as a cookie
     * 
     * @param string $token The JWT token
     * @return bool Whether the cookie was set
     */
    public function setTokenCookie($token) {
        // Set HTTP-only cookie with secure flag (in production)
        return setcookie('jwt_token', $token, [
            'expires' => time() + $this->token_expiry,
            'path' => '/',
            'domain' => '',
            'secure' => true,   // Set to true in production with HTTPS
            'httponly' => true, // Prevent JavaScript access
            'samesite' => 'Strict'
        ]);
    }
    
    /**
     * Get a JWT token from cookie
     * 
     * @return string|bool The token or false if not found
     */
    public function getTokenFromCookie() {
        return isset($_COOKIE['jwt_token']) ? $_COOKIE['jwt_token'] : false;
    }
    
    /**
     * Clear JWT token cookie
     * 
     * @return bool Whether the cookie was cleared
     */
    public function clearTokenCookie() {
        return setcookie('jwt_token', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => '',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
    }
}
?> 
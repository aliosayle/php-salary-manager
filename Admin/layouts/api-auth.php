<?php
/**
 * API Authentication for Rental Management System
 * 
 * This file provides JWT authentication for API endpoints
 */

require_once __DIR__ . "/jwt-helper.php";

/**
 * Class APIAuth
 * 
 * Handles API authentication with JWT
 */
class APIAuth {
    private $jwt;
    private $authenticated = false;
    private $user = null;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->jwt = new JWTHelper();
        $this->checkAuthentication();
    }
    
    /**
     * Check if the request is authenticated
     * 
     * @return bool Whether the request is authenticated
     */
    private function checkAuthentication() {
        // Check for token in Authorization header
        $token = $this->jwt->getTokenFromHeader();
        
        // If not in header, check cookie
        if (!$token) {
            $token = $this->jwt->getTokenFromCookie();
        }
        
        // No token found
        if (!$token) {
            $this->authenticated = false;
            return false;
        }
        
        // Validate token
        if (!$this->jwt->isTokenValid($token)) {
            $this->authenticated = false;
            return false;
        }
        
        // Get user data from token
        $this->user = $this->jwt->getUserFromToken($token);
        $this->authenticated = true;
        
        return true;
    }
    
    /**
     * Check if the request is authenticated
     * 
     * @return bool Whether the request is authenticated
     */
    public function isAuthenticated() {
        return $this->authenticated;
    }
    
    /**
     * Get the authenticated user
     * 
     * @return array|null The authenticated user data or null if not authenticated
     */
    public function getUser() {
        return $this->user;
    }
    
    /**
     * Require authentication for an API endpoint
     * 
     * @param bool $returnJson Whether to return a JSON response (true) or exit with 401 (false)
     * @return array|null The user data if authenticated, or null if not and $returnJson is false
     */
    public function requireAuth($returnJson = true) {
        if (!$this->isAuthenticated()) {
            if ($returnJson) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'Unauthorized access',
                    'error' => 'Authentication required'
                ]);
                exit;
            } else {
                http_response_code(401);
                exit;
            }
        }
        
        return $this->user;
    }
    
    /**
     * Ensure user has required role
     * 
     * @param int|array $requiredRoleIds Role ID(s) required to access the resource
     * @param bool $returnJson Whether to return a JSON response (true) or exit with 403 (false)
     * @return bool Whether the user has the required role
     */
    public function requireRole($requiredRoleIds, $returnJson = true) {
        // First ensure user is authenticated
        $user = $this->requireAuth($returnJson);
        
        // Convert single role ID to array
        if (!is_array($requiredRoleIds)) {
            $requiredRoleIds = [$requiredRoleIds];
        }
        
        // Check if user has any of the required roles
        if (!in_array($user['role_id'], $requiredRoleIds)) {
            if ($returnJson) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'Forbidden access',
                    'error' => 'Insufficient permissions'
                ]);
                exit;
            } else {
                http_response_code(403);
                exit;
            }
        }
        
        return true;
    }
}
?> 
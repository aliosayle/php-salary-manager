<?php
/**
 * Session Manager Class for Rental Management System
 * 
 * This class handles session management with security features like:
 * - Session hijacking protection
 * - Session expiration
 * - IP and user agent validation
 * - Database-stored sessions
 */
class SessionManager {
    private $pdo;
    private $sessionLifetime;
    private $sessionToken;
    private $userID;
    private $sessionID;
    private $sessionData;
    private $logFile;
    
    /**
     * Constructor
     * 
     * @param PDO $pdo The PDO database connection
     * @param int $sessionLifetime The session lifetime in seconds (default: from config)
     */
    public function __construct($pdo, $sessionLifetime = null) {
        $this->pdo = $pdo;
        $this->sessionLifetime = $sessionLifetime ?? SESSION_LIFETIME;
        
        // Set up error logging
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $this->logFile = $logDir . '/error.log';
        
        // Ensure the sessions table exists
        $this->createSessionsTable();
    }
    
    /**
     * Log messages to the error log
     * 
     * @param string $message The message to log
     */
    private function log($message) {
        error_log($message);
    }
    
    /**
     * Create the sessions table if it doesn't exist
     */
    private function createSessionsTable() {
        try {
            // Check if the sessions table exists
            $tableExists = false;
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'sessions'");
            $tableExists = $stmt->rowCount() > 0;
            
            if (!$tableExists) {
                // Create the sessions table
                $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS sessions (
                    id VARCHAR(36) PRIMARY KEY,
                    user_id VARCHAR(36) NOT NULL,
                    session_token VARCHAR(255) NOT NULL UNIQUE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    expires_at TIMESTAMP NOT NULL,
                    is_active BOOLEAN DEFAULT TRUE,
                    public_ip VARCHAR(45),
                    local_ip VARCHAR(45),
                    payload JSON,
                    browser_info TEXT,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                )
                ");
                
                // Create the sessions trigger if using UUIDs
                $this->pdo->exec("
                DROP TRIGGER IF EXISTS before_insert_sessions;
                ");
                
                $this->pdo->exec("
                CREATE TRIGGER before_insert_sessions
                BEFORE INSERT ON sessions
                FOR EACH ROW
                BEGIN
                    IF NEW.id IS NULL THEN
                        SET NEW.id = UUID();
                    END IF;
                END;
                ");
            }
        } catch (PDOException $e) {
            $this->log("Error creating sessions table: " . $e->getMessage());
        }
    }
    
    /**
     * Start or validate the session
     * 
     * @return bool Whether the session is valid
     */
    public function startSession() {
        try {
            // Ensure session is started
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            $this->log("Starting session check. Session status: " . session_status());
            $this->log("SessionManager has token: " . (isset($_SESSION['session_token']) ? 'Yes' : 'No'));
            
            // Check if we have a session token in the session
            if (isset($_SESSION['session_token'])) {
                $this->sessionToken = $_SESSION['session_token'];
                
                // Validate the session token
                return $this->validateSession();
            }
            
            return false;
        } catch (Exception $e) {
            $this->log("Session start error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Validate the session against the database and update last activity
     * 
     * @return bool Whether the session is valid
     */
    public function validateSession() {
        // If session_token is not set in session, it's not valid
        if (!isset($_SESSION['session_token'])) {
            error_log("Session token not found in session");
            return false;
        }

        try {
            // Get the session data from database
            $stmt = $this->pdo->prepare("SELECT * FROM sessions WHERE session_token = ? AND is_active = 1");
            $stmt->execute([$_SESSION['session_token']]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);

            // If session not found or not active, it's not valid
            if (!$session) {
                error_log("Session token not found in database or inactive: " . $_SESSION['session_token']);
                return false;
            }

            // Increase session timeout to 12 hours for better user experience
            $sessionTimeout = 12 * 60 * 60; // 12 hours in seconds
            
            // Check if session has expired
            $lastActivity = strtotime($session['last_activity']);
            $currentTime = time();
            $timeSinceLastActivity = $currentTime - $lastActivity;
            
            if ($timeSinceLastActivity > $sessionTimeout) {
                error_log("Session expired. Last activity: " . $session['last_activity'] . 
                         ", Time since last activity: " . $timeSinceLastActivity . " seconds");
                
                // Invalidate the session
                $this->invalidateSession($_SESSION['session_token']);
                return false;
            }

            // Update last activity time
            $stmt = $this->pdo->prepare("UPDATE sessions SET last_activity = NOW() WHERE session_token = ?");
            $stmt->execute([$_SESSION['session_token']]);

            // Update session data
            $_SESSION = array_merge($_SESSION, [
                'user_id' => $session['user_id'],
                'name' => $session['user_name'],
                'email' => $session['user_email'],
                'role_id' => $session['role_id'],
                'loggedin' => true,
                'last_activity' => date('Y-m-d H:i:s')
            ]);

                return true;
        } catch (PDOException $e) {
            error_log("Session validation error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create a new session
     * 
     * @param string $userID The user ID
     * @param array $sessionData The session data to store
     * @return string|bool The session token or false on failure
     */
    public function createSession($userID, $sessionData = []) {
        try {
            // Generate a secure token
            $token = generateSecureToken();
            
            // Calculate expiration time - 30 days from now
            $expiresAt = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60));
            
            // Get client info
            $publicIP = $this->getClientIP();
            $localIP = $this->getLocalIP();
            $browserInfo = $_SERVER['HTTP_USER_AGENT'] ?? '';
            
            $this->log("Creating new session for user: $userID, IP: $publicIP, Browser: $browserInfo");
            
            // Prepare the query
            $stmt = $this->pdo->prepare("
                INSERT INTO sessions 
                (user_id, session_token, expires_at, public_ip, local_ip, payload, browser_info, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1)
            ");
            
            // Execute the query
            $stmt->execute([
                $userID,
                $token,
                $expiresAt,
                $publicIP,
                $localIP,
                json_encode($sessionData),
                $browserInfo
            ]);
            
            // Set the token in the session
            $_SESSION['session_token'] = $token;
            $_SESSION['user_id'] = $userID;
            
            // Also set the session token in a cookie - secure, http-only
            setcookie('session_token', $token, [
                'expires' => time() + (30 * 24 * 60 * 60),
                'path' => '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Strict'
            ]);
            $this->log("Session token cookie set: " . $token);
            
            // Store session data in PHP session for compatibility
            foreach ($sessionData as $key => $value) {
                $_SESSION[$key] = $value;
            }
            
            // Set the token, user ID, and session data
            $this->sessionToken = $token;
            $this->userID = $userID;
            $this->sessionData = $sessionData;
            
            $this->log("New session created with token: $token");
            return $token;
        } catch (PDOException $e) {
            $this->log("Session creation error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Extend the current session lifetime
     * 
     * @return bool Whether the session was extended
     */
    private function extendSession() {
        try {
            // Calculate new expiration time - 30 days from now
            $expiresAt = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60));
            
            // Prepare the query
            $stmt = $this->pdo->prepare("
                UPDATE sessions
                SET expires_at = ?
                WHERE id = ?
            ");
            
            // Execute the query
            $stmt->execute([$expiresAt, $this->sessionID]);
            
            $this->log("Session extended until: $expiresAt");
            return true;
        } catch (PDOException $e) {
            $this->log("Session extension error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Invalidate the session
     * 
     * @param string $token The session token to invalidate
     * @return bool Whether the session was invalidated
     */
    public function invalidateSession($token = null) {
        // If no token is provided, use the current session token
        $token = $token ?? $_COOKIE['session_token'] ?? $_SESSION['session_token'] ?? null;
        
        // If no token is available, return false
        if (!$token) {
            $this->log("No session token provided or found for invalidation");
            return false;
        }
        
        try {
            // Prepare the query
            $stmt = $this->pdo->prepare("
                UPDATE sessions
                SET is_active = 0
                WHERE session_token = ?
            ");
            
            // Execute the query
            $stmt->execute([$token]);
            
            // Clear session data
            if (isset($_SESSION)) {
                session_unset();
                session_destroy();
            }
            
            // Clear session cookie
            if (isset($_COOKIE['session_token'])) {
                setcookie('session_token', '', [
                    'expires' => time() - 3600,
                    'path' => '/',
                    'secure' => true,
                    'httponly' => true,
                    'samesite' => 'Strict'
                ]);
            }
            
            $this->log("Session invalidated: $token");
            return true;
        } catch (PDOException $e) {
            $this->log("Session invalidation error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Destroy the current session
     * 
     * @return bool Whether the session was destroyed
     */
    public function destroySession() {
        try {
            $this->log("Destroying session");
            
            // Invalidate in database
            $this->invalidateSession();
            
            // Unset all session variables
            $_SESSION = [];
            
            // Destroy the session cookie
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params["path"],
                    $params["domain"],
                    $params["secure"],
                    $params["httponly"]
                );
            }
            
            // Destroy the session
            session_destroy();
            
            $this->log("Session destroyed successfully");
            return true;
        } catch (Exception $e) {
            $this->log("Session destruction error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get the client's real IP address
     * Properly handles proxy servers and various header configurations
     * Uses a public IP API service for more accurate detection
     * 
     * @return string The client's IP address
     */
    private function getClientIP() {
        // First try to get the IP from public API services (more reliable for public IP)
        try {
            // Try ipify API first - simple and reliable
            $public_ip = $this->fetchPublicIP();
            
            if ($public_ip) {
                $this->log("IP Detection - Using public API service: {$public_ip}");
                return $public_ip;
            }
        } catch (Exception $e) {
            $this->log("IP Detection - API service error: " . $e->getMessage());
            // Continue with fallback methods if API fails
        }
        
        // Log all possible IP sources for debugging
        $this->log("IP Detection - SERVER vars: " . 
            "REMOTE_ADDR=" . ($_SERVER['REMOTE_ADDR'] ?? 'Not set') . ", " .
            "HTTP_X_FORWARDED_FOR=" . ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'Not set') . ", " .
            "HTTP_CLIENT_IP=" . ($_SERVER['HTTP_CLIENT_IP'] ?? 'Not set') . ", " .
            "HTTP_X_REAL_IP=" . ($_SERVER['HTTP_X_REAL_IP'] ?? 'Not set')
        );
        
        // Check for proxy servers first
        $ip_keys = array(
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_CLIENT_IP',        // Client IP
            'HTTP_X_FORWARDED_FOR',  // Forwarded for
            'HTTP_X_FORWARDED',      // Forwarded
            'HTTP_X_CLUSTER_CLIENT_IP', // Cluster client IP
            'HTTP_FORWARDED_FOR',    // Forwarded for alternate
            'HTTP_FORWARDED',        // Forwarded alternate
            'HTTP_X_REAL_IP',        // Real IP
            'REMOTE_ADDR',           // Remote address (fallback)
        );
        
        // Try to find the first valid IP
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                // Some headers can contain multiple IPs, comma separated
                // Get the first one which is usually the real client IP
                $ip_list = explode(',', $_SERVER[$key]);
                $client_ip = trim($ip_list[0]);
                
                // Validate IP format
                if (filter_var($client_ip, FILTER_VALIDATE_IP)) {
                    $this->log("IP Detection - Using {$key}: {$client_ip}");
                    return $client_ip;
                }
            }
        }
        
        // Default fallback
        $this->log("IP Detection - All methods failed, using REMOTE_ADDR as fallback");
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    /**
     * Fetch public IP from a public API service
     * 
     * @return string|null The public IP address or null if failed
     */
    private function fetchPublicIP() {
        $apis = [
            'https://api.ipify.org/',           // Simple text response
            'https://api.ipify.org?format=json', // JSON response
            'https://ipinfo.io/ip',             // Alternative service
            'https://api.my-ip.io/ip',          // Another alternative
            'https://checkip.amazonaws.com/',   // AWS IP check service
        ];
        
        foreach ($apis as $api_url) {
            try {
                $this->log("Attempting to fetch public IP from: $api_url");
                $options = [
                    'http' => [
                        'method' => 'GET',
                        'timeout' => 3 // Timeout in seconds
                    ]
                ];
                $context = stream_context_create($options);
                $response = @file_get_contents($api_url, false, $context);
                
                if ($response !== false) {
                    // If the response is JSON, parse it
                    if (strpos($api_url, 'format=json') !== false) {
                        $data = json_decode($response, true);
                        $ip = $data['ip'] ?? null;
                    } else {
                        // Otherwise, just clean the response
                        $ip = trim($response);
                    }
                    
                    // Validate IP format
                    if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
                        $this->log("Successfully fetched public IP: $ip from $api_url");
                        return $ip;
                    }
                }
            } catch (Exception $e) {
                $this->log("Error fetching from $api_url: " . $e->getMessage());
                continue; // Try next API
            }
        }
        
        $this->log("All public IP API services failed");
        return null;
    }
    
    /**
     * Get the local IP address
     * 
     * @return string The local IP address
     */
    private function getLocalIP() {
        return gethostbyname(gethostname());
    }
    
    /**
     * Invalidate all sessions for a user
     * 
     * @param string $userID The user ID
     * @return bool Whether the operation was successful
     */
    public function invalidateAllUserSessions($userID) {
        try {
            // Prepare the query
            $stmt = $this->pdo->prepare("
                UPDATE sessions
                SET is_active = 0
                WHERE user_id = ?
            ");
            
            // Execute the query
            $stmt->execute([$userID]);
            
            $this->log("All sessions invalidated for user: " . $userID);
            return true;
        } catch (PDOException $e) {
            $this->log("Error invalidating all sessions: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Invalidate a specific session by token
     * 
     * @param string $sessionToken The session token to invalidate
     * @return bool Whether the session was invalidated
     */
    public function invalidateSessionByToken($sessionToken) {
        try {
            if (empty($sessionToken)) {
                $this->log("Cannot invalidate session: No session token provided");
                return false;
            }
            
            // Prepare the query
            $stmt = $this->pdo->prepare("
                UPDATE sessions
                SET is_active = 0
                WHERE session_token = ?
            ");
            
            // Execute the query
            $result = $stmt->execute([$sessionToken]);
            
            $this->log("Session invalidation by token " . ($result ? "successful" : "failed") . ": " . $sessionToken);
            return $result;
        } catch (PDOException $e) {
            $this->log("Session invalidation error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Set the active dataset for the current user session
     * 
     * @param string $dataset_id The dataset ID to set as active
     * @return bool Whether the dataset was set successfully
     */
    public function setActiveDataset($dataset_id) {
        // Check if session is valid
        if (!$this->validateSession()) {
            $this->log("Cannot set active dataset - invalid session");
            return false;
        }
        
        // Check if user is logged in
        if (!isset($_SESSION['user_id'])) {
            $this->log("Cannot set active dataset - user not logged in");
            return false;
        }
        
        $user_id = $_SESSION['user_id'];
        
        try {
            // Verify this dataset is assigned to the user
            $stmt = $this->pdo->prepare("
                SELECT ud.id, d.name 
                FROM user_datasets ud
                JOIN datasets d ON ud.dataset_id = d.id
                WHERE ud.user_id = ? AND ud.dataset_id = ?
            ");
            $stmt->execute([$user_id, $dataset_id]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$result) {
                $this->log("User $user_id does not have access to dataset $dataset_id");
                return false;
            }
            
            // Update session with active dataset
            $_SESSION['active_dataset_id'] = $dataset_id;
            $_SESSION['active_dataset_name'] = $result['name'];
            
            $this->log("Active dataset set to: $dataset_id (" . $result['name'] . ") for user: $user_id");
            return true;
        } catch (PDOException $e) {
            $this->log("Error setting active dataset: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get the active dataset for the current user session
     * 
     * @return array|null The active dataset or null if none is set
     */
    public function getActiveDataset() {
        // Check if active dataset is already in session
        if (isset($_SESSION['active_dataset_id'])) {
            $dataset_id = $_SESSION['active_dataset_id'];
            
            try {
                // Get dataset details
                $stmt = $this->pdo->prepare("
                    SELECT d.*, ud.is_default
                    FROM datasets d
                    JOIN user_datasets ud ON d.id = ud.dataset_id
                    WHERE d.id = ? AND ud.user_id = ?
                ");
                $stmt->execute([$dataset_id, $_SESSION['user_id']]);
                
                $dataset = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($dataset) {
                    return $dataset;
                }
            } catch (PDOException $e) {
                $this->log("Error retrieving active dataset: " . $e->getMessage());
            }
        }
        
        // If no active dataset is set, try to get the default dataset
        try {
            if (isset($_SESSION['user_id'])) {
                $stmt = $this->pdo->prepare("
                    SELECT d.*, ud.is_default
                    FROM datasets d
                    JOIN user_datasets ud ON d.id = ud.dataset_id
                    WHERE ud.user_id = ? AND ud.is_default = 1
                    LIMIT 1
                ");
                $stmt->execute([$_SESSION['user_id']]);
                
                $dataset = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($dataset) {
                    // Set this as the active dataset in session
                    $_SESSION['active_dataset_id'] = $dataset['id'];
                    $_SESSION['active_dataset_name'] = $dataset['name'];
                    
                    $this->log("Default dataset set as active: " . $dataset['id']);
                    return $dataset;
                }
                
                // If no default dataset, get the first available dataset
                $stmt = $this->pdo->prepare("
                    SELECT d.*, ud.is_default
                    FROM datasets d
                    JOIN user_datasets ud ON d.id = ud.dataset_id
                    WHERE ud.user_id = ?
                    ORDER BY d.name
                    LIMIT 1
                ");
                $stmt->execute([$_SESSION['user_id']]);
                
                $dataset = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($dataset) {
                    // Set this as the active dataset in session
                    $_SESSION['active_dataset_id'] = $dataset['id'];
                    $_SESSION['active_dataset_name'] = $dataset['name'];
                    
                    $this->log("First available dataset set as active: " . $dataset['id']);
                    return $dataset;
                }
            }
        } catch (PDOException $e) {
            $this->log("Error finding default dataset: " . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Get all datasets available to the current user
     * 
     * @return array List of datasets available to the user
     */
    public function getUserDatasets() {
        if (!isset($_SESSION['user_id'])) {
            $this->log("Cannot get datasets - user not logged in");
            return [];
        }
        
        $user_id = $_SESSION['user_id'];
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT d.id, d.name, d.description, ud.is_default
                FROM datasets d
                JOIN user_datasets ud ON d.id = ud.dataset_id
                WHERE ud.user_id = ?
                ORDER BY ud.is_default DESC, d.name ASC
            ");
            $stmt->execute([$user_id]);
            
            $datasets = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $this->log("Retrieved " . count($datasets) . " datasets for user: $user_id");
            return $datasets;
        } catch (PDOException $e) {
            $this->log("Error retrieving user datasets: " . $e->getMessage());
            return [];
        }
    }
}
?> 
<?php
require_once 'config.php';

/**
 * User Authentication Class
 */
class Auth {
    private $conn;
    private $table_name = "users";
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    /**
     * Register new user
     */
    public function register($username, $email, $password) {
        // Check if user already exists
        if ($this->userExists($username, $email)) {
            throw new Exception("User already exists with this username or email");
        }
        
        // Validate input
        if (!validateEmail($email)) {
            throw new Exception("Invalid email format");
        }
        
        if (strlen($password) < 6) {
            throw new Exception("Password must be at least 6 characters long");
        }
        
        $query = "INSERT INTO " . $this->table_name . " 
                  SET username=:username, email=:email, password_hash=:password_hash";
        
        $stmt = $this->conn->prepare($query);
        
        // Hash password
        $password_hash = password_hash($password, PASSWORD_BCRYPT);
        
        $stmt->bindParam(":username", $username);
        $stmt->bindParam(":email", $email);
        $stmt->bindParam(":password_hash", $password_hash);
        
        if ($stmt->execute()) {
            $user_id = $this->conn->lastInsertId();
            logActivity($user_id, 'user_registered');
            return $user_id;
        }
        
        throw new Exception("Registration failed");
    }
    
    /**
     * Login user
     */
    public function login($username, $password) {
        $query = "SELECT user_id, username, email, password_hash, subscription_type, is_active 
                  FROM " . $this->table_name . " 
                  WHERE (username = :username OR email = :username) AND is_active = 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":username", $username);
        $stmt->execute();
        
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            throw new Exception("Invalid credentials");
        }
        
        if (!password_verify($password, $user['password_hash'])) {
            throw new Exception("Invalid credentials");
        }
        
        // Update last login
        $this->updateLastLogin($user['user_id']);
        
        // Log activity
        logActivity($user['user_id'], 'user_login');
        
        // Generate JWT token
        $token = generateJWT($user['user_id'], $user['username']);
        
        return [
            'token' => $token,
            'user' => [
                'user_id' => $user['user_id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'subscription_type' => $user['subscription_type']
            ]
        ];
    }
    
    /**
     * Google OAuth login
     */
    public function googleLogin($google_id, $email, $name) {
        // Check if user exists with this Google ID
        $query = "SELECT user_id, username, email, subscription_type 
                  FROM " . $this->table_name . " 
                  WHERE google_id = :google_id AND is_active = 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":google_id", $google_id);
        $stmt->execute();
        
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            // Update last login
            $this->updateLastLogin($user['user_id']);
            logActivity($user['user_id'], 'google_login');
        } else {
            // Create new user
            $username = $this->generateUniqueUsername($name);
            
            $query = "INSERT INTO " . $this->table_name . " 
                      SET username=:username, email=:email, google_id=:google_id, 
                          password_hash=:password_hash";
            
            $stmt = $this->conn->prepare($query);
            $password_hash = password_hash(generateUUID(), PASSWORD_BCRYPT); // Random password for Google users
            
            $stmt->bindParam(":username", $username);
            $stmt->bindParam(":email", $email);
            $stmt->bindParam(":google_id", $google_id);
            $stmt->bindParam(":password_hash", $password_hash);
            
            if (!$stmt->execute()) {
                throw new Exception("Google registration failed");
            }
            
            $user_id = $this->conn->lastInsertId();
            logActivity($user_id, 'google_register');
            
            $user = [
                'user_id' => $user_id,
                'username' => $username,
                'email' => $email,
                'subscription_type' => 'free'
            ];
        }
        
        $token = generateJWT($user['user_id'], $user['username']);
        
        return [
            'token' => $token,
            'user' => $user
        ];
    }
    
    /**
     * Reset password request
     */
    public function requestPasswordReset($email) {
        $query = "SELECT user_id FROM " . $this->table_name . " 
                  WHERE email = :email AND is_active = 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":email", $email);
        $stmt->execute();
        
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            throw new Exception("No account found with this email");
        }
        
        $reset_token = generateUUID();
        $expires_at = date('Y-m-d H:i:s', time() + (24 * 60 * 60)); // 24 hours
        
        $query = "UPDATE " . $this->table_name . " 
                  SET reset_token = :reset_token, reset_token_expires = :expires_at 
                  WHERE user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":reset_token", $reset_token);
        $stmt->bindParam(":expires_at", $expires_at);
        $stmt->bindParam(":user_id", $user['user_id']);
        
        if ($stmt->execute()) {
            // In a real application, send email with reset link
            // For this example, we'll just return the token
            logActivity($user['user_id'], 'password_reset_requested');
            return $reset_token;
        }
        
        throw new Exception("Failed to generate reset token");
    }
    
    /**
     * Reset password
     */
    public function resetPassword($token, $new_password) {
        $query = "SELECT user_id FROM " . $this->table_name . " 
                  WHERE reset_token = :token AND reset_token_expires > NOW() AND is_active = 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":token", $token);
        $stmt->execute();
        
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            throw new Exception("Invalid or expired reset token");
        }
        
        if (strlen($new_password) < 6) {
            throw new Exception("Password must be at least 6 characters long");
        }
        
        $password_hash = password_hash($new_password, PASSWORD_BCRYPT);
        
        $query = "UPDATE " . $this->table_name . " 
                  SET password_hash = :password_hash, reset_token = NULL, reset_token_expires = NULL 
                  WHERE user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":password_hash", $password_hash);
        $stmt->bindParam(":user_id", $user['user_id']);
        
        if ($stmt->execute()) {
            logActivity($user['user_id'], 'password_reset_completed');
            return true;
        }
        
        throw new Exception("Failed to reset password");
    }
    
    /**
     * Update user profile
     */
    public function updateProfile($user_id, $data) {
        $allowed_fields = ['username', 'email', 'profile_image'];
        $update_fields = [];
        $params = [];
        
        foreach ($data as $field => $value) {
            if (in_array($field, $allowed_fields)) {
                $update_fields[] = "$field = :$field";
                $params[":$field"] = $value;
            }
        }
        
        if (empty($update_fields)) {
            throw new Exception("No valid fields to update");
        }
        
        // Check if new username/email is unique
        if (isset($data['username']) || isset($data['email'])) {
            $check_query = "SELECT user_id FROM " . $this->table_name . " 
                           WHERE (username = :check_username OR email = :check_email) 
                           AND user_id != :user_id";
            
            $check_stmt = $this->conn->prepare($check_query);
            $check_stmt->bindParam(":check_username", $data['username'] ?? '');
            $check_stmt->bindParam(":check_email", $data['email'] ?? '');
            $check_stmt->bindParam(":user_id", $user_id);
            $check_stmt->execute();
            
            if ($check_stmt->fetch()) {
                throw new Exception("Username or email already exists");
            }
        }
        
        $query = "UPDATE " . $this->table_name . " 
                  SET " . implode(', ', $update_fields) . " 
                  WHERE user_id = :user_id";
        
        $params[':user_id'] = $user_id;
        $stmt = $this->conn->prepare($query);
        
        if ($stmt->execute($params)) {
            logActivity($user_id, 'profile_updated', null, $data);
            return true;
        }
        
        throw new Exception("Failed to update profile");
    }
    
    /**
     * Change password
     */
    public function changePassword($user_id, $current_password, $new_password) {
        $query = "SELECT password_hash FROM " . $this->table_name . " 
                  WHERE user_id = :user_id AND is_active = 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":user_id", $user_id);
        $stmt->execute();
        
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            throw new Exception("User not found");
        }
        
        if (!password_verify($current_password, $user['password_hash'])) {
            throw new Exception("Current password is incorrect");
        }
        
        if (strlen($new_password) < 6) {
            throw new Exception("New password must be at least 6 characters long");
        }
        
        $new_password_hash = password_hash($new_password, PASSWORD_BCRYPT);
        
        $query = "UPDATE " . $this->table_name . " 
                  SET password_hash = :password_hash 
                  WHERE user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":password_hash", $new_password_hash);
        $stmt->bindParam(":user_id", $user_id);
        
        if ($stmt->execute()) {
            logActivity($user_id, 'password_changed');
            return true;
        }
        
        throw new Exception("Failed to change password");
    }
    
    /**
     * Get user profile
     */
    public function getProfile($user_id) {
        $query = "SELECT user_id, username, email, profile_image, subscription_type, 
                         created_at, last_login
                  FROM " . $this->table_name . " 
                  WHERE user_id = :user_id AND is_active = 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":user_id", $user_id);
        $stmt->execute();
        
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            throw new Exception("User not found");
        }
        
        // Get user statistics
        $stats_query = "SELECT 
                           (SELECT COUNT(*) FROM mindmaps WHERE user_id = :user_id AND is_archived = 0) as total_mindmaps,
                           (SELECT COUNT(*) FROM mindmaps WHERE user_id = :user_id AND is_public = 1) as public_mindmaps,
                           (SELECT COUNT(*) FROM collaborators WHERE user_id = :user_id AND status = 'accepted') as collaborations
                        ";
        
        $stats_stmt = $this->conn->prepare($stats_query);
        $stats_stmt->bindParam(":user_id", $user_id);
        $stats_stmt->execute();
        $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
        
        $user['statistics'] = $stats;
        
        return $user;
    }
    
    /**
     * Deactivate user account
     */
    public function deactivateAccount($user_id) {
        $query = "UPDATE " . $this->table_name . " 
                  SET is_active = 0 
                  WHERE user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":user_id", $user_id);
        
        if ($stmt->execute()) {
            logActivity($user_id, 'account_deactivated');
            return true;
        }
        
        throw new Exception("Failed to deactivate account");
    }
    
    // Private helper methods
    private function userExists($username, $email) {
        $query = "SELECT user_id FROM " . $this->table_name . " 
                  WHERE username = :username OR email = :email";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":username", $username);
        $stmt->bindParam(":email", $email);
        $stmt->execute();
        
        return $stmt->fetch() !== false;
    }
    
    private function updateLastLogin($user_id) {
        $query = "UPDATE " . $this->table_name . " 
                  SET last_login = NOW() 
                  WHERE user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":user_id", $user_id);
        $stmt->execute();
    }
    
    private function generateUniqueUsername($name) {
        $base_username = strtolower(str_replace(' ', '', $name));
        $base_username = preg_replace('/[^a-z0-9]/', '', $base_username);
        
        $username = $base_username;
        $counter = 1;
        
        while ($this->usernameExists($username)) {
            $username = $base_username . $counter;
            $counter++;
        }
        
        return $username;
    }
    
    private function usernameExists($username) {
        $query = "SELECT user_id FROM " . $this->table_name . " 
                  WHERE username = :username";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":username", $username);
        $stmt->execute();
        
        return $stmt->fetch() !== false;
    }
}

// API Endpoints

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    setCORSHeaders();
    
    $database = new Database();
    $db = $database->getConnection();
    $auth = new Auth($db);
    
    $input = json_decode(file_get_contents("php://input"), true);
    $action = $_GET['action'] ?? '';
    
    try {
        switch ($action) {
            case 'register':
                if (!isset($input['username'], $input['email'], $input['password'])) {
                    sendResponse(false, 'Missing required fields', null, 400);
                }
                
                $user_id = $auth->register(
                    sanitizeInput($input['username']),
                    sanitizeInput($input['email']),
                    $input['password']
                );
                
                sendResponse(true, 'User registered successfully', ['user_id' => $user_id]);
                break;
                
            case 'login':
                if (!isset($input['username'], $input['password'])) {
                    sendResponse(false, 'Missing username or password', null, 400);
                }
                
                $result = $auth->login(
                    sanitizeInput($input['username']),
                    $input['password']
                );
                
                sendResponse(true, 'Login successful', $result);
                break;
                
            case 'google-login':
                if (!isset($input['google_id'], $input['email'], $input['name'])) {
                    sendResponse(false, 'Missing Google OAuth data', null, 400);
                }
                
                $result = $auth->googleLogin(
                    $input['google_id'],
                    sanitizeInput($input['email']),
                    sanitizeInput($input['name'])
                );
                
                sendResponse(true, 'Google login successful', $result);
                break;
                
            case 'forgot-password':
                if (!isset($input['email'])) {
                    sendResponse(false, 'Email is required', null, 400);
                }
                
                $token = $auth->requestPasswordReset(sanitizeInput($input['email']));
                
                sendResponse(true, 'Password reset token generated', ['reset_token' => $token]);
                break;
                
            case 'reset-password':
                if (!isset($input['token'], $input['password'])) {
                    sendResponse(false, 'Missing token or password', null, 400);
                }
                
                $auth->resetPassword($input['token'], $input['password']);
                
                sendResponse(true, 'Password reset successfully');
                break;
                
            case 'change-password':
                $user = requireAuth();
                
                if (!isset($input['current_password'], $input['new_password'])) {
                    sendResponse(false, 'Missing current or new password', null, 400);
                }
                
                $auth->changePassword(
                    $user['user_id'],
                    $input['current_password'],
                    $input['new_password']
                );
                
                sendResponse(true, 'Password changed successfully');
                break;
                
            case 'update-profile':
                $user = requireAuth();
                
                if (empty($input)) {
                    sendResponse(false, 'No data provided', null, 400);
                }
                
                // Handle file upload if profile image is being updated
                if (isset($_FILES['profile_image'])) {
                    try {
                        $image_path = handleImageUpload($_FILES['profile_image'], 'profile');
                        $input['profile_image'] = $image_path;
                    } catch (Exception $e) {
                        sendResponse(false, $e->getMessage(), null, 400);
                    }
                }
                
                $auth->updateProfile($user['user_id'], $input);
                
                sendResponse(true, 'Profile updated successfully');
                break;
                
            case 'deactivate':
                $user = requireAuth();
                $auth->deactivateAccount($user['user_id']);
                sendResponse(true, 'Account deactivated successfully');
                break;
                
            default:
                sendResponse(false, 'Invalid action', null, 400);
        }
    } catch (Exception $e) {
        sendResponse(false, $e->getMessage(), null, 400);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    setCORSHeaders();
    
    $action = $_GET['action'] ?? '';
    
    if ($action === 'profile') {
        $user = requireAuth();
        
        $database = new Database();
        $db = $database->getConnection();
        $auth = new Auth($db);
        
        try {
            $profile = $auth->getProfile($user['user_id']);
            sendResponse(true, 'Profile retrieved successfully', $profile);
        } catch (Exception $e) {
            sendResponse(false, $e->getMessage(), null, 400);
        }
    } else {
        sendResponse(false, 'Invalid action', null, 400);
    }
}
?>
<?php
/**
 * Example Auth Controller
 * 
 * Shows the pattern for creating controllers and endpoints.
 */

class AuthController extends Controller {

    /**
     * CSRF bootstrap endpoint.
     * GET /api/auth/csrf
     */
    public function csrf() {
        $this->csrfToken();
    }
    
    /**
     * Login endpoint.
     * POST /api/auth/login
     * 
     * Body: { "email": "...", "password": "..." }
     */
    public function login() {
        logPostArrival('AuthController::login');
        
        $this->requireCsrf();

        $email = trim((string)$this->getPost('email', ''));
        $password = trim((string)$this->getPost('password', ''));

        // Validate required fields
        if (!$email || !$password) {
            $this->badRequest('Email and password are required');
        }

        // Find user by email
        $userModel = new User($this->db);
        $user = $userModel->findByEmail($email);

        if (!$user) {
            logError('Login failed: user not found', ['email' => $email]);
            $this->unauthorized('Invalid email or password');
        }

        if (!$user['is_active']) {
            $this->unauthorized('User account is inactive');
        }

        // Verify password
        if (!$userModel->verifyPassword($password, $user['password_hash'])) {
            logError('Login failed: password mismatch', ['user_id' => $user['id']]);
            $this->unauthorized('Invalid email or password');
        }

        // Set user session
        $this->auth->setUser(
            $user['id'],
            $user['name'],
            $user['role'],
            $user['email']
        );

        // Log successful login
        $this->logEvent('User logged in', ['user_id' => $user['id'], 'email' => $user['email']]);
        $this->auditAction('login', 'user_session', $user['id'], null, [
            'email' => $user['email'],
            'role' => $user['role'],
        ]);

        // Return user info
        $this->success([
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
            ],
        ]);
    }

    /**
     * Logout endpoint.
     * POST /api/auth/logout
     */
    public function logout() {
        $this->requireAuth();
        $this->requireCsrf();

        $userId = $this->getUserId();
        $this->auth->logout();

        $this->logEvent('User logged out', ['user_id' => $userId]);
        $this->auditAction('logout', 'user_session', $userId, null, ['logged_out' => true]);

        $this->success(['message' => 'Logged out successfully']);
    }

    /**
     * Get current user info.
     * GET /api/auth/user
     */
    public function user() {
        $this->requireAuth();

        $user = $this->getUser();

        $this->success([
            'user' => $user,
        ]);
    }
}

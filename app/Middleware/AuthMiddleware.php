<?php
/**
 * Authentication Middleware
 * 
 * Validates user session and enforces permission gates.
 */

class AuthMiddleware {
    private $config;
    private $authChecked = false;
    private $authenticated = false;
    private $currentUser = null;

    public function __construct($config) {
        $this->config = $config;
    }

    /**
     * Check if user is authenticated.
     */
    public function isAuthenticated() {
        if ($this->authChecked) {
            return $this->authenticated;
        }

        $this->authChecked = true;

        if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
            $this->authenticated = false;
            $this->currentUser = null;
            return false;
        }

        if (!$this->validateSessionState()) {
            $this->authenticated = false;
            $this->currentUser = null;
            return false;
        }

        $_SESSION['last_activity'] = time();
        $this->authenticated = true;
        $this->currentUser = $this->hydrateCurrentUser();
        return true;
    }

    /**
     * Get the current authenticated user.
     */
    public function getUser() {
        if (!$this->isAuthenticated()) {
            return null;
        }

        return $this->currentUser;
    }

    /**
     * Get the validated user without re-reading session state.
     */
    public function getValidatedUser() {
        return $this->currentUser;
    }

    /**
     * Check if user has a specific role.
     */
    public function hasRole($role) {
        if (!$this->isAuthenticated()) {
            return false;
        }
        
        return $_SESSION['user_role'] === $role;
    }

    /**
     * Check if user has any of the given roles.
     */
    public function hasAnyRole($roles) {
        if (!$this->isAuthenticated()) {
            return false;
        }
        
        $userRole = $_SESSION['user_role'] ?? 'guest';
        return in_array($userRole, (array) $roles);
    }

    /**
     * Require authentication and optionally specific role.
     * Exits with 401/403 if not authorized.
     */
    public function require($role = null) {
        if (!$this->isAuthenticated()) {
            http_response_code(401);
            exit(json_encode(['error' => 'Unauthorized']));
        }
        
        if ($role && !$this->hasRole($role)) {
            http_response_code(403);
            exit(json_encode(['error' => 'Forbidden']));
        }
    }

    /**
     * Set user session after login.
     */
    public function setUser($userId, $userName, $userRole, $userEmail = '') {
        session_regenerate_id(true);
        $_SESSION['user_id']   = $userId;
        $_SESSION['user_name'] = $userName;
        $_SESSION['user_role'] = $userRole;
        $_SESSION['user_email'] = $userEmail;
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();
        $_SESSION['session_fingerprint'] = $this->buildSessionFingerprint();
        $this->authChecked = true;
        $this->authenticated = true;
        $this->currentUser = $this->hydrateCurrentUser();
    }

    /**
     * Clear user session on logout.
     */
    public function logout() {
        unset($_SESSION['user_id']);
        unset($_SESSION['user_name']);
        unset($_SESSION['user_role']);
        unset($_SESSION['user_email']);
        unset($_SESSION['login_time']);
        unset($_SESSION['last_activity']);
        unset($_SESSION['session_fingerprint']);
        $this->authChecked = true;
        $this->authenticated = false;
        $this->currentUser = null;
        session_destroy();
    }

    private function validateSessionState() {
        $expectedFingerprint = $_SESSION['session_fingerprint'] ?? null;
        $currentFingerprint = $this->buildSessionFingerprint();
        if (!$expectedFingerprint || !hash_equals($expectedFingerprint, $currentFingerprint)) {
            $this->logSessionValidation('failed_fingerprint', ['user_id' => $_SESSION['user_id'] ?? null]);
            $this->logout();
            return false;
        }

        $idleTimeout = (int)($this->config['security']['session_idle_timeout'] ?? 43200);
        $lastActivity = (int)($_SESSION['last_activity'] ?? $_SESSION['login_time'] ?? time());
        if ($idleTimeout > 0 && (time() - $lastActivity) > $idleTimeout) {
            $this->logSessionValidation('failed_timeout', ['user_id' => $_SESSION['user_id'] ?? null]);
            $this->logout();
            return false;
        }

        $this->logSessionValidation('passed', ['user_id' => $_SESSION['user_id'] ?? null]);
        return true;
    }

    private function buildSessionFingerprint() {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $mode = $this->config['security']['session_fingerprint_mode'] ?? 'user_agent_ip_prefix';

        if ($mode === 'user_agent') {
            return hash('sha256', $userAgent);
        }

        if ($mode === 'user_agent_ip') {
            return hash('sha256', $userAgent . '|' . $ipAddress);
        }

        return hash('sha256', $userAgent . '|' . $this->normalizeIpForFingerprint($ipAddress));
    }

    private function logSessionValidation($status, array $context = []) {
        if (isset($GLOBALS['transactionLogger']) && $GLOBALS['transactionLogger'] instanceof TransactionLogger) {
            $GLOBALS['transactionLogger']->recordSessionValidation($status, $context);
            return;
        }

        logError('Session validation ' . $status, $context, $status === 'passed' ? 'info' : 'warning');
    }

    private function hydrateCurrentUser() {
        return [
            'id' => $_SESSION['user_id'],
            'name' => $_SESSION['user_name'] ?? 'Unknown',
            'role' => $_SESSION['user_role'] ?? 'technician',
            'email' => $_SESSION['user_email'] ?? '',
        ];
    }

    private function normalizeIpForFingerprint($ipAddress) {
        if (!is_string($ipAddress) || $ipAddress === '') {
            return 'unknown';
        }

        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ipAddress);
            $prefixBits = (int) ($this->config['security']['session_ip_prefix_v4'] ?? 24);
            $octetsToKeep = max(1, min(4, (int) ceil($prefixBits / 8)));
            return implode('.', array_slice($parts, 0, $octetsToKeep));
        }

        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packedIp = @inet_pton($ipAddress);
            if ($packedIp === false) {
                return $ipAddress;
            }

            $prefixBits = (int) ($this->config['security']['session_ip_prefix_v6'] ?? 64);
            $bytesToKeep = max(1, min(16, (int) ceil($prefixBits / 8)));
            $normalized = substr($packedIp, 0, $bytesToKeep) . str_repeat("\0", 16 - $bytesToKeep);
            $expanded = inet_ntop($normalized);
            return $expanded !== false ? $expanded : $ipAddress;
        }

        return $ipAddress;
    }
}

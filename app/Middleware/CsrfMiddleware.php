<?php
/**
 * CSRF Protection Middleware
 * 
 * Validates CSRF tokens on state-changing requests (POST, PUT, DELETE).
 */

class CsrfMiddleware {
    /**
     * Check if request method should be protected.
     */
    public static function needsProtection($method) {
        return in_array(strtoupper($method), ['POST', 'PUT', 'DELETE', 'PATCH']);
    }

    /**
     * Validate CSRF token for the current request.
     * Returns true if valid, false if missing or invalid.
     */
    public static function validate() {
        $method = $_SERVER['REQUEST_METHOD'];
        
        if (!self::needsProtection($method)) {
            return true;
        }
        
        // Get token from POST, GET, or X-CSRF-Token header
        $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        
        if (!$token) {
            return false;
        }
        
        if (!isset($_SESSION['csrf_token'])) {
            return false;
        }
        
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Require valid CSRF token; exit with 403 if invalid.
     */
    public static function require() {
        if (!self::validate()) {
            http_response_code(403);
            exit(json_encode(['error' => 'CSRF token mismatch']));
        }
    }
}

<?php
/**
 * CSRF Token Helper
 * 
 * Provides CSRF token generation and verification for state-changing requests.
 * CRITICAL: session_start() must be called before including this file.
 * 
 * Standards (lessons_learned):
 * - Apply CSRF protection to ALL state-changing requests (POST, PUT, DELETE, PATCH),
 *   including list delete buttons and hidden form operations.
 * - If token is not stored, something output before session_start() — check file line 1.
 */

if (!function_exists('generateCSRFToken')) {
    /**
     * Generate or retrieve CSRF token for the session.
     */
    function generateCSRFToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('getCSRFToken')) {
    /**
     * Get the CSRF token for display in forms.
     */
    function getCSRFToken() {
        return generateCSRFToken();
    }
}

if (!function_exists('verifyCSRFToken')) {
    /**
     * Verify CSRF token from POST/PUT/DELETE requests.
     * 
     * @param string $token Token to verify
     * @return bool True if valid, false otherwise
     */
    function verifyCSRFToken($token = null) {
        if ($token === null) {
            $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? null;
        }
        
        if (!$token || !isset($_SESSION['csrf_token'])) {
            return false;
        }
        
        return hash_equals($_SESSION['csrf_token'], $token);
    }
}

if (!function_exists('csrfTokenInput')) {
    /**
     * Output CSRF token as a hidden input field (HTML).
     */
    function csrfTokenInput() {
        return sprintf(
            '<input type="hidden" name="csrf_token" value="%s">',
            htmlspecialchars(getCSRFToken(), ENT_QUOTES, 'UTF-8')
        );
    }
}

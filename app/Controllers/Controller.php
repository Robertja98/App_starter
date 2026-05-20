<?php
/**
 * Base Controller Class
 * 
 * Provides common request/response handling, auth checks, CSRF validation.
 * All API controllers should extend this class.
 */

require_once __DIR__ . '/../Helpers/debug_helper.php';
require_once __DIR__ . '/../Helpers/csrf_helper.php';

class Controller {
    protected $config;
    protected $db;
    protected $auth;
    protected $apiKeyAuth;
    protected $transactionLogger;
    protected $currentUser = null;
    protected $currentApiKey = null;
    private $requestBody = null;
    
    public function __construct($config, Database $db, ?AuthMiddleware $auth = null, ?ApiKeyMiddleware $apiKeyAuth = null) {
        $this->config = $config;
        $this->db = $db;
        $this->auth = $auth;
        $this->apiKeyAuth = $apiKeyAuth;
        $this->transactionLogger = $GLOBALS['transactionLogger'] ?? null;

        if ($this->transactionLogger instanceof TransactionLogger) {
            $this->transactionLogger->startRequest([
                'controller' => static::class,
                'request_data' => $this->getRequestData(),
            ]);
        }

        if ($this->auth && $this->auth->isAuthenticated()) {
            $this->currentUser = $this->auth->getValidatedUser();
        }

        // Log incoming request
        $this->logRequest();
    }

    /**
     * Log incoming request for diagnostics.
     */
    protected function logRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'])) {
            logPostArrival(static::class, [
                'method' => $method,
                'body_keys' => array_keys($this->getRequestData()),
            ]);
        }
    }

    /**
     * Controller-local wrapper for debug logging.
     */
    protected function logPostArrival($handler, $extraData = []) {
        logPostArrival($handler, $extraData);
    }

    /**
     * Controller-local wrapper for error logging.
     */
    protected function logError($message, $context = [], $level = 'error') {
        logError($message, is_array($context) ? $context : ['detail' => $context], $level);
    }

    /**
     * Require authentication.
     * Exits with 401 if not authenticated.
     */
    protected function requireAuth() {
        if (!$this->auth || !$this->auth->isAuthenticated()) {
            if ($this->transactionLogger instanceof TransactionLogger) {
                $this->transactionLogger->logSecurityEvent('auth_required_failed', ['controller' => static::class]);
            }
            $this->unauthorized('Authentication required');
        }

        $this->currentUser = $this->auth->getValidatedUser();

        if ($this->transactionLogger instanceof TransactionLogger) {
            $this->transactionLogger->setActorContext('session', $this->currentUser, null);
        }
    }

    /**
     * Require a valid API key with the requested scopes.
     */
    protected function requireApiKey(array $scopes = []) {
        if (!$this->apiKeyAuth) {
            $this->forbidden('API key authentication is unavailable');
        }

        $record = $this->apiKeyAuth->authenticate($scopes);
        if (!$record) {
            $failureReason = method_exists($this->apiKeyAuth, 'getLastFailureReason')
                ? $this->apiKeyAuth->getLastFailureReason()
                : null;

            if ($this->transactionLogger instanceof TransactionLogger) {
                $this->transactionLogger->logSecurityEvent('api_key_required_failed', [
                    'controller' => static::class,
                    'scopes' => $scopes,
                    'reason' => $failureReason,
                ]);
            }

            if ($failureReason === 'insufficient_scope') {
                $this->forbidden('API key lacks required scope');
            }

            $this->error('Valid API key required', 401);
        }

        $this->currentApiKey = $record;
        if ($this->transactionLogger instanceof TransactionLogger) {
            $this->transactionLogger->setActorContext('api_key', null, $record);
        }
        return $record;
    }

    /**
     * Allow either browser session auth or a scoped API key.
     */
    protected function requireAuthOrApiKey(array $scopes = []) {
        if ($this->auth && $this->auth->isAuthenticated()) {
            $this->currentUser = $this->auth->getValidatedUser();
            if ($this->transactionLogger instanceof TransactionLogger) {
                $this->transactionLogger->setActorContext('session', $this->currentUser, null);
            }
            return 'session';
        }

        $this->requireApiKey($scopes);
        return 'api_key';
    }

    /**
     * Require a specific role.
     * Exits with 403 if user doesn't have the role.
     */
    protected function requireRole($role) {
        $this->requireAuth();
        
        if (!$this->auth->hasRole($role)) {
            $this->forbidden("Role '{$role}' required");
        }
    }

    /**
     * Require any of the given roles.
     */
    protected function requireAnyRole($roles) {
        $this->requireAuth();
        
        if (!$this->auth->hasAnyRole($roles)) {
            $this->forbidden("One of these roles required: " . implode(', ', $roles));
        }
    }

    /**
     * Require valid CSRF token for state-changing requests.
     * Exits with 403 if invalid.
     */
    protected function requireCsrf() {
        if (!verifyCSRFToken($this->getPost('csrf_token'))) {
            $this->forbidden('Invalid CSRF token');
        }
    }

    /**
     * Expose the session CSRF token for API clients.
     */
    public function csrfToken() {
        $this->success([
            'csrf_token' => getCSRFToken(),
            'authenticated' => $this->auth ? $this->auth->isAuthenticated() : false,
        ]);
    }

    /**
     * Get the current authenticated user.
     * Returns null if not authenticated.
     */
    protected function getUser() {
        return $this->currentUser;
    }

    protected function getCurrentApiKey() {
        return $this->currentApiKey;
    }

    /**
     * Get user ID or null.
     */
    protected function getUserId() {
        return $this->currentUser ? $this->currentUser['id'] : null;
    }

    /**
     * Get user role or null.
     */
    protected function getUserRole() {
        return $this->currentUser ? $this->currentUser['role'] : null;
    }

    /**
     * Send success JSON response.
     */
    protected function success($data = null, $code = 200) {
        if ($this->transactionLogger instanceof TransactionLogger) {
            $this->transactionLogger->finalizeRequest($code, [
                'response_summary' => $this->summarizePayload($data),
            ], 'info');
        }

        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success',
            'data' => $data,
        ]);
        exit;
    }

    /**
     * Send error JSON response.
     */
    protected function error($message, $code = 400, $errors = null) {
        if ($errors && $this->transactionLogger instanceof TransactionLogger) {
            $this->transactionLogger->recordValidationFailure((array)$errors);
        }

        if ($this->transactionLogger instanceof TransactionLogger) {
            $this->transactionLogger->finalizeRequest($code, [
                'error_message' => $message,
                'response_summary' => $this->summarizePayload($errors),
            ], $code >= 500 ? 'error' : 'warning');
        }

        http_response_code($code);
        header('Content-Type: application/json');
        
        $response = [
            'status' => 'error',
            'message' => $message,
        ];
        
        if ($errors !== null) {
            $response['errors'] = $errors;
        }
        
        echo json_encode($response);
        exit;
    }

    /**
     * Send 400 Bad Request response.
     */
    protected function badRequest($message, $errors = null) {
        $this->error($message, 400, $errors);
    }

    /**
     * Send 401 Unauthorized response.
     */
    protected function unauthorized($message = 'Unauthorized') {
        $this->error($message, 401);
    }

    /**
     * Send 403 Forbidden response.
     */
    protected function forbidden($message = 'Forbidden') {
        $this->error($message, 403);
    }

    /**
     * Send 404 Not Found response.
     */
    protected function notFound($message = 'Not found') {
        $this->error($message, 404);
    }

    /**
     * Send 422 Unprocessable Entity response (validation errors).
     */
    protected function unprocessable($message, $errors = []) {
        $this->error($message, 422, $errors);
    }

    /**
     * Send 500 Internal Server Error response.
     */
    protected function internalError($message = 'Internal server error') {
        logError($message);
        $this->error($message, 500);
    }

    /**
     * Get JSON request body.
     */
    protected function getJsonBody() {
        if ($this->requestBody !== null) {
            return $this->requestBody;
        }

        $json = file_get_contents('php://input');
        $decoded = json_decode($json, true);
        $this->requestBody = is_array($decoded) ? $decoded : [];
        return $this->requestBody;
    }

    /**
     * Get normalized request data from form posts or JSON payloads.
     */
    protected function getRequestData() {
        if (!empty($_POST)) {
            return $_POST;
        }

        return $this->getJsonBody();
    }

    /**
     * Get POST parameter with optional default.
     */
    protected function getPost($key, $default = null) {
        $data = $this->getRequestData();
        return $data[$key] ?? $default;
    }

    /**
     * Get query parameter with optional default.
     */
    protected function getQuery($key, $default = null) {
        return $_GET[$key] ?? $default;
    }

    /**
     * Redirect to a URL.
     */
    protected function redirect($url) {
        header("Location: $url");
        exit;
    }

    /**
     * Log an application event.
     */
    protected function logEvent($message, $context = []) {
        logError($message, $context, 'info');
    }

    protected function auditAction($action, $entityType, $entityId = null, $oldValues = null, $newValues = null, $context = []) {
        if ($this->transactionLogger instanceof TransactionLogger) {
            $this->transactionLogger->logAudit($action, $entityType, $entityId, $oldValues, $newValues, $context);
        }
    }

    private function summarizePayload($payload) {
        if ($payload === null) {
            return null;
        }

        if (is_array($payload)) {
            return [
                'keys' => array_keys($payload),
                'count' => count($payload),
            ];
        }

        if (is_object($payload)) {
            return [
                'type' => get_class($payload),
            ];
        }

        return ['type' => gettype($payload)];
    }
}

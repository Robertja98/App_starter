<?php
/**
 * Central transaction logger for request validation, error handling, and audit learning.
 */

class TransactionLogger {
    private $db;
    private $config;
    private $logFile;
    private $maxLogDepth;
    private $maxLogArrayItems;
    private $maxLogStringLength;
    private $requestId;
    private $startedAt;
    private $requestContext = [];
    private $finalized = false;
    private $dbRequestLoggingEnabled;
    private $dbAuditLoggingEnabled;

    public function __construct(Database $db, array $config) {
        $this->db = $db;
        $this->config = $config;
        $this->logFile = __DIR__ . '/../../transaction_log.txt';
        $this->dbRequestLoggingEnabled = (bool)($config['observability']['db_request_logging'] ?? true);
        $this->dbAuditLoggingEnabled = (bool)($config['observability']['db_audit_logging'] ?? true);
        $this->maxLogDepth = max(1, (int)($config['observability']['max_log_depth'] ?? 4));
        $this->maxLogArrayItems = max(1, (int)($config['observability']['max_log_array_items'] ?? 25));
        $this->maxLogStringLength = max(32, (int)($config['observability']['max_log_string_length'] ?? 500));
    }

    public function startRequest(array $context = []) {
        if ($this->startedAt !== null) {
            return $this->requestId;
        }

        $this->requestId = bin2hex(random_bytes(8));
        $this->startedAt = microtime(true);
        $this->requestContext = [
            'request_id' => $this->requestId,
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'path' => $_SERVER['REQUEST_URI'] ?? 'cli',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'session_id' => session_id() ?: null,
            'controller' => $context['controller'] ?? null,
            'request_payload' => $this->sanitize($context['request_data'] ?? []),
            'auth_mode' => 'anonymous',
            'is_authenticated' => false,
            'validation' => [],
        ];

        return $this->requestId;
    }

    public function setActorContext(string $authMode, ?array $user = null, ?array $apiKey = null) {
        $this->requestContext['auth_mode'] = $authMode;
        $this->requestContext['is_authenticated'] = $authMode !== 'anonymous';
        $this->requestContext['user_id'] = $user['id'] ?? null;
        $this->requestContext['api_key_id'] = $apiKey['id'] ?? null;
        $this->requestContext['api_key_name'] = $apiKey['name'] ?? null;
    }

    public function recordSessionValidation(string $status, array $context = []) {
        $entry = [
            'request_id' => $this->requestId,
            'status' => $status,
            'context' => $this->sanitize($context),
        ];
        $this->writeFileLog('session_validation', $entry, $status === 'passed' ? 'info' : 'warning');
    }

    public function recordValidationFailure(array $errors) {
        $this->requestContext['validation'] = $this->sanitize($errors);
    }

    public function logSecurityEvent(string $event, array $context = [], string $level = 'warning') {
        $entry = [
            'request_id' => $this->requestId,
            'event' => $event,
            'context' => $this->sanitize($context),
        ];
        $this->writeFileLog('security', $entry, $level);
    }

    public function logAudit(string $action, string $entityType, $entityId = null, $oldValues = null, $newValues = null, array $context = []) {
        if (!$this->dbAuditLoggingEnabled) {
            return;
        }

        $payload = [
            'request_id' => $this->requestId,
            'context' => $this->sanitize($context),
            'new_values' => $this->sanitize($newValues),
        ];

        $this->db->insert('audit_log', [
            'user_id' => $this->requestContext['user_id'] ?? null,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'old_values' => $oldValues !== null ? json_encode($this->sanitize($oldValues)) : null,
            'new_values' => json_encode($payload),
            'ip_address' => $this->requestContext['ip_address'] ?? null,
        ]);
    }

    public function finalizeRequest(int $statusCode, array $extra = [], string $level = 'info') {
        if ($this->finalized) {
            return;
        }

        $durationMs = $this->startedAt !== null ? (int) round((microtime(true) - $this->startedAt) * 1000) : null;
        $entry = array_merge($this->requestContext, [
            'status_code' => $statusCode,
            'duration_ms' => $durationMs,
        ], $this->sanitize($extra));

        $this->writeFileLog('request', $entry, $level);
        $this->persistTransaction($entry);
        $this->finalized = true;
    }

    private function persistTransaction(array $entry) {
        if (!$this->dbRequestLoggingEnabled) {
            return;
        }

        $this->db->insert('transaction_logs', [
            'request_id' => $entry['request_id'] ?? null,
            'session_id' => $entry['session_id'] ?? null,
            'user_id' => $entry['user_id'] ?? null,
            'api_key_id' => $entry['api_key_id'] ?? null,
            'method' => $entry['method'] ?? null,
            'path' => $entry['path'] ?? null,
            'controller' => $entry['controller'] ?? null,
            'auth_mode' => $entry['auth_mode'] ?? 'anonymous',
            'is_authenticated' => !empty($entry['is_authenticated']) ? 1 : 0,
            'status_code' => $entry['status_code'] ?? null,
            'duration_ms' => $entry['duration_ms'] ?? null,
            'validation_errors' => !empty($entry['validation']) ? json_encode($entry['validation']) : null,
            'request_payload' => !empty($entry['request_payload']) ? json_encode($entry['request_payload']) : null,
            'response_summary' => !empty($entry['response_summary']) ? json_encode($entry['response_summary']) : null,
            'error_message' => $entry['error_message'] ?? null,
            'ip_address' => $entry['ip_address'] ?? null,
            'user_agent' => $entry['user_agent'] ?? null,
        ]);
    }

    private function writeFileLog(string $type, array $entry, string $level) {
        $line = json_encode([
            'timestamp' => gmdate('c'),
            'type' => $type,
            'level' => $level,
            'entry' => $entry,
        ], JSON_UNESCAPED_SLASHES);

        file_put_contents($this->logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function sanitize($value, int $depth = 0) {
        $sensitiveKeys = [
            'password', 'password_hash', 'csrf_token', 'api_key', 'x-api-key', 'authorization',
            'encryption_key', 'key_hash', 'smtp_password', 'mail_password'
        ];

        if ($depth >= $this->maxLogDepth) {
            return '[TRUNCATED_DEPTH]';
        }

        if (is_array($value)) {
            $clean = [];
            $index = 0;
            foreach ($value as $key => $item) {
                if ($index >= $this->maxLogArrayItems) {
                    $clean['__truncated__'] = 'additional items omitted';
                    break;
                }

                $normalizedKey = is_string($key) ? strtolower($key) : $key;
                if (is_string($normalizedKey) && in_array($normalizedKey, $sensitiveKeys, true)) {
                    $clean[$key] = '[REDACTED]';
                } else {
                    $clean[$key] = $this->sanitize($item, $depth + 1);
                }
                $index++;
            }
            return $clean;
        }

        if (is_object($value)) {
            return $this->sanitize((array) $value, $depth + 1);
        }

        if (is_string($value) && strlen($value) > $this->maxLogStringLength) {
            return substr($value, 0, $this->maxLogStringLength) . '...[TRUNCATED]';
        }

        return $value;
    }
}
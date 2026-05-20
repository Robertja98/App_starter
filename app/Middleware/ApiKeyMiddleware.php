<?php
/**
 * API key middleware for non-browser clients.
 */

class ApiKeyMiddleware {
    private $config;
    private $db;
    private $currentKey = null;
    private $lastFailureReason = null;

    public function __construct($config, Database $db) {
        $this->config = $config;
        $this->db = $db;
    }

    public function authenticate(array $requiredScopes = []) {
        $this->lastFailureReason = null;
        $apiKey = $this->extractApiKey();
        if (!$apiKey) {
            $this->lastFailureReason = 'missing_api_key';
            return null;
        }

        $model = new ApiKey($this->db);
        $hash = hashApiKeyValue($apiKey, $this->config);
        $record = $model->findActiveByHash($hash);
        if (!$record) {
            $this->lastFailureReason = 'invalid_or_expired_key';
            return null;
        }

        $grantedScopes = $model->getScopes($record);
        foreach ($requiredScopes as $scope) {
            if (!in_array($scope, $grantedScopes, true)) {
                $this->lastFailureReason = 'insufficient_scope';
                return null;
            }
        }

        $model->touchUsage($record['id']);
        $record['granted_scopes'] = $grantedScopes;
        $this->currentKey = $record;
        return $record;
    }

    public function getLastFailureReason() {
        return $this->lastFailureReason;
    }

    public function getCurrentKey() {
        return $this->currentKey;
    }

    private function extractApiKey() {
        $headerName = strtoupper(str_replace('-', '_', $this->config['security']['api_key_header'] ?? 'X-API-Key'));
        $direct = $_SERVER['HTTP_' . $headerName] ?? null;
        if (is_string($direct) && trim($direct) !== '') {
            return trim($direct);
        }

        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^ApiKey\s+(.+)$/i', $authHeader, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }
}
<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Helpers/security_helper.php';

/**
 * Replace this profile first when adapting the starter to a new application.
 * Keep the smoke harness and validation patterns; swap the domain fixtures.
 */
function smokeTemplateProfile(): array
{
    return [
        'admin_email' => getenv('SMOKE_ADMIN_EMAIL') ?: 'admin@example.com',
        'admin_password' => getenv('SMOKE_ADMIN_PASSWORD') ?: 'password123',
        'site' => [
            'name' => 'Backend Smoke Site',
            'updated_name' => 'Backend Smoke Site Updated',
            'address_line1' => '123 Validation Lane',
            'city' => 'Toronto',
            'province' => 'ON',
            'postal_code' => 'M5V3A8',
            'contact_person' => 'Smoke Tester',
            'updated_contact_person' => 'Updated Tester',
            'contact_phone' => '416-555-0101',
        ],
        'customer' => [
            'name_prefix' => 'Backend Smoke Customer',
            'city' => 'Toronto',
            'province' => 'ON',
            'postal_code' => 'M5V3A8',
            'country' => 'CA',
            'phone' => '416-555-0100',
        ],
        'equipment' => [
            'type' => 'tank',
            'model' => 'Backend Smoke Tank',
            'capacity_liters' => 1000,
            'serial_prefix' => 'SMOKE-',
        ],
        'measurement' => [
            'type' => 'pH',
            'value' => 7.2,
            'unit' => 'pH',
            'status' => 'normal',
        ],
        'consumable' => [
            'name' => 'Filter Cartridge',
            'quantity_used' => 1,
            'unit' => 'unit',
            'reason' => 'Routine replacement',
        ],
        'repair' => [
            'issue_description' => 'Pump seal is leaking',
            'recommendation' => 'Replace the pump seal during the next visit',
            'priority' => 'high',
            'estimated_cost' => 500.00,
            'status' => 'recommended',
            'updated_recommendation' => 'Approved for immediate replacement',
        ],
        'sync_repair' => [
            'issue' => 'Offline pump leak',
            'notes' => 'Replace seal during next maintenance window',
            'priority' => 'high',
            'status' => 'recommended',
        ],
        'media' => [
            'sync_filename' => 'offline-photo.jpg',
            'upload_filename' => 'smoke.png',
            'missing_csrf_filename' => 'missing-csrf.png',
            'invalid_filename' => 'invalid.txt',
        ],
    ];
}

function loadConfig(string $repoRoot): array
{
    $config = (static function ($file) {
        return require $file;
    })($repoRoot . '/config/app.php');

    $localConfigPath = $repoRoot . '/config/app.local.php';
    if (file_exists($localConfigPath)) {
        $localConfig = (static function ($file) {
            return require $file;
        })($localConfigPath);
        $config = array_replace_recursive($config, $localConfig);
    }

    return $config;
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function openDatabase(array $config): mysqli
{
    $db = new mysqli(
        $config['db']['host'],
        $config['db']['user'],
        $config['db']['password'],
        $config['db']['database']
    );

    if ($db->connect_error) {
        throw new RuntimeException('Database connection failed: ' . $db->connect_error);
    }

    $db->set_charset($config['db']['charset'] ?? 'utf8mb4');
    return $db;
}

function fetchTransactionCount(mysqli $db): int
{
    $result = $db->query('SELECT COUNT(*) AS cnt FROM transaction_logs');
    if (!$result) {
        throw new RuntimeException('Failed to count transaction logs: ' . $db->error);
    }

    $row = $result->fetch_assoc();
    return (int) ($row['cnt'] ?? 0);
}

function fetchAuditCount(mysqli $db): int
{
    $result = $db->query('SELECT COUNT(*) AS cnt FROM audit_log');
    if (!$result) {
        throw new RuntimeException('Failed to count audit logs: ' . $db->error);
    }

    $row = $result->fetch_assoc();
    return (int) ($row['cnt'] ?? 0);
}

function auditEntryExists(mysqli $db, int $minimumId, string $action, string $entityType, ?int $entityId = null): bool
{
    if ($entityId === null) {
        $stmt = $db->prepare('SELECT id FROM audit_log WHERE id > ? AND action = ? AND entity_type = ? ORDER BY id DESC LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('Failed to prepare audit existence query: ' . $db->error);
        }

        $stmt->bind_param('iss', $minimumId, $action, $entityType);
    } else {
        $stmt = $db->prepare('SELECT id FROM audit_log WHERE id > ? AND action = ? AND entity_type = ? AND entity_id = ? ORDER BY id DESC LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('Failed to prepare audit existence query: ' . $db->error);
        }

        $stmt->bind_param('issi', $minimumId, $action, $entityType, $entityId);
    }

    if (!$stmt->execute()) {
        throw new RuntimeException('Failed to execute audit existence query: ' . $stmt->error);
    }

    $result = $stmt->get_result();
    return $result !== false && $result->num_rows > 0;
}

function createSmokeApiKey(mysqli $db, array $config, array $scopes = ['sync:write']): array
{
    $plainTextKey = generateApiKeyPlaintext($config);
    $keyPrefix = substr($plainTextKey, 0, 15);
    $keyHash = hashApiKeyValue($plainTextKey, $config);
    $name = 'backend-smoke-' . bin2hex(random_bytes(4));
    $scopesJson = json_encode(array_values($scopes));

    $stmt = $db->prepare('INSERT INTO api_keys (name, key_prefix, key_hash, scopes, is_active, created_by_user_id) VALUES (?, ?, ?, ?, 1, 1)');
    if (!$stmt) {
        throw new RuntimeException('Failed to prepare API key insert: ' . $db->error);
    }

    $stmt->bind_param('ssss', $name, $keyPrefix, $keyHash, $scopesJson);
    if (!$stmt->execute()) {
        throw new RuntimeException('Failed to insert smoke API key: ' . $stmt->error);
    }

    return [
        'id' => $db->insert_id,
        'api_key' => $plainTextKey,
    ];
}

function createSmokeCustomer(mysqli $db, array $profile): int
{
    $name = $profile['customer']['name_prefix'] . ' ' . bin2hex(random_bytes(4));
    $email = 'smoke+' . bin2hex(random_bytes(3)) . '@example.com';
    $phone = $profile['customer']['phone'];
    $city = $profile['customer']['city'];
    $province = $profile['customer']['province'];
    $postalCode = $profile['customer']['postal_code'];
    $country = $profile['customer']['country'];

    $stmt = $db->prepare('INSERT INTO customers (name, contact_email, contact_phone, city, province, postal_code, country, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)');
    if (!$stmt) {
        throw new RuntimeException('Failed to prepare customer insert: ' . $db->error);
    }

    $stmt->bind_param('sssssss', $name, $email, $phone, $city, $province, $postalCode, $country);
    if (!$stmt->execute()) {
        throw new RuntimeException('Failed to insert smoke customer: ' . $stmt->error);
    }

    return (int) $db->insert_id;
}

function createSmokeEquipment(mysqli $db, int $siteId, array $profile): int
{
    $equipmentType = $profile['equipment']['type'];
    $model = $profile['equipment']['model'];
    $serialNumber = $profile['equipment']['serial_prefix'] . strtoupper(bin2hex(random_bytes(4)));
    $capacityLiters = (int) $profile['equipment']['capacity_liters'];

    $stmt = $db->prepare('INSERT INTO equipment (site_id, equipment_type, model, serial_number, capacity_liters, is_active) VALUES (?, ?, ?, ?, ?, 1)');
    if (!$stmt) {
        throw new RuntimeException('Failed to prepare equipment insert: ' . $db->error);
    }

    $stmt->bind_param('isssi', $siteId, $equipmentType, $model, $serialNumber, $capacityLiters);
    if (!$stmt->execute()) {
        throw new RuntimeException('Failed to insert smoke equipment: ' . $stmt->error);
    }

    return (int) $db->insert_id;
}

function deleteSmokeApiKey(mysqli $db, ?int $id): void
{
    if (!$id) {
        return;
    }

    $stmt = $db->prepare('DELETE FROM api_keys WHERE id = ?');
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();
}

function deleteSmokeCustomer(mysqli $db, ?int $id): void
{
    if (!$id) {
        return;
    }

    $stmt = $db->prepare('DELETE FROM customers WHERE id = ?');
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();
}

function extractCookies(array $headers, array &$cookieJar): void
{
    foreach ($headers as $header) {
        if (stripos($header, 'Set-Cookie:') !== 0) {
            continue;
        }

        $cookie = trim(substr($header, strlen('Set-Cookie:')));
        $segments = explode(';', $cookie);
        $nameValue = trim($segments[0]);
        if ($nameValue === '' || strpos($nameValue, '=') === false) {
            continue;
        }

        [$name, $value] = explode('=', $nameValue, 2);
        $cookieJar[$name] = $value;
    }
}

function buildCookieHeader(array $cookieJar): string
{
    $parts = [];
    foreach ($cookieJar as $name => $value) {
        $parts[] = $name . '=' . $value;
    }

    return implode('; ', $parts);
}

function request(string $method, string $url, ?array $jsonBody = null, array &$cookieJar = [], array $extraHeaders = []): array
{
    $headers = array_merge([
        'Accept: application/json',
        'User-Agent: ServiceAppBackendSmoke/1.0',
    ], $extraHeaders);

    if (!empty($cookieJar)) {
        $headers[] = 'Cookie: ' . buildCookieHeader($cookieJar);
    }

    $body = null;
    if ($jsonBody !== null) {
        $body = json_encode($jsonBody);
        if ($body === false) {
            throw new RuntimeException('Failed to encode JSON request body');
        }
        $headers[] = 'Content-Type: application/json';
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 15,
        ],
    ]);

    $responseBody = @file_get_contents($url, false, $context);
    if ($responseBody === false && empty($http_response_header)) {
        throw new RuntimeException('HTTP request failed: ' . $method . ' ' . $url);
    }

    $responseHeaders = $http_response_header ?? [];
    extractCookies($responseHeaders, $cookieJar);

    $statusLine = $responseHeaders[0] ?? 'HTTP/1.1 500';
    preg_match('/\s(\d{3})\s/', $statusLine, $matches);
    $statusCode = isset($matches[1]) ? (int) $matches[1] : 500;

    $decoded = json_decode((string) $responseBody, true);
    return [
        'status' => $statusCode,
        'body' => $decoded,
        'raw' => (string) $responseBody,
        'headers' => $responseHeaders,
    ];
}

function requestMultipart(string $url, array $fields, array $files, array &$cookieJar = [], array $extraHeaders = []): array
{
    $boundary = '--------------------------' . bin2hex(random_bytes(12));
    $headers = array_merge([
        'Accept: application/json',
        'User-Agent: ServiceAppBackendSmoke/1.0',
        'Content-Type: multipart/form-data; boundary=' . $boundary,
    ], $extraHeaders);

    if (!empty($cookieJar)) {
        $headers[] = 'Cookie: ' . buildCookieHeader($cookieJar);
    }

    $body = '';
    foreach ($fields as $name => $value) {
        $body .= "--{$boundary}\r\n";
        $body .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
        $body .= (string) $value . "\r\n";
    }

    foreach ($files as $name => $file) {
        $body .= "--{$boundary}\r\n";
        $body .= 'Content-Disposition: form-data; name="' . $name . '"; filename="' . $file['filename'] . '"' . "\r\n";
        $body .= 'Content-Type: ' . ($file['content_type'] ?? 'application/octet-stream') . "\r\n\r\n";
        $body .= $file['content'] . "\r\n";
    }

    $body .= "--{$boundary}--\r\n";

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 15,
        ],
    ]);

    $responseBody = @file_get_contents($url, false, $context);
    if ($responseBody === false && empty($http_response_header)) {
        throw new RuntimeException('HTTP multipart request failed: POST ' . $url);
    }

    $responseHeaders = $http_response_header ?? [];
    extractCookies($responseHeaders, $cookieJar);

    $statusLine = $responseHeaders[0] ?? 'HTTP/1.1 500';
    preg_match('/\s(\d{3})\s/', $statusLine, $matches);
    $statusCode = isset($matches[1]) ? (int) $matches[1] : 500;

    $decoded = json_decode((string) $responseBody, true);
    return [
        'status' => $statusCode,
        'body' => $decoded,
        'raw' => (string) $responseBody,
        'headers' => $responseHeaders,
    ];
}

function runSmokeSuite(string $repoRoot, string $baseUrl): void
{
    $config = loadConfig($repoRoot);
    $profile = smokeTemplateProfile();
    $db = openDatabase($config);
    $beforeCount = fetchTransactionCount($db);
    $beforeAuditCount = fetchAuditCount($db);
    $cookieJar = [];
    $smokeApiKeyId = null;
    $wrongScopeApiKeyId = null;
    $smokeCustomerId = null;
    $smokeEquipmentId = null;

    echo "Running backend smoke tests against {$baseUrl}\n";

    try {
        $smokeApiKey = createSmokeApiKey($db, $config);
        $smokeApiKeyId = (int) $smokeApiKey['id'];
        $wrongScopeApiKey = createSmokeApiKey($db, $config, ['media:write']);
        $wrongScopeApiKeyId = (int) $wrongScopeApiKey['id'];
        $smokeCustomerId = createSmokeCustomer($db, $profile);

        $csrfResponse = request('GET', $baseUrl . '/api/auth/csrf', null, $cookieJar);
        assertTrue($csrfResponse['status'] === 200, 'CSRF endpoint did not return 200');
        $csrfToken = $csrfResponse['body']['data']['csrf_token'] ?? null;
        assertTrue(is_string($csrfToken) && $csrfToken !== '', 'CSRF token missing from response');
        assertTrue(isset($cookieJar[$config['session']['name'] ?? 'service_app_session']), 'Session cookie missing after CSRF bootstrap');
        echo "[PASS] CSRF bootstrap\n";

        $loginResponse = request('POST', $baseUrl . '/api/auth/login', [
            'csrf_token' => $csrfToken,
            'email' => $profile['admin_email'],
            'password' => $profile['admin_password'],
        ], $cookieJar);
        assertTrue($loginResponse['status'] === 200, 'Login did not return 200');
        assertTrue(($loginResponse['body']['status'] ?? null) === 'success', 'Login did not report success');
        echo "[PASS] Login\n";

        $userResponse = request('GET', $baseUrl . '/api/auth/user', null, $cookieJar);
        assertTrue($userResponse['status'] === 200, 'Authenticated user lookup did not return 200');
        assertTrue(($userResponse['body']['data']['user']['email'] ?? null) === $profile['admin_email'], 'Authenticated user lookup returned unexpected payload');
        echo "[PASS] Authenticated user lookup\n";

        $siteResponse = request('POST', $baseUrl . '/api/sites', [
            'csrf_token' => $csrfToken,
            'customer_id' => $smokeCustomerId,
            'site_name' => $profile['site']['name'],
            'address_line1' => $profile['site']['address_line1'],
            'city' => $profile['site']['city'],
            'province' => $profile['site']['province'],
            'postal_code' => $profile['site']['postal_code'],
            'contact_person' => $profile['site']['contact_person'],
            'contact_phone' => $profile['site']['contact_phone'],
        ], $cookieJar);
        assertTrue($siteResponse['status'] === 201, 'Site create with CSRF should return 201');
        $siteId = $siteResponse['body']['data']['id'] ?? null;
        assertTrue(is_numeric($siteId), 'Site create did not return an id');
        $smokeEquipmentId = createSmokeEquipment($db, (int) $siteId, $profile);
        echo "[PASS] Site create succeeds with CSRF\n";

        $siteUpdateResponse = request('PUT', $baseUrl . '/api/sites/' . (int) $siteId, [
            'csrf_token' => $csrfToken,
            'site_name' => $profile['site']['updated_name'],
            'contact_person' => $profile['site']['updated_contact_person'],
        ], $cookieJar);
        assertTrue($siteUpdateResponse['status'] === 200, 'Site update with CSRF should return 200');
        assertTrue(($siteUpdateResponse['body']['data']['site_name'] ?? null) === $profile['site']['updated_name'], 'Site update did not persist the new name');
        assertTrue(($siteUpdateResponse['body']['data']['contact_person'] ?? null) === $profile['site']['updated_contact_person'], 'Site update did not persist the new contact person');
        echo "[PASS] Site update succeeds with CSRF\n";

        $visitResponse = request('POST', $baseUrl . '/api/visits', [
            'site_id' => (int) $siteId,
            'technician_id' => 1,
            'visit_status' => 'scheduled',
            'visit_date' => date('Y-m-d'),
            'idempotency_key' => 'backend-smoke-no-csrf',
        ], $cookieJar);
        assertTrue($visitResponse['status'] === 403, 'Visit create without CSRF should return 403');
        assertTrue(($visitResponse['body']['message'] ?? null) === 'Invalid CSRF token', 'Visit create without CSRF returned the wrong error');
        echo "[PASS] Visit create rejects missing CSRF\n";

        $anonymousCookies = [];
        $syncResponse = request('POST', $baseUrl . '/api/sync', ['queue' => []], $anonymousCookies);
        assertTrue($syncResponse['status'] === 401, 'Anonymous sync request should return 401');
        assertTrue(($syncResponse['body']['message'] ?? null) === 'Valid API key required', 'Anonymous sync request returned the wrong error');
        echo "[PASS] Sync endpoint rejects missing auth\n";

        $sessionSyncMissingCsrfResponse = request('POST', $baseUrl . '/api/sync', ['queue' => []], $cookieJar);
        assertTrue($sessionSyncMissingCsrfResponse['status'] === 403, 'Session sync without CSRF should return 403');
        assertTrue(($sessionSyncMissingCsrfResponse['body']['message'] ?? null) === 'Invalid CSRF token', 'Session sync without CSRF returned the wrong error');

        $sessionSyncWithCsrfResponse = request('POST', $baseUrl . '/api/sync', [
            'csrf_token' => $csrfToken,
            'queue' => [],
        ], $cookieJar);
        assertTrue($sessionSyncWithCsrfResponse['status'] === 400, 'Session sync with CSRF should reach queue validation and return 400');
        assertTrue(($sessionSyncWithCsrfResponse['body']['message'] ?? null) === 'Invalid or empty queue', 'Session sync with CSRF returned the wrong validation error');
        echo "[PASS] Session-auth sync enforces CSRF\n";

        $wrongScopeSyncResponse = request(
            'POST',
            $baseUrl . '/api/sync',
            ['queue' => []],
            $anonymousCookies,
            ['X-API-Key: ' . $wrongScopeApiKey['api_key']]
        );
        assertTrue($wrongScopeSyncResponse['status'] === 401, 'Wrong-scope API key should be rejected with 401');
        assertTrue(($wrongScopeSyncResponse['body']['message'] ?? null) === 'Valid API key required', 'Wrong-scope API key returned the wrong error');
        echo "[PASS] Sync endpoint rejects wrong-scope API key\n";

        $apiKeySyncResponse = request(
            'POST',
            $baseUrl . '/api/sync',
            ['queue' => []],
            $anonymousCookies,
            ['X-API-Key: ' . $smokeApiKey['api_key']]
        );
        assertTrue($apiKeySyncResponse['status'] === 400, 'API-key sync request should reach queue validation and return 400');
        assertTrue(($apiKeySyncResponse['body']['message'] ?? null) === 'Invalid or empty queue', 'API-key sync request returned the wrong validation error');
        echo "[PASS] Sync endpoint accepts scoped API key\n";

        $syncIdempotencyKey = 'backend-smoke-sync-' . bin2hex(random_bytes(4));
        $syncQueue = [
            'queue' => [[
                'type' => 'visit',
                'action' => 'create',
                'data' => [
                    'site_id' => (int) $siteId,
                    'technician_id' => 1,
                    'visit_status' => 'scheduled',
                    'visit_date' => date('Y-m-d'),
                    'visit_notes' => 'Smoke sync visit',
                ],
                'idempotency_key' => $syncIdempotencyKey,
                'timestamp' => time(),
            ]],
        ];

        $syncCreateResponse = request(
            'POST',
            $baseUrl . '/api/sync',
            $syncQueue,
            $anonymousCookies,
            ['X-API-Key: ' . $smokeApiKey['api_key']]
        );
        assertTrue($syncCreateResponse['status'] === 200, 'API-key sync create request should return 200');
        $syncVisitResult = $syncCreateResponse['body']['data']['results'][0] ?? [];
        assertTrue(($syncVisitResult['status'] ?? null) === 'success', 'API-key sync create did not report success');
        $syncedVisitId = $syncVisitResult['id'] ?? null;
        assertTrue(is_numeric($syncedVisitId), 'API-key sync create did not return a visit id');
        echo "[PASS] Sync creates visit with API key\n";

        $syncDuplicateResponse = request(
            'POST',
            $baseUrl . '/api/sync',
            $syncQueue,
            $anonymousCookies,
            ['X-API-Key: ' . $smokeApiKey['api_key']]
        );
        assertTrue($syncDuplicateResponse['status'] === 200, 'Duplicate sync request should return 200');
        assertTrue(($syncDuplicateResponse['body']['data']['results'][0]['status'] ?? null) === 'duplicate', 'Duplicate sync request did not report duplicate');
        echo "[PASS] Sync duplicate idempotency is enforced\n";

        $syncMeasurementKey = 'backend-smoke-sync-measurement-' . bin2hex(random_bytes(4));
        $syncConsumableKey = 'backend-smoke-sync-consumable-' . bin2hex(random_bytes(4));
        $auditBeforeMeasurementAndConsumableSync = fetchAuditCount($db);

        $syncMeasurementAndConsumableResponse = request(
            'POST',
            $baseUrl . '/api/sync',
            [
                'queue' => [
                    [
                        'type' => 'measurement',
                        'action' => 'create',
                        'data' => [
                            'service_visit_id' => (int) $syncedVisitId,
                            'equipment_id' => $smokeEquipmentId,
                            'measurement_type' => $profile['measurement']['type'],
                            'value' => $profile['measurement']['value'],
                            'unit' => $profile['measurement']['unit'],
                            'status' => $profile['measurement']['status'],
                        ],
                        'idempotency_key' => $syncMeasurementKey,
                        'timestamp' => time(),
                    ],
                    [
                        'type' => 'consumable',
                        'action' => 'create',
                        'data' => [
                            'service_visit_id' => (int) $syncedVisitId,
                            'equipment_id' => $smokeEquipmentId,
                            'name' => $profile['consumable']['name'],
                            'quantity_used' => $profile['consumable']['quantity_used'],
                            'unit' => $profile['consumable']['unit'],
                            'reason' => $profile['consumable']['reason'],
                            'is_billable' => 1,
                        ],
                        'idempotency_key' => $syncConsumableKey,
                        'timestamp' => time(),
                    ],
                ],
            ],
            $anonymousCookies,
            ['X-API-Key: ' . $smokeApiKey['api_key']]
        );
        assertTrue($syncMeasurementAndConsumableResponse['status'] === 200, 'Sync measurement/consumable request should return 200');
        $syncMeasurementResult = $syncMeasurementAndConsumableResponse['body']['data']['results'][0] ?? [];
        $syncConsumableResult = $syncMeasurementAndConsumableResponse['body']['data']['results'][1] ?? [];
        assertTrue(($syncMeasurementResult['status'] ?? null) === 'success', 'Sync measurement create did not report success');
        assertTrue(is_numeric($syncMeasurementResult['id'] ?? null), 'Sync measurement create did not return an id');
        assertTrue(($syncConsumableResult['status'] ?? null) === 'success', 'Sync consumable create did not report success');
        assertTrue(is_numeric($syncConsumableResult['id'] ?? null), 'Sync consumable create did not return an id');
        $syncMeasurementId = (int) ($syncMeasurementResult['id'] ?? 0);
        $syncConsumableId = (int) ($syncConsumableResult['id'] ?? 0);
        assertTrue(auditEntryExists($db, $auditBeforeMeasurementAndConsumableSync, 'insert', 'measurement', $syncMeasurementId), 'Sync measurement insert should create an audit log row');
        assertTrue(auditEntryExists($db, $auditBeforeMeasurementAndConsumableSync, 'insert', 'consumable', $syncConsumableId), 'Sync consumable insert should create an audit log row');

        $syncMeasurementAndConsumableDuplicateResponse = request(
            'POST',
            $baseUrl . '/api/sync',
            [
                'queue' => [
                    [
                        'type' => 'measurement',
                        'action' => 'create',
                        'data' => [
                            'service_visit_id' => (int) $syncedVisitId,
                            'equipment_id' => $smokeEquipmentId,
                            'measurement_type' => $profile['measurement']['type'],
                            'value' => $profile['measurement']['value'],
                            'unit' => $profile['measurement']['unit'],
                            'status' => $profile['measurement']['status'],
                        ],
                        'idempotency_key' => $syncMeasurementKey,
                        'timestamp' => time(),
                    ],
                    [
                        'type' => 'consumable',
                        'action' => 'create',
                        'data' => [
                            'service_visit_id' => (int) $syncedVisitId,
                            'equipment_id' => $smokeEquipmentId,
                            'name' => $profile['consumable']['name'],
                            'quantity_used' => $profile['consumable']['quantity_used'],
                            'unit' => $profile['consumable']['unit'],
                            'reason' => $profile['consumable']['reason'],
                            'is_billable' => 1,
                        ],
                        'idempotency_key' => $syncConsumableKey,
                        'timestamp' => time(),
                    ],
                ],
            ],
            $anonymousCookies,
            ['X-API-Key: ' . $smokeApiKey['api_key']]
        );
        assertTrue($syncMeasurementAndConsumableDuplicateResponse['status'] === 200, 'Duplicate sync measurement/consumable request should return 200');
        $syncMeasurementDuplicateResult = $syncMeasurementAndConsumableDuplicateResponse['body']['data']['results'][0] ?? [];
        $syncConsumableDuplicateResult = $syncMeasurementAndConsumableDuplicateResponse['body']['data']['results'][1] ?? [];
        assertTrue(($syncMeasurementDuplicateResult['status'] ?? null) === 'duplicate', 'Duplicate sync measurement request should report duplicate');
        assertTrue(($syncConsumableDuplicateResult['status'] ?? null) === 'duplicate', 'Duplicate sync consumable request should report duplicate');
        echo "[PASS] Sync measurement and consumable idempotency is enforced with audit rows\n";

        $syncRepairAndMediaResponse = request(
            'POST',
            $baseUrl . '/api/sync',
            [
                'queue' => [
                    [
                        'type' => 'repair',
                        'action' => 'create',
                        'data' => [
                            'service_visit_id' => (int) $syncedVisitId,
                            'equipment_id' => $smokeEquipmentId,
                            'issue' => $profile['sync_repair']['issue'],
                            'notes' => $profile['sync_repair']['notes'],
                            'priority' => $profile['sync_repair']['priority'],
                            'status' => $profile['sync_repair']['status'],
                        ],
                        'timestamp' => time(),
                    ],
                    [
                        'type' => 'media',
                        'action' => 'create',
                        'data' => [
                            'service_visit_id' => (int) $syncedVisitId,
                            'equipment_id' => $smokeEquipmentId,
                            'file_name' => $profile['media']['sync_filename'],
                            'mime_type' => 'image/jpeg',
                            'file_size' => 2048,
                        ],
                        'timestamp' => time(),
                    ],
                ],
            ],
            $anonymousCookies,
            ['X-API-Key: ' . $smokeApiKey['api_key']]
        );
        assertTrue($syncRepairAndMediaResponse['status'] === 200, 'Sync repair/media request should return 200');
        $syncRepairResult = $syncRepairAndMediaResponse['body']['data']['results'][0] ?? [];
        $syncMediaResult = $syncRepairAndMediaResponse['body']['data']['results'][1] ?? [];
        assertTrue(($syncRepairResult['status'] ?? null) === 'success', 'Sync repair create did not report success');
        assertTrue(is_numeric($syncRepairResult['id'] ?? null), 'Sync repair create did not return an id');
        assertTrue(($syncMediaResult['status'] ?? null) === 'success', 'Sync media create did not report success');
        assertTrue(is_numeric($syncMediaResult['id'] ?? null), 'Sync media create did not return an id');
        $syncRepairId = (int) ($syncRepairResult['id'] ?? 0);
        $syncMediaId = (int) ($syncMediaResult['id'] ?? 0);
        echo "[PASS] Sync repair and media payloads are normalized and accepted\n";

        $syncInvalidRepairAndMediaResponse = request(
            'POST',
            $baseUrl . '/api/sync',
            [
                'queue' => [
                    [
                        'type' => 'repair',
                        'action' => 'create',
                        'data' => [
                            'service_visit_id' => (int) $syncedVisitId,
                            'equipment_id' => '',
                            'issue' => '',
                            'status' => 'not-a-status',
                        ],
                        'timestamp' => time(),
                    ],
                    [
                        'type' => 'media',
                        'action' => 'create',
                        'data' => [
                            'equipment_id' => $smokeEquipmentId,
                            'file_name' => '',
                            'mime_type' => 'text/plain',
                        ],
                        'timestamp' => time(),
                    ],
                ],
            ],
            $anonymousCookies,
            ['X-API-Key: ' . $smokeApiKey['api_key']]
        );
        assertTrue($syncInvalidRepairAndMediaResponse['status'] === 200, 'Invalid sync repair/media request should return 200 with per-item errors');
        $invalidSyncRepairResult = $syncInvalidRepairAndMediaResponse['body']['data']['results'][0] ?? [];
        $invalidSyncMediaResult = $syncInvalidRepairAndMediaResponse['body']['data']['results'][1] ?? [];
        assertTrue(($invalidSyncRepairResult['status'] ?? null) === 'error', 'Invalid sync repair item should report error');
        assertTrue(($invalidSyncRepairResult['message'] ?? null) === 'Validation failed', 'Invalid sync repair item returned the wrong message');
        assertTrue(isset($invalidSyncRepairResult['errors']['equipment_id']), 'Invalid sync repair item should report an equipment_id validation error');
        assertTrue(isset($invalidSyncRepairResult['errors']['issue_description']), 'Invalid sync repair item should report an issue_description validation error');
        assertTrue(isset($invalidSyncRepairResult['errors']['status']), 'Invalid sync repair item should report a status validation error');
        assertTrue(($invalidSyncMediaResult['status'] ?? null) === 'error', 'Invalid sync media item should report error');
        assertTrue(($invalidSyncMediaResult['message'] ?? null) === 'Validation failed', 'Invalid sync media item returned the wrong message');
        assertTrue(isset($invalidSyncMediaResult['errors']['visit_id']), 'Invalid sync media item should report a visit_id validation error');
        assertTrue(isset($invalidSyncMediaResult['errors']['stored_filename']), 'Invalid sync media item should report a stored_filename validation error');
        assertTrue(isset($invalidSyncMediaResult['errors']['mime_type']), 'Invalid sync media item should report a mime_type validation error');
        echo "[PASS] Sync repair and media payloads reject malformed queue items\n";

        $repairMissingCsrfResponse = request('POST', $baseUrl . '/api/repairs', [
            'visit_id' => (int) $syncedVisitId,
            'equipment_id' => $smokeEquipmentId,
            'issue_description' => 'Negative-path repair without CSRF',
            'priority' => 'medium',
            'status' => 'recommended',
        ], $cookieJar);
        assertTrue($repairMissingCsrfResponse['status'] === 403, 'Repair create without CSRF should return 403');
        assertTrue(($repairMissingCsrfResponse['body']['message'] ?? null) === 'Invalid CSRF token', 'Repair create without CSRF returned the wrong error');
        echo "[PASS] Repair create rejects missing CSRF\n";

        $repairInvalidResponse = request('POST', $baseUrl . '/api/repairs', [
            'csrf_token' => $csrfToken,
            'visit_id' => (int) $syncedVisitId,
            'equipment_id' => $smokeEquipmentId,
            'issue_description' => '',
            'priority' => 'medium',
            'status' => 'not-a-status',
        ], $cookieJar);
        assertTrue($repairInvalidResponse['status'] === 422, 'Invalid repair payload should return 422');
        assertTrue(isset($repairInvalidResponse['body']['errors']['issue_description']), 'Invalid repair payload should report an issue_description validation error');
        assertTrue(isset($repairInvalidResponse['body']['errors']['status']), 'Invalid repair payload should report a status validation error');
        echo "[PASS] Repair create rejects invalid payloads\n";

        $measurementCreateResponse = request('POST', $baseUrl . '/api/measurements', [
            'csrf_token' => $csrfToken,
            'visit_id' => (int) $syncedVisitId,
            'equipment_id' => $smokeEquipmentId,
            'measurement_type' => $profile['measurement']['type'],
            'value' => $profile['measurement']['value'],
            'unit' => $profile['measurement']['unit'],
            'status' => $profile['measurement']['status'],
        ], $cookieJar);
        assertTrue($measurementCreateResponse['status'] === 201, 'Measurement create with CSRF should return 201');
        $measurementId = $measurementCreateResponse['body']['data']['id'] ?? null;
        assertTrue(is_numeric($measurementId), 'Measurement create did not return an id');
        echo "[PASS] Measurement create succeeds with CSRF\n";

        $measurementListResponse = request('GET', $baseUrl . '/api/measurements?visit_id=' . (int) $syncedVisitId, null, $cookieJar);
        assertTrue($measurementListResponse['status'] === 200, 'Measurement list by visit should return 200');
        $measurements = $measurementListResponse['body']['data']['measurements'] ?? [];
        $foundMeasurement = false;
        foreach ($measurements as $measurement) {
            if ((int) ($measurement['id'] ?? 0) === (int) $measurementId) {
                $foundMeasurement = true;
                break;
            }
        }
        assertTrue($foundMeasurement, 'Created measurement was not returned by the visit measurement list');
        echo "[PASS] Measurement list returns created record\n";

        $consumableCreateResponse = request('POST', $baseUrl . '/api/consumables', [
            'csrf_token' => $csrfToken,
            'visit_id' => (int) $syncedVisitId,
            'equipment_id' => $smokeEquipmentId,
            'consumable_name' => $profile['consumable']['name'],
            'quantity_used' => $profile['consumable']['quantity_used'],
            'unit' => $profile['consumable']['unit'],
            'is_billable' => 1,
            'reason' => $profile['consumable']['reason'],
        ], $cookieJar);
        assertTrue($consumableCreateResponse['status'] === 201, 'Consumable create with CSRF should return 201');
        $consumableId = $consumableCreateResponse['body']['data']['id'] ?? null;
        assertTrue(is_numeric($consumableId), 'Consumable create did not return an id');
        echo "[PASS] Consumable create succeeds with CSRF\n";

        $consumableListResponse = request('GET', $baseUrl . '/api/consumables?visit_id=' . (int) $syncedVisitId, null, $cookieJar);
        assertTrue($consumableListResponse['status'] === 200, 'Consumable list by visit should return 200');
        $consumables = $consumableListResponse['body']['data']['consumables'] ?? [];
        $foundConsumable = false;
        foreach ($consumables as $consumable) {
            if ((int) ($consumable['id'] ?? 0) === (int) $consumableId) {
                $foundConsumable = true;
                break;
            }
        }
        assertTrue($foundConsumable, 'Created consumable was not returned by the visit consumable list');
        echo "[PASS] Consumable list returns created record\n";

        $pngPixel = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aF9sAAAAASUVORK5CYII=', true);
        assertTrue($pngPixel !== false, 'Failed to decode smoke PNG fixture');

        $mediaMissingCsrfResponse = requestMultipart(
            $baseUrl . '/api/media/upload',
            [
                'visit_id' => (int) $syncedVisitId,
                'equipment_id' => $smokeEquipmentId,
            ],
            [
                'file' => [
                    'filename' => $profile['media']['missing_csrf_filename'],
                    'content_type' => 'image/png',
                    'content' => $pngPixel,
                ],
            ],
            $cookieJar
        );
        assertTrue($mediaMissingCsrfResponse['status'] === 403, 'Media upload without CSRF should return 403');
        assertTrue(($mediaMissingCsrfResponse['body']['message'] ?? null) === 'Invalid CSRF token', 'Media upload without CSRF returned the wrong error');
        echo "[PASS] Media upload rejects missing CSRF\n";

        $mediaNoFileResponse = requestMultipart(
            $baseUrl . '/api/media/upload',
            [
                'csrf_token' => $csrfToken,
                'visit_id' => (int) $syncedVisitId,
                'equipment_id' => $smokeEquipmentId,
            ],
            [],
            $cookieJar
        );
        assertTrue($mediaNoFileResponse['status'] === 400, 'Media upload without a file should return 400');
        assertTrue(($mediaNoFileResponse['body']['message'] ?? null) === 'No file uploaded or upload error', 'Media upload without a file returned the wrong error');
        echo "[PASS] Media upload rejects missing files\n";

        $mediaInvalidMimeResponse = requestMultipart(
            $baseUrl . '/api/media/upload',
            [
                'csrf_token' => $csrfToken,
                'visit_id' => (int) $syncedVisitId,
                'equipment_id' => $smokeEquipmentId,
            ],
            [
                'file' => [
                    'filename' => $profile['media']['invalid_filename'],
                    'content_type' => 'text/plain',
                    'content' => "not an allowed media type\n",
                ],
            ],
            $cookieJar
        );
        assertTrue($mediaInvalidMimeResponse['status'] === 422, 'Media upload with an invalid MIME type should return 422');
        assertTrue(isset($mediaInvalidMimeResponse['body']['errors']['mime_type']), 'Invalid media upload should report a mime_type validation error');
        echo "[PASS] Media upload rejects invalid file types\n";

        $mediaUploadResponse = requestMultipart(
            $baseUrl . '/api/media/upload',
            [
                'csrf_token' => $csrfToken,
                'visit_id' => (int) $syncedVisitId,
                'equipment_id' => $smokeEquipmentId,
            ],
            [
                'file' => [
                    'filename' => $profile['media']['upload_filename'],
                    'content_type' => 'image/png',
                    'content' => $pngPixel,
                ],
            ],
            $cookieJar
        );
        assertTrue($mediaUploadResponse['status'] === 201, 'Media upload with CSRF should return 201');
        $mediaId = $mediaUploadResponse['body']['data']['id'] ?? null;
        assertTrue(is_numeric($mediaId), 'Media upload did not return an id');
        echo "[PASS] Media upload succeeds with CSRF\n";

        $mediaShowResponse = request('GET', $baseUrl . '/api/media/' . (int) $mediaId, null, $cookieJar);
        assertTrue($mediaShowResponse['status'] === 200, 'Media show should return 200');
        assertTrue((int) ($mediaShowResponse['body']['data']['id'] ?? 0) === (int) $mediaId, 'Media show did not return the created record');
        echo "[PASS] Media show returns created record\n";

        $storedFilename = $mediaShowResponse['body']['data']['stored_filename'] ?? null;
        assertTrue(is_string($storedFilename) && $storedFilename !== '', 'Media show did not return a stored filename');

        $mediaDeleteResponse = request('DELETE', $baseUrl . '/api/media/' . (int) $mediaId, [
            'csrf_token' => $csrfToken,
        ], $cookieJar);
        assertTrue($mediaDeleteResponse['status'] === 200, 'Media delete with CSRF should return 200');
        echo "[PASS] Media delete succeeds with CSRF\n";

        $mediaMissingResponse = request('GET', $baseUrl . '/api/media/' . (int) $mediaId, null, $cookieJar);
        assertTrue($mediaMissingResponse['status'] === 404, 'Deleted media should return 404 on fetch');
        echo "[PASS] Media delete removes record\n";

        $repairCreateResponse = request('POST', $baseUrl . '/api/repairs', [
            'csrf_token' => $csrfToken,
            'visit_id' => (int) $syncedVisitId,
            'equipment_id' => $smokeEquipmentId,
            'issue_description' => $profile['repair']['issue_description'],
            'recommendation' => $profile['repair']['recommendation'],
            'priority' => $profile['repair']['priority'],
            'estimated_cost' => $profile['repair']['estimated_cost'],
            'status' => $profile['repair']['status'],
        ], $cookieJar);
        assertTrue($repairCreateResponse['status'] === 201, 'Repair create with CSRF should return 201');
        $repairId = $repairCreateResponse['body']['data']['id'] ?? null;
        assertTrue(is_numeric($repairId), 'Repair create did not return an id');
        echo "[PASS] Repair create succeeds with CSRF\n";

        $repairListResponse = request('GET', $baseUrl . '/api/repairs?visit_id=' . (int) $syncedVisitId, null, $cookieJar);
        assertTrue($repairListResponse['status'] === 200, 'Repair list by visit should return 200');
        $repairs = $repairListResponse['body']['data']['repairs'] ?? [];
        $foundRepair = false;
        foreach ($repairs as $repair) {
            if ((int) ($repair['id'] ?? 0) === (int) $repairId) {
                $foundRepair = true;
                break;
            }
        }
        assertTrue($foundRepair, 'Created repair was not returned by the visit repair list');
        echo "[PASS] Repair list returns created record\n";

        $repairUpdateResponse = request('PUT', $baseUrl . '/api/repairs/' . (int) $repairId, [
            'csrf_token' => $csrfToken,
            'status' => 'approved',
            'recommendation' => 'Approved for immediate replacement',
        ], $cookieJar);
        assertTrue($repairUpdateResponse['status'] === 200, 'Repair update with CSRF should return 200');
        assertTrue(($repairUpdateResponse['body']['data']['status'] ?? null) === 'approved', 'Repair update did not persist the new status');
        echo "[PASS] Repair update succeeds with CSRF\n";

        $repairDeleteResponse = request('DELETE', $baseUrl . '/api/repairs/' . (int) $repairId, [
            'csrf_token' => $csrfToken,
        ], $cookieJar);
        assertTrue($repairDeleteResponse['status'] === 200, 'Repair delete with CSRF should return 200');
        echo "[PASS] Repair delete succeeds with CSRF\n";

        $repairMissingResponse = request('GET', $baseUrl . '/api/repairs/' . (int) $repairId, null, $cookieJar);
        assertTrue($repairMissingResponse['status'] === 404, 'Deleted repair should return 404 on fetch');
        echo "[PASS] Repair delete removes record\n";

        $logoutResponse = request('POST', $baseUrl . '/api/auth/logout', [
            'csrf_token' => $csrfToken,
        ], $cookieJar);
        assertTrue($logoutResponse['status'] === 200, 'Logout did not return 200');
        assertTrue(($logoutResponse['body']['status'] ?? null) === 'success', 'Logout did not report success');
        echo "[PASS] Logout succeeds with CSRF\n";

        $postLogoutUserResponse = request('GET', $baseUrl . '/api/auth/user', null, $cookieJar);
        assertTrue($postLogoutUserResponse['status'] === 401, 'User endpoint should return 401 after logout');
        echo "[PASS] Logout clears authenticated session\n";

        $afterAuditCount = fetchAuditCount($db);
        assertTrue(($afterAuditCount - $beforeAuditCount) >= 11, 'Audit log count did not increase as expected');
        assertTrue(auditEntryExists($db, $beforeAuditCount, 'login', 'user_session', 1), 'Missing login audit entry');
        assertTrue(auditEntryExists($db, $beforeAuditCount, 'insert', 'site', (int) $siteId), 'Missing site insert audit entry');
        assertTrue(auditEntryExists($db, $beforeAuditCount, 'update', 'site', (int) $siteId), 'Missing site update audit entry');
        assertTrue(auditEntryExists($db, $beforeAuditCount, 'insert', 'repair_recommendation', $syncRepairId), 'Missing sync repair insert audit entry');
        assertTrue(auditEntryExists($db, $beforeAuditCount, 'insert', 'media_item', $syncMediaId), 'Missing sync media insert audit entry');
        assertTrue(auditEntryExists($db, $beforeAuditCount, 'insert', 'media_item', (int) $mediaId), 'Missing media insert audit entry');
        assertTrue(auditEntryExists($db, $beforeAuditCount, 'delete', 'media_item', (int) $mediaId), 'Missing media delete audit entry');
        assertTrue(auditEntryExists($db, $beforeAuditCount, 'insert', 'repair_recommendation', (int) $repairId), 'Missing repair insert audit entry');
        assertTrue(auditEntryExists($db, $beforeAuditCount, 'update', 'repair_recommendation', (int) $repairId), 'Missing repair update audit entry');
        assertTrue(auditEntryExists($db, $beforeAuditCount, 'delete', 'repair_recommendation', (int) $repairId), 'Missing repair delete audit entry');
        assertTrue(auditEntryExists($db, $beforeAuditCount, 'logout', 'user_session', 1), 'Missing logout audit entry');
        echo "[PASS] Audit logs recorded login, site changes, sync inserts, media events, repair events, and logout\n";

        $afterCount = fetchTransactionCount($db);
        assertTrue(($afterCount - $beforeCount) >= 33, 'Transaction log count did not increase as expected');
        echo "[PASS] Transaction logs recorded request activity\n";

        echo "Smoke suite passed. Transaction logs grew by " . ($afterCount - $beforeCount) . ".\n";
    } finally {
        deleteSmokeCustomer($db, $smokeCustomerId);
        deleteSmokeApiKey($db, $wrongScopeApiKeyId);
        deleteSmokeApiKey($db, $smokeApiKeyId);
    }
}

$repoRoot = dirname(__DIR__);
$baseUrl = $argv[1] ?? 'http://localhost:8000';

try {
    runSmokeSuite($repoRoot, rtrim($baseUrl, '/'));
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, '[FAIL] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
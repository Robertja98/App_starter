<?php
/**
 * CLI utility to generate a scoped API key.
 * Usage:
 *   php tools/generate_api_key.php sync-device "sync:write,media:write" 1
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Helpers/security_helper.php';

$name = $argv[1] ?? 'default-client';
$scopeArg = $argv[2] ?? 'sync:write';
$createdByUserId = isset($argv[3]) ? (int)$argv[3] : null;

$scopes = array_values(array_filter(array_map('trim', explode(',', $scopeArg))));
if (empty($scopes)) {
    fwrite(STDERR, "At least one scope is required.\n");
    exit(1);
}

$plainTextKey = generateApiKeyPlaintext($config);
$model = new ApiKey($db);
$id = $model->createKeyRecord($name, $plainTextKey, $scopes, $createdByUserId);

if (!$id) {
    fwrite(STDERR, "Failed to create API key record.\n");
    exit(1);
}

fwrite(STDOUT, json_encode([
    'id' => $id,
    'name' => $name,
    'scopes' => $scopes,
    'api_key' => $plainTextKey,
], JSON_PRETTY_PRINT) . PHP_EOL);
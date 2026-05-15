<?php
/**
 * Application Bootstrap
 * 
 * Initializes configuration, database, and middleware.
 * This file is included once by the router and makes $config, $db, $auth available globally.
 */

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../error_log.txt');

// Load configuration in isolated scope so local config files cannot overwrite the
// bootstrap variables they are being merged into.
$config = (static function ($file) {
    return require $file;
})(__DIR__ . '/../config/app.php');

// Load local overrides if present
if (file_exists(__DIR__ . '/../config/app.local.php')) {
    $localConfig = (static function ($file) {
        return require $file;
    })(__DIR__ . '/../config/app.local.php');
    $config = array_replace_recursive($config, $localConfig);
}

// Set timezone
date_default_timezone_set($config['app']['timezone'] ?? 'UTC');

// Load helpers
require_once __DIR__ . '/Helpers/csrf_helper.php';
require_once __DIR__ . '/Helpers/debug_helper.php';
require_once __DIR__ . '/Helpers/validation_helper.php';
require_once __DIR__ . '/Helpers/security_helper.php';

// Initialize session (before any output)
session_set_cookie_params([
    'lifetime' => (int) (($config['session']['lifetime'] ?? 1440) * 60),
    'path' => '/',
    'secure' => !empty($config['session']['cookie_secure']),
    'httponly' => !empty($config['session']['cookie_httponly']),
    'samesite' => $config['session']['cookie_samesite'] ?? 'Lax',
]);
session_name($config['session']['name'] ?? 'service_app_session');
session_start();

// Load database layer
require_once __DIR__ . '/Database/Database.php';

// Load middleware
require_once __DIR__ . '/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/Middleware/CsrfMiddleware.php';
require_once __DIR__ . '/Middleware/ApiKeyMiddleware.php';

// Load base classes
require_once __DIR__ . '/Models/Model.php';
require_once __DIR__ . '/Controllers/Controller.php';
require_once __DIR__ . '/Services/TransactionLogger.php';

// Load all models
$modelsPath = __DIR__ . '/Models/';
foreach (glob($modelsPath . '*.php') as $file) {
    if (basename($file) !== 'Model.php') {
        require_once $file;
    }
}

// Initialize database connection
$db = new Database($config);

// Initialize authentication middleware
$auth = new AuthMiddleware($config);
$apiKeyAuth = new ApiKeyMiddleware($config, $db);
$transactionLogger = new TransactionLogger($db, $config);

// Make variables available to router
$GLOBALS['config'] = $config;
$GLOBALS['db'] = $db;
$GLOBALS['auth'] = $auth;
$GLOBALS['apiKeyAuth'] = $apiKeyAuth;
$GLOBALS['transactionLogger'] = $transactionLogger;

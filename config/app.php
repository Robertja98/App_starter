<?php
/**
 * Application Configuration
 * 
 * Environment-specific settings are loaded from config/app.local.php if it exists.
 * DO NOT commit app.local.php to version control.
 */

return [
    // Database
    'db' => [
        'host'     => getenv('DB_HOST') ?: 'localhost',
        'user'     => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASS') ?: '',
        'database' => getenv('DB_NAME') ?: 'app_local_dev',
        'charset'  => 'utf8mb4',
    ],

    // Application
    'app' => [
        'name'      => getenv('APP_NAME') ?: 'My App Starter',
        'short_name' => getenv('APP_SHORT_NAME') ?: 'My App',
        'description' => getenv('APP_DESCRIPTION') ?: 'Offline-capable PHP and MySQL application starter',
        'debug'     => filter_var(getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN),
        'timezone'  => getenv('APP_TIMEZONE') ?: 'America/Toronto',
    ],

    // Session & Security
    'session' => [
        'name' => getenv('SESSION_NAME') ?: 'app_session',
        'lifetime'   => 1440, // 24 hours in minutes
        'cookie_secure' => true, // HTTPS only
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ],

    // File uploads
    'upload' => [
        'max_size'   => 5 * 1024 * 1024, // 5 MB
        'allowed_types' => ['jpg', 'jpeg', 'png', 'gif', 'pdf'],
        'storage_path' => realpath(__DIR__ . '/../storage/uploads'),
    ],

    // API
    'api' => [
        'base_url' => getenv('API_BASE_URL') ?: '/api',
        'max_body_size' => 10 * 1024 * 1024, // 10 MB for media metadata
    ],

    // Security
    'security' => [
        'encryption_key' => getenv('APP_KEY') ?: '',
        'api_key_header' => 'X-API-Key',
        'api_key_prefix' => getenv('API_KEY_PREFIX') ?: 'app_',
        'api_key_hash_algo' => 'sha256',
        'session_idle_timeout' => 60 * 60 * 12,
        'session_fingerprint_mode' => 'user_agent_ip_prefix',
        'session_ip_prefix_v4' => 24,
        'session_ip_prefix_v6' => 64,
        'headers' => [
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(self), microphone=(), geolocation=(self)',
        ],
    ],

    'observability' => [
        'db_request_logging' => true,
        'db_audit_logging' => true,
        'max_log_depth' => 4,
        'max_log_array_items' => 25,
        'max_log_string_length' => 500,
    ],

    // Email (GoDaddy cPanel-compatible settings)
    'mail' => [
        'driver' => 'smtp',
        'host'   => getenv('MAIL_HOST') ?: 'localhost',
        'port'   => getenv('MAIL_PORT') ?: 587,
        'from'   => getenv('MAIL_FROM') ?: 'noreply@example.com',
    ],
];

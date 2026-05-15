<?php

return [
    'db' => [
        'host' => 'localhost',
        'user' => 'root',
        'password' => 'change-me',
        'database' => 'app_local_dev',
    ],
    'app' => [
        'name' => 'My App Starter',
        'debug' => true,
        'timezone' => 'America/Toronto',
    ],
    'session' => [
        'cookie_secure' => false,
    ],
    'security' => [
        'encryption_key' => 'replace-with-a-32-byte-or-longer-secret',
        'session_fingerprint_mode' => 'user_agent_ip_prefix',
        'session_ip_prefix_v4' => 24,
        'session_ip_prefix_v6' => 64,
    ],
    'observability' => [
        'db_request_logging' => true,
        'db_audit_logging' => true,
    ],
    'mail' => [
        'host' => 'localhost',
        'port' => 587,
        'from' => 'noreply@example.com',
    ],
];
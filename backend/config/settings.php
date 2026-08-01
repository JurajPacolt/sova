<?php

declare(strict_types=1);

$envString = static function (string $name, string $default = ''): string {
    $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

    return is_string($value) ? $value : $default;
};

$envBool = static function (string $name, bool $default) use ($envString): bool {
    $value = filter_var(
        $envString($name, $default ? 'true' : 'false'),
        FILTER_VALIDATE_BOOL,
        FILTER_NULL_ON_FAILURE,
    );

    return $value ?? $default;
};

$envInt = static function (string $name, int $default) use ($envString): int {
    $value = filter_var($envString($name, (string) $default), FILTER_VALIDATE_INT);

    return $value === false ? $default : $value;
};

/**
 * @param list<string> $default
 *
 * @return list<string>
 */
$envList = static function (string $name, array $default = []) use ($envString): array {
    $defaultItems = [];

    foreach ($default as $item) {
        if (is_string($item)) {
            $defaultItems[] = $item;
        }
    }

    $value = $envString($name, implode(',', $defaultItems));

    if ($value === '') {
        return [];
    }

    return array_values(array_filter(array_map('trim', explode(',', $value))));
};

return [
    'app' => [
        'name' => $envString('APP_NAME', 'SOVA API'),
        'environment' => $envString('APP_ENV', 'production'),
        'debug' => $envBool('APP_DEBUG', false),
        'version' => $envString('APP_VERSION', 'dev'),
        'public_url' => $envString('APP_PUBLIC_URL', 'http://localhost:4200'),
    ],
    'logger' => [
        'level' => $envString('LOG_LEVEL', 'info'),
        'path' => $envString('LOG_PATH', 'php://stderr'),
    ],
    'database' => [
        'url' => $envString('DATABASE_URL'),
        'driver' => $envString('DATABASE_DRIVER', 'pdo_pgsql'),
        'host' => $envString('DATABASE_HOST', '127.0.0.1'),
        'port' => $envInt('DATABASE_PORT', 5432),
        'name' => $envString('DATABASE_NAME', 'sova'),
        'user' => $envString('DATABASE_USER', 'sova'),
        'password' => $envString('DATABASE_PASSWORD', 'sova_dev_password'),
        'server_version' => $envString('DATABASE_SERVER_VERSION', '17'),
        'ssl_mode' => $envString('DATABASE_SSL_MODE', 'prefer'),
    ],
    'auth' => [
        'session_cookie_name' => 'sova_session',
        'csrf_cookie_name' => 'sova_csrf',
        'csrf_header_name' => 'X-CSRF-Token',
        'session_ttl_seconds' => $envInt('AUTH_SESSION_TTL_SECONDS', 28_800),
        'cookie_secure' => $envBool('AUTH_COOKIE_SECURE', true),
        'cookie_same_site' => $envString('AUTH_COOKIE_SAME_SITE', 'Lax'),
        'rate_limit_secret' => $envString('AUTH_RATE_LIMIT_SECRET'),
        'rate_limit_window_seconds' => $envInt('AUTH_RATE_LIMIT_WINDOW_SECONDS', 900),
        'rate_limit_block_seconds' => $envInt('AUTH_RATE_LIMIT_BLOCK_SECONDS', 900),
        'rate_limit_account_attempts' => $envInt('AUTH_RATE_LIMIT_ACCOUNT_ATTEMPTS', 5),
        'rate_limit_ip_attempts' => $envInt('AUTH_RATE_LIMIT_IP_ATTEMPTS', 20),
        'password_reset_ttl_seconds' => $envInt(
            'AUTH_PASSWORD_RESET_TTL_SECONDS',
            1_800,
        ),
        'email_verification_ttl_seconds' => $envInt(
            'AUTH_EMAIL_VERIFICATION_TTL_SECONDS',
            86_400,
        ),
        'invitation_ttl_seconds' => $envInt(
            'AUTH_INVITATION_TTL_SECONDS',
            604_800,
        ),
        'invitation_resend_cooldown_seconds' => $envInt(
            'AUTH_INVITATION_RESEND_COOLDOWN_SECONDS',
            60,
        ),
        'recovery_request_ttl_seconds' => $envInt(
            'AUTH_RECOVERY_REQUEST_TTL_SECONDS',
            900,
        ),
        'recovery_rate_limit_window_seconds' => $envInt(
            'AUTH_RECOVERY_RATE_LIMIT_WINDOW_SECONDS',
            3_600,
        ),
        'recovery_rate_limit_block_seconds' => $envInt(
            'AUTH_RECOVERY_RATE_LIMIT_BLOCK_SECONDS',
            3_600,
        ),
        'recovery_rate_limit_account_requests' => $envInt(
            'AUTH_RECOVERY_RATE_LIMIT_ACCOUNT_REQUESTS',
            3,
        ),
        'recovery_rate_limit_ip_requests' => $envInt(
            'AUTH_RECOVERY_RATE_LIMIT_IP_REQUESTS',
            20,
        ),
    ],
    'security' => [
        'sensitive_payload_key_id' => $envString('SENSITIVE_PAYLOAD_KEY_ID'),
        'sensitive_payload_key' => $envString('SENSITIVE_PAYLOAD_KEY'),
    ],
    // Attachment bytes never live in the database. The directory must sit
    // outside anything the web server serves directly, or a file could be
    // fetched without passing the download authorisation.
    'attachments' => [
        'path' => $envString('ATTACHMENT_STORAGE_PATH', __DIR__ . '/../var/attachments'),
        'tenant_quota_bytes' => $envInt(
            'ATTACHMENT_TENANT_QUOTA_BYTES',
            20 * 1024 * 1024 * 1024,
        ),
        'scanner' => $envString('ATTACHMENT_SCANNER', 'none'),
        'scanner_host' => $envString('ATTACHMENT_SCANNER_HOST', '127.0.0.1'),
        'scanner_port' => $envInt('ATTACHMENT_SCANNER_PORT', 3310),
        'scanner_connect_timeout_ms' => $envInt(
            'ATTACHMENT_SCANNER_CONNECT_TIMEOUT_MS',
            1000,
        ),
        'scanner_read_timeout_seconds' => $envInt(
            'ATTACHMENT_SCANNER_READ_TIMEOUT_SECONDS',
            30,
        ),
    ],
    'comments' => [
        // Grace window in which an author may still edit their own comment.
        // Beyond it, and for anyone else's comment, `comment.moderate` decides.
        'edit_window_seconds' => $envInt('COMMENT_EDIT_WINDOW_SECONDS', 900),
    ],
    // SovaQL safety limits (spec SOVAQL-A-DASHBOARDY.md §4.12). Operations may
    // tune them, but no value here can widen the tenant scope or bypass the
    // database statement timeout.
    'search' => [
        'max_query_bytes' => $envInt('SEARCH_MAX_QUERY_BYTES', 8192),
        'max_ast_nodes' => $envInt('SEARCH_MAX_AST_NODES', 100),
        'max_paren_depth' => $envInt('SEARCH_MAX_PAREN_DEPTH', 10),
        'max_in_values' => $envInt('SEARCH_MAX_IN_VALUES', 100),
        'max_sort_fields' => $envInt('SEARCH_MAX_SORT_FIELDS', 3),
        'default_page_size' => $envInt('SEARCH_DEFAULT_PAGE_SIZE', 50),
        'max_page_size' => $envInt('SEARCH_MAX_PAGE_SIZE', 100),
        'statement_timeout_ms' => $envInt('SEARCH_STATEMENT_TIMEOUT_MS', 3000),
        'rate_limit_window_seconds' => $envInt('SEARCH_RATE_LIMIT_WINDOW_SECONDS', 60),
        'rate_limit_requests' => $envInt('SEARCH_RATE_LIMIT_REQUESTS', 120),
    ],
    'mailer' => [
        'dsn' => $envString('MAILER_DSN', 'null://null'),
        'from' => $envString('MAILER_FROM', 'noreply@example.test'),
    ],
    'outbox' => [
        'max_attempts' => $envInt('OUTBOX_MAX_ATTEMPTS', 5),
    ],
    'cors' => [
        'allowed_origins' => $envList('CORS_ALLOWED_ORIGINS', ['http://localhost:4200']),
        'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        'allowed_headers' => [
            'Accept',
            'Authorization',
            'Content-Type',
            'X-CSRF-Token',
            'X-Request-ID',
        ],
        'exposed_headers' => ['X-Request-ID'],
        'max_age' => 600,
    ],
];

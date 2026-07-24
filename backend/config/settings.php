<?php

declare(strict_types=1);

$envString = static function (string $name, string $default = ''): string {
    $value = $_ENV[$name] ?? $_SERVER[$name] ?? $default;

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

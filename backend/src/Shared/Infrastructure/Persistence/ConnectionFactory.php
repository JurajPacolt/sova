<?php

declare(strict_types=1);

namespace Sova\Shared\Infrastructure\Persistence;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\DriverManager;
use RuntimeException;
use Sova\Shared\Infrastructure\Configuration\Settings;

final class ConnectionFactory
{
    /**
     * @param list<Middleware> $middlewares
     */
    public static function create(Settings $settings, array $middlewares = []): Connection
    {
        $url = $settings->string('database.url', '');
        $serverVersion = $settings->string('database.server_version');
        $configuration = new Configuration();
        $configuration->setMiddlewares($middlewares);

        if ($url !== '') {
            return DriverManager::getConnection(
                [
                    'url' => $url,
                    'serverVersion' => $serverVersion,
                ],
                $configuration,
            );
        }

        $driver = $settings->string('database.driver');

        if ($driver !== 'pdo_pgsql') {
            throw new RuntimeException('SOVA backend requires the pdo_pgsql database driver.');
        }

        return DriverManager::getConnection(
            [
                'driver' => $driver,
                'host' => $settings->string('database.host'),
                'port' => $settings->int('database.port'),
                'dbname' => $settings->string('database.name'),
                'user' => $settings->string('database.user'),
                'password' => $settings->string('database.password'),
                'serverVersion' => $serverVersion,
                'sslmode' => $settings->string('database.ssl_mode', 'prefer'),
            ],
            $configuration,
        );
    }

    private function __construct() {}
}

<?php

declare(strict_types=1);

use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Dotenv\Dotenv;
use Sova\Shared\Infrastructure\Configuration\Settings;
use Sova\Shared\Infrastructure\Persistence\ConnectionFactory;

require __DIR__ . '/vendor/autoload.php';

if (is_file(__DIR__ . '/.env')) {
    Dotenv::createImmutable(__DIR__)->safeLoad();
}

/** @var array<string, mixed> $settingsValues */
$settingsValues = require __DIR__ . '/config/settings.php';
$settings = new Settings($settingsValues);
$connection = ConnectionFactory::create($settings);

$configuration = new ConfigurationArray([
    'table_storage' => [
        'table_name' => 'doctrine_migration_versions',
    ],
    'migrations_paths' => [
        'Sova\Migrations' => __DIR__ . '/migrations',
    ],
    'all_or_nothing' => true,
    'check_database_platform' => true,
]);

return DependencyFactory::fromConnection(
    $configuration,
    new ExistingConnection($connection),
);

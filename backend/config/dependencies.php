<?php

declare(strict_types=1);

use function DI\autowire;
use function DI\factory;

use Doctrine\DBAL\Connection;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Sova\Shared\Infrastructure\Configuration\Settings;
use Sova\Shared\Infrastructure\Persistence\ConnectionFactory;

return [
    Settings::class => static function (): Settings {
        /** @var array<string, mixed> $settings */
        $settings = require __DIR__ . '/settings.php';

        return new Settings($settings);
    },
    LoggerInterface::class => factory(static function (Settings $settings): LoggerInterface {
        $level = match (strtolower($settings->string('logger.level', 'info'))) {
            'debug' => Level::Debug,
            'notice' => Level::Notice,
            'warning' => Level::Warning,
            'error' => Level::Error,
            'critical' => Level::Critical,
            'alert' => Level::Alert,
            'emergency' => Level::Emergency,
            default => Level::Info,
        };

        $logger = new Logger($settings->string('app.name', 'SOVA API'));
        $logger->pushHandler(
            new StreamHandler(
                $settings->string('logger.path', 'php://stderr'),
                $level,
            ),
        );

        return $logger;
    }),
    ResponseFactoryInterface::class => autowire(ResponseFactory::class),
    Connection::class => factory(
        static fn(Settings $settings): Connection => ConnectionFactory::create($settings),
    ),
];

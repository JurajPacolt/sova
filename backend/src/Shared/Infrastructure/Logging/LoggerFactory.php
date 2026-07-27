<?php

declare(strict_types=1);

namespace Sova\Shared\Infrastructure\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Sova\Shared\Infrastructure\Configuration\Settings;

final class LoggerFactory
{
    public static function create(Settings $settings): LoggerInterface
    {
        $service = $settings->string('app.name', 'SOVA API');
        $environment = $settings->string('app.environment', 'production');
        $handler = new StreamHandler(
            $settings->string('logger.path', 'php://stderr'),
            self::resolveLevel($settings->string('logger.level', 'info')),
        );
        $handler->setFormatter(new JsonFormatter(
            batchMode: JsonFormatter::BATCH_MODE_JSON,
            appendNewline: true,
            ignoreEmptyContextAndExtra: false,
            includeStacktraces: $settings->bool('app.debug', false),
        ));

        $logger = new Logger($service);
        $logger->pushProcessor(new ApplicationContextProcessor($service, $environment));
        $logger->pushHandler($handler);

        return $logger;
    }

    private static function resolveLevel(string $level): Level
    {
        return match (strtolower($level)) {
            'debug' => Level::Debug,
            'notice' => Level::Notice,
            'warning' => Level::Warning,
            'error' => Level::Error,
            'critical' => Level::Critical,
            'alert' => Level::Alert,
            'emergency' => Level::Emergency,
            default => Level::Info,
        };
    }

    private function __construct() {}
}

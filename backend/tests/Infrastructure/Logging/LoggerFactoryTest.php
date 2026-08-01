<?php

declare(strict_types=1);

namespace Sova\Tests\Infrastructure\Logging;

use DateTimeImmutable;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Sova\Shared\Infrastructure\Configuration\Settings;
use Sova\Shared\Infrastructure\Logging\ApplicationContextProcessor;
use Sova\Shared\Infrastructure\Logging\LoggerFactory;

final class LoggerFactoryTest extends TestCase
{
    public function testLoggerUsesJsonAndApplicationContext(): void
    {
        $settings = new Settings([
            'app' => [
                'name' => 'SOVA Test API',
                'environment' => 'test',
                'debug' => false,
            ],
            'logger' => [
                'level' => 'warning',
                'path' => 'php://memory',
            ],
        ]);

        $logger = LoggerFactory::create($settings);

        self::assertInstanceOf(Logger::class, $logger);

        $handlers = $logger->getHandlers();
        self::assertCount(1, $handlers);
        self::assertInstanceOf(StreamHandler::class, $handlers[0]);
        self::assertSame(Level::Warning, $handlers[0]->getLevel());
        self::assertInstanceOf(JsonFormatter::class, $handlers[0]->getFormatter());

        $processor = new ApplicationContextProcessor('SOVA Test API', 'test');
        $record = $processor(new LogRecord(
            new DateTimeImmutable('2026-07-26T00:00:00Z'),
            'SOVA Test API',
            Level::Info,
            'Test message',
        ));

        self::assertSame('SOVA Test API', $record->extra['service']);
        self::assertSame('test', $record->extra['environment']);
    }
}

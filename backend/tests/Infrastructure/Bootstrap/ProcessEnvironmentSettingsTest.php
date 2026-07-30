<?php

declare(strict_types=1);

namespace Sova\Tests\Infrastructure\Bootstrap;

use PHPUnit\Framework\TestCase;

final class ProcessEnvironmentSettingsTest extends TestCase
{
    public function testReadsProcessEnvironmentWhenPhpEnvironmentArraysAreEmpty(): void
    {
        $name = 'DATABASE_HOST';
        $originalProcessValue = getenv($name);
        $hadEnvValue = array_key_exists($name, $_ENV);
        $originalEnvValue = $_ENV[$name] ?? null;
        $hadServerValue = array_key_exists($name, $_SERVER);
        $originalServerValue = $_SERVER[$name] ?? null;

        try {
            putenv($name . '=postgres.runtime.test');
            unset($_ENV[$name], $_SERVER[$name]);

            $settings = require dirname(__DIR__, 3) . '/config/settings.php';

            self::assertIsArray($settings);
            self::assertIsArray($settings['database']);
            self::assertSame(
                'postgres.runtime.test',
                $settings['database']['host'],
            );
        } finally {
            if ($originalProcessValue === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $originalProcessValue);
            }

            if ($hadEnvValue) {
                $_ENV[$name] = $originalEnvValue;
            } else {
                unset($_ENV[$name]);
            }

            if ($hadServerValue) {
                $_SERVER[$name] = $originalServerValue;
            } else {
                unset($_SERVER[$name]);
            }
        }
    }
}

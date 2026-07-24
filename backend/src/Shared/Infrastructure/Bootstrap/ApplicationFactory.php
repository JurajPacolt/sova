<?php

declare(strict_types=1);

namespace Sova\Shared\Infrastructure\Bootstrap;

use DI\Container;
use DI\ContainerBuilder;
use Dotenv\Dotenv;
use Slim\App;
use Slim\Factory\AppFactory;
use UnexpectedValueException;

final class ApplicationFactory
{
    /**
     * @return App<Container>
     */
    public static function create(string $rootPath): App
    {
        if (is_file($rootPath . '/.env')) {
            Dotenv::createImmutable($rootPath)->safeLoad();
        }

        $definitions = require $rootPath . '/config/dependencies.php';

        if (!is_array($definitions)) {
            throw new UnexpectedValueException('Dependency definitions must return an array.');
        }

        $containerBuilder = new ContainerBuilder();
        $containerBuilder->useAutowiring(true);
        $containerBuilder->addDefinitions($definitions);

        $container = $containerBuilder->build();
        $app = AppFactory::createFromContainer($container);

        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();

        $routes = require $rootPath . '/config/routes.php';
        $middleware = require $rootPath . '/config/middleware.php';

        if (!is_callable($routes) || !is_callable($middleware)) {
            throw new UnexpectedValueException(
                'Routes and middleware configuration must return callables.',
            );
        }

        $routes($app);
        $middleware($app);

        return $app;
    }

    private function __construct() {}
}

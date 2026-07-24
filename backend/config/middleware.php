<?php

declare(strict_types=1);

use DI\Container;
use Slim\App;
use Sova\Shared\Infrastructure\Http\Middleware\ApiErrorMiddleware;
use Sova\Shared\Infrastructure\Http\Middleware\CorsMiddleware;
use Sova\Shared\Infrastructure\Http\Middleware\RequestIdMiddleware;

/**
 * @param App<Container> $app
 */
return static function (App $app): void {
    /*
     * Slim middleware is executed in last-in, first-out order:
     * Request ID -> CORS -> API errors -> routing -> body parsing -> action.
     */
    $app->add(ApiErrorMiddleware::class);
    $app->add(CorsMiddleware::class);
    $app->add(RequestIdMiddleware::class);
};

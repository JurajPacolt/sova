<?php

declare(strict_types=1);

use DI\Container;
use Slim\App;
use Sova\Shared\Infrastructure\Http\Middleware\ApiErrorMiddleware;
use Sova\Shared\Infrastructure\Http\Middleware\CorsMiddleware;
use Sova\Shared\Infrastructure\Http\Middleware\RequestIdMiddleware;
use Sova\Shared\Infrastructure\Http\Middleware\SecurityHeadersMiddleware;

/**
 * @param App<Container> $app
 */
return static function (App $app): void {
    /*
     * Slim middleware is executed in last-in, first-out order:
     * Request ID -> security headers -> API errors -> CORS -> routing -> body parsing -> action.
     */
    $app->add(CorsMiddleware::class);
    $app->add(ApiErrorMiddleware::class);
    $app->add(SecurityHeadersMiddleware::class);
    $app->add(RequestIdMiddleware::class);
};

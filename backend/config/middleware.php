<?php

declare(strict_types=1);

use DI\Container;
use Slim\App;
use Sova\Shared\Infrastructure\Http\Middleware\ApiErrorMiddleware;
use Sova\Shared\Infrastructure\Http\Middleware\CorsMiddleware;
use Sova\Shared\Infrastructure\Http\Middleware\RequestIdMiddleware;
use Sova\Shared\Infrastructure\Http\Middleware\RequestLogMiddleware;
use Sova\Shared\Infrastructure\Http\Middleware\SecurityHeadersMiddleware;

/**
 * @param App<Container> $app
 */
return static function (App $app): void {
    /*
     * Slim middleware is executed in last-in, first-out order:
     * Request ID -> request log -> security headers -> API errors -> CORS ->
     * routing -> body parsing -> action.
     *
     * The access log sits directly inside the request ID, so every line it
     * writes can be correlated, and outside the error handling, so the status
     * it reports is the one the client actually received.
     */
    $app->add(CorsMiddleware::class);
    $app->add(ApiErrorMiddleware::class);
    $app->add(SecurityHeadersMiddleware::class);
    $app->add(RequestLogMiddleware::class);
    $app->add(RequestIdMiddleware::class);
};

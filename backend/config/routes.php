<?php

declare(strict_types=1);

use DI\Container;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use Sova\Shared\Presentation\Http\Action\ApiInfoAction;
use Sova\Shared\Presentation\Http\Action\Health\LivenessAction;
use Sova\Shared\Presentation\Http\Action\Health\ReadinessAction;

/**
 * @param App<Container> $app
 */
return static function (App $app): void {
    $app->group('/api/v1', function (RouteCollectorProxy $group): void {
        $group->get('', ApiInfoAction::class)->setName('api.info');

        $group->group('/health', function (RouteCollectorProxy $health): void {
            $health->get('', LivenessAction::class)->setName('health');
            $health->get('/live', LivenessAction::class)->setName('health.live');
            $health->get('/ready', ReadinessAction::class)->setName('health.ready');
        });
    });
};

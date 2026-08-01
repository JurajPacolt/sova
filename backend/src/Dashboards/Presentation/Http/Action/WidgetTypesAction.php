<?php

declare(strict_types=1);

namespace Sova\Dashboards\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sova\Dashboards\Domain\WidgetRegistry\WidgetDefinition;
use Sova\Dashboards\Domain\WidgetRegistry\WidgetRegistry;
use Sova\Dashboards\Presentation\Http\DashboardContext;
use Sova\Dashboards\Presentation\Http\WidgetSerializer;
use Sova\Shared\Presentation\Http\JsonResponse;

/**
 * The widget registry as a contract the client can read: keys, schema versions,
 * sizes and the dimensions each type can aggregate by.
 *
 * It carries no component names and no markup — the client maps a `type_key` to
 * a component itself, so nothing the server sends can name something to run.
 */
final readonly class WidgetTypesAction
{
    public function __construct(
        private WidgetRegistry $registry,
        private WidgetSerializer $serializer,
        private DashboardContext $context,
    ) {}

    /**
     * @throws JsonException
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $this->context->resolve($request);

        return JsonResponse::write($response, [
            'widget_types' => array_map(
                fn(WidgetDefinition $definition): array => $this->serializer
                    ->serializeDefinition($definition),
                $this->registry->definitions(),
            ),
        ]);
    }
}

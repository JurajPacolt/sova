<?php

declare(strict_types=1);

namespace Sova\Dashboards\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sova\Dashboards\Application\DashboardWidget;
use Sova\Dashboards\Application\WidgetService;
use Sova\Dashboards\Presentation\Http\DashboardContext;
use Sova\Dashboards\Presentation\Http\DashboardInput;
use Sova\Dashboards\Presentation\Http\WidgetSerializer;
use Sova\Shared\Presentation\Http\JsonResponse;

/**
 * Applies a whole arrangement in one atomic request.
 *
 * One request rather than one per widget, because moving two widgets past each
 * other is only legal as a pair — a per-widget endpoint would have to reject
 * the first half of a legal move. The dashboard version travels with it, so a
 * second tab arranging the same page loses cleanly with `409` instead of
 * writing half of its layout over this one.
 */
final readonly class DashboardLayoutAction
{
    public function __construct(
        private WidgetService $widgets,
        private WidgetSerializer $serializer,
        private DashboardContext $context,
        private DashboardInput $input,
    ) {}

    /**
     * @param array<string, string> $args
     *
     * @throws JsonException
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        [, $tenant, $subject, $membershipId] = $this->context->resolve($request);
        $dashboardId = $this->input->identifier($args['dashboardId'] ?? '');
        $body = $request->getParsedBody();
        $payload = is_array($body) ? $body : [];

        $this->widgets->applyLayout(
            $subject,
            $tenant->id,
            $dashboardId,
            $membershipId,
            $this->input->version($payload['expected_version'] ?? null),
            $this->input->placements($payload['widgets'] ?? null),
        );

        return JsonResponse::write($response, [
            'widgets' => array_map(
                fn(DashboardWidget $widget): array => $this->serializer->serialize($widget),
                $this->widgets->listForDashboard($tenant->id, $dashboardId, $membershipId),
            ),
        ]);
    }
}

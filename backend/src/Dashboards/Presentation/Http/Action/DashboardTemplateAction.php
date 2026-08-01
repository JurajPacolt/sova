<?php

declare(strict_types=1);

namespace Sova\Dashboards\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sova\Dashboards\Application\DashboardService;
use Sova\Dashboards\Application\DashboardWidget;
use Sova\Dashboards\Application\StarterDashboardProvisioner;
use Sova\Dashboards\Application\WidgetService;
use Sova\Dashboards\Presentation\Http\DashboardContext;
use Sova\Dashboards\Presentation\Http\DashboardSerializer;
use Sova\Dashboards\Presentation\Http\WidgetSerializer;
use Sova\Shared\Presentation\Http\JsonResponse;

/**
 * Builds a dashboard from the system starter template (spec §7.5).
 *
 * Restoring **adds**; it never overwrites. The caller keeps every dashboard,
 * widget and saved query they already had, keeps their default, and keeps where
 * they last were — the point of the route is to hand back a working starting
 * point after somebody has emptied or rearranged theirs, not to reset them.
 * The name is theirs to choose; a collision with one of their own dashboards is
 * resolved by counting up rather than by refusing, since the template is
 * precisely the path for people who do not want to name things first.
 *
 * The response carries the widgets as well, because a dashboard that was just
 * created from a template is never interesting without them.
 */
final readonly class DashboardTemplateAction
{
    public function __construct(
        private StarterDashboardProvisioner $starter,
        private DashboardService $dashboards,
        private WidgetService $widgets,
        private DashboardSerializer $serializer,
        private WidgetSerializer $widgetSerializer,
        private DashboardContext $context,
    ) {}

    /**
     * @throws JsonException
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        [, $tenant, $subject, $membershipId] = $this->context->resolve($request);
        $body = $request->getParsedBody();
        $payload = is_array($body) ? $body : [];
        $name = $payload['name'] ?? null;

        $dashboardId = $this->starter->restore(
            $subject,
            $tenant->id,
            $membershipId,
            is_string($name) ? mb_substr($name, 0, 160) : null,
        );

        return JsonResponse::write(
            $response,
            [
                'dashboard' => $this->serializer->serialize(
                    $this->dashboards->get($tenant->id, $dashboardId, $membershipId),
                    false,
                ),
                'widgets' => array_map(
                    fn(DashboardWidget $widget): array => $this->widgetSerializer->serialize($widget),
                    $this->widgets->listForDashboard($tenant->id, $dashboardId, $membershipId),
                ),
            ],
            201,
        );
    }
}

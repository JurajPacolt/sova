<?php

declare(strict_types=1);

namespace Sova\Dashboards\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sova\Dashboards\Application\Dashboard;
use Sova\Dashboards\Application\DashboardService;
use Sova\Dashboards\Application\StarterDashboardProvisioner;
use Sova\Dashboards\Presentation\Http\DashboardContext;
use Sova\Dashboards\Presentation\Http\DashboardInput;
use Sova\Dashboards\Presentation\Http\DashboardSerializer;
use Sova\Shared\Presentation\Http\JsonResponse;

/**
 * Lists the caller's own dashboards and creates new ones.
 *
 * The list also names which dashboard to open when none was asked for: the last
 * one this member looked at, or their default when that preference is missing
 * or points at something since deleted.
 *
 * A member who has never opened dashboards is given the starter one here (spec
 * §7.2): every active member must have at least one, and making that true on
 * the server means it is true no matter which client asked. It writes only when
 * there is nothing at all, so a repeated or prefetched list changes nothing —
 * and it deliberately leaves the *active* preference alone, because a prefetch
 * must never move where somebody lands next. The new dashboard is their
 * default, which is what an empty preference resolves to anyway.
 */
final readonly class DashboardsAction
{
    public function __construct(
        private DashboardService $dashboards,
        private DashboardSerializer $serializer,
        private DashboardContext $context,
        private DashboardInput $input,
        private StarterDashboardProvisioner $starter,
    ) {}

    /**
     * @throws JsonException
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        [, $tenant, $subject, $membershipId] = $this->context->resolve($request);

        if ($request->getMethod() === 'POST') {
            $body = $request->getParsedBody();
            $payload = is_array($body) ? $body : [];

            $dashboardId = $this->dashboards->create(
                $subject,
                $tenant->id,
                $membershipId,
                $this->input->name($payload['name'] ?? null),
            );

            return JsonResponse::write(
                $response,
                [
                    'dashboard' => $this->serializer->serialize(
                        $this->dashboards->get($tenant->id, $dashboardId, $membershipId),
                        false,
                    ),
                ],
                201,
            );
        }

        $this->starter->ensure($subject, $tenant->id, $membershipId);
        $active = $this->dashboards->resolveActive($tenant->id, $membershipId);

        return JsonResponse::write($response, [
            'dashboards' => array_map(
                fn(Dashboard $dashboard): array => $this->serializer->serialize(
                    $dashboard,
                    $active !== null && $active->id === $dashboard->id,
                ),
                $this->dashboards->listOwned($tenant->id, $membershipId),
            ),
            'active_dashboard_id' => $active?->id,
        ]);
    }
}

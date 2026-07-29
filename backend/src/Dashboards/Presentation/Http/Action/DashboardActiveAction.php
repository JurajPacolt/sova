<?php

declare(strict_types=1);

namespace Sova\Dashboards\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sova\Dashboards\Application\DashboardService;
use Sova\Dashboards\Presentation\Http\DashboardContext;
use Sova\Dashboards\Presentation\Http\DashboardInput;
use Sova\Dashboards\Presentation\Http\DashboardSerializer;
use Sova\Shared\Presentation\Http\JsonResponse;

/**
 * Records which dashboard the caller last opened, so returning to
 * `/t/:tenantSlug/dashboard` lands where they left off.
 *
 * It is a personal preference per tenant, not a property of the dashboard, and
 * needs nothing beyond being able to open it. It is a separate `PUT` rather
 * than a side effect of reading, because a prefetch or a link preview must not
 * move somebody's landing page for them.
 */
final readonly class DashboardActiveAction
{
    public function __construct(
        private DashboardService $dashboards,
        private DashboardSerializer $serializer,
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
        [, $tenant, , $membershipId] = $this->context->resolve($request);
        $dashboardId = $this->input->identifier($args['dashboardId'] ?? '');

        $this->dashboards->setActive($tenant->id, $dashboardId, $membershipId);

        return JsonResponse::write($response, [
            'dashboard' => $this->serializer->serialize(
                $this->dashboards->get($tenant->id, $dashboardId, $membershipId),
                true,
            ),
        ]);
    }
}

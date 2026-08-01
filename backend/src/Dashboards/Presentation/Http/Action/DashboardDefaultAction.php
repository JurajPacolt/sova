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
 * Makes one dashboard the caller's default — the one to fall back on when the
 * active preference is missing or points at something since deleted.
 *
 * Exactly one dashboard is the default at any moment, which the database
 * enforces with a partial unique index; the move happens in one transaction, so
 * there is never an instant with two defaults or none. Repeating the call is a
 * no-op.
 */
final readonly class DashboardDefaultAction
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
        [, $tenant, $subject, $membershipId] = $this->context->resolve($request);
        $dashboardId = $this->input->identifier($args['dashboardId'] ?? '');

        $this->dashboards->makeDefault($subject, $tenant->id, $dashboardId, $membershipId);

        return JsonResponse::write($response, [
            'dashboard' => $this->serializer->serialize(
                $this->dashboards->get($tenant->id, $dashboardId, $membershipId),
                $this->dashboards->activeDashboardId($tenant->id, $membershipId) === $dashboardId,
            ),
        ]);
    }
}

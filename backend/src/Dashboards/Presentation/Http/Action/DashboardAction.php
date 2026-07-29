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
 * Reads, renames, reorders and deletes one dashboard.
 *
 * Reading does **not** record the dashboard as the active one. Marking it is a
 * change, and a change does not belong in a `GET`: a prefetch or a link preview
 * would otherwise move somebody's landing page for them. `PUT …/active` says it
 * out loud instead.
 */
final readonly class DashboardAction
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

        if ($request->getMethod() === 'DELETE') {
            $this->dashboards->delete($subject, $tenant->id, $dashboardId, $membershipId);

            return $response->withStatus(204);
        }

        if ($request->getMethod() === 'PATCH') {
            $body = $request->getParsedBody();
            $payload = is_array($body) ? $body : [];

            $this->dashboards->rename(
                $subject,
                $tenant->id,
                $dashboardId,
                $membershipId,
                $this->input->version($payload['expected_version'] ?? null),
                $this->input->name($payload['name'] ?? null),
                $this->input->position($payload['position'] ?? null),
            );
        }

        $active = $this->dashboards->activeDashboardId($tenant->id, $membershipId);

        return JsonResponse::write($response, [
            'dashboard' => $this->serializer->serialize(
                $this->dashboards->get($tenant->id, $dashboardId, $membershipId),
                $active === $dashboardId,
            ),
        ]);
    }
}

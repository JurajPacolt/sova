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
 * Duplicates a dashboard with its widgets under a new name.
 *
 * The copy points at the **same** saved queries. Duplicating those as well
 * would quietly double the member's query list every time they copied a
 * dashboard, and two identical queries under two names are worse than one.
 * The copy never inherits the default flag.
 */
final readonly class CopyDashboardAction
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
        $body = $request->getParsedBody();
        $payload = is_array($body) ? $body : [];

        $copyId = $this->dashboards->copy(
            $subject,
            $tenant->id,
            $dashboardId,
            $membershipId,
            $this->input->name($payload['name'] ?? null),
        );

        return JsonResponse::write(
            $response,
            [
                'dashboard' => $this->serializer->serialize(
                    $this->dashboards->get($tenant->id, $copyId, $membershipId),
                    false,
                ),
            ],
            201,
        );
    }
}

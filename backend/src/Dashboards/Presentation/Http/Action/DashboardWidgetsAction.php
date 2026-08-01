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
 * Lists and adds the widgets of one dashboard.
 *
 * A new widget lands **under** everything already there rather than squeezing
 * into a gap: predictable beats clever when somebody is arranging a page.
 * Moving it is then one explicit layout request.
 */
final readonly class DashboardWidgetsAction
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

        if ($request->getMethod() === 'POST') {
            $body = $request->getParsedBody();
            $payload = is_array($body) ? $body : [];

            $widgetId = $this->widgets->create(
                $subject,
                $tenant->id,
                $dashboardId,
                $membershipId,
                $this->input->sourceIdentifier($payload['saved_query_id'] ?? null),
                is_string($payload['type_key'] ?? null) ? $payload['type_key'] : '',
                $this->input->title($payload['title'] ?? null),
                $this->input->configuration($payload['configuration'] ?? null),
            );

            return JsonResponse::write(
                $response,
                [
                    'widget' => $this->serializer->serialize(
                        $this->widgets->get($tenant->id, $dashboardId, $widgetId, $membershipId),
                    ),
                ],
                201,
            );
        }

        return JsonResponse::write($response, [
            'widgets' => array_map(
                fn(DashboardWidget $widget): array => $this->serializer->serialize($widget),
                $this->widgets->listForDashboard($tenant->id, $dashboardId, $membershipId),
            ),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Sova\Dashboards\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sova\Dashboards\Application\WidgetService;
use Sova\Dashboards\Presentation\Http\DashboardContext;
use Sova\Dashboards\Presentation\Http\DashboardInput;
use Sova\Dashboards\Presentation\Http\WidgetSerializer;
use Sova\Shared\Presentation\Http\JsonResponse;

/**
 * Reconfigures or removes one widget.
 *
 * The type is fixed for the life of the instance: changing it would reinterpret
 * a configuration written against a different schema, which is exactly what the
 * registry forbids. Swapping the source or the settings is a normal edit and
 * carries the version the caller saw.
 */
final readonly class DashboardWidgetAction
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
        $widgetId = $this->input->widgetIdentifier($args['widgetId'] ?? '');

        if ($request->getMethod() === 'DELETE') {
            $this->widgets->delete($subject, $tenant->id, $dashboardId, $widgetId, $membershipId);

            return $response->withStatus(204);
        }

        $body = $request->getParsedBody();
        $payload = is_array($body) ? $body : [];

        $this->widgets->update(
            $subject,
            $tenant->id,
            $dashboardId,
            $widgetId,
            $membershipId,
            $this->input->version($payload['expected_version'] ?? null),
            $this->input->sourceIdentifier($payload['saved_query_id'] ?? null),
            $this->input->title($payload['title'] ?? null),
            $this->input->configuration($payload['configuration'] ?? null),
        );

        return JsonResponse::write($response, [
            'widget' => $this->serializer->serialize(
                $this->widgets->get($tenant->id, $dashboardId, $widgetId, $membershipId),
            ),
        ]);
    }
}

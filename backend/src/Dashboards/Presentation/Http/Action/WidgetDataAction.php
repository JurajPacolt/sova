<?php

declare(strict_types=1);

namespace Sova\Dashboards\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sova\Dashboards\Application\WidgetDataService;
use Sova\Dashboards\Presentation\Http\DashboardContext;
use Sova\Dashboards\Presentation\Http\DashboardInput;
use Sova\Issues\Application\Search\QueryTimedOutException;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Presentation\Http\JsonResponse;

/**
 * What one widget shows, loaded on its own.
 *
 * Each widget is fetched separately and gets its own result or its own error,
 * so one unreachable source does not blank the whole dashboard (spec §9). The
 * query runs **as the caller**: the same shared dashboard legitimately shows
 * different numbers to different people, because each sees only what their own
 * `issue.view` scope justifies.
 */
final readonly class WidgetDataAction
{
    public function __construct(
        private WidgetDataService $data,
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

        try {
            $payload = $this->data->load(
                $subject,
                $tenant->id,
                $dashboardId,
                $widgetId,
                $membershipId,
            );
        } catch (QueryTimedOutException $exception) {
            // The query was valid and accepted; the server gave up on it.
            throw new DomainProblemException(
                ProblemType::ServiceUnavailable,
                'QUERY_TIMEOUT',
                'The widget query exceeded the execution time limit.',
                previous: $exception,
            );
        }

        return JsonResponse::write($response, ['data' => $payload]);
    }
}

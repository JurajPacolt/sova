<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sova\ProjectConfiguration\Application\WorkflowConfigurationService;
use Sova\ProjectConfiguration\Presentation\Http\ConfigurationSerializer;
use Sova\ProjectConfiguration\Presentation\Http\WorkflowConfigurationRequestContext;
use Sova\Shared\Presentation\Http\JsonResponse;

final readonly class ConfigurationHistoryAction
{
    private const DEFAULT_LIMIT = 50;

    public function __construct(
        private WorkflowConfigurationService $service,
        private ConfigurationSerializer $serializer,
        private WorkflowConfigurationRequestContext $context,
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
        $resolved = $this->context->resolve($request, $args);
        $this->context->requireManage($resolved);

        $entries = $this->service->history(
            $resolved->tenant->id,
            $resolved->projectId,
            $this->limit($request->getQueryParams()['limit'] ?? null),
        );

        return JsonResponse::write($response, [
            'history' => array_map(
                $this->serializer->serializeHistoryEntry(...),
                $entries,
            ),
        ]);
    }

    private function limit(mixed $value): int
    {
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return self::DEFAULT_LIMIT;
    }
}

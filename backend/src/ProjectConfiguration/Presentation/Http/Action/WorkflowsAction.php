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

final readonly class WorkflowsAction
{
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

        return JsonResponse::write($response, [
            'workflows' => array_map(
                $this->serializer->serializeWorkflow(...),
                $this->service->listWorkflows($resolved->tenant->id, $resolved->projectId),
            ),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sova\ProjectConfiguration\Application\DraftContentValidator;
use Sova\ProjectConfiguration\Application\WorkflowConfigurationService;
use Sova\ProjectConfiguration\Application\WorkflowVersionView;
use Sova\ProjectConfiguration\Presentation\Http\ConfigurationSerializer;
use Sova\ProjectConfiguration\Presentation\Http\ResolvedWorkflowRequest;
use Sova\ProjectConfiguration\Presentation\Http\WorkflowConfigurationRequestContext;
use Sova\Shared\Presentation\Http\JsonResponse;

/**
 * The single editable workflow draft: POST copies the published version into a
 * fresh draft, PUT replaces its content. Both need `project.workflow.manage`.
 */
final readonly class WorkflowDraftAction
{
    public function __construct(
        private WorkflowConfigurationService $service,
        private DraftContentValidator $draftValidator,
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

        if ($request->getMethod() === 'POST') {
            return JsonResponse::write($response, [
                'draft_version' => $this->serializer->serializeVersion(
                    $this->service->createDraft(
                        $resolved->tenant->id,
                        $resolved->projectId,
                        $this->context->workflowId($args),
                    ),
                ),
            ], 201);
        }

        return JsonResponse::write($response, [
            'draft_version' => $this->serializer->serializeVersion($this->updateDraft($request, $resolved, $args)),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    private function updateDraft(
        ServerRequestInterface $request,
        ResolvedWorkflowRequest $resolved,
        array $args,
    ): WorkflowVersionView {
        $body = $request->getParsedBody();
        /** @var array<string, mixed> $payload */
        $payload = is_array($body) ? $body : [];

        return $this->service->updateDraft(
            $resolved->tenant->id,
            $resolved->projectId,
            $this->context->workflowId($args),
            $this->draftValidator->parse($payload),
        );
    }
}

<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sova\ProjectConfiguration\Application\IssueTypeAdministrationService;
use Sova\ProjectConfiguration\Presentation\Http\ConfigurationSerializer;
use Sova\ProjectConfiguration\Presentation\Http\IssueTypeRequestInput;
use Sova\ProjectConfiguration\Presentation\Http\WorkflowConfigurationRequestContext;
use Sova\Shared\Presentation\Http\JsonResponse;

final readonly class ProjectIssueTypesAction
{
    public function __construct(
        private IssueTypeAdministrationService $service,
        private ConfigurationSerializer $serializer,
        private IssueTypeRequestInput $input,
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

        if (strtoupper($request->getMethod()) === 'GET') {
            return JsonResponse::write($response, [
                'issue_types' => array_map(
                    $this->serializer->serializeIssueType(...),
                    $this->service->list(
                        $resolved->tenant->id,
                        $resolved->projectId,
                    ),
                ),
            ]);
        }

        $body = $request->getParsedBody();
        /** @var array<string, mixed> $payload */
        $payload = is_array($body) ? $body : [];
        $issueType = $this->service->create(
            $resolved->tenant->id,
            $resolved->projectId,
            $this->input->create($payload),
            $resolved->session->actorUserId,
            $this->context->requestId($request),
            $this->context->ipAddress($request),
        );

        return JsonResponse::write(
            $response,
            ['issue_type' => $this->serializer->serializeIssueType($issueType)],
            201,
        );
    }
}

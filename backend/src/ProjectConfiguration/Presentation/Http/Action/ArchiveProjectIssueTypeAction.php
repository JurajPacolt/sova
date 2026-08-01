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

final readonly class ArchiveProjectIssueTypeAction
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
        $body = $request->getParsedBody();
        /** @var array<string, mixed> $payload */
        $payload = is_array($body) ? $body : [];
        [$expectedConfigVersion, $expectedTypeVersion] =
            $this->input->archiveVersions($payload);
        $issueType = $this->service->archive(
            $resolved->tenant->id,
            $resolved->projectId,
            $this->context->issueTypeId($args),
            $expectedConfigVersion,
            $expectedTypeVersion,
            $resolved->session->actorUserId,
            $this->context->requestId($request),
            $this->context->ipAddress($request),
        );

        return JsonResponse::write($response, [
            'issue_type' => $this->serializer->serializeIssueType($issueType),
        ]);
    }
}

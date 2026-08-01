<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sova\ProjectConfiguration\Application\PublishInput;
use Sova\ProjectConfiguration\Application\WorkflowConfigurationService;
use Sova\ProjectConfiguration\Presentation\Http\ConfigurationSerializer;
use Sova\ProjectConfiguration\Presentation\Http\WorkflowConfigurationRequestContext;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Presentation\Http\JsonResponse;

final readonly class PublishWorkflowAction
{
    private const STATUS_CODE_PATTERN = '/^[A-Z][A-Z0-9_]{1,31}$/';

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
        $this->context->requirePublish($resolved);

        $body = $request->getParsedBody();
        /** @var array<string, mixed> $payload */
        $payload = is_array($body) ? $body : [];

        $published = $this->service->publish(
            $resolved->tenant->id,
            $resolved->projectId,
            $this->context->workflowId($args),
            new PublishInput(
                expectedConfigVersion: $this->expectedConfigVersion(
                    $payload['expected_config_version'] ?? null,
                ),
                statusMapping: $this->statusMapping($payload['status_mapping'] ?? null),
            ),
            $resolved->session->actorUserId,
            $this->context->requestId($request),
            $this->context->ipAddress($request),
        );

        return JsonResponse::write($response, [
            'published_version' => $this->serializer->serializeVersion($published),
        ]);
    }

    private function expectedConfigVersion(mixed $value): int
    {
        if (is_int($value) && $value >= 1) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value >= 1) {
            return (int) $value;
        }

        throw new DomainProblemException(
            ProblemType::ValidationFailed,
            'WORKFLOW_INPUT_INVALID',
            'Send the configuration revision the publish was chosen against.',
            ['expected_config_version' => ['Provide the current configuration revision.']],
        );
    }

    /**
     * @return array<string, string> removed status code => target status code
     */
    private function statusMapping(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (!is_array($value) || array_is_list($value)) {
            throw $this->mappingInvalid();
        }

        $mapping = [];

        foreach ($value as $from => $to) {
            if (
                !is_string($from)
                || !is_string($to)
                || preg_match(self::STATUS_CODE_PATTERN, $from) !== 1
                || preg_match(self::STATUS_CODE_PATTERN, $to) !== 1
            ) {
                throw $this->mappingInvalid();
            }

            $mapping[$from] = $to;
        }

        return $mapping;
    }

    private function mappingInvalid(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::ValidationFailed,
            'WORKFLOW_INPUT_INVALID',
            'The status mapping must map status codes to status codes.',
            ['status_mapping' => ['Map each removed status code to a target status code.']],
        );
    }
}

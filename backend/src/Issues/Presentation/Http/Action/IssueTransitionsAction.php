<?php

declare(strict_types=1);

namespace Sova\Issues\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sova\Authorization\Application\AuthorizationScope;
use Sova\Authorization\Application\AuthorizationService;
use Sova\Authorization\Domain\Permission;
use Sova\Issues\Application\AvailableTransition;
use Sova\Issues\Application\IssueRepository;
use Sova\Issues\Application\IssueService;
use Sova\Issues\Presentation\Http\IssueRequestContext;
use Sova\ProjectConfiguration\Presentation\Http\ConfigurationSerializer;
use Sova\Shared\Presentation\Http\JsonResponse;

final readonly class IssueTransitionsAction
{
    public function __construct(
        private IssueService $issues,
        private IssueRepository $repository,
        private ConfigurationSerializer $serializer,
        private AuthorizationService $authorization,
        private IssueRequestContext $context,
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
        $resolved = $this->context->resolve(
            $request,
            $args['issueId'] ?? '',
            $this->repository,
        );
        $issue = $resolved->issue;
        $this->authorization->require(
            $resolved->subject,
            Permission::IssueView,
            AuthorizationScope::project($issue->tenantId, $issue->projectId),
        );

        $available = $this->issues->availableTransitions(
            $issue,
            $this->context->transitionPermissionCheck(
                $resolved->subject,
                $issue->tenantId,
                $issue->projectId,
            ),
            $this->context->transitionActor(
                $resolved->subject,
                $issue->tenantId,
                $issue->projectId,
                $resolved->actorMembershipId,
            ),
        );

        return JsonResponse::write($response, [
            // The version the list was computed against, so the client can send
            // it back and detect a concurrent change.
            'issue_version' => $issue->version,
            'transitions' => array_map(
                fn(AvailableTransition $entry): array => $this->serializer
                    ->serializeTransition($entry->transition)
                    + ['required_fields' => $entry->requiredFields],
                $available,
            ),
        ]);
    }
}

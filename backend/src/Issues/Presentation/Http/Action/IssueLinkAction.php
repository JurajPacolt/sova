<?php

declare(strict_types=1);

namespace Sova\Issues\Presentation\Http\Action;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sova\Authorization\Application\AuthorizationScope;
use Sova\Authorization\Application\AuthorizationService;
use Sova\Authorization\Domain\Permission;
use Sova\Issues\Application\IssueRepository;
use Sova\Issues\Application\Link\IssueLinkRepository;
use Sova\Issues\Application\Link\IssueLinkService;
use Sova\Issues\Presentation\Http\IssueRequestContext;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;

/**
 * Removes one link. The link is loaded through the issue of the route and must
 * have that issue at one of its ends, so an identifier from another issue or
 * tenant reads as missing. Either end may remove it — the relationship belongs
 * to both — but only with `issue.edit` on the project of the issue being
 * worked from.
 */
final readonly class IssueLinkAction
{
    public function __construct(
        private IssueLinkService $links,
        private IssueLinkRepository $repository,
        private IssueRepository $issues,
        private AuthorizationService $authorization,
        private IssueRequestContext $context,
    ) {}

    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $resolved = $this->context->resolve(
            $request,
            $args['issueId'] ?? '',
            $this->issues,
        );
        $issue = $resolved->issue;
        $this->authorization->require(
            $resolved->subject,
            Permission::IssueEdit,
            AuthorizationScope::project($issue->tenantId, $issue->projectId),
        );

        $link = $this->repository->find($issue->tenantId, $this->identifier($args['linkId'] ?? ''));

        if (
            $link === null
            || ($link->sourceIssueId !== $issue->id && $link->targetIssueId !== $issue->id)
        ) {
            throw $this->notFound();
        }

        $this->links->delete($issue, $link, $resolved->session->actorUserId);

        return $response->withStatus(204);
    }

    private function identifier(string $value): string
    {
        try {
            return (string) UuidV7::fromString($value);
        } catch (InvalidArgumentException) {
            throw $this->notFound();
        }
    }

    private function notFound(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::ResourceNotFound,
            'ISSUE_LINK_NOT_FOUND',
            'The link was not found.',
        );
    }
}

<?php

declare(strict_types=1);

namespace Sova\Issues\Presentation\Http\Action;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sova\Authorization\Application\AuthorizationScope;
use Sova\Authorization\Application\AuthorizationService;
use Sova\Authorization\Domain\Permission;
use Sova\Issues\Application\Attachment\AttachmentDetails;
use Sova\Issues\Application\Attachment\AttachmentRepository;
use Sova\Issues\Application\Attachment\AttachmentService;
use Sova\Issues\Application\IssueRepository;
use Sova\Issues\Presentation\Http\IssueRequestContext;
use Sova\Issues\Presentation\Http\ResolvedIssue;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;

/**
 * Downloads or removes one attachment.
 *
 * Every download is authorised here and now — there is no public URL and no
 * shortcut past this check, which is why the storage directory must stay
 * outside anything the web server serves directly.
 *
 * The response always says `Content-Disposition: attachment` and
 * `X-Content-Type-Options: nosniff`. User-supplied bytes served inline from the
 * API origin would be a stored cross-site scripting vector, so nothing is ever
 * rendered in the browser, whatever its type claims to be.
 */
final readonly class IssueAttachmentAction
{
    public function __construct(
        private AttachmentService $attachments,
        private AttachmentRepository $repository,
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
            Permission::IssueView,
            AuthorizationScope::project($issue->tenantId, $issue->projectId),
        );

        $attachment = $this->attachment($resolved, $args['attachmentId'] ?? '');

        if ($request->getMethod() === 'DELETE') {
            $this->assertMayRemove($resolved, $attachment);
            $this->attachments->delete($issue, $attachment, $resolved->session->actorUserId);

            return $response->withStatus(204);
        }

        $bytes = $this->attachments->download($attachment);
        $response->getBody()->write($bytes);

        return $response
            ->withHeader('Content-Type', $attachment->mediaType)
            ->withHeader('Content-Length', (string) strlen($bytes))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Disposition', sprintf(
                "attachment; filename*=UTF-8''%s",
                rawurlencode($attachment->originalName),
            ));
    }

    /**
     * The uploader may remove their own file; anyone else needs
     * `attachment.moderate` on the project.
     */
    private function assertMayRemove(
        ResolvedIssue $resolved,
        AttachmentDetails $attachment,
    ): void {
        if ($attachment->uploadedByUserId === $resolved->session->actorUserId) {
            return;
        }

        $this->authorization->require(
            $resolved->subject,
            Permission::AttachmentModerate,
            AuthorizationScope::project(
                $resolved->issue->tenantId,
                $resolved->issue->projectId,
            ),
        );
    }

    private function attachment(
        ResolvedIssue $resolved,
        string $attachmentId,
    ): AttachmentDetails {
        try {
            $identifier = (string) UuidV7::fromString($attachmentId);
        } catch (InvalidArgumentException) {
            throw $this->notFound();
        }

        $attachment = $this->repository->find($resolved->issue->tenantId, $identifier);

        if ($attachment === null || $attachment->issueId !== $resolved->issue->id) {
            throw $this->notFound();
        }

        return $attachment;
    }

    private function notFound(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::ResourceNotFound,
            'ATTACHMENT_NOT_FOUND',
            'The attachment was not found.',
        );
    }
}

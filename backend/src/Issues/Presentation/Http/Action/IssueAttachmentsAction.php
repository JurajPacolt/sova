<?php

declare(strict_types=1);

namespace Sova\Issues\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Sova\Authorization\Application\AuthorizationScope;
use Sova\Authorization\Application\AuthorizationService;
use Sova\Authorization\Domain\Permission;
use Sova\Issues\Application\Attachment\AttachmentDetails;
use Sova\Issues\Application\Attachment\AttachmentService;
use Sova\Issues\Application\Attachment\UploadedAttachment;
use Sova\Issues\Application\IssueRepository;
use Sova\Issues\Presentation\Http\AttachmentSerializer;
use Sova\Issues\Presentation\Http\IssueRequestContext;
use Sova\Issues\Presentation\Http\ResolvedIssue;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Presentation\Http\JsonResponse;

/**
 * Lists and accepts the attachments of one issue. Reading needs `issue.view`
 * and uploading needs `attachment.upload`, both on the issue's project.
 *
 * Exactly one file per request, as the MVP contract requires: a multi-file
 * request is refused rather than partially accepted, so the caller always knows
 * what happened.
 */
final readonly class IssueAttachmentsAction
{
    public function __construct(
        private AttachmentService $attachments,
        private IssueRepository $issues,
        private AttachmentSerializer $serializer,
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
            $this->issues,
        );
        $issue = $resolved->issue;
        $this->authorization->require(
            $resolved->subject,
            Permission::IssueView,
            AuthorizationScope::project($issue->tenantId, $issue->projectId),
        );

        if ($request->getMethod() !== 'POST') {
            return JsonResponse::write($response, [
                'attachments' => array_map(
                    fn(AttachmentDetails $attachment): array => $this->serializer
                        ->serialize($attachment),
                    $this->attachments->listForIssue($issue->tenantId, $issue->id),
                ),
            ]);
        }

        $this->authorization->require(
            $resolved->subject,
            Permission::AttachmentUpload,
            AuthorizationScope::project($issue->tenantId, $issue->projectId),
        );

        return JsonResponse::write(
            $response,
            [
                'attachment' => $this->serializer->serialize($this->attachments->upload(
                    $issue,
                    $this->file($request),
                    $this->uploader($resolved),
                    $resolved->session->actorUserId,
                )),
            ],
            201,
        );
    }

    private function file(ServerRequestInterface $request): UploadedAttachment
    {
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;

        if (count($files) !== 1 || !$file instanceof UploadedFileInterface) {
            throw $this->invalid('Attach exactly one file in the "file" field.');
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw $this->invalid('The file did not upload completely.');
        }

        $path = $file->getStream()->getMetadata('uri');
        $size = $file->getSize();

        if (!is_string($path) || !is_file($path)) {
            throw $this->invalid('The uploaded file could not be read.');
        }

        // The size is measured on disk rather than taken from the request, so a
        // understated Content-Length cannot slip past the limit.
        $measured = filesize($path);

        return new UploadedAttachment(
            $file->getClientFilename() ?? 'attachment',
            $path,
            is_int($measured) && $measured > 0 ? $measured : (int) $size,
        );
    }

    private function uploader(ResolvedIssue $resolved): string
    {
        $membershipId = $resolved->actorMembershipId;

        if ($membershipId === null) {
            throw new DomainProblemException(
                ProblemType::PermissionDenied,
                'ATTACHMENT_UPLOADER_REQUIRED',
                'Only a tenant member can attach a file to an issue.',
            );
        }

        return $membershipId;
    }

    private function invalid(string $message): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::ValidationFailed,
            'ATTACHMENT_UPLOAD_INVALID',
            $message,
            ['file' => [$message]],
        );
    }
}

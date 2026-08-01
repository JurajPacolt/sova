<?php

declare(strict_types=1);

namespace Sova\Issues\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sova\Authorization\Application\AuthorizationScope;
use Sova\Authorization\Application\AuthorizationService;
use Sova\Authorization\Domain\Permission;
use Sova\Issues\Application\IssueRepository;
use Sova\Issues\Presentation\Http\IssueRequestContext;
use Sova\Issues\Presentation\Http\IssueSerializer;
use Sova\Shared\Presentation\Http\JsonResponse;

final readonly class IssueAction
{
    public function __construct(
        private IssueRepository $issues,
        private IssueSerializer $serializer,
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
        $this->authorization->require(
            $resolved->subject,
            Permission::IssueView,
            AuthorizationScope::project(
                $resolved->issue->tenantId,
                $resolved->issue->projectId,
            ),
        );

        return JsonResponse::write($response, [
            'issue' => $this->serializer->serialize($resolved->issue),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Sova\Issues\Presentation\Http;

use Sova\Authorization\Application\AuthorizationSubject;
use Sova\Identity\Application\Authentication\SessionContext;
use Sova\Issues\Application\IssueDetails;

final readonly class ResolvedIssue
{
    public function __construct(
        public SessionContext $session,
        public AuthorizationSubject $subject,
        public IssueDetails $issue,
        public ?string $actorMembershipId = null,
    ) {}
}

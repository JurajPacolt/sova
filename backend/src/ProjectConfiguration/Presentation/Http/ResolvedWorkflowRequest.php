<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Presentation\Http;

use Sova\Authorization\Application\AuthorizationSubject;
use Sova\Identity\Application\Authentication\SessionContext;
use Sova\Tenancy\Application\Access\AccessibleTenant;

final readonly class ResolvedWorkflowRequest
{
    public function __construct(
        public SessionContext $session,
        public AccessibleTenant $tenant,
        public AuthorizationSubject $subject,
        public string $projectId,
    ) {}
}

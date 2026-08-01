<?php

declare(strict_types=1);

namespace Sova\Dashboards\Presentation\Http;

use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Sova\Authorization\Application\AuthorizationSubject;
use Sova\Identity\Application\Authentication\SessionContext;
use Sova\Identity\Infrastructure\Http\Middleware\SessionAuthenticationMiddleware;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Tenancy\Application\Access\AccessibleTenant;
use Sova\Tenancy\Infrastructure\Http\Middleware\TenantContextMiddleware;

/**
 * Shared resolution for the dashboard routes.
 *
 * A dashboard is owned by a tenant *membership*, so a caller acting purely on
 * system power has nothing to own and is turned away here rather than reaching
 * a dashboard with a null owner.
 */
final readonly class DashboardContext
{
    /**
     * @return array{SessionContext, AccessibleTenant, AuthorizationSubject, string}
     */
    public function resolve(ServerRequestInterface $request): array
    {
        $session = $request->getAttribute(SessionAuthenticationMiddleware::ATTRIBUTE);
        $tenant = $request->getAttribute(TenantContextMiddleware::ATTRIBUTE);

        if (!$session instanceof SessionContext || !$tenant instanceof AccessibleTenant) {
            throw new RuntimeException('Dashboards require session and tenant contexts.');
        }

        if ($tenant->membershipId === null) {
            throw new DomainProblemException(
                ProblemType::PermissionDenied,
                'DASHBOARD_MEMBERSHIP_REQUIRED',
                'Only a tenant member can work with dashboards.',
            );
        }

        return [
            $session,
            $tenant,
            AuthorizationSubject::contextual(
                $session->actorUserId,
                $session->userId,
                $session->actorIsSuperadmin,
            ),
            $tenant->membershipId,
        ];
    }
}

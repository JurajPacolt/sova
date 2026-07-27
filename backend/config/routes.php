<?php

declare(strict_types=1);

use DI\Container;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use Sova\Authorization\Presentation\Http\Action\MutateTenantRoleAssignmentAction;
use Sova\Authorization\Presentation\Http\Action\MutateTenantRoleDefinitionAction;
use Sova\Authorization\Presentation\Http\Action\TenantRolesAction;
use Sova\Identity\Infrastructure\Http\Middleware\CsrfProtectionMiddleware;
use Sova\Identity\Infrastructure\Http\Middleware\SessionAuthenticationMiddleware;
use Sova\Identity\Presentation\Http\Action\ChangeSystemUserStatusAction;
use Sova\Identity\Presentation\Http\Action\ForgotPasswordAction;
use Sova\Identity\Presentation\Http\Action\GetCurrentSessionAction;
use Sova\Identity\Presentation\Http\Action\ListSessionsAction;
use Sova\Identity\Presentation\Http\Action\LoginAction;
use Sova\Identity\Presentation\Http\Action\LogoutAction;
use Sova\Identity\Presentation\Http\Action\MutateSystemSuperadminAction;
use Sova\Identity\Presentation\Http\Action\RequestEmailVerificationAction;
use Sova\Identity\Presentation\Http\Action\ResetPasswordAction;
use Sova\Identity\Presentation\Http\Action\RevokeSessionAction;
use Sova\Identity\Presentation\Http\Action\SystemImpersonationsAction;
use Sova\Identity\Presentation\Http\Action\SystemUsersAction;
use Sova\Identity\Presentation\Http\Action\VerifyEmailAction;
use Sova\Projects\Presentation\Http\Action\ChangeProjectStatusAction;
use Sova\Projects\Presentation\Http\Action\MutateProjectRoleAssignmentAction;
use Sova\Projects\Presentation\Http\Action\MutateProjectWorkgroupAction;
use Sova\Projects\Presentation\Http\Action\ProjectMembersAction;
use Sova\Projects\Presentation\Http\Action\ProjectRolesAction;
use Sova\Projects\Presentation\Http\Action\ProjectsAction;
use Sova\Projects\Presentation\Http\Action\ProjectWorkgroupsAction;
use Sova\Shared\Presentation\Http\Action\ApiInfoAction;
use Sova\Shared\Presentation\Http\Action\Health\LivenessAction;
use Sova\Shared\Presentation\Http\Action\Health\ReadinessAction;
use Sova\Shared\Presentation\Http\Action\SystemSecurityAuditAction;
use Sova\Shared\Presentation\Http\Action\TenantSecurityAuditAction;
use Sova\Shared\Presentation\Http\Action\TenantSecurityAuditExportAction;
use Sova\Tenancy\Infrastructure\Http\Middleware\TenantContextMiddleware;
use Sova\Tenancy\Presentation\Http\Action\AcceptExistingAccountInvitationAction;
use Sova\Tenancy\Presentation\Http\Action\AcceptNewAccountInvitationAction;
use Sova\Tenancy\Presentation\Http\Action\ChangeSystemTenantStatusAction;
use Sova\Tenancy\Presentation\Http\Action\ChangeTenantMembershipStatusAction;
use Sova\Tenancy\Presentation\Http\Action\CreateInvitationAction;
use Sova\Tenancy\Presentation\Http\Action\GetTenantAction;
use Sova\Tenancy\Presentation\Http\Action\InspectInvitationAction;
use Sova\Tenancy\Presentation\Http\Action\ListTenantsAction;
use Sova\Tenancy\Presentation\Http\Action\SystemTenantsAction;
use Sova\Tenancy\Presentation\Http\Action\TenantMembershipsAction;
use Sova\Workgroups\Presentation\Http\Action\ChangeWorkgroupStatusAction;
use Sova\Workgroups\Presentation\Http\Action\MutateWorkgroupMemberAction;
use Sova\Workgroups\Presentation\Http\Action\WorkgroupMembersAction;
use Sova\Workgroups\Presentation\Http\Action\WorkgroupsAction;

/**
 * @param App<Container> $app
 */
return static function (App $app): void {
    $app->group('/api/v1', function (RouteCollectorProxy $group): void {
        $group->get('', ApiInfoAction::class)->setName('api.info');

        $group->group('/health', function (RouteCollectorProxy $health): void {
            $health->get('', LivenessAction::class)->setName('health');
            $health->get('/live', LivenessAction::class)->setName('health.live');
            $health->get('/ready', ReadinessAction::class)->setName('health.ready');
        });

        $group->group('/auth', function (RouteCollectorProxy $auth): void {
            $auth->post('/login', LoginAction::class)->setName('auth.login');
            $auth
                ->post('/password/forgot', ForgotPasswordAction::class)
                ->setName('auth.password.forgot');
            $auth
                ->post('/password/reset', ResetPasswordAction::class)
                ->setName('auth.password.reset');
            $auth
                ->post(
                    '/email/verification/request',
                    RequestEmailVerificationAction::class,
                )
                ->setName('auth.email.verification.request');
            $auth
                ->post('/email/verify', VerifyEmailAction::class)
                ->setName('auth.email.verify');
            $auth
                ->post(
                    '/invitations/inspect',
                    InspectInvitationAction::class,
                )
                ->setName('auth.invitations.inspect');
            $auth
                ->post(
                    '/invitations/accept',
                    AcceptNewAccountInvitationAction::class,
                )
                ->setName('auth.invitations.accept-new');

            $protected = $auth->group('', function (RouteCollectorProxy $session): void {
                $session->post('/logout', LogoutAction::class)->setName('auth.logout');
                $session
                    ->get('/session', GetCurrentSessionAction::class)
                    ->setName('auth.session.current');
                $session->get('/sessions', ListSessionsAction::class)->setName('auth.sessions');
                $session->delete(
                    '/sessions/{sessionId}',
                    RevokeSessionAction::class,
                )->setName('auth.sessions.revoke');
                $session->post(
                    '/invitations/accept-existing',
                    AcceptExistingAccountInvitationAction::class,
                )->setName('auth.invitations.accept-existing');
            });
            $protected->add(CsrfProtectionMiddleware::class);
            $protected->add(SessionAuthenticationMiddleware::class);
        });

        $group
            ->get('/tenants', ListTenantsAction::class)
            ->setName('tenants.list')
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->get('/tenants/{tenantId}', GetTenantAction::class)
            ->setName('tenants.get')
            ->add(TenantContextMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->post(
                '/tenants/{tenantId}/invitations',
                CreateInvitationAction::class,
            )
            ->setName('tenants.invitations.create')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->map(
                ['GET', 'POST'],
                '/tenants/{tenantId}/roles',
                TenantRolesAction::class,
            )
            ->setName('tenants.roles.collection')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->map(
                ['PUT', 'DELETE'],
                '/tenants/{tenantId}/roles/{roleId}',
                MutateTenantRoleDefinitionAction::class,
            )
            ->setName('tenants.roles.mutate')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->map(
                ['PUT', 'DELETE'],
                '/tenants/{tenantId}/memberships/{membershipId}'
                    . '/roles/{roleId}',
                MutateTenantRoleAssignmentAction::class,
            )
            ->setName('tenants.memberships.roles.mutate')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->get(
                '/tenants/{tenantId}/memberships',
                TenantMembershipsAction::class,
            )
            ->setName('tenants.memberships.list')
            ->add(TenantContextMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->patch(
                '/tenants/{tenantId}/memberships/{membershipId}',
                ChangeTenantMembershipStatusAction::class,
            )
            ->setName('tenants.memberships.status')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->get(
                '/tenants/{tenantId}/audit',
                TenantSecurityAuditAction::class,
            )
            ->setName('tenants.audit.list')
            ->add(TenantContextMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->get(
                '/tenants/{tenantId}/audit/export',
                TenantSecurityAuditExportAction::class,
            )
            ->setName('tenants.audit.export')
            ->add(TenantContextMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->map(
                ['GET', 'POST'],
                '/tenants/{tenantId}/workgroups',
                WorkgroupsAction::class,
            )
            ->setName('tenants.workgroups.collection')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->patch(
                '/tenants/{tenantId}/workgroups/{workgroupId}',
                ChangeWorkgroupStatusAction::class,
            )
            ->setName('tenants.workgroups.status')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->get(
                '/tenants/{tenantId}/workgroups/{workgroupId}/members',
                WorkgroupMembersAction::class,
            )
            ->setName('tenants.workgroups.members.list')
            ->add(TenantContextMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->map(
                ['PUT', 'DELETE'],
                '/tenants/{tenantId}/workgroups/{workgroupId}'
                    . '/members/{membershipId}',
                MutateWorkgroupMemberAction::class,
            )
            ->setName('tenants.workgroups.members.mutate')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->map(
                ['GET', 'POST'],
                '/tenants/{tenantId}/projects',
                ProjectsAction::class,
            )
            ->setName('tenants.projects.collection')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->patch(
                '/tenants/{tenantId}/projects/{projectId}',
                ChangeProjectStatusAction::class,
            )
            ->setName('tenants.projects.status')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->get(
                '/tenants/{tenantId}/projects/{projectId}/roles',
                ProjectRolesAction::class,
            )
            ->setName('tenants.projects.roles.list')
            ->add(TenantContextMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->get(
                '/tenants/{tenantId}/projects/{projectId}/members',
                ProjectMembersAction::class,
            )
            ->setName('tenants.projects.members.list')
            ->add(TenantContextMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->map(
                ['PUT', 'DELETE'],
                '/tenants/{tenantId}/projects/{projectId}'
                    . '/members/{membershipId}/roles/{roleId}',
                MutateProjectRoleAssignmentAction::class,
            )
            ->setName('tenants.projects.members.roles.mutate')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->get(
                '/tenants/{tenantId}/projects/{projectId}/workgroups',
                ProjectWorkgroupsAction::class,
            )
            ->setName('tenants.projects.workgroups.list')
            ->add(TenantContextMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->map(
                ['PUT', 'DELETE'],
                '/tenants/{tenantId}/projects/{projectId}'
                    . '/workgroups/{workgroupId}',
                MutateProjectWorkgroupAction::class,
            )
            ->setName('tenants.projects.workgroups.mutate')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $system = $group->group('/system', function (
            RouteCollectorProxy $system,
        ): void {
            $system
                ->map(['GET', 'POST'], '/tenants', SystemTenantsAction::class)
                ->setName('system.tenants.collection');
            $system
                ->patch(
                    '/tenants/{tenantId}',
                    ChangeSystemTenantStatusAction::class,
                )
                ->setName('system.tenants.status');
            $system
                ->get('/audit', SystemSecurityAuditAction::class)
                ->setName('system.audit.list');
            $system
                ->get('/users', SystemUsersAction::class)
                ->setName('system.users.list');
            $system
                ->patch(
                    '/users/{userId}',
                    ChangeSystemUserStatusAction::class,
                )
                ->setName('system.users.status');
            $system
                ->map(
                    ['PUT', 'DELETE'],
                    '/users/{userId}/superadmin',
                    MutateSystemSuperadminAction::class,
                )
                ->setName('system.users.superadmin');
            $system
                ->post('/impersonations', SystemImpersonationsAction::class)
                ->setName('system.impersonations.start');
            $system
                ->delete(
                    '/impersonations/current',
                    SystemImpersonationsAction::class,
                )
                ->setName('system.impersonations.end');
        });
        $system->add(CsrfProtectionMiddleware::class);
        $system->add(SessionAuthenticationMiddleware::class);
    });
};

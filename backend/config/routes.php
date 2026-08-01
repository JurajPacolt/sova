<?php

declare(strict_types=1);

use DI\Container;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use Sova\Authorization\Presentation\Http\Action\MutateTenantRoleAssignmentAction;
use Sova\Authorization\Presentation\Http\Action\MutateTenantRoleDefinitionAction;
use Sova\Authorization\Presentation\Http\Action\TenantRolesAction;
use Sova\Dashboards\Presentation\Http\Action\CopyDashboardAction;
use Sova\Dashboards\Presentation\Http\Action\DashboardAction;
use Sova\Dashboards\Presentation\Http\Action\DashboardActiveAction;
use Sova\Dashboards\Presentation\Http\Action\DashboardDefaultAction;
use Sova\Dashboards\Presentation\Http\Action\DashboardLayoutAction;
use Sova\Dashboards\Presentation\Http\Action\DashboardsAction;
use Sova\Dashboards\Presentation\Http\Action\DashboardTemplateAction;
use Sova\Dashboards\Presentation\Http\Action\DashboardWidgetAction;
use Sova\Dashboards\Presentation\Http\Action\DashboardWidgetsAction;
use Sova\Dashboards\Presentation\Http\Action\WidgetDataAction;
use Sova\Dashboards\Presentation\Http\Action\WidgetTypesAction;
use Sova\Identity\Infrastructure\Http\Middleware\CsrfProtectionMiddleware;
use Sova\Identity\Infrastructure\Http\Middleware\SessionAuthenticationMiddleware;
use Sova\Identity\Presentation\Http\Action\BeginMfaEnrollmentAction;
use Sova\Identity\Presentation\Http\Action\ChangeSystemUserStatusAction;
use Sova\Identity\Presentation\Http\Action\ConfirmMfaEnrollmentAction;
use Sova\Identity\Presentation\Http\Action\ForgotPasswordAction;
use Sova\Identity\Presentation\Http\Action\GetCurrentSessionAction;
use Sova\Identity\Presentation\Http\Action\ListSessionsAction;
use Sova\Identity\Presentation\Http\Action\LoginAction;
use Sova\Identity\Presentation\Http\Action\LogoutAction;
use Sova\Identity\Presentation\Http\Action\MfaAction;
use Sova\Identity\Presentation\Http\Action\MutateSystemSuperadminAction;
use Sova\Identity\Presentation\Http\Action\RegenerateMfaRecoveryCodesAction;
use Sova\Identity\Presentation\Http\Action\RequestEmailVerificationAction;
use Sova\Identity\Presentation\Http\Action\ResetPasswordAction;
use Sova\Identity\Presentation\Http\Action\RevokeSessionAction;
use Sova\Identity\Presentation\Http\Action\SystemImpersonationsAction;
use Sova\Identity\Presentation\Http\Action\SystemUsersAction;
use Sova\Identity\Presentation\Http\Action\VerifyEmailAction;
use Sova\Issues\Presentation\Http\Action\ChangeIssueTypeAction;
use Sova\Issues\Presentation\Http\Action\ExecuteIssueTransitionAction;
use Sova\Issues\Presentation\Http\Action\IssueAction;
use Sova\Issues\Presentation\Http\Action\IssueAttachmentAction;
use Sova\Issues\Presentation\Http\Action\IssueAttachmentsAction;
use Sova\Issues\Presentation\Http\Action\IssueCommentAction;
use Sova\Issues\Presentation\Http\Action\IssueCommentsAction;
use Sova\Issues\Presentation\Http\Action\IssueHistoryAction;
use Sova\Issues\Presentation\Http\Action\IssueLinkAction;
use Sova\Issues\Presentation\Http\Action\IssueLinksAction;
use Sova\Issues\Presentation\Http\Action\IssueQueryMetadataAction;
use Sova\Issues\Presentation\Http\Action\IssuesAction;
use Sova\Issues\Presentation\Http\Action\IssueTransitionsAction;
use Sova\Issues\Presentation\Http\Action\IssueWatchAction;
use Sova\Issues\Presentation\Http\Action\IssueWatchersAction;
use Sova\Issues\Presentation\Http\Action\SearchIssuesAction;
use Sova\Issues\Presentation\Http\Action\ValidateIssueQueryAction;
use Sova\Notifications\Presentation\Http\Action\MarkNotificationsReadAction;
use Sova\Notifications\Presentation\Http\Action\NotificationPreferencesAction;
use Sova\Notifications\Presentation\Http\Action\NotificationsAction;
use Sova\ProjectConfiguration\Presentation\Http\Action\ArchiveProjectIssueTypeAction;
use Sova\ProjectConfiguration\Presentation\Http\Action\ConfigurationHistoryAction;
use Sova\ProjectConfiguration\Presentation\Http\Action\ProjectConfigurationAction;
use Sova\ProjectConfiguration\Presentation\Http\Action\ProjectIssueTypeAction;
use Sova\ProjectConfiguration\Presentation\Http\Action\ProjectIssueTypesAction;
use Sova\ProjectConfiguration\Presentation\Http\Action\PublishWorkflowAction;
use Sova\ProjectConfiguration\Presentation\Http\Action\ValidateWorkflowDraftAction;
use Sova\ProjectConfiguration\Presentation\Http\Action\WorkflowDraftAction;
use Sova\ProjectConfiguration\Presentation\Http\Action\WorkflowImpactAction;
use Sova\ProjectConfiguration\Presentation\Http\Action\WorkflowsAction;
use Sova\Projects\Presentation\Http\Action\ChangeProjectAction;
use Sova\Projects\Presentation\Http\Action\MutateProjectRoleAssignmentAction;
use Sova\Projects\Presentation\Http\Action\MutateProjectWorkgroupAction;
use Sova\Projects\Presentation\Http\Action\ProjectMembersAction;
use Sova\Projects\Presentation\Http\Action\ProjectRolesAction;
use Sova\Projects\Presentation\Http\Action\ProjectsAction;
use Sova\Projects\Presentation\Http\Action\ProjectWorkgroupsAction;
use Sova\SavedQueries\Presentation\Http\Action\ArchiveSavedQueryAction;
use Sova\SavedQueries\Presentation\Http\Action\SavedQueriesAction;
use Sova\SavedQueries\Presentation\Http\Action\SavedQueryAction;
use Sova\SavedQueries\Presentation\Http\Action\SavedQueryFavouriteAction;
use Sova\SavedQueries\Presentation\Http\Action\SavedQueryGrantsAction;
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
use Sova\Tenancy\Presentation\Http\Action\GetTenantAction;
use Sova\Tenancy\Presentation\Http\Action\InspectInvitationAction;
use Sova\Tenancy\Presentation\Http\Action\InvitationAction;
use Sova\Tenancy\Presentation\Http\Action\ListTenantsAction;
use Sova\Tenancy\Presentation\Http\Action\ResendInvitationAction;
use Sova\Tenancy\Presentation\Http\Action\SystemTenantsAction;
use Sova\Tenancy\Presentation\Http\Action\TenantInvitationsAction;
use Sova\Tenancy\Presentation\Http\Action\TenantMembershipsAction;
use Sova\Tenancy\Presentation\Http\Action\TenantSettingsAction;
use Sova\Tenancy\Presentation\Http\Action\UpdateTenantSettingsAction;
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
                $session
                    ->map(['GET', 'DELETE'], '/mfa', MfaAction::class)
                    ->setName('auth.mfa');
                $session
                    ->post(
                        '/mfa/enrollment',
                        BeginMfaEnrollmentAction::class,
                    )
                    ->setName('auth.mfa.enrollment.begin');
                $session
                    ->post(
                        '/mfa/enrollment/confirm',
                        ConfirmMfaEnrollmentAction::class,
                    )
                    ->setName('auth.mfa.enrollment.confirm');
                $session
                    ->post(
                        '/mfa/recovery-codes',
                        RegenerateMfaRecoveryCodesAction::class,
                    )
                    ->setName('auth.mfa.recovery-codes.regenerate');
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
            ->get(
                '/tenants/{tenantId}/settings',
                TenantSettingsAction::class,
            )
            ->setName('tenants.settings.get')
            ->add(TenantContextMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->patch(
                '/tenants/{tenantId}/settings/{section}',
                UpdateTenantSettingsAction::class,
            )
            ->setName('tenants.settings.update')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->map(
                ['GET', 'POST'],
                '/tenants/{tenantId}/invitations',
                TenantInvitationsAction::class,
            )
            ->setName('tenants.invitations.collection')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->map(
                ['PATCH', 'DELETE'],
                '/tenants/{tenantId}/invitations/{invitationId}',
                InvitationAction::class,
            )
            ->setName('tenants.invitations.mutate')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->post(
                '/tenants/{tenantId}/invitations/{invitationId}/resend',
                ResendInvitationAction::class,
            )
            ->setName('tenants.invitations.resend')
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
                ChangeProjectAction::class,
            )
            ->setName('tenants.projects.change')
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

        $group
            ->get(
                '/tenants/{tenantId}/projects/{projectId}/configuration',
                ProjectConfigurationAction::class,
            )
            ->setName('tenants.projects.configuration')
            ->add(TenantContextMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->get(
                '/tenants/{tenantId}/projects/{projectId}/configuration/history',
                ConfigurationHistoryAction::class,
            )
            ->setName('tenants.projects.configuration.history')
            ->add(TenantContextMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->map(
                ['GET', 'POST'],
                '/tenants/{tenantId}/projects/{projectId}/issue-types',
                ProjectIssueTypesAction::class,
            )
            ->setName('tenants.projects.issue-types.collection')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->patch(
                '/tenants/{tenantId}/projects/{projectId}/issue-types/{issueTypeId}',
                ProjectIssueTypeAction::class,
            )
            ->setName('tenants.projects.issue-types.update')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->post(
                '/tenants/{tenantId}/projects/{projectId}'
                    . '/issue-types/{issueTypeId}/archive',
                ArchiveProjectIssueTypeAction::class,
            )
            ->setName('tenants.projects.issue-types.archive')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->get(
                '/tenants/{tenantId}/projects/{projectId}/workflows',
                WorkflowsAction::class,
            )
            ->setName('tenants.projects.workflows.list')
            ->add(TenantContextMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->get(
                '/tenants/{tenantId}/projects/{projectId}/workflows/{workflowId}/validation',
                ValidateWorkflowDraftAction::class,
            )
            ->setName('tenants.projects.workflows.validation')
            ->add(TenantContextMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->get(
                '/tenants/{tenantId}/projects/{projectId}/workflows/{workflowId}/impact',
                WorkflowImpactAction::class,
            )
            ->setName('tenants.projects.workflows.impact')
            ->add(TenantContextMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->map(
                ['POST', 'PUT'],
                '/tenants/{tenantId}/projects/{projectId}/workflows/{workflowId}/draft',
                WorkflowDraftAction::class,
            )
            ->setName('tenants.projects.workflows.draft')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->post(
                '/tenants/{tenantId}/projects/{projectId}/workflows/{workflowId}/publish',
                PublishWorkflowAction::class,
            )
            ->setName('tenants.projects.workflows.publish')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->map(
                ['GET', 'POST'],
                '/tenants/{tenantId}/projects/{projectId}/issues',
                IssuesAction::class,
            )
            ->setName('tenants.projects.issues.collection')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->post('/tenants/{tenantId}/issues/search', SearchIssuesAction::class)
            ->setName('tenants.issues.search')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->post(
                '/tenants/{tenantId}/issue-query/validate',
                ValidateIssueQueryAction::class,
            )
            ->setName('tenants.issue-query.validate')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->get(
                '/tenants/{tenantId}/issue-query/metadata',
                IssueQueryMetadataAction::class,
            )
            ->setName('tenants.issue-query.metadata')
            ->add(TenantContextMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->get('/tenants/{tenantId}/issues/{issueId}', IssueAction::class)
            ->setName('tenants.issues.detail')
            ->add(TenantContextMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->get(
                '/tenants/{tenantId}/issues/{issueId}/transitions',
                IssueTransitionsAction::class,
            )
            ->setName('tenants.issues.transitions.list')
            ->add(TenantContextMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->post(
                '/tenants/{tenantId}/issues/{issueId}/transitions/{transitionId}',
                ExecuteIssueTransitionAction::class,
            )
            ->setName('tenants.issues.transitions.execute')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->map(['GET', 'POST'], '/tenants/{tenantId}/saved-queries', SavedQueriesAction::class)
            ->setName('tenants.savedQueries.collection')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->map(
                ['GET', 'PATCH'],
                '/tenants/{tenantId}/saved-queries/{savedQueryId}',
                SavedQueryAction::class,
            )
            ->setName('tenants.savedQueries.item')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->map(
                ['GET', 'PUT'],
                '/tenants/{tenantId}/saved-queries/{savedQueryId}/grants',
                SavedQueryGrantsAction::class,
            )
            ->setName('tenants.savedQueries.grants')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->map(
                ['PUT', 'DELETE'],
                '/tenants/{tenantId}/saved-queries/{savedQueryId}/favourite',
                SavedQueryFavouriteAction::class,
            )
            ->setName('tenants.savedQueries.favourite')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->post(
                '/tenants/{tenantId}/saved-queries/{savedQueryId}/archive',
                ArchiveSavedQueryAction::class,
            )
            ->setName('tenants.savedQueries.archive')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->map(['GET', 'POST'], '/tenants/{tenantId}/dashboards', DashboardsAction::class)
            ->setName('tenants.dashboards.collection')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        // Declared before `{dashboardId}`, which would otherwise swallow the
        // literal segment and answer "no such dashboard".
        $group
            ->post(
                '/tenants/{tenantId}/dashboards/from-template',
                DashboardTemplateAction::class,
            )
            ->setName('tenants.dashboards.fromTemplate')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->map(
                ['GET', 'PATCH', 'DELETE'],
                '/tenants/{tenantId}/dashboards/{dashboardId}',
                DashboardAction::class,
            )
            ->setName('tenants.dashboards.item')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->put(
                '/tenants/{tenantId}/dashboards/{dashboardId}/default',
                DashboardDefaultAction::class,
            )
            ->setName('tenants.dashboards.default')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->put(
                '/tenants/{tenantId}/dashboards/{dashboardId}/active',
                DashboardActiveAction::class,
            )
            ->setName('tenants.dashboards.active')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->post(
                '/tenants/{tenantId}/dashboards/{dashboardId}/copy',
                CopyDashboardAction::class,
            )
            ->setName('tenants.dashboards.copy')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->map(
                ['GET', 'POST'],
                '/tenants/{tenantId}/dashboards/{dashboardId}/widgets',
                DashboardWidgetsAction::class,
            )
            ->setName('tenants.dashboards.widgets.collection')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->map(
                ['PATCH', 'DELETE'],
                '/tenants/{tenantId}/dashboards/{dashboardId}/widgets/{widgetId}',
                DashboardWidgetAction::class,
            )
            ->setName('tenants.dashboards.widgets.item')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->put(
                '/tenants/{tenantId}/dashboards/{dashboardId}/layout',
                DashboardLayoutAction::class,
            )
            ->setName('tenants.dashboards.layout')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->get(
                '/tenants/{tenantId}/dashboards/{dashboardId}/widgets/{widgetId}/data',
                WidgetDataAction::class,
            )
            ->setName('tenants.dashboards.widgets.data')
            ->add(TenantContextMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->get('/tenants/{tenantId}/widget-types', WidgetTypesAction::class)
            ->setName('tenants.widgetTypes')
            ->add(TenantContextMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->get('/tenants/{tenantId}/notifications', NotificationsAction::class)
            ->setName('tenants.notifications.list')
            ->add(TenantContextMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->map(
                ['GET', 'PUT'],
                '/tenants/{tenantId}/notification-preferences',
                NotificationPreferencesAction::class,
            )
            ->setName('tenants.notifications.preferences')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->post(
                '/tenants/{tenantId}/notifications/read',
                MarkNotificationsReadAction::class,
            )
            ->setName('tenants.notifications.read')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->map(
                ['GET', 'POST'],
                '/tenants/{tenantId}/issues/{issueId}/comments',
                IssueCommentsAction::class,
            )
            ->setName('tenants.issues.comments.collection')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->map(
                ['PATCH', 'DELETE'],
                '/tenants/{tenantId}/issues/{issueId}/comments/{commentId}',
                IssueCommentAction::class,
            )
            ->setName('tenants.issues.comments.item')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->map(
                ['GET', 'POST'],
                '/tenants/{tenantId}/issues/{issueId}/attachments',
                IssueAttachmentsAction::class,
            )
            ->setName('tenants.issues.attachments.collection')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->map(
                ['GET', 'DELETE'],
                '/tenants/{tenantId}/issues/{issueId}/attachments/{attachmentId}',
                IssueAttachmentAction::class,
            )
            ->setName('tenants.issues.attachments.item')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->map(
                ['GET', 'POST'],
                '/tenants/{tenantId}/issues/{issueId}/links',
                IssueLinksAction::class,
            )
            ->setName('tenants.issues.links.collection')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->delete(
                '/tenants/{tenantId}/issues/{issueId}/links/{linkId}',
                IssueLinkAction::class,
            )
            ->setName('tenants.issues.links.item')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->get(
                '/tenants/{tenantId}/issues/{issueId}/watchers',
                IssueWatchersAction::class,
            )
            ->setName('tenants.issues.watchers.list')
            ->add(TenantContextMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->map(
                ['PUT', 'DELETE'],
                '/tenants/{tenantId}/issues/{issueId}/watchers/me',
                IssueWatchAction::class,
            )
            ->setName('tenants.issues.watchers.self')
            ->add(TenantContextMiddleware::class)
            ->add(CsrfProtectionMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->get(
                '/tenants/{tenantId}/issues/{issueId}/history',
                IssueHistoryAction::class,
            )
            ->setName('tenants.issues.history.list')
            ->add(TenantContextMiddleware::class)
            ->add(SessionAuthenticationMiddleware::class);

        $group
            ->post(
                '/tenants/{tenantId}/issues/{issueId}/type',
                ChangeIssueTypeAction::class,
            )
            ->setName('tenants.issues.type.change')
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

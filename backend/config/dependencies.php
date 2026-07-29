<?php

declare(strict_types=1);

use function DI\autowire;
use function DI\factory;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Sova\Authorization\Application\EffectivePermissionProvider;
use Sova\Authorization\Application\TenantRoleProvisioner;
use Sova\Authorization\Application\TenantRoleRepository;
use Sova\Authorization\Infrastructure\Persistence\DoctrineEffectivePermissionProvider;
use Sova\Authorization\Infrastructure\Persistence\DoctrineTenantRoleProvisioner;
use Sova\Authorization\Infrastructure\Persistence\DoctrineTenantRoleRepository;
use Sova\Dashboards\Application\DashboardRepository;
use Sova\Dashboards\Application\WidgetRepository;
use Sova\Dashboards\Infrastructure\Persistence\DoctrineDashboardRepository;
use Sova\Dashboards\Infrastructure\Persistence\DoctrineWidgetRepository;
use Sova\Dashboards\Infrastructure\SavedQueries\WidgetSavedQueryUsageProbe;
use Sova\Identity\Application\Authentication\AuthenticationEventRecorder;
use Sova\Identity\Application\Authentication\LoginRateLimiter;
use Sova\Identity\Application\Authentication\UserCredentialsRepository;
use Sova\Identity\Application\Authentication\UserSessionRepository;
use Sova\Identity\Application\EmailVerification\EmailVerificationMailer;
use Sova\Identity\Application\Impersonation\ImpersonationRepository;
use Sova\Identity\Application\PasswordRecovery\PasswordResetMailer;
use Sova\Identity\Application\Security\PasswordHasher;
use Sova\Identity\Application\Security\PublicEmailRateLimiter;
use Sova\Identity\Application\System\SystemUserRepository;
use Sova\Identity\Application\Token\OneTimeTokenRepository;
use Sova\Identity\Application\Token\UserActionRequestPublisher;
use Sova\Identity\Infrastructure\Mail\SymfonyEmailVerificationMailer;
use Sova\Identity\Infrastructure\Mail\SymfonyPasswordResetMailer;
use Sova\Identity\Infrastructure\Persistence\DoctrineAuthenticationEventRecorder;
use Sova\Identity\Infrastructure\Persistence\DoctrineImpersonationRepository;
use Sova\Identity\Infrastructure\Persistence\DoctrineLoginRateLimiter;
use Sova\Identity\Infrastructure\Persistence\DoctrineOneTimeTokenRepository;
use Sova\Identity\Infrastructure\Persistence\DoctrinePublicEmailRateLimiter;
use Sova\Identity\Infrastructure\Persistence\DoctrineSystemUserRepository;
use Sova\Identity\Infrastructure\Persistence\DoctrineUserActionRequestPublisher;
use Sova\Identity\Infrastructure\Persistence\DoctrineUserCredentialsRepository;
use Sova\Identity\Infrastructure\Persistence\DoctrineUserSessionRepository;
use Sova\Identity\Infrastructure\Security\Argon2idPasswordHasher;
use Sova\Issues\Application\Attachment\AttachmentRepository;
use Sova\Issues\Application\Attachment\AttachmentScanner;
use Sova\Issues\Application\Attachment\AttachmentStorage;
use Sova\Issues\Application\Comment\CommentEventPublisher;
use Sova\Issues\Application\Comment\CommentRepository;
use Sova\Issues\Application\History\HistoryRepository;
use Sova\Issues\Application\IssueEventPublisher;
use Sova\Issues\Application\IssueRepository;
use Sova\Issues\Application\Link\IssueLinkRepository;
use Sova\Issues\Application\Search\IssueAggregationRepository;
use Sova\Issues\Application\Search\IssueSearchRepository;
use Sova\Issues\Application\Search\QueryCompiler;
use Sova\Issues\Application\Search\QueryRateLimiter;
use Sova\Issues\Application\Search\ReferenceResolver;
use Sova\Issues\Application\Search\SearchScopeProvider;
use Sova\Issues\Application\Watcher\WatcherRepository;
use Sova\Issues\Domain\QueryLanguage\QueryLimits;
use Sova\Issues\Infrastructure\Persistence\DoctrineAttachmentRepository;
use Sova\Issues\Infrastructure\Persistence\DoctrineCommentEventPublisher;
use Sova\Issues\Infrastructure\Persistence\DoctrineCommentRepository;
use Sova\Issues\Infrastructure\Persistence\DoctrineHistoryRepository;
use Sova\Issues\Infrastructure\Persistence\DoctrineIssueAggregationRepository;
use Sova\Issues\Infrastructure\Persistence\DoctrineIssueEventPublisher;
use Sova\Issues\Infrastructure\Persistence\DoctrineIssueLinkRepository;
use Sova\Issues\Infrastructure\Persistence\DoctrineIssueMigrator;
use Sova\Issues\Infrastructure\Persistence\DoctrineIssueRepository;
use Sova\Issues\Infrastructure\Persistence\DoctrineIssueSearchRepository;
use Sova\Issues\Infrastructure\Persistence\DoctrineQueryRateLimiter;
use Sova\Issues\Infrastructure\Persistence\DoctrineReferenceResolver;
use Sova\Issues\Infrastructure\Persistence\DoctrineSearchScopeProvider;
use Sova\Issues\Infrastructure\Persistence\DoctrineWatcherRepository;
use Sova\Issues\Infrastructure\Persistence\IssueQueryCompiler;
use Sova\Issues\Infrastructure\Storage\FilesystemAttachmentStorage;
use Sova\Issues\Infrastructure\Storage\UnavailableAttachmentScanner;
use Sova\Notifications\Application\IssueEventNotifier;
use Sova\Notifications\Application\MemberDirectory;
use Sova\Notifications\Application\NotificationEmailHandler;
use Sova\Notifications\Application\NotificationMailer;
use Sova\Notifications\Application\NotificationRepository;
use Sova\Notifications\Application\PreferenceRepository;
use Sova\Notifications\Infrastructure\Mail\SymfonyNotificationMailer;
use Sova\Notifications\Infrastructure\Persistence\DoctrineMemberDirectory;
use Sova\Notifications\Infrastructure\Persistence\DoctrineNotificationRepository;
use Sova\Notifications\Infrastructure\Persistence\DoctrinePreferenceRepository;
use Sova\ProjectConfiguration\Application\ConfigurationEventPublisher;
use Sova\ProjectConfiguration\Application\IssueMigrator;
use Sova\ProjectConfiguration\Application\ProjectConfigurationProvisioner;
use Sova\ProjectConfiguration\Application\ProjectConfigurationRepository;
use Sova\ProjectConfiguration\Application\WorkflowConfigurationRepository;
use Sova\ProjectConfiguration\Infrastructure\Persistence\DoctrineConfigurationEventPublisher;
use Sova\ProjectConfiguration\Infrastructure\Persistence\DoctrineProjectConfigurationProvisioner;
use Sova\ProjectConfiguration\Infrastructure\Persistence\DoctrineProjectConfigurationRepository;
use Sova\ProjectConfiguration\Infrastructure\Persistence\DoctrineWorkflowConfigurationRepository;
use Sova\Projects\Application\ProjectRepository;
use Sova\Projects\Application\ProjectRoleProvisioner;
use Sova\Projects\Application\ProjectRoleRepository;
use Sova\Projects\Infrastructure\Persistence\DoctrineProjectRepository;
use Sova\Projects\Infrastructure\Persistence\DoctrineProjectRoleProvisioner;
use Sova\Projects\Infrastructure\Persistence\DoctrineProjectRoleRepository;
use Sova\SavedQueries\Application\SavedQueryRepository;
use Sova\SavedQueries\Application\SavedQueryUsageProbe;
use Sova\SavedQueries\Infrastructure\Persistence\DoctrineSavedQueryRepository;
use Sova\Shared\Application\Audit\SecurityAuditReader;
use Sova\Shared\Application\Audit\SecurityAuditRecorder;
use Sova\Shared\Application\Security\SensitivePayloadCipher;
use Sova\Shared\Infrastructure\Configuration\Settings;
use Sova\Shared\Infrastructure\Logging\LoggerFactory;
use Sova\Shared\Infrastructure\Outbox\OutboxDispatcher;
use Sova\Shared\Infrastructure\Persistence\ConnectionFactory;
use Sova\Shared\Infrastructure\Persistence\DoctrineSecurityAuditReader;
use Sova\Shared\Infrastructure\Persistence\DoctrineSecurityAuditRecorder;
use Sova\Shared\Infrastructure\Security\SodiumSensitivePayloadCipher;
use Sova\Tenancy\Application\Access\TenantAccessRepository;
use Sova\Tenancy\Application\Invitation\InvitationMailer;
use Sova\Tenancy\Application\Invitation\InvitationPublisher;
use Sova\Tenancy\Application\Invitation\InvitationRepository;
use Sova\Tenancy\Application\Membership\TenantMembershipRepository;
use Sova\Tenancy\Application\System\SystemTenantRepository;
use Sova\Tenancy\Infrastructure\Mail\SymfonyInvitationMailer;
use Sova\Tenancy\Infrastructure\Persistence\DoctrineInvitationPublisher;
use Sova\Tenancy\Infrastructure\Persistence\DoctrineInvitationRepository;
use Sova\Tenancy\Infrastructure\Persistence\DoctrineSystemTenantRepository;
use Sova\Tenancy\Infrastructure\Persistence\DoctrineTenantAccessRepository;
use Sova\Tenancy\Infrastructure\Persistence\DoctrineTenantMembershipRepository;
use Sova\Workgroups\Application\WorkgroupRepository;
use Sova\Workgroups\Infrastructure\Persistence\DoctrineWorkgroupRepository;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;

return [
    Settings::class => static function (): Settings {
        /** @var array<string, mixed> $settings */
        $settings = require __DIR__ . '/settings.php';

        return new Settings($settings);
    },
    LoggerInterface::class => factory(
        static fn(Settings $settings): LoggerInterface => LoggerFactory::create($settings),
    ),
    ResponseFactoryInterface::class => autowire(ResponseFactory::class),
    EffectivePermissionProvider::class => autowire(
        DoctrineEffectivePermissionProvider::class,
    ),
    TenantRoleProvisioner::class => autowire(
        DoctrineTenantRoleProvisioner::class,
    ),
    TenantRoleRepository::class => autowire(
        DoctrineTenantRoleRepository::class,
    ),
    PasswordHasher::class => autowire(Argon2idPasswordHasher::class),
    SensitivePayloadCipher::class => autowire(
        SodiumSensitivePayloadCipher::class,
    ),
    OneTimeTokenRepository::class => autowire(
        DoctrineOneTimeTokenRepository::class,
    ),
    UserCredentialsRepository::class => autowire(DoctrineUserCredentialsRepository::class),
    UserSessionRepository::class => autowire(DoctrineUserSessionRepository::class),
    ImpersonationRepository::class => autowire(
        DoctrineImpersonationRepository::class,
    ),
    SystemUserRepository::class => autowire(
        DoctrineSystemUserRepository::class,
    ),
    LoginRateLimiter::class => autowire(DoctrineLoginRateLimiter::class),
    PublicEmailRateLimiter::class => autowire(
        DoctrinePublicEmailRateLimiter::class,
    ),
    UserActionRequestPublisher::class => autowire(
        DoctrineUserActionRequestPublisher::class,
    ),
    PasswordResetMailer::class => autowire(
        SymfonyPasswordResetMailer::class,
    ),
    EmailVerificationMailer::class => autowire(
        SymfonyEmailVerificationMailer::class,
    ),
    AuthenticationEventRecorder::class => autowire(
        DoctrineAuthenticationEventRecorder::class,
    ),
    SecurityAuditRecorder::class => autowire(DoctrineSecurityAuditRecorder::class),
    SecurityAuditReader::class => autowire(DoctrineSecurityAuditReader::class),
    TenantAccessRepository::class => autowire(
        DoctrineTenantAccessRepository::class,
    ),
    TenantMembershipRepository::class => autowire(
        DoctrineTenantMembershipRepository::class,
    ),
    SystemTenantRepository::class => autowire(
        DoctrineSystemTenantRepository::class,
    ),
    WorkgroupRepository::class => autowire(
        DoctrineWorkgroupRepository::class,
    ),
    ProjectRepository::class => autowire(
        DoctrineProjectRepository::class,
    ),
    ProjectRoleRepository::class => autowire(
        DoctrineProjectRoleRepository::class,
    ),
    ProjectRoleProvisioner::class => autowire(
        DoctrineProjectRoleProvisioner::class,
    ),
    ProjectConfigurationProvisioner::class => autowire(
        DoctrineProjectConfigurationProvisioner::class,
    ),
    ProjectConfigurationRepository::class => autowire(
        DoctrineProjectConfigurationRepository::class,
    ),
    WorkflowConfigurationRepository::class => autowire(
        DoctrineWorkflowConfigurationRepository::class,
    ),
    ConfigurationEventPublisher::class => autowire(
        DoctrineConfigurationEventPublisher::class,
    ),
    IssueMigrator::class => autowire(
        DoctrineIssueMigrator::class,
    ),
    IssueRepository::class => autowire(
        DoctrineIssueRepository::class,
    ),
    IssueEventPublisher::class => autowire(
        DoctrineIssueEventPublisher::class,
    ),
    CommentRepository::class => autowire(
        DoctrineCommentRepository::class,
    ),
    CommentEventPublisher::class => autowire(
        DoctrineCommentEventPublisher::class,
    ),
    HistoryRepository::class => autowire(
        DoctrineHistoryRepository::class,
    ),
    DashboardRepository::class => autowire(
        DoctrineDashboardRepository::class,
    ),
    WidgetRepository::class => autowire(
        DoctrineWidgetRepository::class,
    ),
    SavedQueryUsageProbe::class => autowire(
        WidgetSavedQueryUsageProbe::class,
    ),
    SavedQueryRepository::class => autowire(
        DoctrineSavedQueryRepository::class,
    ),
    NotificationRepository::class => autowire(
        DoctrineNotificationRepository::class,
    ),
    PreferenceRepository::class => autowire(
        DoctrinePreferenceRepository::class,
    ),
    MemberDirectory::class => autowire(
        DoctrineMemberDirectory::class,
    ),
    NotificationMailer::class => autowire(
        SymfonyNotificationMailer::class,
    ),
    OutboxDispatcher::class => factory(
        static fn(
            Connection $connection,
            IssueEventNotifier $notifier,
            NotificationEmailHandler $emailHandler,
            Settings $settings,
        ): OutboxDispatcher => new OutboxDispatcher(
            $connection,
            // Handlers are listed explicitly rather than discovered, so adding
            // a consumer is a visible decision and the dispatcher never claims
            // an event nobody handles.
            [$notifier, $emailHandler],
            $settings,
        ),
    ),
    AttachmentRepository::class => autowire(
        DoctrineAttachmentRepository::class,
    ),
    AttachmentStorage::class => autowire(
        FilesystemAttachmentStorage::class,
    ),
    AttachmentScanner::class => factory(
        static function (
            Settings $settings,
            LoggerInterface $logger,
        ): AttachmentScanner {
            $scanner = $settings->string('attachments.scanner', 'none');
            $environment = $settings->string('app.environment', 'production');

            // The same guard the mailer uses for a null transport: a missing
            // scanner is a development convenience, never a production state.
            if ($environment === 'production' && $scanner === 'none') {
                throw new RuntimeException(
                    'ATTACHMENT_SCANNER must configure a real scanner in production.',
                );
            }

            return new UnavailableAttachmentScanner($logger);
        },
    ),
    WatcherRepository::class => autowire(
        DoctrineWatcherRepository::class,
    ),
    IssueLinkRepository::class => autowire(
        DoctrineIssueLinkRepository::class,
    ),
    QueryLimits::class => factory(
        static fn(Settings $settings): QueryLimits => new QueryLimits(
            $settings->int('search.max_query_bytes', 8192),
            $settings->int('search.max_ast_nodes', 100),
            $settings->int('search.max_paren_depth', 10),
            $settings->int('search.max_in_values', 100),
            $settings->int('search.max_sort_fields', 3),
            $settings->int('search.default_page_size', 50),
            $settings->int('search.max_page_size', 100),
            $settings->int('search.statement_timeout_ms', 3000),
        ),
    ),
    QueryCompiler::class => autowire(
        IssueQueryCompiler::class,
    ),
    SearchScopeProvider::class => autowire(
        DoctrineSearchScopeProvider::class,
    ),
    ReferenceResolver::class => autowire(
        DoctrineReferenceResolver::class,
    ),
    IssueAggregationRepository::class => autowire(
        DoctrineIssueAggregationRepository::class,
    ),
    IssueSearchRepository::class => autowire(
        DoctrineIssueSearchRepository::class,
    ),
    QueryRateLimiter::class => autowire(
        DoctrineQueryRateLimiter::class,
    ),
    InvitationRepository::class => autowire(
        DoctrineInvitationRepository::class,
    ),
    InvitationPublisher::class => autowire(
        DoctrineInvitationPublisher::class,
    ),
    InvitationMailer::class => autowire(
        SymfonyInvitationMailer::class,
    ),
    MailerInterface::class => factory(
        static function (Settings $settings): MailerInterface {
            $dsn = $settings->string('mailer.dsn', 'null://null');
            $environment = $settings->string('app.environment', 'production');

            if ($environment === 'production' && $dsn === 'null://null') {
                throw new RuntimeException(
                    'MAILER_DSN must configure a real transport in production.',
                );
            }

            return new Mailer(Transport::fromDsn($dsn));
        },
    ),
    Connection::class => factory(
        static fn(Settings $settings): Connection => ConnectionFactory::create($settings),
    ),
];

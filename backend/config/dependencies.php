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
use Sova\Projects\Application\ProjectRepository;
use Sova\Projects\Application\ProjectRoleProvisioner;
use Sova\Projects\Application\ProjectRoleRepository;
use Sova\Projects\Infrastructure\Persistence\DoctrineProjectRepository;
use Sova\Projects\Infrastructure\Persistence\DoctrineProjectRoleProvisioner;
use Sova\Projects\Infrastructure\Persistence\DoctrineProjectRoleRepository;
use Sova\Shared\Application\Audit\SecurityAuditReader;
use Sova\Shared\Application\Audit\SecurityAuditRecorder;
use Sova\Shared\Application\Security\SensitivePayloadCipher;
use Sova\Shared\Infrastructure\Configuration\Settings;
use Sova\Shared\Infrastructure\Logging\LoggerFactory;
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

<?php

declare(strict_types=1);

namespace Sova\Tests\Api;

use DI\Container;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Sova\Authorization\Application\TenantRoleProvisioner;
use Sova\Authorization\Domain\DefaultRole;
use Sova\Identity\Application\Security\OneTimeTokenGenerator;
use Sova\Identity\Infrastructure\Security\Argon2idPasswordHasher;
use Sova\Shared\Application\Security\SensitivePayloadCipher;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Infrastructure\Bootstrap\ApplicationFactory;
use Sova\Shared\Infrastructure\Configuration\Settings;
use Sova\Tenancy\Application\Invitation\InvitationMailer;
use Sova\Tenancy\Application\Invitation\InvitationRepository;
use Sova\Tenancy\Application\Invitation\TenantInvitation;
use Sova\Tenancy\Infrastructure\Background\InvitationOutboxWorker;

final class InvitationApiTest extends TestCase
{
    /**
     * @var App<Container>
     */
    private App $app;
    private Connection $connection;
    private string $superadminId;
    private string $existingUserId;
    private string $tenantId;

    protected function setUp(): void
    {
        if (getenv('RUN_DATABASE_TESTS') !== 'true') {
            self::markTestSkipped(
                'Set RUN_DATABASE_TESTS=true and migrate PostgreSQL before database tests.',
            );
        }

        /** @var App<Container> $app */
        $app = ApplicationFactory::create(dirname(__DIR__, 2));
        $connection = $app->getContainer()->get(Connection::class);

        if (!$connection instanceof Connection) {
            self::fail('The container must provide a Doctrine DBAL connection.');
        }

        $this->app = $app;
        $this->connection = $connection;
        $this->connection->beginTransaction();
        $this->superadminId = $this->insertUser(
            'superadmin-invite@example.test',
            'Superadmin invitation passphrase',
        );
        $this->existingUserId = $this->insertUser(
            'existing-invite@example.test',
            'Existing invitation passphrase',
        );
        $this->tenantId = (string) UuidV7::generate();
        $this->connection->insert('tenants', [
            'id' => $this->tenantId,
            'name' => 'Invitation Tenant',
            'slug' => sprintf(
                'invitation-%s',
                substr(str_replace('-', '', $this->tenantId), 0, 12),
            ),
            'status' => 'ACTIVE',
        ]);
        $roles = $app->getContainer()->get(TenantRoleProvisioner::class);

        if (!$roles instanceof TenantRoleProvisioner) {
            self::fail('The container must provide a tenant role provisioner.');
        }

        $roles->provisionDefaults($this->tenantId, $this->superadminId);
        $this->connection->insert('user_system_roles', [
            'user_id' => $this->superadminId,
            'role_code' => 'SUPERADMIN',
        ]);
    }

    protected function tearDown(): void
    {
        if (
            isset($this->connection)
            && $this->connection->isTransactionActive()
        ) {
            $this->connection->rollBack();
        }
    }

    public function testSuperadminCanInviteAndNewAccountAcceptanceIsAtomic(): void
    {
        $login = $this->login(
            'superadmin-invite@example.test',
            'Superadmin invitation passphrase',
        );
        $created = $this->createInvitation(
            $login,
            'new-invite@example.test',
        );

        self::assertSame(201, $created->getStatusCode());
        $createdPayload = $this->decode($created);
        $invitationPayload = $createdPayload['invitation'] ?? null;
        self::assertIsArray($invitationPayload);
        $invitationId = $invitationPayload['id'] ?? null;
        self::assertIsString($invitationId);
        self::assertSame('PENDING', $invitationPayload['status'] ?? null);

        $storedHash = $this->connection->fetchOne(
            'SELECT token_hash FROM tenant_invitations WHERE id = :id',
            ['id' => $invitationId],
        );
        self::assertIsString($storedHash);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $storedHash);

        $mailer = new CapturingInvitationMailer();

        self::assertSame(1, $this->invitationWorker($mailer)->runBatch());
        self::assertCount(1, $mailer->messages);
        $token = $mailer->messages[0]['token'];
        self::assertSame(hash('sha256', $token), $storedHash);

        $preview = $this->post(
            '/api/v1/auth/invitations/inspect',
            ['token' => $token],
        );

        self::assertSame(200, $preview->getStatusCode());
        $previewPayload = $this->decode($preview);
        $previewInvitation = $previewPayload['invitation'] ?? null;
        self::assertIsArray($previewInvitation);
        self::assertSame(
            'Invitation Tenant',
            $previewInvitation['tenant_name'] ?? null,
        );

        $weak = $this->acceptNew(
            $token,
            'New Invite',
            'passwordpassword',
        );
        self::assertSame(422, $weak->getStatusCode());
        self::assertSame(
            'PASSWORD_POLICY_VIOLATION',
            $this->decode($weak)['code'],
        );
        self::assertSame(
            'PENDING',
            $this->connection->fetchOne(
                'SELECT status FROM tenant_invitations WHERE id = :id',
                ['id' => $invitationId],
            ),
        );

        $password = 'A unique invitation passphrase 2026';
        $accepted = $this->acceptNew($token, 'New Invite', $password);

        self::assertSame(201, $accepted->getStatusCode());
        $acceptedPayload = $this->decode($accepted);
        $newUserId = $acceptedPayload['user_id'] ?? null;
        self::assertIsString($newUserId);
        self::assertTrue($acceptedPayload['membership_created'] ?? false);
        self::assertSame(
            'ACTIVE',
            $this->connection->fetchOne(
                'SELECT status FROM users WHERE id = :id',
                ['id' => $newUserId],
            ),
        );
        self::assertNotEmpty($this->connection->fetchOne(
            'SELECT email_verified_at FROM users WHERE id = :id',
            ['id' => $newUserId],
        ));
        self::assertSame(
            1,
            $this->connection->fetchOne(
                <<<'SQL'
                    SELECT COUNT(*)
                    FROM tenant_memberships
                    WHERE tenant_id = :tenant_id
                        AND user_id = :user_id
                        AND status = 'ACTIVE'
                    SQL,
                [
                    'tenant_id' => $this->tenantId,
                    'user_id' => $newUserId,
                ],
            ),
        );
        self::assertSame(
            410,
            $this->acceptNew($token, 'New Invite', $password)->getStatusCode(),
        );
        self::assertSame(
            200,
            $this->login('new-invite@example.test', $password)->getStatusCode(),
        );
        self::assertSame(
            2,
            $this->connection->fetchOne(
                <<<'SQL'
                    SELECT COUNT(*)
                    FROM security_audit_events
                    WHERE tenant_id = :tenant_id
                        AND event_type IN (
                            'TENANT_INVITATION_CREATED',
                            'TENANT_INVITATION_ACCEPTED'
                        )
                    SQL,
                ['tenant_id' => $this->tenantId],
            ),
        );
        self::assertSame(
            'PURGED',
            $this->connection->fetchOne(
                <<<'SQL'
                    SELECT sensitive.ciphertext
                    FROM outbox_sensitive_payloads sensitive
                    INNER JOIN outbox_events event ON event.id = sensitive.event_id
                    WHERE event.aggregate_id = :invitation_id
                    SQL,
                ['invitation_id' => $invitationId],
            ),
        );
    }

    public function testExistingAccountMustSignInWithTheInvitedEmail(): void
    {
        $superadminLogin = $this->login(
            'superadmin-invite@example.test',
            'Superadmin invitation passphrase',
        );
        self::assertSame(
            201,
            $this->createInvitation(
                $superadminLogin,
                'existing-invite@example.test',
            )->getStatusCode(),
        );
        $mailer = new CapturingInvitationMailer();
        self::assertSame(1, $this->invitationWorker($mailer)->runBatch());
        $token = $mailer->messages[0]['token'];

        self::assertSame(
            409,
            $this->acceptNew(
                $token,
                'Existing Invite',
                'A unique existing-account passphrase',
            )->getStatusCode(),
        );

        $mismatch = $this->acceptExisting($superadminLogin, $token);
        self::assertSame(403, $mismatch->getStatusCode());
        self::assertSame(
            'INVITATION_ACCOUNT_MISMATCH',
            $this->decode($mismatch)['code'],
        );

        $existingLogin = $this->login(
            'existing-invite@example.test',
            'Existing invitation passphrase',
        );
        $accepted = $this->acceptExisting($existingLogin, $token);

        self::assertSame(200, $accepted->getStatusCode());
        self::assertSame(
            $this->existingUserId,
            $this->decode($accepted)['user_id'],
        );
        self::assertSame(
            1,
            $this->connection->fetchOne(
                <<<'SQL'
                    SELECT COUNT(*)
                    FROM tenant_memberships
                    WHERE tenant_id = :tenant_id
                        AND user_id = :user_id
                        AND status = 'ACTIVE'
                    SQL,
                [
                    'tenant_id' => $this->tenantId,
                    'user_id' => $this->existingUserId,
                ],
            ),
        );
    }

    public function testRegularTenantMemberWithoutRoleCannotCreateInvitation(): void
    {
        $this->insertExistingUserMembership();
        $login = $this->login(
            'existing-invite@example.test',
            'Existing invitation passphrase',
        );
        $response = $this->createInvitation(
            $login,
            'forbidden-invite@example.test',
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(
            'PERMISSION_DENIED',
            $this->decode($response)['code'],
        );
    }

    public function testTenantAdministratorCanCreateInvitation(): void
    {
        $membershipId = $this->insertExistingUserMembership();
        $roleId = $this->connection->fetchOne(
            <<<'SQL'
                SELECT id
                FROM tenant_roles
                WHERE tenant_id = :tenant_id
                    AND code = :code
                SQL,
            [
                'tenant_id' => $this->tenantId,
                'code' => DefaultRole::TenantAdmin->value,
            ],
        );
        self::assertIsString($roleId);
        $this->connection->insert('tenant_membership_role_assignments', [
            'tenant_id' => $this->tenantId,
            'membership_id' => $membershipId,
            'role_id' => $roleId,
            'granted_by_user_id' => $this->superadminId,
        ]);
        $login = $this->login(
            'existing-invite@example.test',
            'Existing invitation passphrase',
        );

        $response = $this->createInvitation(
            $login,
            'tenant-admin-invite@example.test',
        );

        self::assertSame(201, $response->getStatusCode());
    }

    private function insertExistingUserMembership(): string
    {
        $membershipId = (string) UuidV7::generate();
        $this->connection->insert('tenant_memberships', [
            'id' => $membershipId,
            'tenant_id' => $this->tenantId,
            'user_id' => $this->existingUserId,
            'status' => 'ACTIVE',
        ]);

        return $membershipId;
    }

    private function insertUser(string $email, string $password): string
    {
        $id = (string) UuidV7::generate();
        $this->connection->insert('users', [
            'id' => $id,
            'email' => $email,
            'normalized_email' => $email,
            'password_hash' => (new Argon2idPasswordHasher())->hash($password),
            'display_name' => $email,
            'preferred_locale' => 'sk',
            'status' => 'ACTIVE',
            'email_verified_at' => '2026-07-26 00:00:00+00',
        ]);

        return $id;
    }

    private function login(string $email, string $password): ResponseInterface
    {
        return $this->post('/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);
    }

    private function createInvitation(
        ResponseInterface $login,
        string $email,
    ): ResponseInterface {
        return $this->app->handle(
            $this->request(
                'POST',
                sprintf(
                    '/api/v1/tenants/%s/invitations',
                    $this->tenantId,
                ),
            )
                ->withCookieParams([
                    'sova_session' => $this->cookieValue(
                        $login,
                        'sova_session',
                    ),
                ])
                ->withHeader(
                    'X-CSRF-Token',
                    $this->cookieValue($login, 'sova_csrf'),
                )
                ->withParsedBody(['email' => $email]),
        );
    }

    private function acceptNew(
        string $token,
        string $displayName,
        string $password,
    ): ResponseInterface {
        return $this->post('/api/v1/auth/invitations/accept', [
            'token' => $token,
            'display_name' => $displayName,
            'preferred_locale' => 'sk',
            'password' => $password,
            'password_confirmation' => $password,
        ]);
    }

    private function acceptExisting(
        ResponseInterface $login,
        string $token,
    ): ResponseInterface {
        return $this->app->handle(
            $this->request(
                'POST',
                '/api/v1/auth/invitations/accept-existing',
            )
                ->withCookieParams([
                    'sova_session' => $this->cookieValue(
                        $login,
                        'sova_session',
                    ),
                ])
                ->withHeader(
                    'X-CSRF-Token',
                    $this->cookieValue($login, 'sova_csrf'),
                )
                ->withParsedBody(['token' => $token]),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(string $uri, array $payload): ResponseInterface
    {
        return $this->app->handle(
            $this->request('POST', $uri)->withParsedBody($payload),
        );
    }

    private function invitationWorker(
        InvitationMailer $mailer,
    ): InvitationOutboxWorker {
        $container = $this->app->getContainer();
        $cipher = $container->get(SensitivePayloadCipher::class);
        $invitations = $container->get(InvitationRepository::class);
        $settings = $container->get(Settings::class);

        if (!$cipher instanceof SensitivePayloadCipher) {
            self::fail('The container must provide a sensitive payload cipher.');
        }

        if (!$invitations instanceof InvitationRepository) {
            self::fail('The container must provide an invitation repository.');
        }

        if (!$settings instanceof Settings) {
            self::fail('The container must provide application settings.');
        }

        return new InvitationOutboxWorker(
            connection: $this->connection,
            cipher: $cipher,
            invitations: $invitations,
            tokenGenerator: new OneTimeTokenGenerator(),
            mailer: $mailer,
            settings: $settings,
        );
    }

    private function request(
        string $method,
        string $uri,
    ): \Psr\Http\Message\ServerRequestInterface {
        return (new ServerRequestFactory())->createServerRequest(
            $method,
            $uri,
            ['REMOTE_ADDR' => '203.0.113.90'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $decoded = json_decode(
            $response->getBody()->__toString(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        if (!is_array($decoded)) {
            self::fail('Expected a JSON object response.');
        }

        $payload = [];

        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                self::fail('Expected JSON object keys to be strings.');
            }

            $payload[$key] = $value;
        }

        return $payload;
    }

    private function cookieValue(
        ResponseInterface $response,
        string $cookieName,
    ): string {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            if (!str_starts_with($header, sprintf('%s=', $cookieName))) {
                continue;
            }

            $pair = explode(';', $header, 2)[0];
            $value = substr($pair, strlen($cookieName) + 1);

            return rawurldecode($value);
        }

        self::fail(sprintf('Cookie "%s" was not set.', $cookieName));
    }
}

final class CapturingInvitationMailer implements InvitationMailer
{
    /**
     * @var list<array{email: string, token: string}>
     */
    public array $messages = [];

    public function send(
        TenantInvitation $invitation,
        string $plainTextToken,
    ): void {
        $this->messages[] = [
            'email' => $invitation->email,
            'token' => $plainTextToken,
        ];
    }
}

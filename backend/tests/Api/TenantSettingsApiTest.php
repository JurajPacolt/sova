<?php

declare(strict_types=1);

namespace Sova\Tests\Api;

use DI\Container;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Sova\Authorization\Application\TenantRoleProvisioner;
use Sova\Authorization\Domain\DefaultRole;
use Sova\Identity\Infrastructure\Security\Argon2idPasswordHasher;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Infrastructure\Bootstrap\ApplicationFactory;

final class TenantSettingsApiTest extends TestCase
{
    private const PASSWORD = 'A unique tenant settings passphrase';

    /**
     * @var App<Container>
     */
    private App $app;
    private Connection $connection;
    private string $ownerId;
    private string $memberId;
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
        $roles = $app->getContainer()->get(TenantRoleProvisioner::class);

        if (!$connection instanceof Connection) {
            self::fail('The container must provide a Doctrine DBAL connection.');
        }

        if (!$roles instanceof TenantRoleProvisioner) {
            self::fail('The container must provide a tenant role provisioner.');
        }

        $this->app = $app;
        $this->connection = $connection;
        $this->connection->beginTransaction();
        $this->ownerId = $this->insertUser('tenant-settings-owner');
        $this->memberId = $this->insertUser('tenant-settings-member');
        $this->tenantId = $this->insertTenant();
        $roles->provisionDefaults($this->tenantId, $this->ownerId);
        $this->addMembership($this->ownerId, DefaultRole::TenantOwner);
        $this->addMembership($this->memberId, DefaultRole::Member);
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

    public function testOwnerUpdatesSectionsIndependentlyWithOneTenantRevision(): void
    {
        $login = $this->login('tenant-settings-owner');
        $read = $this->settingsRequest($login, 'GET');

        self::assertSame(200, $read->getStatusCode());
        $initial = $this->decode($read)['settings'] ?? null;
        self::assertIsArray($initial);
        self::assertSame('Settings tenant', $initial['name'] ?? null);
        self::assertSame('sk', $initial['default_locale'] ?? null);
        self::assertSame('Europe/Bratislava', $initial['timezone'] ?? null);
        self::assertSame(1, $initial['revision'] ?? null);

        $general = $this->settingsRequest(
            $login,
            'PATCH',
            'general',
            ['name' => 'Renamed tenant', 'expected_revision' => 1],
        );
        self::assertSame(200, $general->getStatusCode());
        $renamed = $this->decode($general)['settings'] ?? null;
        self::assertIsArray($renamed);
        self::assertSame('Renamed tenant', $renamed['name'] ?? null);
        self::assertSame(2, $renamed['revision'] ?? null);

        $stale = $this->settingsRequest(
            $login,
            'PATCH',
            'localization',
            [
                'default_locale' => 'en',
                'timezone' => 'Europe/London',
                'expected_revision' => 1,
            ],
        );
        self::assertSame(409, $stale->getStatusCode());
        self::assertSame(
            'TENANT_REVISION_CONFLICT',
            $this->decode($stale)['code'] ?? null,
        );

        $localization = $this->settingsRequest(
            $login,
            'PATCH',
            'localization',
            [
                'default_locale' => 'en',
                'timezone' => 'Europe/London',
                'expected_revision' => 2,
            ],
        );
        self::assertSame(200, $localization->getStatusCode());
        $localized = $this->decode($localization)['settings'] ?? null;
        self::assertIsArray($localized);
        self::assertSame('en', $localized['default_locale'] ?? null);
        self::assertSame('Europe/London', $localized['timezone'] ?? null);
        self::assertSame(3, $localized['revision'] ?? null);

        self::assertSame(
            [
                'TENANT_GENERAL_SETTINGS_CHANGED',
                'TENANT_LOCALIZATION_SETTINGS_CHANGED',
            ],
            $this->connection->fetchFirstColumn(
                <<<'SQL'
                    SELECT event_type
                    FROM security_audit_events
                    WHERE tenant_id = :tenant_id
                        AND event_type LIKE 'TENANT_%_SETTINGS_CHANGED'
                    ORDER BY occurred_at, id
                    SQL,
                ['tenant_id' => $this->tenantId],
            ),
        );
    }

    public function testInvalidSectionDoesNotBlockSavingAnotherSection(): void
    {
        $login = $this->login('tenant-settings-owner');
        $invalid = $this->settingsRequest(
            $login,
            'PATCH',
            'localization',
            [
                'default_locale' => 'en',
                'timezone' => 'Not/A-Timezone',
                'expected_revision' => 1,
            ],
        );

        self::assertSame(422, $invalid->getStatusCode());
        self::assertSame(
            'TENANT_SETTINGS_INPUT_INVALID',
            $this->decode($invalid)['code'] ?? null,
        );

        $general = $this->settingsRequest(
            $login,
            'PATCH',
            'general',
            ['name' => 'Still saveable', 'expected_revision' => 1],
        );
        self::assertSame(200, $general->getStatusCode());
        $saved = $this->decode($general)['settings'] ?? null;
        self::assertIsArray($saved);
        self::assertSame(
            'Still saveable',
            $saved['name'] ?? null,
        );
    }

    public function testPlainMemberCannotReadOrChangeTenantSettings(): void
    {
        $login = $this->login('tenant-settings-member');

        foreach ([
            $this->settingsRequest($login, 'GET'),
            $this->settingsRequest(
                $login,
                'PATCH',
                'general',
                ['name' => 'Forbidden', 'expected_revision' => 1],
            ),
        ] as $response) {
            self::assertSame(403, $response->getStatusCode());
            self::assertSame(
                'PERMISSION_DENIED',
                $this->decode($response)['code'] ?? null,
            );
        }
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function settingsRequest(
        ResponseInterface $login,
        string $method,
        ?string $section = null,
        ?array $payload = null,
    ): ResponseInterface {
        $uri = sprintf('/api/v1/tenants/%s/settings', $this->tenantId);

        if ($section !== null) {
            $uri .= '/' . $section;
        }

        $request = $this->authenticatedRequest($method, $uri, $login);

        if ($payload !== null) {
            $request = $request->withParsedBody($payload);
        }

        return $this->app->handle($request);
    }

    private function insertUser(string $prefix): string
    {
        $id = (string) UuidV7::generate();
        $email = sprintf('%s@example.test', $prefix);
        $this->connection->insert('users', [
            'id' => $id,
            'email' => $email,
            'normalized_email' => $email,
            'password_hash' => (new Argon2idPasswordHasher())->hash(
                self::PASSWORD,
            ),
            'display_name' => ucfirst(str_replace('-', ' ', $prefix)),
            'preferred_locale' => 'en',
            'status' => 'ACTIVE',
        ]);

        return $id;
    }

    private function insertTenant(): string
    {
        $id = (string) UuidV7::generate();
        $this->connection->insert('tenants', [
            'id' => $id,
            'name' => 'Settings tenant',
            'slug' => sprintf('settings-%s', substr($id, 0, 8)),
            'status' => 'ACTIVE',
        ]);

        return $id;
    }

    private function addMembership(
        string $userId,
        DefaultRole $role,
    ): void {
        $membershipId = (string) UuidV7::generate();
        $this->connection->insert('tenant_memberships', [
            'id' => $membershipId,
            'tenant_id' => $this->tenantId,
            'user_id' => $userId,
            'status' => 'ACTIVE',
        ]);
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO tenant_membership_role_assignments (
                    tenant_id,
                    membership_id,
                    role_id,
                    granted_by_user_id
                )
                SELECT :tenant_id, :membership_id, id, :owner_id
                FROM tenant_roles
                WHERE tenant_id = :tenant_id
                    AND code = :role_code
                SQL,
            [
                'tenant_id' => $this->tenantId,
                'membership_id' => $membershipId,
                'owner_id' => $this->ownerId,
                'role_code' => $role->value,
            ],
        );
    }

    private function login(string $prefix): ResponseInterface
    {
        $response = $this->app->handle(
            $this->request('POST', '/api/v1/auth/login')
                ->withParsedBody([
                    'email' => sprintf('%s@example.test', $prefix),
                    'password' => self::PASSWORD,
                ]),
        );
        self::assertSame(200, $response->getStatusCode());

        return $response;
    }

    private function authenticatedRequest(
        string $method,
        string $uri,
        ResponseInterface $login,
    ): ServerRequestInterface {
        return $this->request($method, $uri)
            ->withCookieParams([
                'sova_session' => $this->cookieValue($login, 'sova_session'),
            ])
            ->withHeader(
                'X-CSRF-Token',
                $this->cookieValue($login, 'sova_csrf'),
            );
    }

    private function request(
        string $method,
        string $uri,
    ): ServerRequestInterface {
        return (new ServerRequestFactory())->createServerRequest(
            $method,
            $uri,
            ['REMOTE_ADDR' => '203.0.113.120'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $payload = json_decode(
            (string) $response->getBody(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($payload);
        $result = [];

        foreach ($payload as $key => $value) {
            self::assertIsString($key);
            $result[$key] = $value;
        }

        return $result;
    }

    private function cookieValue(
        ResponseInterface $response,
        string $name,
    ): string {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            if (
                preg_match(
                    sprintf('/(?:^|;\\s*)%s=([^;]+)/', preg_quote($name, '/')),
                    $header,
                    $matches,
                ) === 1
            ) {
                return urldecode($matches[1]);
            }
        }

        self::fail(sprintf('Cookie "%s" was not set.', $name));
    }
}

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
use Sova\Identity\Application\System\SystemUserAdministrationService;
use Sova\Identity\Infrastructure\Security\Argon2idPasswordHasher;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Infrastructure\Bootstrap\ApplicationFactory;

final class SystemUserAdministrationApiTest extends TestCase
{
    private const PASSWORD = 'A unique system user administration passphrase';

    /**
     * @var App<Container>
     */
    private App $app;
    private Connection $connection;
    private string $superadminId;
    private string $memberId;

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
        $this->superadminId = $this->insertUser('sysuser-superadmin');
        $this->memberId = $this->insertUser('sysuser-member');
        $this->connection->insert('user_system_roles', [
            'user_id' => $this->superadminId,
            'role_code' => 'SUPERADMIN',
            'granted_by_user_id' => $this->superadminId,
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

    public function testSystemUserEndpointsRejectANormalUser(): void
    {
        $login = $this->login('sysuser-member');
        $response = $this->app->handle(
            $this->authenticatedRequest('GET', '/api/v1/system/users', $login),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('PERMISSION_DENIED', $this->decode($response)['code']);
    }

    public function testListReturnsEveryAccountWithSuperadminFlag(): void
    {
        $login = $this->login('sysuser-superadmin');
        $response = $this->app->handle(
            $this->authenticatedRequest('GET', '/api/v1/system/users', $login),
        );
        $users = $this->decode($response)['users'] ?? null;

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($users);
        /** @var array<string, array<string, mixed>> $byId */
        $byId = [];

        foreach ($users as $user) {
            self::assertIsArray($user);
            $id = $user['id'] ?? null;
            self::assertIsString($id);
            $byId[$id] = $user;
        }

        self::assertTrue($byId[$this->superadminId]['is_superadmin'] ?? null);
        self::assertFalse($byId[$this->memberId]['is_superadmin'] ?? null);
        self::assertSame('ACTIVE', $byId[$this->memberId]['status'] ?? null);
        self::assertArrayNotHasKey('password_hash', $byId[$this->memberId]);
    }

    public function testStatusChangeDisablesAndReactivatesAndIsIdempotent(): void
    {
        $login = $this->login('sysuser-superadmin');
        $disable = $this->changeStatus($login, $this->memberId, 'DISABLED');
        self::assertSame(200, $disable->getStatusCode());
        self::assertSame('DISABLED', $this->userField($disable, 'status'));

        $repeat = $this->changeStatus($login, $this->memberId, 'DISABLED');
        self::assertSame(200, $repeat->getStatusCode());

        $reactivate = $this->changeStatus($login, $this->memberId, 'ACTIVE');
        self::assertSame(200, $reactivate->getStatusCode());
        self::assertSame('ACTIVE', $this->userField($reactivate, 'status'));
    }

    public function testStatusChangeRejectsUnsupportedTargetsAndSelfManagement(): void
    {
        $login = $this->login('sysuser-superadmin');
        $invalid = $this->changeStatus($login, $this->memberId, 'DELETED');
        self::assertSame(422, $invalid->getStatusCode());
        self::assertSame(
            'SYSTEM_USER_STATUS_INVALID',
            $this->decode($invalid)['code'],
        );

        $self = $this->changeStatus($login, $this->superadminId, 'DISABLED');
        self::assertSame(409, $self->getStatusCode());
        self::assertSame(
            'SYSTEM_USER_SELF_MANAGEMENT_FORBIDDEN',
            $this->decode($self)['code'],
        );
    }

    public function testSuperadminGrantIsIdempotentAndRevokeIsBlockedForSelf(): void
    {
        $login = $this->login('sysuser-superadmin');
        $grant = $this->app->handle(
            $this->authenticatedRequest(
                'PUT',
                sprintf('/api/v1/system/users/%s/superadmin', $this->memberId),
                $login,
            ),
        );
        self::assertSame(200, $grant->getStatusCode());
        self::assertTrue($this->userField($grant, 'is_superadmin'));

        $grantAgain = $this->app->handle(
            $this->authenticatedRequest(
                'PUT',
                sprintf('/api/v1/system/users/%s/superadmin', $this->memberId),
                $login,
            ),
        );
        self::assertSame(200, $grantAgain->getStatusCode());

        $revoke = $this->app->handle(
            $this->authenticatedRequest(
                'DELETE',
                sprintf('/api/v1/system/users/%s/superadmin', $this->memberId),
                $login,
            ),
        );
        self::assertSame(200, $revoke->getStatusCode());
        self::assertFalse($this->userField($revoke, 'is_superadmin'));

        $revokeAgain = $this->app->handle(
            $this->authenticatedRequest(
                'DELETE',
                sprintf('/api/v1/system/users/%s/superadmin', $this->memberId),
                $login,
            ),
        );
        self::assertSame(200, $revokeAgain->getStatusCode());

        $selfRevoke = $this->app->handle(
            $this->authenticatedRequest(
                'DELETE',
                sprintf('/api/v1/system/users/%s/superadmin', $this->superadminId),
                $login,
            ),
        );
        self::assertSame(409, $selfRevoke->getStatusCode());
        self::assertSame(
            'SYSTEM_SUPERADMIN_SELF_MANAGEMENT_FORBIDDEN',
            $this->decode($selfRevoke)['code'],
        );
    }

    public function testLastActiveSuperadminCannotBeRevokedByAnotherActor(): void
    {
        $service = $this->app->getContainer()->get(
            SystemUserAdministrationService::class,
        );

        if (!$service instanceof SystemUserAdministrationService) {
            self::fail('The container must provide the system user administration service.');
        }

        $this->expectException(DomainProblemException::class);
        $this->expectExceptionMessage(
            'The system must retain at least one active superadmin.',
        );

        $service->revokeSuperadmin(
            userId: $this->superadminId,
            actorUserId: $this->memberId,
            requestId: 'test-last-superadmin',
            ipAddress: null,
        );
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

    private function changeStatus(
        ResponseInterface $login,
        string $userId,
        string $status,
    ): ResponseInterface {
        return $this->app->handle(
            $this->authenticatedRequest(
                'PATCH',
                sprintf('/api/v1/system/users/%s', $userId),
                $login,
            )->withParsedBody(['status' => $status]),
        );
    }

    private function userField(ResponseInterface $response, string $field): mixed
    {
        $user = $this->decode($response)['user'] ?? null;
        self::assertIsArray($user);

        return $user[$field] ?? null;
    }

    private function authenticatedRequest(
        string $method,
        string $uri,
        ResponseInterface $login,
    ): ServerRequestInterface {
        return $this->request($method, $uri)
            ->withCookieParams([
                'sova_session' => $this->cookieValue(
                    $login,
                    'sova_session',
                ),
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

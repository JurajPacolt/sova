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

final class ProjectApiTest extends TestCase
{
    private const PASSWORD = 'A unique project administration passphrase';

    /**
     * @var App<Container>
     */
    private App $app;
    private Connection $connection;
    private string $ownerId;
    private string $ownerMembershipId;
    private string $memberId;
    private string $memberMembershipId;
    private string $secondMemberId;
    private string $secondMembershipId;
    private string $tenantId;
    private string $foreignTenantId;

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
        $this->ownerId = $this->insertUser('project-owner');
        $this->memberId = $this->insertUser('project-member');
        $this->secondMemberId = $this->insertUser('project-second');
        $this->tenantId = $this->insertTenant('project-primary');
        $this->foreignTenantId = $this->insertTenant('project-foreign');
        $roles->provisionDefaults($this->tenantId, $this->ownerId);
        $this->ownerMembershipId = $this->addMembership(
            $this->tenantId,
            $this->ownerId,
            DefaultRole::TenantOwner,
        );
        $this->memberMembershipId = $this->addMembership(
            $this->tenantId,
            $this->memberId,
            DefaultRole::Member,
        );
        $this->secondMembershipId = $this->addMembership(
            $this->tenantId,
            $this->secondMemberId,
            DefaultRole::Member,
        );
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

    public function testTenantAdminCreatesListsAndArchivesAProject(): void
    {
        $login = $this->login('project-owner');
        $projectId = $this->createProject($login, 'APP', 'Application');

        $projects = $this->visibleProjects($login);
        self::assertSame(['APP'], array_keys($projects));
        self::assertSame([], $projects['APP']);

        $roles = $this->projectRoles($login, $projectId);
        self::assertSame(
            ['MEMBER', 'PROJECT_MANAGER', 'REPORTER', 'VIEWER'],
            array_keys($roles),
        );
        // Aggregated permission codes arrive from PDO as a string, so an empty
        // list here means the hydration silently dropped every grant.
        self::assertContains('project.view', $this->projectRolePermissions($login, $projectId));

        $archive = $this->changeStatus($login, $projectId, 'ARCHIVED');
        self::assertSame(200, $archive->getStatusCode());
        $archived = $this->decode($archive)['project'] ?? null;
        self::assertIsArray($archived);
        self::assertSame('ARCHIVED', $archived['status'] ?? null);

        $repeated = $this->changeStatus($login, $projectId, 'ARCHIVED');
        self::assertSame(200, $repeated->getStatusCode());

        $reactivate = $this->changeStatus($login, $projectId, 'ACTIVE');
        self::assertSame(200, $reactivate->getStatusCode());
        $reactivated = $this->decode($reactivate)['project'] ?? null;
        self::assertIsArray($reactivated);
        self::assertSame('ACTIVE', $reactivated['status'] ?? null);
    }

    public function testProjectCodeMustBeUniqueWithinTheTenant(): void
    {
        $login = $this->login('project-owner');
        $this->createProject($login, 'DUP', 'First');

        $duplicate = $this->postProject($login, [
            'code' => 'DUP',
            'name' => 'Second',
        ]);

        self::assertSame(409, $duplicate->getStatusCode());
        self::assertSame('PROJECT_CODE_TAKEN', $this->decode($duplicate)['code'] ?? null);
    }

    public function testPrivateProjectRequiresALead(): void
    {
        $login = $this->login('project-owner');
        $response = $this->postProject($login, [
            'code' => 'SEC',
            'name' => 'Secret',
            'visibility' => 'PRIVATE',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('PROJECT_INPUT_INVALID', $this->decode($response)['code'] ?? null);
    }

    public function testPlainMemberSeesTenantVisibleProjectsButNotPrivateOnes(): void
    {
        $ownerLogin = $this->login('project-owner');
        $this->createProject($ownerLogin, 'PUB', 'Public');
        $this->createProject(
            $ownerLogin,
            'SEC',
            'Secret',
            'PRIVATE',
            $this->ownerMembershipId,
        );

        $ownerView = $this->visibleProjects($ownerLogin);
        self::assertSame(['PUB', 'SEC'], array_keys($ownerView));
        self::assertSame(['PROJECT_MANAGER'], $ownerView['SEC']);

        $memberView = $this->visibleProjects($this->login('project-member'));
        self::assertSame(['PUB'], array_keys($memberView));
        self::assertSame([], $memberView['PUB']);
    }

    public function testRoleAssignmentRevealsAndRevokesAPrivateProject(): void
    {
        $ownerLogin = $this->login('project-owner');
        $projectId = $this->createProject(
            $ownerLogin,
            'SEC',
            'Secret',
            'PRIVATE',
            $this->ownerMembershipId,
        );
        $roleId = $this->projectRoles($ownerLogin, $projectId)['MEMBER'];

        $assign = $this->mutateAssignment(
            $ownerLogin,
            'PUT',
            $projectId,
            $this->memberMembershipId,
            $roleId,
        );
        self::assertSame(204, $assign->getStatusCode());

        $granted = $this->visibleProjects($this->login('project-member'));
        self::assertSame(['SEC'], array_keys($granted));
        self::assertSame(['MEMBER'], $granted['SEC']);

        $members = $this->projectMembers($ownerLogin, $projectId);
        self::assertContains($this->memberMembershipId, $members);

        $unassign = $this->mutateAssignment(
            $ownerLogin,
            'DELETE',
            $projectId,
            $this->memberMembershipId,
            $roleId,
        );
        self::assertSame(204, $unassign->getStatusCode());

        self::assertSame([], $this->visibleProjects($this->login('project-member')));
    }

    public function testLinkedWorkgroupRevealsAndRevokesAPrivateProject(): void
    {
        $ownerLogin = $this->login('project-owner');
        $projectId = $this->createProject(
            $ownerLogin,
            'SEC',
            'Secret',
            'PRIVATE',
            $this->ownerMembershipId,
        );
        $roleId = $this->projectRoles($ownerLogin, $projectId)['VIEWER'];
        $workgroupId = $this->createWorkgroup($ownerLogin, 'Auditors');
        $this->addWorkgroupMember($ownerLogin, $workgroupId, $this->memberMembershipId);

        $link = $this->app->handle(
            $this->authenticatedRequest(
                'PUT',
                $this->workgroupLinkPath($projectId, $workgroupId),
                $ownerLogin,
            )->withParsedBody(['role_id' => $roleId]),
        );
        self::assertSame(204, $link->getStatusCode());

        $granted = $this->visibleProjects($this->login('project-member'));
        self::assertSame(['SEC'], array_keys($granted));
        self::assertSame(['VIEWER'], $granted['SEC']);

        $links = $this->app->handle(
            $this->authenticatedRequest(
                'GET',
                sprintf(
                    '/api/v1/tenants/%s/projects/%s/workgroups',
                    $this->tenantId,
                    $projectId,
                ),
                $ownerLogin,
            ),
        );
        $linked = $this->decode($links)['workgroups'] ?? null;
        self::assertIsArray($linked);
        self::assertCount(1, $linked);

        $unlink = $this->app->handle(
            $this->authenticatedRequest(
                'DELETE',
                $this->workgroupLinkPath($projectId, $workgroupId),
                $ownerLogin,
            ),
        );
        self::assertSame(204, $unlink->getStatusCode());

        self::assertSame([], $this->visibleProjects($this->login('project-member')));
    }

    public function testProjectManagerAdministersOnlyTheirOwnProject(): void
    {
        $ownerLogin = $this->login('project-owner');
        $managed = $this->createProject($ownerLogin, 'OWN', 'Managed');
        $foreignProject = $this->createProject($ownerLogin, 'OTHER', 'Not managed');
        $managerRoleId = $this->projectRoles($ownerLogin, $managed)['PROJECT_MANAGER'];
        $memberRoleId = $this->projectRoles($ownerLogin, $managed)['MEMBER'];
        $this->mutateAssignment(
            $ownerLogin,
            'PUT',
            $managed,
            $this->memberMembershipId,
            $managerRoleId,
        );

        $memberLogin = $this->login('project-member');

        $addMember = $this->mutateAssignment(
            $memberLogin,
            'PUT',
            $managed,
            $this->secondMembershipId,
            $memberRoleId,
        );
        self::assertSame(204, $addMember->getStatusCode());

        $archiveOwn = $this->changeStatus($memberLogin, $managed, 'ARCHIVED');
        self::assertSame(200, $archiveOwn->getStatusCode());

        $archiveForeign = $this->changeStatus($memberLogin, $foreignProject, 'ARCHIVED');
        self::assertSame(403, $archiveForeign->getStatusCode());
        self::assertSame(
            'PERMISSION_DENIED',
            $this->decode($archiveForeign)['code'] ?? null,
        );
    }

    public function testPlainMemberCannotCreateAProject(): void
    {
        $response = $this->postProject($this->login('project-member'), [
            'code' => 'NOPE',
            'name' => 'Rejected',
        ]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('PERMISSION_DENIED', $this->decode($response)['code'] ?? null);
    }

    public function testProjectsAreIsolatedPerTenant(): void
    {
        $ownerLogin = $this->login('project-owner');
        $projectId = $this->createProject($ownerLogin, 'ISO', 'Isolated');

        $foreignOwnerId = $this->insertUser('project-foreign-owner');
        $this->connection->insert('tenant_memberships', [
            'id' => (string) UuidV7::generate(),
            'tenant_id' => $this->foreignTenantId,
            'user_id' => $foreignOwnerId,
            'status' => 'ACTIVE',
        ]);
        $foreignLogin = $this->login('project-foreign-owner');

        $crossTenantRead = $this->app->handle(
            $this->authenticatedRequest(
                'GET',
                sprintf(
                    '/api/v1/tenants/%s/projects/%s/members',
                    $this->foreignTenantId,
                    $projectId,
                ),
                $foreignLogin,
            ),
        );
        self::assertContains($crossTenantRead->getStatusCode(), [403, 404]);

        $crossTenantWrite = $this->app->handle(
            $this->authenticatedRequest(
                'PATCH',
                sprintf(
                    '/api/v1/tenants/%s/projects/%s',
                    $this->foreignTenantId,
                    $projectId,
                ),
                $foreignLogin,
            )->withParsedBody(['status' => 'ARCHIVED']),
        );
        self::assertContains($crossTenantWrite->getStatusCode(), [403, 404]);

        $foreignListing = $this->app->handle(
            $this->authenticatedRequest(
                'GET',
                sprintf('/api/v1/tenants/%s/projects', $this->foreignTenantId),
                $foreignLogin,
            ),
        );
        $foreignProjects = $this->decode($foreignListing)['projects'] ?? null;
        self::assertIsArray($foreignProjects);
        self::assertCount(0, $foreignProjects);
    }

    /**
     * Project codes the login may see, mapped to that login's own role codes.
     *
     * @return array<string, list<string>>
     */
    private function visibleProjects(ResponseInterface $login): array
    {
        $response = $this->app->handle(
            $this->authenticatedRequest(
                'GET',
                sprintf('/api/v1/tenants/%s/projects', $this->tenantId),
                $login,
            ),
        );
        self::assertSame(200, $response->getStatusCode());
        $projects = $this->decode($response)['projects'] ?? null;
        self::assertIsArray($projects);

        $visible = [];

        foreach ($projects as $project) {
            self::assertIsArray($project);
            $code = $project['code'] ?? null;
            self::assertIsString($code);
            $roles = $project['viewer_roles'] ?? null;
            self::assertIsArray($roles);
            $roleCodes = [];

            foreach ($roles as $role) {
                self::assertIsString($role);
                $roleCodes[] = $role;
            }

            sort($roleCodes);
            $visible[$code] = $roleCodes;
        }

        ksort($visible);

        return $visible;
    }

    /**
     * @return array<string, string> role code to role ID
     */
    private function projectRoles(ResponseInterface $login, string $projectId): array
    {
        $response = $this->app->handle(
            $this->authenticatedRequest(
                'GET',
                sprintf(
                    '/api/v1/tenants/%s/projects/%s/roles',
                    $this->tenantId,
                    $projectId,
                ),
                $login,
            ),
        );
        self::assertSame(200, $response->getStatusCode());
        $roles = $this->decode($response)['roles'] ?? null;
        self::assertIsArray($roles);

        $byCode = [];

        foreach ($roles as $role) {
            self::assertIsArray($role);
            $code = $role['code'] ?? null;
            $id = $role['id'] ?? null;
            self::assertIsString($code);
            self::assertIsString($id);
            $byCode[$code] = $id;
        }

        ksort($byCode);

        return $byCode;
    }

    /**
     * Every permission code granted by the project's `PROJECT_MANAGER` role.
     *
     * @return list<string>
     */
    private function projectRolePermissions(
        ResponseInterface $login,
        string $projectId,
    ): array {
        $response = $this->app->handle(
            $this->authenticatedRequest(
                'GET',
                sprintf(
                    '/api/v1/tenants/%s/projects/%s/roles',
                    $this->tenantId,
                    $projectId,
                ),
                $login,
            ),
        );
        $roles = $this->decode($response)['roles'] ?? null;
        self::assertIsArray($roles);

        foreach ($roles as $role) {
            self::assertIsArray($role);

            if (($role['code'] ?? null) !== 'PROJECT_MANAGER') {
                continue;
            }

            $permissions = $role['permissions'] ?? null;
            self::assertIsArray($permissions);
            $codes = [];

            foreach ($permissions as $permission) {
                self::assertIsString($permission);
                $codes[] = $permission;
            }

            return $codes;
        }

        self::fail('The project manager role was not provisioned.');
    }

    /**
     * @return list<string> membership IDs
     */
    private function projectMembers(ResponseInterface $login, string $projectId): array
    {
        $response = $this->app->handle(
            $this->authenticatedRequest(
                'GET',
                sprintf(
                    '/api/v1/tenants/%s/projects/%s/members',
                    $this->tenantId,
                    $projectId,
                ),
                $login,
            ),
        );
        self::assertSame(200, $response->getStatusCode());
        $members = $this->decode($response)['members'] ?? null;
        self::assertIsArray($members);

        $membershipIds = [];

        foreach ($members as $member) {
            self::assertIsArray($member);
            $membershipId = $member['membership_id'] ?? null;
            self::assertIsString($membershipId);
            $membershipIds[] = $membershipId;
        }

        return $membershipIds;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postProject(
        ResponseInterface $login,
        array $payload,
    ): ResponseInterface {
        return $this->app->handle(
            $this->authenticatedRequest(
                'POST',
                sprintf('/api/v1/tenants/%s/projects', $this->tenantId),
                $login,
            )->withParsedBody($payload),
        );
    }

    private function createProject(
        ResponseInterface $login,
        string $code,
        string $name,
        string $visibility = 'TENANT',
        ?string $leadMembershipId = null,
    ): string {
        $payload = [
            'code' => $code,
            'name' => $name,
            'visibility' => $visibility,
        ];

        if ($leadMembershipId !== null) {
            $payload['lead_membership_id'] = $leadMembershipId;
        }

        $response = $this->postProject($login, $payload);
        self::assertSame(201, $response->getStatusCode());
        $project = $this->decode($response)['project'] ?? null;
        self::assertIsArray($project);
        $id = $project['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function changeStatus(
        ResponseInterface $login,
        string $projectId,
        string $status,
    ): ResponseInterface {
        return $this->app->handle(
            $this->authenticatedRequest(
                'PATCH',
                sprintf(
                    '/api/v1/tenants/%s/projects/%s',
                    $this->tenantId,
                    $projectId,
                ),
                $login,
            )->withParsedBody(['status' => $status]),
        );
    }

    private function mutateAssignment(
        ResponseInterface $login,
        string $method,
        string $projectId,
        string $membershipId,
        string $roleId,
    ): ResponseInterface {
        return $this->app->handle(
            $this->authenticatedRequest(
                $method,
                sprintf(
                    '/api/v1/tenants/%s/projects/%s/members/%s/roles/%s',
                    $this->tenantId,
                    $projectId,
                    $membershipId,
                    $roleId,
                ),
                $login,
            ),
        );
    }

    private function workgroupLinkPath(string $projectId, string $workgroupId): string
    {
        return sprintf(
            '/api/v1/tenants/%s/projects/%s/workgroups/%s',
            $this->tenantId,
            $projectId,
            $workgroupId,
        );
    }

    private function createWorkgroup(ResponseInterface $login, string $name): string
    {
        $response = $this->app->handle(
            $this->authenticatedRequest(
                'POST',
                sprintf('/api/v1/tenants/%s/workgroups', $this->tenantId),
                $login,
            )->withParsedBody(['name' => $name, 'description' => '']),
        );
        self::assertSame(201, $response->getStatusCode());
        $workgroup = $this->decode($response)['workgroup'] ?? null;
        self::assertIsArray($workgroup);
        $id = $workgroup['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function addWorkgroupMember(
        ResponseInterface $login,
        string $workgroupId,
        string $membershipId,
    ): void {
        $response = $this->app->handle(
            $this->authenticatedRequest(
                'PUT',
                sprintf(
                    '/api/v1/tenants/%s/workgroups/%s/members/%s',
                    $this->tenantId,
                    $workgroupId,
                    $membershipId,
                ),
                $login,
            )->withParsedBody(['role' => 'MEMBER']),
        );
        self::assertSame(200, $response->getStatusCode());
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

    private function insertTenant(string $slug): string
    {
        $id = (string) UuidV7::generate();
        $this->connection->insert('tenants', [
            'id' => $id,
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => sprintf('%s-%s', $slug, substr($id, 0, 8)),
            'status' => 'ACTIVE',
        ]);

        return $id;
    }

    private function addMembership(
        string $tenantId,
        string $userId,
        DefaultRole $role,
    ): string {
        $membershipId = (string) UuidV7::generate();
        $this->connection->insert('tenant_memberships', [
            'id' => $membershipId,
            'tenant_id' => $tenantId,
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
                SELECT :tenant_id, :membership_id, id, :user_id
                FROM tenant_roles
                WHERE tenant_id = :tenant_id
                    AND code = :role_code
                SQL,
            [
                'tenant_id' => $tenantId,
                'membership_id' => $membershipId,
                'user_id' => $userId,
                'role_code' => $role->value,
            ],
        );

        return $membershipId;
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
        return (new ServerRequestFactory())->createServerRequest($method, $uri);
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

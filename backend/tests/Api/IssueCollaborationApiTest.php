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

/**
 * End-to-end cover for watchers and issue links.
 *
 * The rules worth protecting: an explicit unwatch must survive the automatic
 * rules, a link must never cross tenants or reveal an issue outside the reader's
 * project scope, and the two directions of a link must never disagree.
 */
final class IssueCollaborationApiTest extends TestCase
{
    private const string PASSWORD = 'A unique collaboration passphrase';

    /**
     * @var App<Container>
     */
    private App $app;
    private Connection $connection;
    private string $ownerId;
    private string $ownerMembershipId;
    private string $memberMembershipId;
    private string $outsiderMembershipId;
    private string $tenantId;
    private string $projectId;
    private string $privateProjectId;

    /**
     * @var array<string, string>
     */
    private array $issueTypes = [];

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
        $this->ownerId = $this->insertUser('collab-owner');
        $memberId = $this->insertUser('collab-member');
        $outsiderId = $this->insertUser('collab-outsider');
        $this->tenantId = $this->insertTenant('collab-primary');
        $roles->provisionDefaults($this->tenantId, $this->ownerId);
        $this->ownerMembershipId = $this->addMembership(
            $this->tenantId,
            $this->ownerId,
            DefaultRole::TenantOwner,
        );
        $this->memberMembershipId = $this->addMembership(
            $this->tenantId,
            $memberId,
            DefaultRole::Member,
        );
        $this->outsiderMembershipId = $this->addMembership(
            $this->tenantId,
            $outsiderId,
            DefaultRole::Member,
        );
        $this->projectId = $this->createProject();
        $this->issueTypes = $this->loadIssueTypes();
        $this->grantProjectRole($this->memberMembershipId, DefaultRole::Member);

        // A second project the plain member has no role in, used to prove that
        // a link never discloses work outside the reader's scope.
        $this->privateProjectId = $this->createProject('OPS');
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
    }

    public function testAuthorWatchesTheIssueTheyFiled(): void
    {
        $login = $this->login('collab-owner');
        $issueId = $this->createIssueId($login, 'BUG', 'Login times out');

        $payload = $this->watchers($login, $issueId);

        self::assertTrue($payload['watching'] ?? null);
        $watchers = $this->rows($payload, 'watchers');
        self::assertCount(1, $watchers);
        self::assertSame($this->ownerMembershipId, $watchers[0]['membership_id'] ?? null);
        self::assertSame('AUTHOR', $watchers[0]['source'] ?? null);
    }

    public function testCommentingSubscribesTheAuthor(): void
    {
        $owner = $this->login('collab-owner');
        $issueId = $this->createIssueId($owner, 'BUG', 'Login times out');

        $member = $this->login('collab-member');
        $this->postComment($member, $issueId, 'I can reproduce this.');

        $sources = [];

        foreach ($this->rows($this->watchers($owner, $issueId), 'watchers') as $watcher) {
            $membershipId = $watcher['membership_id'] ?? null;
            self::assertIsString($membershipId);
            $sources[$membershipId] = $watcher['source'] ?? null;
        }

        self::assertSame('AUTHOR', $sources[$this->ownerMembershipId] ?? null);
        self::assertSame('COMMENT', $sources[$this->memberMembershipId] ?? null);
    }

    /**
     * The point of storing "not watching" instead of deleting the row: an
     * automatic rule must not quietly resubscribe someone who opted out.
     */
    public function testExplicitUnwatchSurvivesTheAutomaticRules(): void
    {
        $owner = $this->login('collab-owner');
        $issueId = $this->createIssueId($owner, 'BUG', 'Login times out');

        $stopped = $this->app->handle($this->authenticatedRequest(
            'DELETE',
            $this->watchPath($issueId),
            $owner,
        ));
        self::assertSame(200, $stopped->getStatusCode());
        self::assertFalse($this->decode($stopped)['watching'] ?? null);
        self::assertFalse($this->watchers($owner, $issueId)['watching'] ?? null);

        // Commenting would normally subscribe the author.
        $this->postComment($owner, $issueId, 'Still not watching.');

        self::assertFalse($this->watchers($owner, $issueId)['watching'] ?? null);
        self::assertSame([], $this->rows($this->watchers($owner, $issueId), 'watchers'));

        // Watching again is an explicit decision and takes effect immediately.
        $resumed = $this->app->handle($this->authenticatedRequest(
            'PUT',
            $this->watchPath($issueId),
            $owner,
        ));
        self::assertSame(200, $resumed->getStatusCode());
        self::assertTrue($this->watchers($owner, $issueId)['watching'] ?? null);
    }

    public function testWatchIsIdempotentInBothDirections(): void
    {
        $login = $this->login('collab-owner');
        $issueId = $this->createIssueId($login, 'BUG', 'Login times out');

        foreach (['PUT', 'PUT', 'DELETE', 'DELETE'] as $method) {
            $response = $this->app->handle($this->authenticatedRequest(
                $method,
                $this->watchPath($issueId),
                $login,
            ));
            self::assertSame(200, $response->getStatusCode());
        }

        self::assertFalse($this->watchers($login, $issueId)['watching'] ?? null);
    }

    public function testLinkIsReadableFromBothEndsWithTheInverseRelation(): void
    {
        $login = $this->login('collab-owner');
        $blocker = $this->createIssueId($login, 'BUG', 'Blocker');
        $blocked = $this->createIssueId($login, 'TASK', 'Blocked work');

        $created = $this->createLink($login, $blocker, $blocked, 'BLOCKS');
        self::assertSame(201, $created->getStatusCode());

        $outward = $this->rows($this->decode($created), 'links');
        self::assertCount(1, $outward);
        self::assertSame('BLOCKS', $outward[0]['relation'] ?? null);
        self::assertTrue($outward[0]['outward'] ?? null);

        $inward = $this->rows($this->links($login, $blocked), 'links');
        self::assertCount(1, $inward);
        self::assertSame('BLOCKS', $inward[0]['type'] ?? null);
        // The same stored row, read from the other side.
        self::assertSame('IS_BLOCKED_BY', $inward[0]['relation'] ?? null);
        self::assertFalse($inward[0]['outward'] ?? null);
        self::assertSame($outward[0]['id'] ?? null, $inward[0]['id'] ?? null);
    }

    public function testSelfLinkAndDuplicatePairAreRejected(): void
    {
        $login = $this->login('collab-owner');
        $first = $this->createIssueId($login, 'BUG', 'First');
        $second = $this->createIssueId($login, 'TASK', 'Second');

        $self = $this->createLink($login, $first, $first, 'RELATES_TO');
        self::assertSame(422, $self->getStatusCode());
        self::assertSame('ISSUE_LINK_SELF', $this->problemCode($self));

        self::assertSame(
            201,
            $this->createLink($login, $first, $second, 'RELATES_TO')->getStatusCode(),
        );

        $again = $this->createLink($login, $first, $second, 'RELATES_TO');
        self::assertSame(409, $again->getStatusCode());
        self::assertSame('ISSUE_LINK_EXISTS', $this->problemCode($again));

        // The mirror pair is refused too: "A blocks B" plus "B blocks A" is
        // contradictory, and a second "relates to" is redundant.
        $mirror = $this->createLink($login, $second, $first, 'RELATES_TO');
        self::assertSame(409, $mirror->getStatusCode());
        self::assertSame('ISSUE_LINK_EXISTS', $this->problemCode($mirror));
    }

    public function testUnknownLinkTypeIsRejected(): void
    {
        $login = $this->login('collab-owner');
        $first = $this->createIssueId($login, 'BUG', 'First');
        $second = $this->createIssueId($login, 'TASK', 'Second');

        $response = $this->createLink($login, $first, $second, 'IS_CAUSED_BY');

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('ISSUE_LINK_INVALID', $this->problemCode($response));
    }

    /**
     * A link must not become a side channel: an issue in a project the reader
     * cannot see is neither listed nor linkable, and both answer the same way
     * as an issue that does not exist.
     */
    public function testLinkNeverRevealsAnIssueOutsideTheReadersScope(): void
    {
        $owner = $this->login('collab-owner');
        $visible = $this->createIssueId($owner, 'BUG', 'Visible work');
        $hidden = $this->createIssueId($owner, 'BUG', 'Secret work', $this->privateProjectId);

        self::assertSame(
            201,
            $this->createLink($owner, $visible, $hidden, 'RELATES_TO')->getStatusCode(),
        );
        self::assertCount(1, $this->rows($this->links($owner, $visible), 'links'));

        // The plain member holds issue.view only in the first project.
        $member = $this->login('collab-member');
        self::assertSame([], $this->rows($this->links($member, $visible), 'links'));

        $attempt = $this->createLink($member, $visible, $hidden, 'BLOCKS');
        self::assertSame(404, $attempt->getStatusCode());
        self::assertSame('ISSUE_NOT_FOUND', $this->problemCode($attempt));

        $missing = $this->createLink(
            $member,
            $visible,
            (string) UuidV7::generate(),
            'BLOCKS',
        );
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame('ISSUE_NOT_FOUND', $this->problemCode($missing));
    }

    public function testLinkIsRemovableFromEitherEndAndRecordedInHistory(): void
    {
        $login = $this->login('collab-owner');
        $first = $this->createIssueId($login, 'BUG', 'First');
        $second = $this->createIssueId($login, 'TASK', 'Second');
        $created = $this->createLink($login, $first, $second, 'DUPLICATES');
        $linkId = $this->rows($this->decode($created), 'links')[0]['id'] ?? null;
        self::assertIsString($linkId);

        // Removed from the target's side, not the source's.
        $removed = $this->app->handle($this->authenticatedRequest(
            'DELETE',
            sprintf('%s/%s', $this->linksPath($second), $linkId),
            $login,
        ));
        self::assertSame(204, $removed->getStatusCode());

        self::assertSame([], $this->rows($this->links($login, $first), 'links'));
        self::assertSame([], $this->rows($this->links($login, $second), 'links'));

        $events = [];

        foreach ($this->rows($this->history($login, $second), 'history') as $entry) {
            $events[] = $entry['event_type'] ?? null;
        }

        self::assertContains('ISSUE_LINK_REMOVED', $events);
    }

    public function testLinkOfAnotherIssueReadsAsMissing(): void
    {
        $login = $this->login('collab-owner');
        $first = $this->createIssueId($login, 'BUG', 'First');
        $second = $this->createIssueId($login, 'TASK', 'Second');
        $third = $this->createIssueId($login, 'TASK', 'Third');
        $created = $this->createLink($login, $first, $second, 'BLOCKS');
        $linkId = $this->rows($this->decode($created), 'links')[0]['id'] ?? null;
        self::assertIsString($linkId);

        $crossed = $this->app->handle($this->authenticatedRequest(
            'DELETE',
            sprintf('%s/%s', $this->linksPath($third), $linkId),
            $login,
        ));

        self::assertSame(404, $crossed->getStatusCode());
        self::assertSame('ISSUE_LINK_NOT_FOUND', $this->problemCode($crossed));
    }

    /**
     * Collaboration follows the issue, not the tenant: a member with no role in
     * the project may neither read the discussion's participants nor its links.
     */
    public function testMemberWithoutProjectAccessSeesNeitherWatchersNorLinks(): void
    {
        $owner = $this->login('collab-owner');
        $issueId = $this->createIssueId($owner, 'BUG', 'Team only');
        self::assertNotSame('', $this->outsiderMembershipId);

        $outsider = $this->login('collab-outsider');

        $watchers = $this->app->handle($this->authenticatedRequest(
            'GET',
            sprintf('/api/v1/tenants/%s/issues/%s/watchers', $this->tenantId, $issueId),
            $outsider,
        ));
        self::assertSame(403, $watchers->getStatusCode());

        $links = $this->app->handle($this->authenticatedRequest(
            'GET',
            $this->linksPath($issueId),
            $outsider,
        ));
        self::assertSame(403, $links->getStatusCode());

        $watch = $this->app->handle($this->authenticatedRequest(
            'PUT',
            $this->watchPath($issueId),
            $outsider,
        ));
        self::assertSame(403, $watch->getStatusCode());
    }

    /**
     * `watcher` was shipped in the field catalog as a known but unsupported
     * field, with the promise that it would light up once its storage landed —
     * without a language version bump. This is that moment.
     */
    public function testSovaQlWatcherFieldIsNowSupported(): void
    {
        $login = $this->login('collab-owner');
        $mine = $this->createIssueId($login, 'BUG', 'I filed this');
        $theirs = $this->createIssueId($login, 'TASK', 'Not watched');

        $this->app->handle($this->authenticatedRequest(
            'DELETE',
            $this->watchPath($theirs),
            $login,
        ));

        self::assertSame(
            ['I filed this'],
            $this->searchTitles($login, 'watcher = currentUser()'),
        );
        self::assertSame(
            ['Not watched'],
            $this->searchTitles($login, 'NOT watcher = currentUser()'),
        );
        self::assertSame(
            ['I filed this'],
            $this->searchTitles(
                $login,
                sprintf('watcher IN (user("%s"))', $this->ownerMembershipId),
            ),
        );

        $metadata = $this->app->handle($this->authenticatedRequest(
            'GET',
            sprintf('/api/v1/tenants/%s/issue-query/metadata', $this->tenantId),
            $login,
        ));
        $names = [];

        foreach ($this->rows($this->decode($metadata), 'fields') as $field) {
            $names[] = $field['name'] ?? null;
        }

        self::assertContains('watcher', $names);
        // The remaining deferred fields must stay hidden.
        self::assertNotContains('labels', $names);
    }

    /**
     * @return list<string>
     */
    private function searchTitles(ResponseInterface $login, string $query): array
    {
        $response = $this->app->handle(
            $this->authenticatedRequest(
                'POST',
                sprintf('/api/v1/tenants/%s/issues/search', $this->tenantId),
                $login,
            )->withParsedBody(['query' => $query]),
        );
        self::assertSame(200, $response->getStatusCode());

        $titles = [];

        foreach ($this->rows($this->decode($response), 'issues') as $issue) {
            $title = $issue['title'] ?? null;
            self::assertIsString($title);
            $titles[] = $title;
        }

        sort($titles);

        return $titles;
    }

    private function createLink(
        ResponseInterface $login,
        string $issueId,
        string $targetIssueId,
        string $type,
    ): ResponseInterface {
        return $this->app->handle(
            $this->authenticatedRequest('POST', $this->linksPath($issueId), $login)
                ->withParsedBody([
                    'target_issue_id' => $targetIssueId,
                    'link_type' => $type,
                ]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function links(ResponseInterface $login, string $issueId): array
    {
        $response = $this->app->handle($this->authenticatedRequest(
            'GET',
            $this->linksPath($issueId),
            $login,
        ));
        self::assertSame(200, $response->getStatusCode());

        return $this->decode($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function watchers(ResponseInterface $login, string $issueId): array
    {
        $response = $this->app->handle($this->authenticatedRequest(
            'GET',
            sprintf('/api/v1/tenants/%s/issues/%s/watchers', $this->tenantId, $issueId),
            $login,
        ));
        self::assertSame(200, $response->getStatusCode());

        return $this->decode($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function history(ResponseInterface $login, string $issueId): array
    {
        $response = $this->app->handle($this->authenticatedRequest(
            'GET',
            sprintf('/api/v1/tenants/%s/issues/%s/history', $this->tenantId, $issueId),
            $login,
        ));
        self::assertSame(200, $response->getStatusCode());

        return $this->decode($response);
    }

    private function postComment(
        ResponseInterface $login,
        string $issueId,
        string $body,
    ): void {
        $response = $this->app->handle(
            $this->authenticatedRequest(
                'POST',
                sprintf('/api/v1/tenants/%s/issues/%s/comments', $this->tenantId, $issueId),
                $login,
            )->withParsedBody(['body' => $body]),
        );
        self::assertSame(201, $response->getStatusCode());
    }

    private function linksPath(string $issueId): string
    {
        return sprintf('/api/v1/tenants/%s/issues/%s/links', $this->tenantId, $issueId);
    }

    private function watchPath(string $issueId): string
    {
        return sprintf(
            '/api/v1/tenants/%s/issues/%s/watchers/me',
            $this->tenantId,
            $issueId,
        );
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $payload, string $key): array
    {
        $rows = $payload[$key] ?? null;
        self::assertIsArray($rows);

        $result = [];

        foreach ($rows as $row) {
            self::assertIsArray($row);
            $entry = [];

            foreach ($row as $column => $value) {
                $entry[(string) $column] = $value;
            }

            $result[] = $entry;
        }

        return $result;
    }

    private function problemCode(ResponseInterface $response): string
    {
        $code = $this->decode($response)['code'] ?? null;
        self::assertIsString($code);

        return $code;
    }

    private function createIssueId(
        ResponseInterface $login,
        string $typeCode,
        string $title,
        ?string $projectId = null,
    ): string {
        $target = $projectId ?? $this->projectId;
        $types = $target === $this->projectId
            ? $this->issueTypes
            : $this->loadIssueTypes($target);

        $response = $this->app->handle(
            $this->authenticatedRequest(
                'POST',
                sprintf(
                    '/api/v1/tenants/%s/projects/%s/issues',
                    $this->tenantId,
                    $target,
                ),
                $login,
            )->withParsedBody([
                'issue_type_id' => $types[$typeCode],
                'title' => $title,
            ]),
        );
        self::assertSame(201, $response->getStatusCode());
        $issue = $this->decode($response)['issue'] ?? null;
        self::assertIsArray($issue);
        $id = $issue['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function grantProjectRole(string $membershipId, DefaultRole $role): void
    {
        $roleId = $this->connection->fetchOne(
            'SELECT id FROM project_roles WHERE project_id = ? AND code = ?',
            [$this->projectId, $role->value],
        );
        self::assertIsString($roleId);

        $response = $this->app->handle($this->authenticatedRequest(
            'PUT',
            sprintf(
                '/api/v1/tenants/%s/projects/%s/members/%s/roles/%s',
                $this->tenantId,
                $this->projectId,
                $membershipId,
                $roleId,
            ),
            $this->login('collab-owner'),
        ));
        self::assertSame(204, $response->getStatusCode());
    }

    private function createProject(string $code = 'APP'): string
    {
        $response = $this->app->handle(
            $this->authenticatedRequest(
                'POST',
                sprintf('/api/v1/tenants/%s/projects', $this->tenantId),
                $this->login('collab-owner'),
            )->withParsedBody([
                'code' => $code,
                'name' => sprintf('Project %s', $code),
                'lead_membership_id' => $this->ownerMembershipId,
            ]),
        );
        self::assertSame(201, $response->getStatusCode());
        $project = $this->decode($response)['project'] ?? null;
        self::assertIsArray($project);
        $id = $project['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return array<string, string>
     */
    private function loadIssueTypes(?string $projectId = null): array
    {
        $types = [];

        foreach ($this->connection->fetchAllAssociative(
            'SELECT code, id FROM project_issue_types WHERE project_id = ?',
            [$projectId ?? $this->projectId],
        ) as $row) {
            $code = $row['code'] ?? null;
            $id = $row['id'] ?? null;

            if (is_string($code) && is_string($id)) {
                $types[$code] = $id;
            }
        }

        return $types;
    }

    private function insertUser(string $prefix): string
    {
        $id = (string) UuidV7::generate();
        $email = sprintf('%s@example.test', $prefix);
        $this->connection->insert('users', [
            'id' => $id,
            'email' => $email,
            'normalized_email' => $email,
            'password_hash' => (new Argon2idPasswordHasher())->hash(self::PASSWORD),
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
            ->withHeader('X-CSRF-Token', $this->cookieValue($login, 'sova_csrf'));
    }

    private function request(string $method, string $uri): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, $uri);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
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

    private function cookieValue(ResponseInterface $response, string $name): string
    {
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

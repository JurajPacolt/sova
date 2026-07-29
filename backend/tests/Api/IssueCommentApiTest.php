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
 * End-to-end cover for issue comments, mentions and the user-facing history.
 *
 * The security-relevant assertions are that a mention cannot address someone
 * who may not see the issue, that raw HTML never reaches storage, that only the
 * author (inside the grace window) or a moderator may change a comment, and
 * that a comment identifier from another issue or tenant reads as missing.
 */
final class IssueCommentApiTest extends TestCase
{
    private const string PASSWORD = 'A unique comment collaboration passphrase';

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
        $this->ownerId = $this->insertUser('comment-owner');
        $memberId = $this->insertUser('comment-member');
        $outsiderId = $this->insertUser('comment-outsider');
        $this->tenantId = $this->insertTenant('comment-primary');
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
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
    }

    public function testCommentIsCreatedListedAndRecordedInHistory(): void
    {
        $login = $this->login('comment-owner');
        $issueId = $this->createIssueId($login, 'BUG', 'Login times out');

        $created = $this->postComment($login, $issueId, 'First **observation**.');
        self::assertSame(201, $created->getStatusCode());
        $comment = $this->commentOf($created);
        self::assertSame('First **observation**.', $comment['body'] ?? null);
        self::assertSame(1, $comment['version'] ?? null);
        self::assertFalse($comment['deleted'] ?? null);
        self::assertArrayHasKey('edited_at', $comment);
        self::assertNull($comment['edited_at']);

        $listed = $this->comments($login, $issueId);
        self::assertCount(1, $listed);

        $history = $this->history($login, $issueId);
        $types = array_map(
            static fn(array $entry): mixed => $entry['event_type'] ?? null,
            $history,
        );
        self::assertContains('COMMENT_ADDED', $types);
        // Newest first.
        self::assertSame('COMMENT_ADDED', $history[0]['event_type'] ?? null);

        self::assertSame(
            1,
            $this->outboxCount('ISSUE_COMMENT', 'COMMENT_ADDED'),
        );
    }

    public function testAuthorEditsOwnCommentWithinTheWindow(): void
    {
        $login = $this->login('comment-owner');
        $issueId = $this->createIssueId($login, 'BUG', 'Login times out');
        $commentId = $this->commentId($this->postComment($login, $issueId, 'Draft.'));

        $edited = $this->app->handle(
            $this->authenticatedRequest(
                'PATCH',
                $this->commentPath($issueId, $commentId),
                $login,
            )->withParsedBody(['body' => 'Corrected.']),
        );

        self::assertSame(200, $edited->getStatusCode());
        $comment = $this->commentOf($edited);
        self::assertSame('Corrected.', $comment['body'] ?? null);
        self::assertSame(2, $comment['version'] ?? null);
        self::assertIsString($comment['edited_at'] ?? null);
    }

    /**
     * A removed comment keeps its place in the discussion but gives up its text
     * and the people it addressed.
     */
    public function testDeletedCommentKeepsItsPlaceWithoutItsContent(): void
    {
        $login = $this->login('comment-owner');
        $issueId = $this->createIssueId($login, 'BUG', 'Login times out');
        $commentId = $this->commentId($this->postComment(
            $login,
            $issueId,
            sprintf(
                'Ping [@Member](sova:user/%s).',
                $this->memberMembershipId,
            ),
        ));

        $deleted = $this->app->handle($this->authenticatedRequest(
            'DELETE',
            $this->commentPath($issueId, $commentId),
            $login,
        ));
        self::assertSame(204, $deleted->getStatusCode());

        $listed = $this->comments($login, $issueId);
        self::assertCount(1, $listed);
        self::assertTrue($listed[0]['deleted'] ?? null);
        self::assertArrayHasKey('body', $listed[0]);
        self::assertNull($listed[0]['body']);
        self::assertSame([], $listed[0]['mentions'] ?? null);

        // Repeating the removal must not fail after the first one succeeded.
        $again = $this->app->handle($this->authenticatedRequest(
            'DELETE',
            $this->commentPath($issueId, $commentId),
            $login,
        ));
        self::assertSame(204, $again->getStatusCode());
    }

    public function testMentionOfAMemberWithAccessIsStored(): void
    {
        $login = $this->login('comment-owner');
        $issueId = $this->createIssueId($login, 'BUG', 'Login times out');

        $created = $this->postComment(
            $login,
            $issueId,
            sprintf('Please look [@Member](sova:user/%s).', $this->memberMembershipId),
        );

        self::assertSame(201, $created->getStatusCode());
        $mentions = $this->commentOf($created)['mentions'] ?? null;
        self::assertIsArray($mentions);
        self::assertCount(1, $mentions);
        self::assertIsArray($mentions[0]);
        self::assertSame($this->memberMembershipId, $mentions[0]['membership_id'] ?? null);
    }

    /**
     * The MVP rule of the webflow: a mention must not address someone who
     * cannot see the issue, because the resulting notification would either
     * leak the issue or never arrive.
     */
    public function testMentionOfAMemberWithoutIssueAccessIsRejected(): void
    {
        $login = $this->login('comment-owner');
        $issueId = $this->createIssueId($login, 'BUG', 'Login times out');

        $rejected = $this->postComment(
            $login,
            $issueId,
            sprintf('Ping [@Outsider](sova:user/%s).', $this->outsiderMembershipId),
        );

        self::assertSame(422, $rejected->getStatusCode());
        self::assertSame('COMMENT_MENTION_NOT_ALLOWED', $this->problemCode($rejected));
        self::assertSame([], $this->comments($login, $issueId));
    }

    public function testUnknownMentionTargetIsRejectedLikeAnInaccessibleOne(): void
    {
        $login = $this->login('comment-owner');
        $issueId = $this->createIssueId($login, 'BUG', 'Login times out');

        $rejected = $this->postComment(
            $login,
            $issueId,
            sprintf('Ping [@Ghost](sova:user/%s).', (string) UuidV7::generate()),
        );

        self::assertSame(422, $rejected->getStatusCode());
        self::assertSame('COMMENT_MENTION_NOT_ALLOWED', $this->problemCode($rejected));
    }

    public function testRawHtmlIsRejectedButCodeBlocksAreNot(): void
    {
        $login = $this->login('comment-owner');
        $issueId = $this->createIssueId($login, 'BUG', 'Login times out');

        $rejected = $this->postComment($login, $issueId, '<script>alert(1)</script>');
        self::assertSame(422, $rejected->getStatusCode());
        self::assertSame('COMMENT_BODY_INVALID', $this->problemCode($rejected));

        $accepted = $this->postComment(
            $login,
            $issueId,
            "The payload was:\n\n```html\n<script>alert(1)</script>\n```\n",
        );
        self::assertSame(201, $accepted->getStatusCode());
    }

    public function testAnotherMemberCannotEditOrDeleteSomebodyElsesComment(): void
    {
        $owner = $this->login('comment-owner');
        $issueId = $this->createIssueId($owner, 'BUG', 'Login times out');
        $commentId = $this->commentId($this->postComment($owner, $issueId, 'Owner note.'));

        $member = $this->login('comment-member');

        $edit = $this->app->handle(
            $this->authenticatedRequest(
                'PATCH',
                $this->commentPath($issueId, $commentId),
                $member,
            )->withParsedBody(['body' => 'Tampered.']),
        );
        self::assertSame(403, $edit->getStatusCode());

        $delete = $this->app->handle($this->authenticatedRequest(
            'DELETE',
            $this->commentPath($issueId, $commentId),
            $member,
        ));
        self::assertSame(403, $delete->getStatusCode());
    }

    /**
     * The project manager holds every project permission, `comment.moderate`
     * among them, so they may remove another member's comment.
     */
    public function testModeratorMayRemoveAnotherMembersComment(): void
    {
        $member = $this->login('comment-member');
        $owner = $this->login('comment-owner');
        $issueId = $this->createIssueId($owner, 'BUG', 'Login times out');
        $commentId = $this->commentId($this->postComment($member, $issueId, 'Member note.'));

        $deleted = $this->app->handle($this->authenticatedRequest(
            'DELETE',
            $this->commentPath($issueId, $commentId),
            $owner,
        ));

        self::assertSame(204, $deleted->getStatusCode());
    }

    public function testMemberWithoutProjectAccessCannotReadOrWriteComments(): void
    {
        $owner = $this->login('comment-owner');
        $issueId = $this->createIssueId($owner, 'BUG', 'Login times out');
        $this->postComment($owner, $issueId, 'Team only.');

        $outsider = $this->login('comment-outsider');

        $list = $this->app->handle($this->authenticatedRequest(
            'GET',
            $this->commentsPath($issueId),
            $outsider,
        ));
        self::assertSame(403, $list->getStatusCode());

        $write = $this->postComment($outsider, $issueId, 'Let me in.');
        self::assertSame(403, $write->getStatusCode());

        $history = $this->app->handle($this->authenticatedRequest(
            'GET',
            sprintf(
                '/api/v1/tenants/%s/issues/%s/history',
                $this->tenantId,
                $issueId,
            ),
            $outsider,
        ));
        self::assertSame(403, $history->getStatusCode());
    }

    public function testCommentOfAnotherIssueReadsAsMissing(): void
    {
        $login = $this->login('comment-owner');
        $first = $this->createIssueId($login, 'BUG', 'First issue');
        $second = $this->createIssueId($login, 'TASK', 'Second issue');
        $commentId = $this->commentId($this->postComment($login, $first, 'On the first.'));

        $crossed = $this->app->handle(
            $this->authenticatedRequest(
                'PATCH',
                $this->commentPath($second, $commentId),
                $login,
            )->withParsedBody(['body' => 'Wrong issue.']),
        );

        self::assertSame(404, $crossed->getStatusCode());
        self::assertSame('COMMENT_NOT_FOUND', $this->problemCode($crossed));
    }

    /**
     * @return array<string, mixed>
     */
    private function commentOf(ResponseInterface $response): array
    {
        $payload = $this->decode($response);
        $comment = $payload['comment'] ?? null;
        self::assertIsArray($comment);

        $result = [];

        foreach ($comment as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }

    private function commentId(ResponseInterface $response): string
    {
        $id = $this->commentOf($response)['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function postComment(
        ResponseInterface $login,
        string $issueId,
        string $body,
    ): ResponseInterface {
        return $this->app->handle(
            $this->authenticatedRequest('POST', $this->commentsPath($issueId), $login)
                ->withParsedBody(['body' => $body]),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function comments(ResponseInterface $login, string $issueId): array
    {
        $response = $this->app->handle($this->authenticatedRequest(
            'GET',
            $this->commentsPath($issueId),
            $login,
        ));
        self::assertSame(200, $response->getStatusCode());

        return $this->rows($this->decode($response), 'comments');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function history(ResponseInterface $login, string $issueId): array
    {
        $response = $this->app->handle($this->authenticatedRequest(
            'GET',
            sprintf('/api/v1/tenants/%s/issues/%s/history', $this->tenantId, $issueId),
            $login,
        ));
        self::assertSame(200, $response->getStatusCode());

        return $this->rows($this->decode($response), 'history');
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

    private function outboxCount(string $aggregateType, string $eventName): int
    {
        $value = $this->connection->fetchOne(
            'SELECT count(*) FROM outbox_events'
                . ' WHERE aggregate_type = ? AND event_name = ? AND tenant_id = ?',
            [$aggregateType, $eventName, $this->tenantId],
        );

        return is_int($value) ? $value : (int) (is_string($value) ? $value : 0);
    }

    private function commentsPath(string $issueId): string
    {
        return sprintf(
            '/api/v1/tenants/%s/issues/%s/comments',
            $this->tenantId,
            $issueId,
        );
    }

    private function commentPath(string $issueId, string $commentId): string
    {
        return sprintf('%s/%s', $this->commentsPath($issueId), $commentId);
    }

    private function problemCode(ResponseInterface $response): string
    {
        $code = $this->decode($response)['code'] ?? null;
        self::assertIsString($code);

        return $code;
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
            $this->login('comment-owner'),
        ));
        self::assertSame(204, $response->getStatusCode());
    }

    private function createIssueId(
        ResponseInterface $login,
        string $typeCode,
        string $title,
    ): string {
        $response = $this->app->handle(
            $this->authenticatedRequest(
                'POST',
                sprintf(
                    '/api/v1/tenants/%s/projects/%s/issues',
                    $this->tenantId,
                    $this->projectId,
                ),
                $login,
            )->withParsedBody([
                'issue_type_id' => $this->issueTypes[$typeCode],
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

    private function createProject(string $code = 'APP'): string
    {
        $response = $this->app->handle(
            $this->authenticatedRequest(
                'POST',
                sprintf('/api/v1/tenants/%s/projects', $this->tenantId),
                $this->login('comment-owner'),
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
    private function loadIssueTypes(): array
    {
        $types = [];

        foreach ($this->connection->fetchAllAssociative(
            'SELECT code, id FROM project_issue_types WHERE project_id = ?',
            [$this->projectId],
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

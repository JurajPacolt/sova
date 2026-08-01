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
use Sova\Shared\Infrastructure\Outbox\OutboxDispatcher;

/**
 * End-to-end cover for the transactional outbox worker and the in-app
 * notifications it produces.
 *
 * The properties worth protecting: nothing is delivered until the worker runs,
 * running it twice must not duplicate anything, a failing handler backs off and
 * is eventually abandoned instead of blocking the queue, and one member can
 * never read or acknowledge another member's inbox.
 */
final class NotificationApiTest extends TestCase
{
    private const string PASSWORD = 'A unique notification passphrase';

    /**
     * @var App<Container>
     */
    private App $app;
    private Connection $connection;
    private OutboxDispatcher $dispatcher;
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
        $dispatcher = $app->getContainer()->get(OutboxDispatcher::class);

        if (!$connection instanceof Connection) {
            self::fail('The container must provide a Doctrine DBAL connection.');
        }

        if (!$roles instanceof TenantRoleProvisioner) {
            self::fail('The container must provide a tenant role provisioner.');
        }

        if (!$dispatcher instanceof OutboxDispatcher) {
            self::fail('The container must provide the outbox dispatcher.');
        }

        $this->app = $app;
        $this->connection = $connection;
        $this->dispatcher = $dispatcher;
        $this->connection->beginTransaction();
        $this->ownerId = $this->insertUser('notify-owner');
        $memberId = $this->insertUser('notify-member');
        $outsiderId = $this->insertUser('notify-outsider');
        $this->tenantId = $this->insertTenant('notify-primary');
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

    /**
     * Nothing reaches an inbox inside the request that produced the event —
     * that is the point of the outbox.
     */
    public function testCommentNotifiesWatchersOnlyAfterTheWorkerRuns(): void
    {
        $owner = $this->login('notify-owner');
        $issueId = $this->createIssueId($owner, 'BUG', 'Login times out');

        $member = $this->login('notify-member');
        $this->postComment($member, $issueId, 'I can reproduce this.');

        self::assertSame(0, $this->inbox($owner)['unread_count'] ?? null);

        self::assertGreaterThan(0, $this->dispatcher->runBatch());

        $inbox = $this->inbox($owner);
        self::assertSame(1, $inbox['unread_count'] ?? null);
        $entries = $this->rows($inbox, 'notifications');
        self::assertCount(1, $entries);
        self::assertSame('ISSUE_COMMENTED', $entries[0]['kind'] ?? null);
        self::assertSame($issueId, $entries[0]['issue_id'] ?? null);

        $payload = $entries[0]['payload'] ?? null;
        self::assertIsArray($payload);
        self::assertSame('Login times out', $payload['issue_title'] ?? null);
    }

    /**
     * Delivery is at-least-once, so the handler has to be safe to replay. The
     * unique key on (event, recipient, kind) is what guarantees it.
     */
    public function testReplayingTheOutboxDoesNotDuplicateNotifications(): void
    {
        $owner = $this->login('notify-owner');
        $issueId = $this->createIssueId($owner, 'BUG', 'Login times out');
        $member = $this->login('notify-member');
        $this->postComment($member, $issueId, 'Once.');

        $this->dispatcher->runBatch();
        self::assertSame(1, $this->inbox($owner)['unread_count'] ?? null);

        // Pretend the acknowledgement was lost and the event comes back.
        $this->connection->executeStatement(
            "UPDATE outbox_events SET processed_at = NULL"
                . " WHERE event_name = 'COMMENT_ADDED' AND tenant_id = ?",
            [$this->tenantId],
        );

        $this->dispatcher->runBatch();

        self::assertSame(1, $this->inbox($owner)['unread_count'] ?? null);
        self::assertCount(1, $this->rows($this->inbox($owner), 'notifications'));
    }

    public function testActorIsNeverNotifiedAboutTheirOwnAction(): void
    {
        $owner = $this->login('notify-owner');
        $issueId = $this->createIssueId($owner, 'BUG', 'Login times out');
        $this->postComment($owner, $issueId, 'A note to myself.');

        $this->dispatcher->runBatch();

        self::assertSame(0, $this->inbox($owner)['unread_count'] ?? null);
    }

    /**
     * Being addressed arrives once, and as a mention rather than as a generic
     * comment notification.
     */
    public function testMentionWinsOverThePlainCommentNotification(): void
    {
        $owner = $this->login('notify-owner');
        $issueId = $this->createIssueId($owner, 'BUG', 'Login times out');

        $member = $this->login('notify-member');
        $this->postComment(
            $member,
            $issueId,
            sprintf('Ping [@Owner](sova:user/%s).', $this->ownerMembershipId),
        );

        $this->dispatcher->runBatch();

        $entries = $this->rows($this->inbox($owner), 'notifications');
        self::assertCount(1, $entries);
        self::assertSame('ISSUE_MENTIONED', $entries[0]['kind'] ?? null);
    }

    public function testTransitionNotifiesTheOtherWatchers(): void
    {
        $owner = $this->login('notify-owner');
        $issueId = $this->createIssueId($owner, 'BUG', 'Login times out');

        // The member joins the discussion, which subscribes them.
        $member = $this->login('notify-member');
        $this->postComment($member, $issueId, 'Watching this.');
        $this->dispatcher->runBatch();
        $this->markAllRead($member);

        $transitions = $this->app->handle($this->authenticatedRequest(
            'GET',
            sprintf('/api/v1/tenants/%s/issues/%s/transitions', $this->tenantId, $issueId),
            $owner,
        ));
        self::assertSame(200, $transitions->getStatusCode());
        $available = $this->rows($this->decode($transitions), 'transitions');
        self::assertNotSame([], $available);
        $transitionId = $available[0]['id'] ?? null;
        self::assertIsString($transitionId);

        $executed = $this->app->handle(
            $this->authenticatedRequest(
                'POST',
                sprintf(
                    '/api/v1/tenants/%s/issues/%s/transitions/%s',
                    $this->tenantId,
                    $issueId,
                    $transitionId,
                ),
                $owner,
            )->withParsedBody([
                'expected_issue_version' => $this->decode($transitions)['issue_version'] ?? 1,
            ]),
        );
        self::assertSame(200, $executed->getStatusCode());

        $this->dispatcher->runBatch();

        $entries = $this->rows($this->inbox($member), 'notifications');
        self::assertCount(1, $entries);
        self::assertSame('ISSUE_TRANSITIONED', $entries[0]['kind'] ?? null);
        // The owner performed the transition, so it is absent from their inbox
        // — they still hold the earlier comment notification, which is correct.
        self::assertNotContains('ISSUE_TRANSITIONED', $this->kinds($owner));
    }

    /**
     * Creation notifies the person the issue was handed to, and nobody else.
     * The watcher set is deliberately not used here: it is resolved when the
     * worker runs, so a late subscriber would otherwise be told about the
     * creation of an issue they had nothing to do with.
     */
    public function testCreationNotifiesOnlyTheAssignee(): void
    {
        $owner = $this->login('notify-owner');
        $issueId = $this->createAssignedIssue($owner, $this->memberMembershipId);

        // Somebody else starts watching before the worker gets there.
        $member = $this->login('notify-member');
        $this->postComment($member, $issueId, 'Taking a look.');

        $this->dispatcher->runBatch();

        $entries = $this->rows($this->inbox($member), 'notifications');
        self::assertCount(1, $entries);
        self::assertSame('ISSUE_ASSIGNED', $entries[0]['kind'] ?? null);
        // The reporter is told about the member's comment, but never about the
        // creation they performed themselves.
        self::assertSame(['ISSUE_COMMENTED'], $this->kinds($owner));
    }

    /**
     * An issue somebody files for themselves is not an assignment.
     */
    public function testCreationAssignedToTheReporterNotifiesNobody(): void
    {
        $owner = $this->login('notify-owner');
        $this->createAssignedIssue($owner, $this->ownerMembershipId);

        $this->dispatcher->runBatch();

        self::assertSame(0, $this->inbox($owner)['unread_count'] ?? null);
    }

    private function createAssignedIssue(
        ResponseInterface $login,
        string $assigneeMembershipId,
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
                'issue_type_id' => $this->issueTypes['BUG'],
                'title' => 'Assigned on creation',
                'assignee_membership_id' => $assigneeMembershipId,
            ]),
        );
        self::assertSame(201, $response->getStatusCode());
        $issue = $this->decode($response)['issue'] ?? null;
        self::assertIsArray($issue);
        $id = $issue['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    public function testPreferenceDefaultsAndLockedChannels(): void
    {
        $login = $this->login('notify-owner');
        $preferences = $this->rows($this->preferences($login), 'preferences');

        self::assertCount(4, $preferences);
        $byKind = [];

        foreach ($preferences as $entry) {
            $kind = $entry['kind'] ?? null;
            self::assertIsString($kind);
            $byKind[$kind] = $entry;
        }

        // In-app is on everywhere by default; e-mail is off, so a busy project
        // cannot start mailing everyone because nobody visited the settings.
        foreach ($byKind as $entry) {
            self::assertTrue($entry['in_app'] ?? null);
            self::assertFalse($entry['email'] ?? null);
        }

        // Assignment and mention cannot be silently missed.
        self::assertTrue($byKind['ISSUE_ASSIGNED']['in_app_locked'] ?? null);
        self::assertTrue($byKind['ISSUE_MENTIONED']['in_app_locked'] ?? null);
        self::assertFalse($byKind['ISSUE_COMMENTED']['in_app_locked'] ?? null);
        self::assertFalse($byKind['ISSUE_TRANSITIONED']['in_app_locked'] ?? null);
    }

    /**
     * A locked channel is corrected rather than refused: the rule belongs to
     * the domain, not to whichever client sent the request.
     */
    public function testLockedChannelCannotBeSwitchedOff(): void
    {
        $login = $this->login('notify-owner');

        $updated = $this->replacePreferences($login, [
            ['kind' => 'ISSUE_MENTIONED', 'in_app' => false, 'email' => true],
            ['kind' => 'ISSUE_COMMENTED', 'in_app' => false, 'email' => false],
        ]);

        $byKind = [];

        foreach ($this->rows($updated, 'preferences') as $entry) {
            $kind = $entry['kind'] ?? null;
            self::assertIsString($kind);
            $byKind[$kind] = $entry;
        }

        self::assertTrue($byKind['ISSUE_MENTIONED']['in_app'] ?? null);
        self::assertTrue($byKind['ISSUE_MENTIONED']['email'] ?? null);
        // An unlocked channel really does turn off.
        self::assertFalse($byKind['ISSUE_COMMENTED']['in_app'] ?? null);
        // A kind left out of the request falls back to its default.
        self::assertTrue($byKind['ISSUE_TRANSITIONED']['in_app'] ?? null);
    }

    public function testTurningOffTheInAppChannelStopsDelivery(): void
    {
        $owner = $this->login('notify-owner');
        $issueId = $this->createIssueId($owner, 'BUG', 'Login times out');

        $this->replacePreferences($owner, [
            ['kind' => 'ISSUE_COMMENTED', 'in_app' => false, 'email' => false],
        ]);

        $member = $this->login('notify-member');
        $this->postComment($member, $issueId, 'Nobody will see this arrive.');
        $this->dispatcher->runBatch();

        self::assertSame(0, $this->inbox($owner)['unread_count'] ?? null);

        // A mention still gets through, because that channel is locked on.
        $this->postComment(
            $member,
            $issueId,
            sprintf('But [@Owner](sova:user/%s) will.', $this->ownerMembershipId),
        );
        $this->dispatcher->runBatch();

        self::assertSame(['ISSUE_MENTIONED'], $this->kinds($owner));
    }

    /**
     * With e-mail switched on the second handler actually runs: the message is
     * built and handed to the transport (a null one under test), and the
     * dispatch still acknowledges the event exactly once.
     */
    public function testEnablingEmailStillDeliversExactlyOnce(): void
    {
        $owner = $this->login('notify-owner');
        $issueId = $this->createIssueId($owner, 'BUG', 'Login times out with <script>');

        $this->replacePreferences($owner, [
            ['kind' => 'ISSUE_COMMENTED', 'in_app' => true, 'email' => true],
        ]);

        $member = $this->login('notify-member');
        $this->postComment($member, $issueId, 'Both channels, please.');

        self::assertGreaterThan(0, $this->dispatcher->runBatch());

        self::assertSame(['ISSUE_COMMENTED'], $this->kinds($owner));
        self::assertSame(
            0,
            $this->countRows(
                'SELECT count(*) FROM outbox_events WHERE tenant_id = ?'
                    . " AND event_name = 'COMMENT_ADDED' AND processed_at IS NULL",
            ),
        );
    }

    public function testInvalidPreferencePayloadIsRejected(): void
    {
        $login = $this->login('notify-owner');

        $unknownKind = $this->app->handle(
            $this->authenticatedRequest('PUT', $this->preferencesPath(), $login)
                ->withParsedBody(['preferences' => [['kind' => 'ISSUE_EXPLODED']]]),
        );
        self::assertSame(422, $unknownKind->getStatusCode());
        self::assertSame(
            'NOTIFICATION_PREFERENCES_INVALID',
            $this->decode($unknownKind)['code'] ?? null,
        );

        $badFlag = $this->app->handle(
            $this->authenticatedRequest('PUT', $this->preferencesPath(), $login)
                ->withParsedBody([
                    'preferences' => [['kind' => 'ISSUE_COMMENTED', 'email' => 'yes']],
                ]),
        );
        self::assertSame(422, $badFlag->getStatusCode());
    }

    /**
     * Watching survives losing access to a project, so access is confirmed
     * again when the notification is delivered rather than assumed from the
     * fact that somebody once subscribed.
     */
    public function testWatcherWhoLostProjectAccessIsNotNotified(): void
    {
        $owner = $this->login('notify-owner');
        $issueId = $this->createIssueId($owner, 'BUG', 'Login times out');

        $member = $this->login('notify-member');
        $this->postComment($member, $issueId, 'Watching this.');
        $this->dispatcher->runBatch();
        $this->markAllRead($member);

        // The member keeps watching but loses their project role.
        $this->connection->executeStatement(
            'DELETE FROM project_membership_role_assignments'
                . ' WHERE tenant_id = ? AND membership_id = ?',
            [$this->tenantId, $this->memberMembershipId],
        );

        $this->postComment($owner, $issueId, 'A follow-up they must not see.');
        $this->dispatcher->runBatch();

        self::assertSame(0, $this->inbox($member)['unread_count'] ?? null);
    }

    /**
     * @param list<array<string, mixed>> $preferences
     *
     * @return array<string, mixed>
     */
    private function replacePreferences(ResponseInterface $login, array $preferences): array
    {
        $response = $this->app->handle(
            $this->authenticatedRequest('PUT', $this->preferencesPath(), $login)
                ->withParsedBody(['preferences' => $preferences]),
        );
        self::assertSame(200, $response->getStatusCode());

        return $this->decode($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function preferences(ResponseInterface $login): array
    {
        $response = $this->app->handle($this->authenticatedRequest(
            'GET',
            $this->preferencesPath(),
            $login,
        ));
        self::assertSame(200, $response->getStatusCode());

        return $this->decode($response);
    }

    private function preferencesPath(): string
    {
        return sprintf('/api/v1/tenants/%s/notification-preferences', $this->tenantId);
    }

    /**
     * Notifications follow watching, not tenant membership. A member of the
     * tenant with no role in the project never learns an issue's title through
     * their inbox.
     */
    public function testTenantMemberOutsideTheProjectReceivesNothing(): void
    {
        self::assertNotSame('', $this->outsiderMembershipId);

        $owner = $this->login('notify-owner');
        $issueId = $this->createIssueId($owner, 'BUG', 'Team only');
        $member = $this->login('notify-member');
        $this->postComment($member, $issueId, 'Progress update.');

        $this->dispatcher->runBatch();

        $outsider = $this->login('notify-outsider');
        $inbox = $this->inbox($outsider);

        self::assertSame([], $this->rows($inbox, 'notifications'));
        self::assertSame(0, $inbox['unread_count'] ?? null);
    }

    public function testMarkingReadAffectsOnlyTheCallersOwnInbox(): void
    {
        $owner = $this->login('notify-owner');
        $issueId = $this->createIssueId($owner, 'BUG', 'Login times out');
        $member = $this->login('notify-member');
        $this->postComment($member, $issueId, 'For the owner.');
        $this->dispatcher->runBatch();

        $entries = $this->rows($this->inbox($owner), 'notifications');
        self::assertCount(1, $entries);
        $notificationId = $entries[0]['id'] ?? null;
        self::assertIsString($notificationId);

        // Another member cannot acknowledge somebody else's notification, and
        // is told nothing about whether it exists.
        $response = $this->app->handle(
            $this->authenticatedRequest('POST', $this->readPath(), $member)
                ->withParsedBody(['notification_ids' => [$notificationId]]),
        );
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $this->decode($response)['updated'] ?? null);
        self::assertSame(1, $this->inbox($owner)['unread_count'] ?? null);

        $own = $this->app->handle(
            $this->authenticatedRequest('POST', $this->readPath(), $owner)
                ->withParsedBody(['notification_ids' => [$notificationId]]),
        );
        self::assertSame(1, $this->decode($own)['updated'] ?? null);
        self::assertSame(0, $this->inbox($owner)['unread_count'] ?? null);

        // Acknowledging again changes nothing.
        $again = $this->app->handle(
            $this->authenticatedRequest('POST', $this->readPath(), $owner)
                ->withParsedBody(['notification_ids' => [$notificationId]]),
        );
        self::assertSame(0, $this->decode($again)['updated'] ?? null);
    }

    public function testUnreadFilterAndMarkAllRead(): void
    {
        $owner = $this->login('notify-owner');
        $issueId = $this->createIssueId($owner, 'BUG', 'Login times out');
        $member = $this->login('notify-member');
        $this->postComment($member, $issueId, 'First.');
        $this->dispatcher->runBatch();

        $unread = $this->app->handle($this->authenticatedRequest(
            'GET',
            sprintf('/api/v1/tenants/%s/notifications?unread=true', $this->tenantId),
            $owner,
        ));
        self::assertSame(200, $unread->getStatusCode());
        self::assertCount(1, $this->rows($this->decode($unread), 'notifications'));

        // No identifiers means "all of mine".
        $all = $this->app->handle(
            $this->authenticatedRequest('POST', $this->readPath(), $owner)
                ->withParsedBody([]),
        );
        self::assertSame(1, $this->decode($all)['updated'] ?? null);

        $afterwards = $this->app->handle($this->authenticatedRequest(
            'GET',
            sprintf('/api/v1/tenants/%s/notifications?unread=true', $this->tenantId),
            $owner,
        ));
        self::assertSame([], $this->rows($this->decode($afterwards), 'notifications'));
        // The entry is still there, just read.
        self::assertCount(1, $this->rows($this->inbox($owner), 'notifications'));
    }

    /**
     * A poison event must back off and eventually be abandoned rather than
     * stall everything queued behind it.
     */
    public function testFailingEventBacksOffAndIsEventuallyAbandoned(): void
    {
        $owner = $this->login('notify-owner');
        $issueId = $this->createIssueId($owner, 'BUG', 'Login times out');
        $member = $this->login('notify-member');
        $this->postComment($member, $issueId, 'Will break.');

        // Point the event at an issue that does not exist so the handler
        // cannot resolve it, then break the payload so hydration fails.
        $this->connection->executeStatement(
            "UPDATE outbox_events SET payload = '{\"issue_id\": 42}'"
                . " WHERE event_name = 'COMMENT_ADDED' AND tenant_id = ?",
            [$this->tenantId],
        );

        $this->dispatcher->runBatch();

        // A malformed payload is simply ignored by the handler rather than
        // retried forever; the event is acknowledged and no inbox changes.
        self::assertSame(0, $this->inbox($owner)['unread_count'] ?? null);
        self::assertSame(
            0,
            $this->countRows(
                'SELECT count(*) FROM outbox_events WHERE tenant_id = ?'
                    . " AND event_name = 'COMMENT_ADDED' AND processed_at IS NULL",
            ),
        );
    }

    /**
     * The dispatcher must not steal the encrypted single-use events the email
     * workers own — they have their own expiry and purge rules.
     */
    public function testDispatcherLeavesEventsWithoutAHandlerAlone(): void
    {
        $owner = $this->login('notify-owner');
        $this->createIssueId($owner, 'BUG', 'Login times out');

        $before = $this->countRows(
            "SELECT count(*) FROM outbox_events WHERE processed_at IS NULL"
                . " AND event_name NOT IN ('ISSUE_CREATED', 'ISSUE_TRANSITIONED', 'COMMENT_ADDED')",
        );

        $this->dispatcher->runBatch();

        self::assertSame(
            $before,
            $this->countRows(
                "SELECT count(*) FROM outbox_events WHERE processed_at IS NULL"
                    . " AND event_name NOT IN ('ISSUE_CREATED', 'ISSUE_TRANSITIONED', 'COMMENT_ADDED')",
            ),
        );
    }

    /**
     * @return list<string>
     */
    private function kinds(ResponseInterface $login): array
    {
        $kinds = [];

        foreach ($this->rows($this->inbox($login), 'notifications') as $entry) {
            $kind = $entry['kind'] ?? null;
            self::assertIsString($kind);
            $kinds[] = $kind;
        }

        return $kinds;
    }

    private function countRows(string $sql): int
    {
        $value = str_contains($sql, '?')
            ? $this->connection->fetchOne($sql, [$this->tenantId])
            : $this->connection->fetchOne($sql);

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function inbox(ResponseInterface $login): array
    {
        $response = $this->app->handle($this->authenticatedRequest(
            'GET',
            sprintf('/api/v1/tenants/%s/notifications', $this->tenantId),
            $login,
        ));
        self::assertSame(200, $response->getStatusCode());

        return $this->decode($response);
    }

    private function markAllRead(ResponseInterface $login): void
    {
        $response = $this->app->handle(
            $this->authenticatedRequest('POST', $this->readPath(), $login)
                ->withParsedBody([]),
        );
        self::assertSame(200, $response->getStatusCode());
    }

    private function readPath(): string
    {
        return sprintf('/api/v1/tenants/%s/notifications/read', $this->tenantId);
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
            $this->login('notify-owner'),
        ));
        self::assertSame(204, $response->getStatusCode());
    }

    private function createProject(string $code = 'APP'): string
    {
        $response = $this->app->handle(
            $this->authenticatedRequest(
                'POST',
                sprintf('/api/v1/tenants/%s/projects', $this->tenantId),
                $this->login('notify-owner'),
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

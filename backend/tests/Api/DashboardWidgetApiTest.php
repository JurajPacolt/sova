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
 * End-to-end cover for dashboard widgets, the registry and the layout.
 *
 * The rules worth protecting: a widget may only render a saved query its owner
 * could already open, an unknown configuration key never reaches storage, the
 * arrangement is validated as a whole rather than one widget at a time, and a
 * query a widget still uses cannot be quietly archived out from under it.
 */
final class DashboardWidgetApiTest extends TestCase
{
    private const string PASSWORD = 'A unique widget passphrase';

    /**
     * @var App<Container>
     */
    private App $app;
    private Connection $connection;
    private string $tenantId;
    private string $ownerMembershipId;

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
        $ownerId = $this->insertUser('widget-owner');
        $otherId = $this->insertUser('widget-other');
        $this->tenantId = $this->insertTenant('widget-primary');
        $roles->provisionDefaults($this->tenantId, $ownerId);
        $this->ownerMembershipId = $this->addMembership(
            $this->tenantId,
            $ownerId,
            DefaultRole::TenantOwner,
        );
        self::assertNotSame(
            '',
            $this->addMembership($this->tenantId, $otherId, DefaultRole::Member),
        );
        self::assertNotSame('', $this->ownerMembershipId);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
    }

    public function testRegistryDescribesTypesWithoutShippingAnyText(): void
    {
        $response = $this->app->handle($this->authenticatedRequest(
            'GET',
            sprintf('/api/v1/tenants/%s/widget-types', $this->tenantId),
            $this->login('widget-owner'),
        ));

        self::assertSame(200, $response->getStatusCode());
        $types = $this->rows($this->decode($response), 'widget_types');
        self::assertCount(5, $types);

        foreach ($types as $type) {
            // Labels are catalog keys, not sentences: the wording belongs to
            // the six translation files, not to the server.
            self::assertIsString($type['label_key'] ?? null);
            self::assertStringStartsWith('widget.type.', (string) $type['label_key']);
            self::assertIsInt($type['min_width'] ?? null);
            self::assertIsInt($type['default_height'] ?? null);
            // Nothing here may name something to run.
            self::assertArrayNotHasKey('component', $type);
        }
    }

    public function testWidgetIsStoredWithTheNormalisedConfiguration(): void
    {
        $login = $this->login('widget-owner');
        $dashboardId = $this->createDashboard($login, 'My work');
        $savedQueryId = $this->createSavedQuery($login, 'Open work', 'statusCategory != DONE');

        $response = $this->addWidget($login, $dashboardId, [
            'saved_query_id' => $savedQueryId,
            'type_key' => 'issue_breakdown',
            'title' => 'By status',
            'configuration' => [
                'group_by' => 'status',
                'top_n' => 5,
                // Unknown keys are not merely ignored — they never reach
                // storage, because the result is built from what the type
                // declares rather than filtered afterwards.
                'onclick' => 'alert(1)',
                'component' => 'AdminPanel',
            ],
        ]);

        self::assertSame(201, $response->getStatusCode());
        $widget = $this->widgetOf($response);
        self::assertSame('issue_breakdown', $widget['type_key'] ?? null);
        self::assertTrue($widget['available'] ?? null);
        self::assertSame('Open work', $widget['source_name'] ?? null);

        $configuration = $widget['configuration'] ?? null;
        self::assertIsArray($configuration);
        self::assertSame('status', $configuration['group_by'] ?? null);
        self::assertSame(5, $configuration['top_n'] ?? null);
        // Defaults are filled in, so a stored configuration keeps working when
        // the schema grows a field.
        self::assertSame('BAR', $configuration['visualization'] ?? null);
        self::assertArrayNotHasKey('onclick', $configuration);
        self::assertArrayNotHasKey('component', $configuration);
    }

    public function testConfigurationIsCheckedAgainstItsType(): void
    {
        $login = $this->login('widget-owner');
        $dashboardId = $this->createDashboard($login, 'My work');
        $savedQueryId = $this->createSavedQuery($login, 'Open work', 'statusCategory != DONE');

        // A dimension the type does not offer.
        $unknownDimension = $this->addWidget($login, $dashboardId, [
            'saved_query_id' => $savedQueryId,
            'type_key' => 'issue_breakdown',
            'configuration' => ['group_by' => 'title'],
        ]);
        self::assertSame(422, $unknownDimension->getStatusCode());
        self::assertSame('WIDGET_CONFIGURATION_INVALID', $this->problemCode($unknownDimension));

        // The same field on both axes would produce a diagonal and nothing else.
        $sameAxes = $this->addWidget($login, $dashboardId, [
            'saved_query_id' => $savedQueryId,
            'type_key' => 'issue_matrix',
            'configuration' => ['rows' => 'status', 'columns' => 'status'],
        ]);
        self::assertSame(422, $sameAxes->getStatusCode());

        // An arbitrary window would let a widget ask for an unbounded scan.
        $range = $this->addWidget($login, $dashboardId, [
            'saved_query_id' => $savedQueryId,
            'type_key' => 'issue_time_series',
            'configuration' => ['range_days' => 4000],
        ]);
        self::assertSame(422, $range->getStatusCode());

        $unknownType = $this->addWidget($login, $dashboardId, [
            'saved_query_id' => $savedQueryId,
            'type_key' => 'issue_pie_of_doom',
            'configuration' => [],
        ]);
        self::assertSame(422, $unknownType->getStatusCode());
    }

    /**
     * A widget must not become a way to run somebody else's private query by
     * pasting its identifier.
     */
    public function testWidgetCannotPointAtAnUnreachableSavedQuery(): void
    {
        $other = $this->login('widget-other');
        $foreignQueryId = $this->createSavedQuery($other, 'Not yours', 'statusCategory != DONE');

        $login = $this->login('widget-owner');
        $dashboardId = $this->createDashboard($login, 'My work');

        $response = $this->addWidget($login, $dashboardId, [
            'saved_query_id' => $foreignQueryId,
            'type_key' => 'issue_count',
            'configuration' => [],
        ]);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('WIDGET_DATA_SOURCE_NOT_FOUND', $this->problemCode($response));

        // A made-up identifier answers identically, so the endpoint cannot be
        // used to find out which queries exist.
        $invented = $this->addWidget($login, $dashboardId, [
            'saved_query_id' => (string) UuidV7::generate(),
            'type_key' => 'issue_count',
            'configuration' => [],
        ]);
        self::assertSame(404, $invented->getStatusCode());
        self::assertSame('WIDGET_DATA_SOURCE_NOT_FOUND', $this->problemCode($invented));
    }

    public function testSharedSavedQueryMayFeedAWidget(): void
    {
        $other = $this->login('widget-other');
        $sharedId = $this->createSavedQuery($other, 'Team view', 'statusCategory != DONE');
        $grants = $this->app->handle(
            $this->authenticatedRequest(
                'PUT',
                sprintf(
                    '/api/v1/tenants/%s/saved-queries/%s/grants',
                    $this->tenantId,
                    $sharedId,
                ),
                $other,
            )->withParsedBody([
                'grants' => [
                    ['membership_id' => $this->ownerMembershipId, 'access' => 'VIEW'],
                ],
            ]),
        );
        self::assertSame(200, $grants->getStatusCode());

        $login = $this->login('widget-owner');
        $dashboardId = $this->createDashboard($login, 'My work');

        $response = $this->addWidget($login, $dashboardId, [
            'saved_query_id' => $sharedId,
            'type_key' => 'issue_count',
            'configuration' => [],
        ]);

        self::assertSame(201, $response->getStatusCode());
    }

    public function testLayoutIsValidatedAsAWholeAndAppliedAtomically(): void
    {
        $login = $this->login('widget-owner');
        $dashboardId = $this->createDashboard($login, 'My work');
        $savedQueryId = $this->createSavedQuery($login, 'Open work', 'statusCategory != DONE');

        $first = $this->addWidgetId($login, $dashboardId, $savedQueryId, 'issue_count');
        $second = $this->addWidgetId($login, $dashboardId, $savedQueryId, 'issue_count');

        // Two widgets in the same cells overlap; the whole request is refused.
        $overlapping = $this->putLayout($login, $dashboardId, 1, [
            ['id' => $first, 'x' => 0, 'y' => 0, 'width' => 3, 'height' => 2],
            ['id' => $second, 'x' => 1, 'y' => 1, 'width' => 3, 'height' => 2],
        ]);
        self::assertSame(422, $overlapping->getStatusCode());
        self::assertSame('DASHBOARD_LAYOUT_INVALID', $this->problemCode($overlapping));

        // Smaller than the type allows.
        $tooSmall = $this->putLayout($login, $dashboardId, 1, [
            ['id' => $first, 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1],
            ['id' => $second, 'x' => 6, 'y' => 0, 'width' => 3, 'height' => 2],
        ]);
        self::assertSame(422, $tooSmall->getStatusCode());

        // Reaching past the right edge of the 12-column grid.
        $offGrid = $this->putLayout($login, $dashboardId, 1, [
            ['id' => $first, 'x' => 10, 'y' => 0, 'width' => 4, 'height' => 2],
            ['id' => $second, 'x' => 0, 'y' => 0, 'width' => 3, 'height' => 2],
        ]);
        self::assertSame(422, $offGrid->getStatusCode());

        // A partial layout would leave the widgets it omitted where they were,
        // which is exactly how two of them end up on top of each other.
        $partial = $this->putLayout($login, $dashboardId, 1, [
            ['id' => $first, 'x' => 0, 'y' => 0, 'width' => 3, 'height' => 2],
        ]);
        self::assertSame(422, $partial->getStatusCode());

        $applied = $this->putLayout($login, $dashboardId, 1, [
            ['id' => $first, 'x' => 0, 'y' => 0, 'width' => 3, 'height' => 2],
            ['id' => $second, 'x' => 3, 'y' => 0, 'width' => 3, 'height' => 2],
        ]);
        self::assertSame(200, $applied->getStatusCode());

        $placed = $this->rows($this->decode($applied), 'widgets');
        self::assertSame(0, $placed[0]['x'] ?? null);
        self::assertSame(3, $placed[1]['x'] ?? null);

        // The dashboard version moved, so the same request cannot land twice.
        $stale = $this->putLayout($login, $dashboardId, 1, [
            ['id' => $first, 'x' => 6, 'y' => 0, 'width' => 3, 'height' => 2],
            ['id' => $second, 'x' => 0, 'y' => 0, 'width' => 3, 'height' => 2],
        ]);
        self::assertSame(409, $stale->getStatusCode());
        self::assertSame('DASHBOARD_VERSION_CONFLICT', $this->problemCode($stale));

        // And nothing moved: the refused layout was not half-applied.
        $unchanged = $this->rows($this->widgets($login, $dashboardId), 'widgets');
        self::assertSame(0, $unchanged[0]['x'] ?? null);
        self::assertSame(3, $unchanged[1]['x'] ?? null);
    }

    public function testNewWidgetLandsUnderneathWhatIsAlreadyThere(): void
    {
        $login = $this->login('widget-owner');
        $dashboardId = $this->createDashboard($login, 'My work');
        $savedQueryId = $this->createSavedQuery($login, 'Open work', 'statusCategory != DONE');

        $this->addWidgetId($login, $dashboardId, $savedQueryId, 'issue_count');
        $this->addWidgetId($login, $dashboardId, $savedQueryId, 'issue_list');

        $widgets = $this->rows($this->widgets($login, $dashboardId), 'widgets');
        self::assertCount(2, $widgets);
        // The second starts where the first ends, so nothing overlaps by
        // default and the person moves it deliberately if they want to.
        self::assertSame(0, $widgets[0]['y'] ?? null);
        self::assertSame(2, $widgets[1]['y'] ?? null);
    }

    /**
     * Archiving a query a widget still renders would leave the dashboard
     * pointing at something withdrawn.
     */
    public function testSavedQueryInUseCannotBeArchived(): void
    {
        $login = $this->login('widget-owner');
        $dashboardId = $this->createDashboard($login, 'My work');
        $savedQueryId = $this->createSavedQuery($login, 'Open work', 'statusCategory != DONE');
        $widgetId = $this->addWidgetId($login, $dashboardId, $savedQueryId, 'issue_count');

        $refused = $this->app->handle(
            $this->authenticatedRequest(
                'POST',
                sprintf(
                    '/api/v1/tenants/%s/saved-queries/%s/archive',
                    $this->tenantId,
                    $savedQueryId,
                ),
                $login,
            )->withParsedBody(['expected_version' => 1]),
        );
        self::assertSame(409, $refused->getStatusCode());
        self::assertSame('SAVED_QUERY_IN_USE', $this->problemCode($refused));
        // The count makes the refusal actionable rather than merely a no. It
        // travels in the detail: field errors belong to validation problems,
        // and this is a conflict.
        $detail = $this->decode($refused)['detail'] ?? null;
        self::assertIsString($detail);
        self::assertStringContainsString('1 dashboard widget', $detail);

        $removed = $this->app->handle($this->authenticatedRequest(
            'DELETE',
            sprintf(
                '/api/v1/tenants/%s/dashboards/%s/widgets/%s',
                $this->tenantId,
                $dashboardId,
                $widgetId,
            ),
            $login,
        ));
        self::assertSame(204, $removed->getStatusCode());

        $archived = $this->app->handle(
            $this->authenticatedRequest(
                'POST',
                sprintf(
                    '/api/v1/tenants/%s/saved-queries/%s/archive',
                    $this->tenantId,
                    $savedQueryId,
                ),
                $login,
            )->withParsedBody(['expected_version' => 1]),
        );
        self::assertSame(200, $archived->getStatusCode());
    }

    public function testWidgetsOnAnotherMembersDashboardAreUnreachable(): void
    {
        $login = $this->login('widget-owner');
        $dashboardId = $this->createDashboard($login, 'My work');
        $savedQueryId = $this->createSavedQuery($login, 'Open work', 'statusCategory != DONE');
        $widgetId = $this->addWidgetId($login, $dashboardId, $savedQueryId, 'issue_count');

        $other = $this->login('widget-other');

        $listed = $this->app->handle($this->authenticatedRequest(
            'GET',
            sprintf('/api/v1/tenants/%s/dashboards/%s/widgets', $this->tenantId, $dashboardId),
            $other,
        ));
        // The dashboard is invisible, so its widgets are unreachable before any
        // widget rule is consulted.
        self::assertSame(404, $listed->getStatusCode());
        self::assertSame('DASHBOARD_NOT_FOUND', $this->problemCode($listed));

        $deleted = $this->app->handle($this->authenticatedRequest(
            'DELETE',
            sprintf(
                '/api/v1/tenants/%s/dashboards/%s/widgets/%s',
                $this->tenantId,
                $dashboardId,
                $widgetId,
            ),
            $other,
        ));
        self::assertSame(404, $deleted->getStatusCode());
    }

    public function testCopyingADashboardDuplicatesWidgetsButNotQueries(): void
    {
        $login = $this->login('widget-owner');
        $dashboardId = $this->createDashboard($login, 'My work');
        $savedQueryId = $this->createSavedQuery($login, 'Open work', 'statusCategory != DONE');
        $this->addWidgetId($login, $dashboardId, $savedQueryId, 'issue_count');

        $response = $this->app->handle(
            $this->authenticatedRequest(
                'POST',
                sprintf('/api/v1/tenants/%s/dashboards/%s/copy', $this->tenantId, $dashboardId),
                $login,
            )->withParsedBody(['name' => 'My work (copy)']),
        );
        self::assertSame(201, $response->getStatusCode());

        $dashboard = $this->decode($response)['dashboard'] ?? null;
        self::assertIsArray($dashboard);
        $copyId = $dashboard['id'] ?? null;
        self::assertIsString($copyId);

        $copied = $this->rows($this->widgets($login, $copyId), 'widgets');
        self::assertCount(1, $copied);
        // A new widget instance, but the very same source: duplicating the
        // query would double the member's list on every copy.
        self::assertSame($savedQueryId, $copied[0]['saved_query_id'] ?? null);

        $queries = $this->app->handle($this->authenticatedRequest(
            'GET',
            sprintf('/api/v1/tenants/%s/saved-queries', $this->tenantId),
            $login,
        ));
        self::assertCount(1, $this->rows($this->decode($queries), 'saved_queries'));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function addWidget(
        ResponseInterface $login,
        string $dashboardId,
        array $payload,
    ): ResponseInterface {
        return $this->app->handle(
            $this->authenticatedRequest(
                'POST',
                sprintf(
                    '/api/v1/tenants/%s/dashboards/%s/widgets',
                    $this->tenantId,
                    $dashboardId,
                ),
                $login,
            )->withParsedBody($payload),
        );
    }

    private function addWidgetId(
        ResponseInterface $login,
        string $dashboardId,
        string $savedQueryId,
        string $typeKey,
    ): string {
        $response = $this->addWidget($login, $dashboardId, [
            'saved_query_id' => $savedQueryId,
            'type_key' => $typeKey,
            'configuration' => $typeKey === 'issue_breakdown' ? ['group_by' => 'status'] : [],
        ]);
        self::assertSame(201, $response->getStatusCode());
        $id = $this->widgetOf($response)['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param list<array<string, mixed>> $placements
     */
    private function putLayout(
        ResponseInterface $login,
        string $dashboardId,
        int $expectedVersion,
        array $placements,
    ): ResponseInterface {
        return $this->app->handle(
            $this->authenticatedRequest(
                'PUT',
                sprintf(
                    '/api/v1/tenants/%s/dashboards/%s/layout',
                    $this->tenantId,
                    $dashboardId,
                ),
                $login,
            )->withParsedBody([
                'expected_version' => $expectedVersion,
                'widgets' => $placements,
            ]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function widgets(ResponseInterface $login, string $dashboardId): array
    {
        $response = $this->app->handle($this->authenticatedRequest(
            'GET',
            sprintf(
                '/api/v1/tenants/%s/dashboards/%s/widgets',
                $this->tenantId,
                $dashboardId,
            ),
            $login,
        ));
        self::assertSame(200, $response->getStatusCode());

        return $this->decode($response);
    }

    private function createDashboard(ResponseInterface $login, string $name): string
    {
        $response = $this->app->handle(
            $this->authenticatedRequest(
                'POST',
                sprintf('/api/v1/tenants/%s/dashboards', $this->tenantId),
                $login,
            )->withParsedBody(['name' => $name]),
        );
        self::assertSame(201, $response->getStatusCode());
        $dashboard = $this->decode($response)['dashboard'] ?? null;
        self::assertIsArray($dashboard);
        $id = $dashboard['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function createSavedQuery(
        ResponseInterface $login,
        string $name,
        string $query,
    ): string {
        $response = $this->app->handle(
            $this->authenticatedRequest(
                'POST',
                sprintf('/api/v1/tenants/%s/saved-queries', $this->tenantId),
                $login,
            )->withParsedBody(['name' => $name, 'query' => $query]),
        );
        self::assertSame(201, $response->getStatusCode());
        $savedQuery = $this->decode($response)['saved_query'] ?? null;
        self::assertIsArray($savedQuery);
        $id = $savedQuery['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function widgetOf(ResponseInterface $response): array
    {
        $widget = $this->decode($response)['widget'] ?? null;
        self::assertIsArray($widget);

        $result = [];

        foreach ($widget as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }

    private function problemCode(ResponseInterface $response): string
    {
        $code = $this->decode($response)['code'] ?? null;
        self::assertIsString($code);

        return $code;
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

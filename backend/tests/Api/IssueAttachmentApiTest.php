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
use Slim\Psr7\UploadedFile;
use Sova\Authorization\Application\TenantRoleProvisioner;
use Sova\Authorization\Domain\DefaultRole;
use Sova\Identity\Infrastructure\Security\Argon2idPasswordHasher;
use Sova\Issues\Domain\Attachment\AttachmentPolicy;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Infrastructure\Bootstrap\ApplicationFactory;

/**
 * End-to-end cover for private attachments.
 *
 * The properties that matter: the allowlist is decided by the bytes and not by
 * the filename, the stored object is never addressed by anything the client
 * sent, a download is authorised every single time and never served inline, and
 * a removed file's bytes go immediately.
 */
final class IssueAttachmentApiTest extends TestCase
{
    private const string PASSWORD = 'A unique attachment passphrase';

    /** A one pixel PNG, so content sniffing sees a genuine image. */
    private const string PNG_BASE64 =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

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
    private string $storagePath;

    /** @var list<string> */
    private array $temporaryFiles = [];

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
        $this->storagePath = dirname(__DIR__, 2) . '/var/test-attachments';
        $this->connection->beginTransaction();
        $this->ownerId = $this->insertUser('attach-owner');
        $memberId = $this->insertUser('attach-member');
        $outsiderId = $this->insertUser('attach-outsider');
        $this->tenantId = $this->insertTenant('attach-primary');
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

        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        // The database rolls back, but bytes on disk do not.
        $this->removeDirectory($this->storagePath);
    }

    public function testUploadStoresTheFileAndListsIt(): void
    {
        $login = $this->login('attach-owner');
        $issueId = $this->createIssueId($login, 'BUG', 'Crash on export');

        $response = $this->upload($login, $issueId, 'diagram.png', $this->png());
        self::assertSame(201, $response->getStatusCode());

        $attachment = $this->decode($response)['attachment'] ?? null;
        self::assertIsArray($attachment);
        self::assertSame('diagram.png', $attachment['name'] ?? null);
        self::assertSame('image/png', $attachment['media_type'] ?? null);
        self::assertSame(strlen($this->png()), $attachment['byte_size'] ?? null);
        self::assertSame(hash('sha256', $this->png()), $attachment['checksum'] ?? null);
        // No scanner is configured in development, and that is recorded
        // honestly rather than disguised as a clean verdict.
        self::assertSame('SKIPPED', $attachment['scan_status'] ?? null);
        self::assertTrue($attachment['downloadable'] ?? null);
        // The internal address is never published.
        self::assertArrayNotHasKey('storage_key', $attachment);

        self::assertCount(1, $this->attachments($login, $issueId));

        $events = [];

        foreach ($this->rows($this->history($login, $issueId), 'history') as $entry) {
            $events[] = $entry['event_type'] ?? null;
        }

        self::assertContains('ATTACHMENT_ADDED', $events);
    }

    /**
     * The stored object must not be reachable by guessing the uploaded name,
     * and must not carry it at all.
     */
    public function testStoredObjectIsNotNamedAfterTheUpload(): void
    {
        $login = $this->login('attach-owner');
        $issueId = $this->createIssueId($login, 'BUG', 'Crash on export');
        $this->upload($login, $issueId, 'diagram.png', $this->png());

        $stored = $this->storedFiles();
        self::assertCount(1, $stored);
        self::assertStringNotContainsString('diagram', $stored[0]);
        self::assertStringNotContainsString('.png', $stored[0]);

        // And the key really is the server-generated shape.
        $key = $this->connection->fetchOne(
            'SELECT storage_key FROM issue_attachments WHERE tenant_id = ?',
            [$this->tenantId],
        );
        self::assertIsString($key);
        self::assertMatchesRegularExpression(
            '#^[0-9a-f-]{36}/[0-9a-f]{2}/[0-9a-f]{2}/[0-9a-f-]{36}$#',
            $key,
        );
    }

    public function testDownloadReturnsTheBytesAndNeverRendersThemInline(): void
    {
        $login = $this->login('attach-owner');
        $issueId = $this->createIssueId($login, 'BUG', 'Crash on export');
        $attachmentId = $this->uploadId($login, $issueId, 'diagram.png', $this->png());

        $response = $this->app->handle($this->authenticatedRequest(
            'GET',
            $this->attachmentPath($issueId, $attachmentId),
            $login,
        ));

        self::assertSame(200, $response->getStatusCode());
        $response->getBody()->rewind();
        self::assertSame($this->png(), (string) $response->getBody());
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertStringStartsWith(
            'attachment;',
            $response->getHeaderLine('Content-Disposition'),
        );
    }

    /**
     * A misleading name must not get a file past the allowlist. This is the
     * case that matters: real script content wearing an image extension.
     */
    public function testContentDecidesTheTypeNotTheFilename(): void
    {
        $login = $this->login('attach-owner');
        $issueId = $this->createIssueId($login, 'BUG', 'Crash on export');

        $script = $this->upload(
            $login,
            $issueId,
            'harmless.png',
            "<?php echo shell_exec(\$_GET['c']); ?>\n",
        );
        self::assertSame(422, $script->getStatusCode());
        self::assertSame('ATTACHMENT_TYPE_NOT_ALLOWED', $this->problemCode($script));

        $html = $this->upload($login, $issueId, 'note.txt', '<html><body>hi</body></html>');
        self::assertSame(422, $html->getStatusCode());
        self::assertSame('ATTACHMENT_TYPE_NOT_ALLOWED', $this->problemCode($html));

        // Genuine image bytes, but a name that claims something else.
        $mismatched = $this->upload($login, $issueId, 'invoice.pdf', $this->png());
        self::assertSame(422, $mismatched->getStatusCode());
        self::assertSame('ATTACHMENT_TYPE_NOT_ALLOWED', $this->problemCode($mismatched));

        self::assertSame([], $this->attachments($login, $issueId));
        self::assertSame([], $this->storedFiles());
    }

    public function testFileOverTheSizeLimitIsRejected(): void
    {
        $login = $this->login('attach-owner');
        $issueId = $this->createIssueId($login, 'BUG', 'Crash on export');

        $path = $this->sparseFile(AttachmentPolicy::MAX_BYTES + 1);
        $response = $this->uploadFile($login, $issueId, 'huge.png', $path);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('ATTACHMENT_TOO_LARGE', $this->problemCode($response));
        self::assertSame([], $this->storedFiles());
    }

    public function testExactlyOneFilePerRequest(): void
    {
        $login = $this->login('attach-owner');
        $issueId = $this->createIssueId($login, 'BUG', 'Crash on export');

        $request = $this->authenticatedRequest(
            'POST',
            $this->attachmentsPath($issueId),
            $login,
        )->withUploadedFiles([
            'file' => $this->uploadedFile('one.png', $this->png()),
            'second' => $this->uploadedFile('two.png', $this->png()),
        ]);

        $response = $this->app->handle($request);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('ATTACHMENT_UPLOAD_INVALID', $this->problemCode($response));
    }

    public function testMemberWithoutProjectAccessCanNeitherUploadNorDownload(): void
    {
        self::assertNotSame('', $this->outsiderMembershipId);

        $owner = $this->login('attach-owner');
        $issueId = $this->createIssueId($owner, 'BUG', 'Crash on export');
        $attachmentId = $this->uploadId($owner, $issueId, 'diagram.png', $this->png());

        $outsider = $this->login('attach-outsider');

        $upload = $this->upload($outsider, $issueId, 'mine.png', $this->png());
        self::assertSame(403, $upload->getStatusCode());

        $download = $this->app->handle($this->authenticatedRequest(
            'GET',
            $this->attachmentPath($issueId, $attachmentId),
            $outsider,
        ));
        self::assertSame(403, $download->getStatusCode());
    }

    public function testUploaderMayRemoveTheirOwnFileAndTheBytesGo(): void
    {
        $login = $this->login('attach-owner');
        $issueId = $this->createIssueId($login, 'BUG', 'Crash on export');
        $attachmentId = $this->uploadId($login, $issueId, 'diagram.png', $this->png());
        self::assertCount(1, $this->storedFiles());

        $removed = $this->app->handle($this->authenticatedRequest(
            'DELETE',
            $this->attachmentPath($issueId, $attachmentId),
            $login,
        ));
        self::assertSame(204, $removed->getStatusCode());

        // Soft delete keeps the record but the file itself is gone at once.
        self::assertSame([], $this->attachments($login, $issueId));
        self::assertSame([], $this->storedFiles());

        $download = $this->app->handle($this->authenticatedRequest(
            'GET',
            $this->attachmentPath($issueId, $attachmentId),
            $login,
        ));
        self::assertSame(404, $download->getStatusCode());
    }

    public function testRemovingSomebodyElsesFileNeedsModeration(): void
    {
        $owner = $this->login('attach-owner');
        $issueId = $this->createIssueId($owner, 'BUG', 'Crash on export');

        $member = $this->login('attach-member');
        $attachmentId = $this->uploadId($member, $issueId, 'members.png', $this->png());

        // The project member holds attachment.upload but not moderation.
        $ownersFile = $this->uploadId($owner, $issueId, 'owners.png', $this->png());
        $denied = $this->app->handle($this->authenticatedRequest(
            'DELETE',
            $this->attachmentPath($issueId, $ownersFile),
            $member,
        ));
        self::assertSame(403, $denied->getStatusCode());

        // The project manager may remove anybody's.
        $allowed = $this->app->handle($this->authenticatedRequest(
            'DELETE',
            $this->attachmentPath($issueId, $attachmentId),
            $owner,
        ));
        self::assertSame(204, $allowed->getStatusCode());
    }

    public function testAttachmentOfAnotherIssueReadsAsMissing(): void
    {
        $login = $this->login('attach-owner');
        $first = $this->createIssueId($login, 'BUG', 'First');
        $second = $this->createIssueId($login, 'TASK', 'Second');
        $attachmentId = $this->uploadId($login, $first, 'diagram.png', $this->png());

        $crossed = $this->app->handle($this->authenticatedRequest(
            'GET',
            $this->attachmentPath($second, $attachmentId),
            $login,
        ));

        self::assertSame(404, $crossed->getStatusCode());
        self::assertSame('ATTACHMENT_NOT_FOUND', $this->problemCode($crossed));
    }

    private function png(): string
    {
        $decoded = base64_decode(self::PNG_BASE64, true);
        self::assertIsString($decoded);

        return $decoded;
    }

    /**
     * @return list<string> storage paths relative to the storage root
     */
    private function storedFiles(): array
    {
        if (!is_dir($this->storagePath)) {
            return [];
        }

        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->storagePath,
                \FilesystemIterator::SKIP_DOTS,
            ),
        );

        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isFile()) {
                $found[] = substr($entry->getPathname(), strlen($this->storagePath) + 1);
            }
        }

        sort($found);

        return $found;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo) {
                $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }
        }

        @rmdir($path);
    }

    private function temporaryFile(string $contents): string
    {
        $path = sys_get_temp_dir() . '/sova-attach-' . bin2hex(random_bytes(8));
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function sparseFile(int $size): string
    {
        $path = sys_get_temp_dir() . '/sova-attach-' . bin2hex(random_bytes(8));
        $handle = fopen($path, 'wb');
        self::assertIsResource($handle);
        fseek($handle, $size - 1);
        fwrite($handle, "\0");
        fclose($handle);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function uploadedFile(string $name, string $contents): UploadedFile
    {
        return new UploadedFile(
            $this->temporaryFile($contents),
            $name,
            null,
            strlen($contents),
            UPLOAD_ERR_OK,
        );
    }

    private function upload(
        ResponseInterface $login,
        string $issueId,
        string $name,
        string $contents,
    ): ResponseInterface {
        return $this->app->handle(
            $this->authenticatedRequest('POST', $this->attachmentsPath($issueId), $login)
                ->withUploadedFiles(['file' => $this->uploadedFile($name, $contents)]),
        );
    }

    private function uploadFile(
        ResponseInterface $login,
        string $issueId,
        string $name,
        string $path,
    ): ResponseInterface {
        $size = filesize($path);

        return $this->app->handle(
            $this->authenticatedRequest('POST', $this->attachmentsPath($issueId), $login)
                ->withUploadedFiles([
                    'file' => new UploadedFile(
                        $path,
                        $name,
                        null,
                        is_int($size) ? $size : 0,
                        UPLOAD_ERR_OK,
                    ),
                ]),
        );
    }

    private function uploadId(
        ResponseInterface $login,
        string $issueId,
        string $name,
        string $contents,
    ): string {
        $response = $this->upload($login, $issueId, $name, $contents);
        self::assertSame(201, $response->getStatusCode());
        $attachment = $this->decode($response)['attachment'] ?? null;
        self::assertIsArray($attachment);
        $id = $attachment['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function attachments(ResponseInterface $login, string $issueId): array
    {
        $response = $this->app->handle($this->authenticatedRequest(
            'GET',
            $this->attachmentsPath($issueId),
            $login,
        ));
        self::assertSame(200, $response->getStatusCode());

        return $this->rows($this->decode($response), 'attachments');
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

    private function attachmentsPath(string $issueId): string
    {
        return sprintf(
            '/api/v1/tenants/%s/issues/%s/attachments',
            $this->tenantId,
            $issueId,
        );
    }

    private function attachmentPath(string $issueId, string $attachmentId): string
    {
        return sprintf('%s/%s', $this->attachmentsPath($issueId), $attachmentId);
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
            $this->login('attach-owner'),
        ));
        self::assertSame(204, $response->getStatusCode());
    }

    private function createProject(string $code = 'APP'): string
    {
        $response = $this->app->handle(
            $this->authenticatedRequest(
                'POST',
                sprintf('/api/v1/tenants/%s/projects', $this->tenantId),
                $this->login('attach-owner'),
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

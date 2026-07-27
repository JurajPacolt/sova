<?php

declare(strict_types=1);

namespace Sova\Shared\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Sova\Authorization\Application\AuthorizationScope;
use Sova\Authorization\Application\AuthorizationService;
use Sova\Authorization\Application\AuthorizationSubject;
use Sova\Authorization\Domain\Permission;
use Sova\Identity\Application\Authentication\SessionContext;
use Sova\Identity\Infrastructure\Http\Middleware\SessionAuthenticationMiddleware;
use Sova\Shared\Application\Audit\AuditActor;
use Sova\Shared\Application\Audit\AuditCursor;
use Sova\Shared\Application\Audit\AuditQuery;
use Sova\Shared\Application\Audit\AuditQueryValidator;
use Sova\Shared\Application\Audit\AuditTenant;
use Sova\Shared\Application\Audit\SecurityAuditEventDetails;
use Sova\Shared\Application\Audit\SecurityAuditReader;
use Sova\Shared\Application\Audit\SecurityAuditRecorder;
use Sova\Shared\Infrastructure\Http\Middleware\RequestIdMiddleware;
use Sova\Tenancy\Application\Access\AccessibleTenant;
use Sova\Tenancy\Infrastructure\Http\Middleware\TenantContextMiddleware;

final readonly class TenantSecurityAuditExportAction
{
    private const PAGE_SIZE = 100;
    private const MAX_PAGES = 50;
    private const MAX_EVENTS = 5000;

    public function __construct(
        private SecurityAuditReader $reader,
        private AuditQueryValidator $validator,
        private AuthorizationService $authorization,
        private SecurityAuditRecorder $audit,
    ) {}

    /**
     * @param array<string, string> $args
     *
     * @throws JsonException
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $session = $this->session($request);
        $tenant = $request->getAttribute(TenantContextMiddleware::ATTRIBUTE);

        if (!$tenant instanceof AccessibleTenant) {
            throw new RuntimeException(
                'Tenant audit export requires a tenant context.',
            );
        }

        $this->authorization->require(
            AuthorizationSubject::contextual(
                $session->actorUserId,
                $session->userId,
                $session->actorIsSuperadmin,
            ),
            Permission::TenantAuditExport,
            AuthorizationScope::tenant($tenant->id),
        );

        $filters = $this->validator->validate($request->getQueryParams());
        $events = $this->collect($filters, $tenant->id);

        $this->audit->record(
            eventType: 'TENANT_AUDIT_EXPORTED',
            outcome: 'SUCCESS',
            reasonCode: 'TENANT_AUDIT_EXPORTED',
            requestId: $this->requestId($request),
            actorUserId: $session->actorUserId,
            tenantId: $tenant->id,
            effectiveUserId: $session->effectiveUserIdForAudit(),
            ipAddress: $this->ipAddress($request),
            metadata: ['result_count' => count($events)],
        );

        $response->getBody()->write($this->toCsv($events));

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader(
                'Content-Disposition',
                sprintf(
                    'attachment; filename="tenant-audit-%s.csv"',
                    $tenant->id,
                ),
            );
    }

    /**
     * @return list<SecurityAuditEventDetails>
     */
    private function collect(AuditQuery $filters, string $tenantId): array
    {
        $events = [];
        $cursor = $filters->cursor;
        $pages = 0;

        do {
            $page = $this->reader->page(
                new AuditQuery(
                    self::PAGE_SIZE,
                    $cursor,
                    $filters->from,
                    $filters->to,
                    $filters->actorUserId,
                    $filters->eventType,
                    $filters->outcome,
                    $filters->requestId,
                ),
                $tenantId,
            );
            array_push($events, ...$page->events);
            $cursor = $page->nextCursor === null
                ? null
                : AuditCursor::decode($page->nextCursor);
            $pages++;
        } while (
            $cursor !== null
            && $pages < self::MAX_PAGES
            && count($events) < self::MAX_EVENTS
        );

        return $events;
    }

    /**
     * @param list<SecurityAuditEventDetails> $events
     *
     * @throws JsonException
     */
    private function toCsv(array $events): string
    {
        $stream = fopen('php://temp', 'w+');

        if ($stream === false) {
            throw new RuntimeException('Unable to open a temporary CSV buffer.');
        }

        fputcsv($stream, [
            'id',
            'occurred_at',
            'event_type',
            'outcome',
            'reason_code',
            'actor_id',
            'actor_email',
            'actor_display_name',
            'effective_user_id',
            'effective_user_email',
            'effective_user_display_name',
            'tenant_id',
            'tenant_name',
            'tenant_slug',
            'request_id',
            'ip_address',
            'metadata',
        ]);

        foreach ($events as $event) {
            [$effectiveUserId, $effectiveUserEmail, $effectiveUserName]
                = $this->actorColumns($event->effectiveUser);
            [$tenantId, $tenantName, $tenantSlug]
                = $this->tenantColumns($event->tenant);

            fputcsv($stream, [
                $event->id,
                $event->occurredAt->format(DATE_ATOM),
                $event->eventType,
                $event->outcome,
                $event->reasonCode,
                $event->actor->id,
                $event->actor->email,
                $event->actor->displayName,
                $effectiveUserId,
                $effectiveUserEmail,
                $effectiveUserName,
                $tenantId,
                $tenantName,
                $tenantSlug,
                $event->requestId,
                $event->ipAddress ?? '',
                json_encode(
                    $event->metadata,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                ),
            ]);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv === false ? '' : $csv;
    }

    /**
     * @return array{string, string, string}
     */
    private function actorColumns(?AuditActor $actor): array
    {
        return $actor === null
            ? ['', '', '']
            : [$actor->id, $actor->email, $actor->displayName];
    }

    /**
     * @return array{string, string, string}
     */
    private function tenantColumns(?AuditTenant $tenant): array
    {
        return $tenant === null
            ? ['', '', '']
            : [$tenant->id, $tenant->name, $tenant->slug];
    }

    private function session(ServerRequestInterface $request): SessionContext
    {
        $session = $request->getAttribute(
            SessionAuthenticationMiddleware::ATTRIBUTE,
        );

        if (!$session instanceof SessionContext) {
            throw new RuntimeException(
                'Tenant audit export requires a session context.',
            );
        }

        return $session;
    }

    private function requestId(ServerRequestInterface $request): string
    {
        $value = $request->getAttribute(RequestIdMiddleware::ATTRIBUTE);

        return is_string($value) ? $value : '';
    }

    private function ipAddress(ServerRequestInterface $request): ?string
    {
        $value = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($value)
            && filter_var($value, FILTER_VALIDATE_IP) !== false
                ? $value
                : null;
    }
}

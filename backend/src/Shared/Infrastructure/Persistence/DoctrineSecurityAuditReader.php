<?php

declare(strict_types=1);

namespace Sova\Shared\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use JsonException;
use RuntimeException;
use Sova\Shared\Application\Audit\AuditActor;
use Sova\Shared\Application\Audit\AuditCursor;
use Sova\Shared\Application\Audit\AuditQuery;
use Sova\Shared\Application\Audit\AuditTenant;
use Sova\Shared\Application\Audit\SecurityAuditEventDetails;
use Sova\Shared\Application\Audit\SecurityAuditPage;
use Sova\Shared\Application\Audit\SecurityAuditReader;

final readonly class DoctrineSecurityAuditReader implements SecurityAuditReader
{
    public function __construct(private Connection $connection) {}

    public function page(
        AuditQuery $query,
        ?string $tenantId,
    ): SecurityAuditPage {
        $builder = $this->baseQuery();

        if ($tenantId !== null) {
            $builder
                ->andWhere('audit.tenant_id = :tenant_id')
                ->setParameter('tenant_id', $tenantId);
        }

        if ($query->cursor !== null) {
            $builder
                ->andWhere(
                    '(audit.occurred_at, audit.id)'
                        . ' < (:cursor_occurred_at, :cursor_id)',
                )
                ->setParameter(
                    'cursor_occurred_at',
                    $query->cursor->occurredAt->format('Y-m-d H:i:s.uP'),
                )
                ->setParameter('cursor_id', $query->cursor->id);
        }

        if ($query->from !== null) {
            $builder
                ->andWhere('audit.occurred_at >= :occurred_from')
                ->setParameter(
                    'occurred_from',
                    $query->from->format('Y-m-d H:i:s.uP'),
                );
        }

        if ($query->to !== null) {
            $builder
                ->andWhere('audit.occurred_at <= :occurred_to')
                ->setParameter(
                    'occurred_to',
                    $query->to->format('Y-m-d H:i:s.uP'),
                );
        }

        $this->applyExactFilter(
            $builder,
            'audit.actor_user_id',
            'actor_user_id',
            $query->actorUserId,
        );
        $this->applyExactFilter(
            $builder,
            'audit.event_type',
            'event_type',
            $query->eventType,
        );
        $this->applyExactFilter(
            $builder,
            'audit.outcome',
            'outcome',
            $query->outcome,
        );
        $this->applyExactFilter(
            $builder,
            'audit.request_id',
            'request_id',
            $query->requestId,
        );

        $rows = $builder
            ->orderBy('audit.occurred_at', 'DESC')
            ->addOrderBy('audit.id', 'DESC')
            ->setMaxResults($query->limit + 1)
            ->executeQuery()
            ->fetchAllAssociative();
        $hasNextPage = count($rows) > $query->limit;

        if ($hasNextPage) {
            array_pop($rows);
        }

        $events = array_map($this->hydrate(...), $rows);
        $lastEvent = $events === [] ? null : $events[array_key_last($events)];
        $nextCursor = $hasNextPage && $lastEvent !== null
            ? (new AuditCursor($lastEvent->occurredAt, $lastEvent->id))->encode()
            : null;

        return new SecurityAuditPage($events, $nextCursor);
    }

    private function baseQuery(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select(
                'audit.id',
                'audit.event_type',
                'audit.outcome',
                'audit.reason_code',
                'audit.request_id',
                'audit.ip_address',
                'audit.metadata',
                'audit.occurred_at',
                'actor.id AS actor_id',
                'actor.email AS actor_email',
                'actor.display_name AS actor_display_name',
                'effective.id AS effective_user_id',
                'effective.email AS effective_user_email',
                'effective.display_name AS effective_user_display_name',
                'tenant.id AS tenant_id',
                'tenant.name AS tenant_name',
                'tenant.slug AS tenant_slug',
            )
            ->from('security_audit_events', 'audit')
            ->innerJoin('audit', 'users', 'actor', 'actor.id = audit.actor_user_id')
            ->leftJoin(
                'audit',
                'users',
                'effective',
                'effective.id = audit.effective_user_id',
            )
            ->leftJoin(
                'audit',
                'tenants',
                'tenant',
                'tenant.id = audit.tenant_id',
            );
    }

    private function applyExactFilter(
        QueryBuilder $builder,
        string $column,
        string $parameter,
        ?string $value,
    ): void {
        if ($value === null) {
            return;
        }

        $builder
            ->andWhere(sprintf('%s = :%s', $column, $parameter))
            ->setParameter($parameter, $value);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): SecurityAuditEventDetails
    {
        return new SecurityAuditEventDetails(
            id: $this->stringValue($row, 'id'),
            actor: new AuditActor(
                $this->stringValue($row, 'actor_id'),
                $this->stringValue($row, 'actor_email'),
                $this->stringValue($row, 'actor_display_name'),
            ),
            effectiveUser: $this->actor(
                $row,
                'effective_user_id',
                'effective_user_email',
                'effective_user_display_name',
            ),
            tenant: $this->tenant($row),
            eventType: $this->stringValue($row, 'event_type'),
            outcome: $this->stringValue($row, 'outcome'),
            reasonCode: $this->stringValue($row, 'reason_code'),
            requestId: $this->stringValue($row, 'request_id'),
            ipAddress: $this->nullableStringValue($row, 'ip_address'),
            metadata: $this->metadata($row['metadata'] ?? null),
            occurredAt: new DateTimeImmutable(
                $this->stringValue($row, 'occurred_at'),
            ),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function actor(
        array $row,
        string $idField,
        string $emailField,
        string $displayNameField,
    ): ?AuditActor {
        $id = $this->nullableStringValue($row, $idField);

        return $id === null
            ? null
            : new AuditActor(
                $id,
                $this->stringValue($row, $emailField),
                $this->stringValue($row, $displayNameField),
            );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function tenant(array $row): ?AuditTenant
    {
        $id = $this->nullableStringValue($row, 'tenant_id');

        return $id === null
            ? null
            : new AuditTenant(
                $id,
                $this->stringValue($row, 'tenant_name'),
                $this->stringValue($row, 'tenant_slug'),
            );
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    private function metadata(mixed $value): array
    {
        if (!is_string($value)) {
            throw new RuntimeException('Audit metadata must be encoded as JSON.');
        }

        try {
            $decoded = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Audit metadata contains invalid JSON.',
                previous: $exception,
            );
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Audit metadata must be a JSON object.');
        }

        $metadata = [];

        foreach ($decoded as $key => $item) {
            if (!is_string($key)) {
                throw new RuntimeException(
                    'Audit metadata keys must be strings.',
                );
            }

            if (
                preg_match(
                    '/(?:password|token|secret|authorization|cookie|ciphertext|hash)/i',
                    $key,
                ) === 1
            ) {
                $metadata[$key] = '[REDACTED]';

                continue;
            }

            if (
                !is_bool($item)
                && !is_int($item)
                && !is_string($item)
                && $item !== null
            ) {
                $metadata[$key] = '[REDACTED]';

                continue;
            }

            $metadata[$key] = $item;
        }

        return $metadata;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function stringValue(array $row, string $field): string
    {
        $value = $row[$field] ?? null;

        if (!is_string($value)) {
            throw new RuntimeException(sprintf(
                'Audit field "%s" must be a string.',
                $field,
            ));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function nullableStringValue(
        array $row,
        string $field,
    ): ?string {
        $value = $row[$field] ?? null;

        if ($value !== null && !is_string($value)) {
            throw new RuntimeException(sprintf(
                'Audit field "%s" must be a string or null.',
                $field,
            ));
        }

        return $value;
    }
}

<?php

declare(strict_types=1);

namespace Sova\Notifications\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Sova\Notifications\Application\NotificationRepository;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Presentation\Http\JsonResponse;
use Sova\Tenancy\Application\Access\AccessibleTenant;
use Sova\Tenancy\Infrastructure\Http\Middleware\TenantContextMiddleware;

/**
 * Marks the caller's notifications as read. An empty or absent list means all
 * of them.
 *
 * Identifiers that belong to somebody else are silently skipped rather than
 * reported: answering differently for "not yours" and "does not exist" would
 * turn the endpoint into an oracle. The response says how many actually
 * changed, which is honest without being a probe.
 */
final readonly class MarkNotificationsReadAction
{
    private const int MAX_IDENTIFIERS = 200;

    public function __construct(private NotificationRepository $notifications) {}

    /**
     * @throws JsonException
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $tenant = $request->getAttribute(TenantContextMiddleware::ATTRIBUTE);

        if (!$tenant instanceof AccessibleTenant) {
            throw new RuntimeException('Notifications require a tenant context.');
        }

        $membershipId = $tenant->membershipId;

        if ($membershipId === null) {
            return JsonResponse::write($response, ['updated' => 0, 'unread_count' => 0]);
        }

        $body = $request->getParsedBody();
        $payload = is_array($body) ? $body : [];

        $updated = $this->notifications->markRead(
            $tenant->id,
            $membershipId,
            $this->identifiers($payload['notification_ids'] ?? null),
        );

        return JsonResponse::write($response, [
            'updated' => $updated,
            'unread_count' => $this->notifications->unreadCount($tenant->id, $membershipId),
        ]);
    }

    /**
     * @return list<string>
     */
    private function identifiers(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (!is_array($value)) {
            throw $this->invalid();
        }

        $identifiers = [];

        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                throw $this->invalid();
            }

            $identifiers[] = $item;

            if (count($identifiers) > self::MAX_IDENTIFIERS) {
                throw $this->invalid();
            }
        }

        return $identifiers;
    }

    private function invalid(): DomainProblemException
    {
        $message = sprintf(
            'Provide at most %d notification identifiers, or none for all.',
            self::MAX_IDENTIFIERS,
        );

        return new DomainProblemException(
            ProblemType::ValidationFailed,
            'NOTIFICATION_SELECTION_INVALID',
            $message,
            ['notification_ids' => [$message]],
        );
    }
}

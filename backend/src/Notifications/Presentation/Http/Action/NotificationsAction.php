<?php

declare(strict_types=1);

namespace Sova\Notifications\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Sova\Notifications\Application\Notification;
use Sova\Notifications\Application\NotificationRepository;
use Sova\Shared\Presentation\Http\JsonResponse;
use Sova\Tenancy\Application\Access\AccessibleTenant;
use Sova\Tenancy\Infrastructure\Http\Middleware\TenantContextMiddleware;

/**
 * The caller's own notification inbox for one tenant.
 *
 * There is no identifier in the path and no permission beyond an active
 * membership: a member reads their own inbox and nobody else's, which is
 * enforced by keying every statement on their membership rather than by a
 * check that could be forgotten.
 */
final readonly class NotificationsAction
{
    private const int MAX_ENTRIES = 100;

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
            // A caller acting purely on system power has no membership, so
            // there is no personal inbox to read — an empty one, not an error.
            return JsonResponse::write($response, [
                'notifications' => [],
                'unread_count' => 0,
            ]);
        }

        $query = $request->getQueryParams();
        $unreadOnly = ($query['unread'] ?? null) === 'true';

        return JsonResponse::write($response, [
            'notifications' => array_map(
                $this->serialize(...),
                $this->notifications->listForMember(
                    $tenant->id,
                    $membershipId,
                    $unreadOnly,
                    self::MAX_ENTRIES,
                ),
            ),
            'unread_count' => $this->notifications->unreadCount($tenant->id, $membershipId),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Notification $notification): array
    {
        return [
            'id' => $notification->id,
            'kind' => $notification->kind,
            'project_id' => $notification->projectId,
            'issue_id' => $notification->issueId,
            'actor' => $notification->actorUserId === null ? null : [
                'user_id' => $notification->actorUserId,
                'display_name' => $notification->actorDisplayName,
            ],
            'payload' => $notification->payload,
            'read_at' => $notification->readAt?->format(DATE_ATOM),
            'created_at' => $notification->createdAt->format(DATE_ATOM),
        ];
    }
}

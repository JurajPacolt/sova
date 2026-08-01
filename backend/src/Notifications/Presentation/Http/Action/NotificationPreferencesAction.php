<?php

declare(strict_types=1);

namespace Sova\Notifications\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Sova\Notifications\Application\ChannelPreference;
use Sova\Notifications\Application\PreferenceRepository;
use Sova\Notifications\Domain\NotificationKind;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Presentation\Http\JsonResponse;
use Sova\Tenancy\Application\Access\AccessibleTenant;
use Sova\Tenancy\Infrastructure\Http\Middleware\TenantContextMiddleware;

/**
 * Reads and replaces the caller's own notification channel settings.
 *
 * The response reports which channels are locked, so the interface can show the
 * automatic rules instead of leaving the user guessing why an option will not
 * turn off. A locked channel submitted as false is corrected rather than
 * refused — the value object enforces it — because the rule belongs to the
 * domain, not to whichever client happened to send the request.
 */
final readonly class NotificationPreferencesAction
{
    public function __construct(private PreferenceRepository $preferences) {}

    /**
     * @throws JsonException
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $tenant = $request->getAttribute(TenantContextMiddleware::ATTRIBUTE);

        if (!$tenant instanceof AccessibleTenant) {
            throw new RuntimeException('Notification settings require a tenant context.');
        }

        $membershipId = $tenant->membershipId;

        if ($membershipId === null) {
            throw new DomainProblemException(
                ProblemType::PermissionDenied,
                'NOTIFICATION_MEMBERSHIP_REQUIRED',
                'Only a tenant member has notification settings.',
            );
        }

        if ($request->getMethod() === 'PUT') {
            $body = $request->getParsedBody();
            $this->preferences->replace(
                $tenant->id,
                $membershipId,
                $this->parse($body),
            );
        }

        return JsonResponse::write($response, [
            'preferences' => array_values(array_map(
                static fn(ChannelPreference $preference): array => [
                    'kind' => $preference->kind->value,
                    'in_app' => $preference->inApp,
                    'email' => $preference->email,
                    'in_app_locked' => $preference->kind->inAppIsMandatory(),
                ],
                $this->preferences->forMember($tenant->id, $membershipId),
            )),
        ]);
    }

    /**
     * @return list<ChannelPreference>
     */
    private function parse(mixed $payload): array
    {
        $entries = is_array($payload) ? ($payload['preferences'] ?? null) : null;

        if (!is_array($entries)) {
            throw $this->invalid('Provide a preferences array.');
        }

        $parsed = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw $this->invalid('Each preference must be an object.');
            }

            $kind = is_string($entry['kind'] ?? null)
                ? NotificationKind::tryFrom($entry['kind'])
                : null;

            if ($kind === null) {
                throw $this->invalid('Each preference must name a known event kind.');
            }

            $parsed[$kind->value] = new ChannelPreference(
                $kind,
                $this->flag($entry['in_app'] ?? null, $kind->defaultInApp()),
                $this->flag($entry['email'] ?? null, $kind->defaultEmail()),
            );
        }

        return array_values($parsed);
    }

    private function flag(mixed $value, bool $fallback): bool
    {
        if ($value === null) {
            return $fallback;
        }

        if (!is_bool($value)) {
            throw $this->invalid('Channel settings must be true or false.');
        }

        return $value;
    }

    private function invalid(string $message): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::ValidationFailed,
            'NOTIFICATION_PREFERENCES_INVALID',
            $message,
            ['preferences' => [$message]],
        );
    }
}

<?php

declare(strict_types=1);

namespace Sova\Tenancy\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Infrastructure\Http\Middleware\RequestIdMiddleware;
use Sova\Shared\Presentation\Http\JsonResponse;
use Sova\Tenancy\Application\Invitation\InvitationAccessService;

final readonly class AcceptNewAccountInvitationAction
{
    /**
     * @var list<string>
     */
    private const LOCALES = ['sk', 'cs', 'en', 'de', 'pl', 'hu'];

    public function __construct(
        private InvitationAccessService $invitationAccess,
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
        $body = $request->getParsedBody();
        $payload = is_array($body) ? $body : [];
        $tokenValue = $payload['token'] ?? null;
        $displayNameValue = $payload['display_name'] ?? null;
        $localeValue = $payload['preferred_locale'] ?? 'sk';
        $passwordValue = $payload['password'] ?? null;
        $confirmationValue = $payload['password_confirmation'] ?? null;
        $token = is_string($tokenValue) ? $tokenValue : '';
        $displayName = is_string($displayNameValue)
            ? trim($displayNameValue)
            : '';
        $locale = is_string($localeValue) ? $localeValue : '';
        $password = is_string($passwordValue) ? $passwordValue : '';
        $confirmation = is_string($confirmationValue) ? $confirmationValue : '';
        $errors = [];

        if ($displayName === '' || mb_strlen($displayName, 'UTF-8') > 160) {
            $errors['display_name'] = [
                'Enter a display name of at most 160 characters.',
            ];
        }

        if (!in_array($locale, self::LOCALES, true)) {
            $errors['preferred_locale'] = ['Choose a supported locale.'];
        }

        if ($password === '' || strlen($password) > 1024) {
            $errors['password'] = ['Enter a password of at most 1024 bytes.'];
        }

        if ($confirmation === '' || !hash_equals($password, $confirmation)) {
            $errors['password_confirmation'] = [
                'The password confirmation must match.',
            ];
        }

        if ($errors !== []) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'INVITATION_ACCEPTANCE_INPUT_INVALID',
                'The invitation acceptance input is invalid.',
                $errors,
            );
        }

        $accepted = $this->invitationAccess->acceptNewAccount(
            plainTextToken: $token,
            displayName: $displayName,
            preferredLocale: $locale,
            password: $password,
            requestId: $this->requestId($request),
            ipAddress: $this->ipAddress($request),
        );

        return JsonResponse::write(
            $response,
            [
                'user_id' => $accepted->userId,
                'tenant_id' => $accepted->tenantId,
                'tenant_slug' => $accepted->tenantSlug,
                'membership_created' => $accepted->membershipCreated,
            ],
            201,
        );
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

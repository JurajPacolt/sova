<?php

declare(strict_types=1);

namespace Sova\Tenancy\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sova\Authorization\Application\AuthorizationScope;
use Sova\Authorization\Application\AuthorizationService;
use Sova\Authorization\Domain\Permission;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Presentation\Http\JsonResponse;
use Sova\Tenancy\Application\Settings\TenantSettingsDetails;
use Sova\Tenancy\Application\Settings\TenantSettingsService;
use Sova\Tenancy\Presentation\Http\InvitationContext;
use Sova\Tenancy\Presentation\Http\TenantSettingsInput;
use Sova\Tenancy\Presentation\Http\TenantSettingsSerializer;

final readonly class UpdateTenantSettingsAction
{
    public function __construct(
        private TenantSettingsService $settings,
        private TenantSettingsInput $input,
        private TenantSettingsSerializer $serializer,
        private InvitationContext $context,
        private AuthorizationService $authorization,
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
        [$session, $tenant, $subject] = $this->context->resolve($request);
        $this->authorization->require(
            $subject,
            Permission::TenantSettingsManage,
            AuthorizationScope::tenant($tenant->id),
        );
        $body = $request->getParsedBody();
        $payload = [];

        if (is_array($body)) {
            foreach ($body as $key => $value) {
                $payload[(string) $key] = $value;
            }
        }

        $section = $args['section'] ?? '';
        $updated = match ($section) {
            'general' => $this->settings->updateGeneral(
                $tenant->id,
                $this->input->general($payload),
                $session->actorUserId,
                $session->effectiveUserIdForAudit(),
                $this->context->requestId($request),
                $this->context->ipAddress($request),
            ),
            'localization' => $this->settings->updateLocalization(
                $tenant->id,
                $this->input->localization($payload),
                $session->actorUserId,
                $session->effectiveUserIdForAudit(),
                $this->context->requestId($request),
                $this->context->ipAddress($request),
            ),
            default => $this->unknownSection(),
        };

        return JsonResponse::write($response, [
            'settings' => $this->serializer->serialize($updated),
        ]);
    }

    private function unknownSection(): TenantSettingsDetails
    {
        throw new DomainProblemException(
            ProblemType::ResourceNotFound,
            'TENANT_SETTINGS_SECTION_NOT_FOUND',
            'The tenant settings section was not found.',
        );
    }
}

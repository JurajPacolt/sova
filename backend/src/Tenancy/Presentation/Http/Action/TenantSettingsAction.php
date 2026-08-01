<?php

declare(strict_types=1);

namespace Sova\Tenancy\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sova\Authorization\Application\AuthorizationScope;
use Sova\Authorization\Application\AuthorizationService;
use Sova\Authorization\Domain\Permission;
use Sova\Shared\Presentation\Http\JsonResponse;
use Sova\Tenancy\Application\Settings\TenantSettingsService;
use Sova\Tenancy\Presentation\Http\InvitationContext;
use Sova\Tenancy\Presentation\Http\TenantSettingsSerializer;

final readonly class TenantSettingsAction
{
    public function __construct(
        private TenantSettingsService $settings,
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
        [, $tenant, $subject] = $this->context->resolve($request);
        $this->authorization->require(
            $subject,
            Permission::TenantSettingsManage,
            AuthorizationScope::tenant($tenant->id),
        );

        return JsonResponse::write($response, [
            'settings' => $this->serializer->serialize(
                $this->settings->get($tenant->id),
            ),
        ]);
    }
}

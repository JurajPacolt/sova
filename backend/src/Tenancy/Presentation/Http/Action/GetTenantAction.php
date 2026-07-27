<?php

declare(strict_types=1);

namespace Sova\Tenancy\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Sova\Shared\Presentation\Http\JsonResponse;
use Sova\Tenancy\Application\Access\AccessibleTenant;
use Sova\Tenancy\Infrastructure\Http\Middleware\TenantContextMiddleware;

final readonly class GetTenantAction
{
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
        $tenant = $request->getAttribute(TenantContextMiddleware::ATTRIBUTE);

        if (!$tenant instanceof AccessibleTenant) {
            throw new RuntimeException('Tenant context is missing.');
        }

        return JsonResponse::write($response, [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status->value,
                'access' => [
                    'type' => $tenant->viaSuperadmin ? 'SUPERADMIN' : 'MEMBERSHIP',
                    'membership_id' => $tenant->membershipId,
                ],
            ],
        ]);
    }
}

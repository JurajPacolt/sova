<?php

declare(strict_types=1);

namespace Sova\Shared\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sova\Shared\Infrastructure\Configuration\Settings;
use Sova\Shared\Presentation\Http\JsonResponse;

final readonly class ApiInfoAction
{
    public function __construct(private Settings $settings) {}

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
        return JsonResponse::write($response, [
            'name' => $this->settings->string('app.name', 'SOVA API'),
            'version' => $this->settings->string('app.version', 'dev'),
            'api_version' => 'v1',
        ]);
    }
}

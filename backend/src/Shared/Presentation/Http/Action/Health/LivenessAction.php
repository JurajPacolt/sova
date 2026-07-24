<?php

declare(strict_types=1);

namespace Sova\Shared\Presentation\Http\Action\Health;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sova\Shared\Infrastructure\Configuration\Settings;
use Sova\Shared\Presentation\Http\JsonResponse;

final readonly class LivenessAction
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
            'status' => 'ok',
            'service' => $this->settings->string('app.name', 'SOVA API'),
            'version' => $this->settings->string('app.version', 'dev'),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Sova\Shared\Presentation\Http;

use JsonException;
use Psr\Http\Message\ResponseInterface;

final class JsonResponse
{
    /**
     * @param array<string, mixed> $payload
     *
     * @throws JsonException
     */
    public static function write(
        ResponseInterface $response,
        array $payload,
        int $status = 200,
    ): ResponseInterface {
        $response->getBody()->write(
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    private function __construct() {}
}

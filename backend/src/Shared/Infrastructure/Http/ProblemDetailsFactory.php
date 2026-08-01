<?php

declare(strict_types=1);

namespace Sova\Shared\Infrastructure\Http;

use Slim\Exception\HttpException;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Infrastructure\Configuration\Settings;
use Throwable;

final readonly class ProblemDetailsFactory
{
    /**
     * @var array<string, array{status: int, title: string, code: string, detail: string}>
     */
    private const DEFINITIONS = [
        'invalid-request' => [
            'status' => 400,
            'title' => 'Invalid Request',
            'code' => 'INVALID_REQUEST',
            'detail' => 'The request could not be processed.',
        ],
        'authentication-required' => [
            'status' => 401,
            'title' => 'Authentication Required',
            'code' => 'AUTHENTICATION_REQUIRED',
            'detail' => 'Authentication is required to access this resource.',
        ],
        'permission-denied' => [
            'status' => 403,
            'title' => 'Permission Denied',
            'code' => 'PERMISSION_DENIED',
            'detail' => 'You do not have permission to perform this action.',
        ],
        'resource-not-found' => [
            'status' => 404,
            'title' => 'Resource Not Found',
            'code' => 'RESOURCE_NOT_FOUND',
            'detail' => 'The requested resource was not found.',
        ],
        'method-not-allowed' => [
            'status' => 405,
            'title' => 'Method Not Allowed',
            'code' => 'METHOD_NOT_ALLOWED',
            'detail' => 'The request method is not supported for this resource.',
        ],
        'not-acceptable' => [
            'status' => 406,
            'title' => 'Not Acceptable',
            'code' => 'NOT_ACCEPTABLE',
            'detail' => 'The requested response format is not available.',
        ],
        'conflict' => [
            'status' => 409,
            'title' => 'Conflict',
            'code' => 'RESOURCE_CONFLICT',
            'detail' => 'The request conflicts with the current resource state.',
        ],
        'gone' => [
            'status' => 410,
            'title' => 'Resource Gone',
            'code' => 'RESOURCE_GONE',
            'detail' => 'The requested resource is no longer available.',
        ],
        'payload-too-large' => [
            'status' => 413,
            'title' => 'Payload Too Large',
            'code' => 'PAYLOAD_TOO_LARGE',
            'detail' => 'The request payload exceeds the allowed limit.',
        ],
        'unsupported-media-type' => [
            'status' => 415,
            'title' => 'Unsupported Media Type',
            'code' => 'UNSUPPORTED_MEDIA_TYPE',
            'detail' => 'The request content type is not supported.',
        ],
        'validation-failed' => [
            'status' => 422,
            'title' => 'Validation Failed',
            'code' => 'VALIDATION_FAILED',
            'detail' => 'One or more request values are invalid.',
        ],
        'rate-limit-exceeded' => [
            'status' => 429,
            'title' => 'Rate Limit Exceeded',
            'code' => 'RATE_LIMIT_EXCEEDED',
            'detail' => 'Too many requests were made. Try again later.',
        ],
        'internal-server-error' => [
            'status' => 500,
            'title' => 'Internal Server Error',
            'code' => 'INTERNAL_SERVER_ERROR',
            'detail' => 'The server could not complete the request.',
        ],
        'service-unavailable' => [
            'status' => 503,
            'title' => 'Service Unavailable',
            'code' => 'SERVICE_UNAVAILABLE',
            'detail' => 'The service is temporarily unavailable.',
        ],
    ];

    public function __construct(private Settings $settings) {}

    public function fromThrowable(
        Throwable $exception,
        string $instance,
        string $requestId,
    ): ProblemDetails {
        if ($exception instanceof DomainProblemException) {
            return $this->fromDomainProblem($exception, $instance, $requestId);
        }

        if ($exception instanceof HttpException) {
            return $this->fromHttpException($exception, $instance, $requestId);
        }

        $definition = $this->definition(ProblemType::InternalServerError);
        $detail = $definition['detail'];

        if (
            $this->settings->bool('app.debug', false)
            && trim($exception->getMessage()) !== ''
        ) {
            $detail = $exception->getMessage();
        }

        return $this->create(
            ProblemType::InternalServerError,
            $definition['code'],
            $detail,
            $instance,
            $requestId,
        );
    }

    private function fromDomainProblem(
        DomainProblemException $exception,
        string $instance,
        string $requestId,
    ): ProblemDetails {
        return $this->create(
            $exception->problemType(),
            $exception->problemCode(),
            $exception->getMessage(),
            $instance,
            $requestId,
            $exception->fieldErrors(),
        );
    }

    private function fromHttpException(
        HttpException $exception,
        string $instance,
        string $requestId,
    ): ProblemDetails {
        $problemType = $this->problemTypeForHttpStatus($exception->getCode());

        if ($problemType === null) {
            return $this->fallbackHttpProblem($exception, $instance, $requestId);
        }

        $definition = $this->definition($problemType);
        $detail = trim($exception->getDescription());

        if ($detail === '' || $definition['status'] >= 500) {
            $detail = $definition['detail'];
        }

        return $this->create(
            $problemType,
            $definition['code'],
            $detail,
            $instance,
            $requestId,
        );
    }

    private function fallbackHttpProblem(
        HttpException $exception,
        string $instance,
        string $requestId,
    ): ProblemDetails {
        $status = $exception->getCode();

        if ($status < 400 || $status > 599) {
            $status = 500;
        }

        if ($status >= 500) {
            $definition = $this->definition(ProblemType::InternalServerError);

            return new ProblemDetails(
                type: $this->typeUri(ProblemType::InternalServerError),
                title: $definition['title'],
                status: $status,
                detail: $definition['detail'],
                instance: $instance,
                requestId: $requestId,
                code: $definition['code'],
            );
        }

        return new ProblemDetails(
            type: 'urn:sova:problem:http-error',
            title: 'Request Failed',
            status: $status,
            detail: 'The request could not be completed.',
            instance: $instance,
            requestId: $requestId,
            code: 'HTTP_ERROR',
        );
    }

    /**
     * @param array<string, list<string>> $fieldErrors
     */
    private function create(
        ProblemType $problemType,
        string $code,
        string $detail,
        string $instance,
        string $requestId,
        array $fieldErrors = [],
    ): ProblemDetails {
        $definition = $this->definition($problemType);

        return new ProblemDetails(
            type: $this->typeUri($problemType),
            title: $definition['title'],
            status: $definition['status'],
            detail: $detail,
            instance: $instance,
            requestId: $requestId,
            code: $code,
            fieldErrors: $fieldErrors,
        );
    }

    private function problemTypeForHttpStatus(int $status): ?ProblemType
    {
        return match ($status) {
            400 => ProblemType::InvalidRequest,
            401 => ProblemType::AuthenticationRequired,
            403 => ProblemType::PermissionDenied,
            404 => ProblemType::ResourceNotFound,
            405 => ProblemType::MethodNotAllowed,
            406 => ProblemType::NotAcceptable,
            409 => ProblemType::Conflict,
            410 => ProblemType::Gone,
            413 => ProblemType::PayloadTooLarge,
            415 => ProblemType::UnsupportedMediaType,
            422 => ProblemType::ValidationFailed,
            429 => ProblemType::RateLimitExceeded,
            500 => ProblemType::InternalServerError,
            503 => ProblemType::ServiceUnavailable,
            default => null,
        };
    }

    /**
     * @return array{status: int, title: string, code: string, detail: string}
     */
    private function definition(ProblemType $problemType): array
    {
        return self::DEFINITIONS[$problemType->value];
    }

    private function typeUri(ProblemType $problemType): string
    {
        return sprintf('urn:sova:problem:%s', $problemType->value);
    }
}

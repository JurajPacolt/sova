<?php

declare(strict_types=1);

namespace Sova\Shared\Domain\Error;

enum ProblemType: string
{
    case InvalidRequest = 'invalid-request';
    case AuthenticationRequired = 'authentication-required';
    case PermissionDenied = 'permission-denied';
    case ResourceNotFound = 'resource-not-found';
    case MethodNotAllowed = 'method-not-allowed';
    case NotAcceptable = 'not-acceptable';
    case Conflict = 'conflict';
    case Gone = 'gone';
    case PayloadTooLarge = 'payload-too-large';
    case UnsupportedMediaType = 'unsupported-media-type';
    case ValidationFailed = 'validation-failed';
    case RateLimitExceeded = 'rate-limit-exceeded';
    case InternalServerError = 'internal-server-error';
    case ServiceUnavailable = 'service-unavailable';
}

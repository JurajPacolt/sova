<?php

declare(strict_types=1);

namespace Sova\Issues\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sova\Issues\Domain\QueryLanguage\FieldCatalog;
use Sova\Issues\Domain\QueryLanguage\QueryLimits;
use Sova\Issues\Presentation\Http\SearchSerializer;
use Sova\Shared\Presentation\Http\JsonResponse;

/**
 * Describes the query language to the editor: the fields this deployment
 * actually supports and the limits currently in force, so the client can guide
 * the user before the server has to reject anything. It exposes no tenant data,
 * only the language surface, but still sits behind the tenant context so an
 * anonymous caller learns nothing.
 */
final readonly class IssueQueryMetadataAction
{
    public function __construct(
        private FieldCatalog $fields,
        private QueryLimits $limits,
        private SearchSerializer $serializer,
    ) {}

    /**
     * @throws JsonException
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        return JsonResponse::write(
            $response,
            $this->serializer->serializeMetadata($this->fields, $this->limits),
        );
    }
}

<?php

declare(strict_types=1);

namespace Sova\Shared\Presentation\Http\Action\Health;

use Doctrine\DBAL\Connection;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Sova\Shared\Infrastructure\Configuration\Settings;
use Sova\Shared\Infrastructure\Http\Middleware\RequestIdMiddleware;
use Sova\Shared\Presentation\Http\JsonResponse;
use Throwable;

final readonly class ReadinessAction
{
    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
        private Settings $settings,
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
        try {
            $this->connection->executeQuery('SELECT 1')->fetchOne();
        } catch (Throwable $exception) {
            $this->logger->warning('Readiness check failed.', [
                'exception' => $exception,
                'request_id' => $request->getAttribute(RequestIdMiddleware::ATTRIBUTE),
            ]);

            return JsonResponse::write($response, [
                'status' => 'not_ready',
                'checks' => ['database' => 'failed'],
            ], 503);
        }

        $enforced = $this->isolationIsEnforced();
        $production = $this->settings->string('app.environment', 'production') === 'production';

        if ($enforced === false) {
            // Row level security is silently inert for a role that bypasses it,
            // and "silently inert" is the worst state a last line of defence can
            // be in. The log says exactly what is wrong; the response does not,
            // because this endpoint answers anyone who can reach it.
            $this->logger->error(
                'The database role bypasses row level security, so the tenant '
                . 'isolation policies do not apply. The application role must be '
                . 'NOSUPERUSER and NOBYPASSRLS (ADR 0010).',
                ['request_id' => $request->getAttribute(RequestIdMiddleware::ATTRIBUTE)],
            );
        }

        // Outside production this is a warning, not a refusal: a development
        // database is routinely owned by a superuser, and a probe that failed
        // there would be switched off rather than fixed.
        if ($enforced === false && $production) {
            return JsonResponse::write($response, [
                'status' => 'not_ready',
                'checks' => ['database' => 'ok', 'tenant_isolation' => 'failed'],
            ], 503);
        }

        return JsonResponse::write($response, [
            'status' => 'ready',
            'checks' => [
                'database' => 'ok',
                'tenant_isolation' => $enforced === true ? 'enforced' : 'unknown',
            ],
        ]);
    }

    /**
     * Whether the connected role is subject to the policies at all.
     *
     * `null` means the question could not be answered — an unreadable catalog is
     * not evidence of a problem, so it is reported as unknown rather than as a
     * failure that would take a healthy deployment out of rotation.
     */
    private function isolationIsEnforced(): ?bool
    {
        try {
            $bypasses = $this->connection->fetchOne(
                <<<'SQL'
                    SELECT rolsuper OR rolbypassrls
                    FROM pg_roles
                    WHERE rolname = current_user
                    SQL,
            );
        } catch (Throwable) {
            return null;
        }

        return is_bool($bypasses) ? !$bypasses : null;
    }
}

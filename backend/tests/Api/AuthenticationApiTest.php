<?php

declare(strict_types=1);

namespace Sova\Tests\Api;

use DateTimeImmutable;
use DI\Container;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Sova\Identity\Application\Authentication\SessionAuthenticator;
use Sova\Identity\Application\Authentication\UserCredentials;
use Sova\Identity\Application\Authentication\UserCredentialsRepository;
use Sova\Identity\Application\EmailVerification\EmailVerificationMailer;
use Sova\Identity\Application\Impersonation\ImpersonationRepository;
use Sova\Identity\Application\Mfa\TotpAuthenticator;
use Sova\Identity\Application\PasswordRecovery\PasswordResetMailer;
use Sova\Identity\Application\Security\OneTimeTokenGenerator;
use Sova\Identity\Application\Security\SessionTokenGenerator;
use Sova\Identity\Application\Token\OneTimeTokenRepository;
use Sova\Identity\Domain\Token\IssuedOneTimeToken;
use Sova\Identity\Infrastructure\Background\IdentityEmailOutboxWorker;
use Sova\Identity\Infrastructure\Http\AuthCookieManager;
use Sova\Identity\Infrastructure\Http\Middleware\SessionAuthenticationMiddleware;
use Sova\Identity\Infrastructure\Persistence\DoctrineUserSessionRepository;
use Sova\Identity\Infrastructure\Security\Argon2idPasswordHasher;
use Sova\Shared\Application\Security\SensitivePayloadCipher;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Infrastructure\Bootstrap\ApplicationFactory;
use Sova\Shared\Infrastructure\Configuration\Settings;

final class AuthenticationApiTest extends TestCase
{
    /**
     * @var App<Container>
     */
    private App $app;
    private Connection $connection;
    private string $userId;

    protected function setUp(): void
    {
        if (getenv('RUN_DATABASE_TESTS') !== 'true') {
            self::markTestSkipped(
                'Set RUN_DATABASE_TESTS=true and migrate PostgreSQL before database tests.',
            );
        }

        /** @var App<Container> $app */
        $app = ApplicationFactory::create(dirname(__DIR__, 2));
        $connection = $app->getContainer()->get(Connection::class);

        if (!$connection instanceof Connection) {
            self::fail('The container must provide a Doctrine DBAL connection.');
        }

        $this->app = $app;
        $this->connection = $connection;
        $this->connection->beginTransaction();
        $this->userId = (string) UuidV7::generate();
        $hasher = new Argon2idPasswordHasher();

        $this->connection->insert('users', [
            'id' => $this->userId,
            'email' => 'member@example.test',
            'normalized_email' => 'member@example.test',
            'password_hash' => $hasher->hash('correct horse battery staple'),
            'display_name' => 'Test Member',
            'preferred_locale' => 'sk',
            'status' => 'ACTIVE',
        ]);
    }

    protected function tearDown(): void
    {
        if (
            isset($this->connection)
            && $this->connection->isTransactionActive()
        ) {
            $this->connection->rollBack();
        }
    }

    public function testUnknownEmailAndWrongPasswordUseTheSameProblem(): void
    {
        $unknown = $this->login('unknown@example.test', 'wrong password');
        $wrongPassword = $this->login('member@example.test', 'wrong password');

        self::assertSame(401, $unknown->getStatusCode());
        self::assertSame(401, $wrongPassword->getStatusCode());

        $unknownPayload = $this->decode($unknown);
        $wrongPasswordPayload = $this->decode($wrongPassword);

        self::assertSame('INVALID_CREDENTIALS', $unknownPayload['code']);
        self::assertSame($unknownPayload['code'], $wrongPasswordPayload['code']);
        self::assertSame($unknownPayload['detail'], $wrongPasswordPayload['detail']);
    }

    public function testLoginCreatesHashedSessionAndListsItAsCurrent(): void
    {
        $login = $this->login(
            'member@example.test',
            'correct horse battery staple',
        );

        self::assertSame(200, $login->getStatusCode());
        $sessionToken = $this->cookieValue($login, 'sova_session');
        $csrfToken = $this->cookieValue($login, 'sova_csrf');
        $cookieHeaders = implode("\n", $login->getHeader('Set-Cookie'));
        $loginPayload = $this->decode($login);
        $session = $loginPayload['session'] ?? null;
        $user = $loginPayload['user'] ?? null;

        self::assertIsArray($session);
        self::assertIsArray($user);
        self::assertFalse($user['is_superadmin'] ?? true);
        $sessionId = $session['id'] ?? null;
        self::assertIsString($sessionId);
        self::assertNotSame($sessionToken, $csrfToken);
        self::assertStringContainsString('sova_session=', $cookieHeaders);
        self::assertStringContainsString('HttpOnly', $cookieHeaders);
        self::assertStringContainsString('SameSite=Lax', $cookieHeaders);

        $storedHash = $this->connection->fetchOne(
            'SELECT token_hash FROM user_sessions WHERE id = :id',
            ['id' => $sessionId],
        );

        self::assertIsString($storedHash);
        self::assertNotSame($sessionToken, $storedHash);
        self::assertSame(hash('sha256', $sessionToken), $storedHash);

        $currentResponse = $this->app->handle(
            $this->request('GET', '/api/v1/auth/session')
                ->withCookieParams(['sova_session' => $sessionToken]),
        );
        $currentUser = $this->decode($currentResponse)['user'] ?? null;
        self::assertSame(200, $currentResponse->getStatusCode());
        self::assertIsArray($currentUser);
        self::assertSame($this->userId, $currentUser['id'] ?? null);
        self::assertFalse($currentUser['is_superadmin'] ?? true);

        $request = $this->request('GET', '/api/v1/auth/sessions')
            ->withCookieParams(['sova_session' => $sessionToken]);
        $response = $this->app->handle($request);
        $payload = $this->decode($response);
        $sessions = $payload['sessions'] ?? null;

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($sessions);
        self::assertCount(1, $sessions);
        $firstSession = $sessions[0] ?? null;
        self::assertIsArray($firstSession);
        self::assertTrue($firstSession['current'] ?? false);
    }

    public function testLoginAndCurrentSessionExposeSuperadminContext(): void
    {
        $this->connection->insert('user_system_roles', [
            'user_id' => $this->userId,
            'role_code' => 'SUPERADMIN',
        ]);
        $login = $this->login(
            'member@example.test',
            'correct horse battery staple',
        );
        $user = $this->decode($login)['user'] ?? null;

        self::assertSame(200, $login->getStatusCode());
        self::assertIsArray($user);
        self::assertTrue($user['is_superadmin'] ?? false);

        $current = $this->app->handle(
            $this->request('GET', '/api/v1/auth/session')
                ->withCookieParams([
                    'sova_session' => $this->cookieValue(
                        $login,
                        'sova_session',
                    ),
                ]),
        );
        $currentUser = $this->decode($current)['user'] ?? null;

        self::assertSame(200, $current->getStatusCode());
        self::assertIsArray($currentUser);
        self::assertTrue($currentUser['is_superadmin'] ?? false);

        $this->connection->delete('user_system_roles', [
            'user_id' => $this->userId,
            'role_code' => 'SUPERADMIN',
        ]);
        $afterRevocation = $this->app->handle(
            $this->request('GET', '/api/v1/auth/session')
                ->withCookieParams([
                    'sova_session' => $this->cookieValue(
                        $login,
                        'sova_session',
                    ),
                ]),
        );
        $userAfterRevocation = $this->decode($afterRevocation)['user'] ?? null;

        self::assertSame(200, $afterRevocation->getStatusCode());
        self::assertIsArray($userAfterRevocation);
        self::assertFalse($userAfterRevocation['is_superadmin'] ?? true);
    }

    public function testMfaEnrollmentLoginChallengeAndSingleUseCodes(): void
    {
        $olderLogin = $this->login(
            'member@example.test',
            'correct horse battery staple',
        );
        $login = $this->login(
            'member@example.test',
            'correct horse battery staple',
        );
        $sessionToken = $this->cookieValue($login, 'sova_session');
        $csrfToken = $this->cookieValue($login, 'sova_csrf');
        $cookies = ['sova_session' => $sessionToken];
        $begin = $this->app->handle(
            $this->request('POST', '/api/v1/auth/mfa/enrollment')
                ->withCookieParams($cookies)
                ->withHeader('X-CSRF-Token', $csrfToken)
                ->withParsedBody([
                    'current_password' => 'correct horse battery staple',
                ]),
        );
        $beginPayload = $this->decode($begin);
        $secret = $beginPayload['secret'] ?? null;
        $otpauthUri = $beginPayload['otpauth_uri'] ?? null;

        self::assertSame(200, $begin->getStatusCode());
        self::assertSame('no-store', $begin->getHeaderLine('Cache-Control'));
        self::assertIsString($secret);
        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/D', $secret);
        self::assertIsString($otpauthUri);
        self::assertStringStartsWith(
            'otpauth://totp/',
            $otpauthUri,
        );

        $ciphertext = $this->connection->fetchOne(
            <<<'SQL'
                SELECT encrypted_secret
                FROM user_mfa_credentials
                WHERE user_id = :user_id
                SQL,
            ['user_id' => $this->userId],
        );
        self::assertIsString($ciphertext);
        self::assertStringNotContainsString($secret, $ciphertext);

        $totp = new TotpAuthenticator();
        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $confirm = $this->app->handle(
            $this->request(
                'POST',
                '/api/v1/auth/mfa/enrollment/confirm',
            )
                ->withCookieParams($cookies)
                ->withHeader('X-CSRF-Token', $csrfToken)
                ->withParsedBody([
                    'code' => $totp->codeAt($secret, $now),
                ]),
        );
        $confirmation = $this->decode($confirm);
        $recoveryCodes = $confirmation['recovery_codes'] ?? null;
        $confirmationMfa = $confirmation['mfa'] ?? null;

        self::assertSame(200, $confirm->getStatusCode());
        self::assertSame('no-store', $confirm->getHeaderLine('Cache-Control'));
        self::assertIsArray($recoveryCodes);
        self::assertCount(10, $recoveryCodes);
        self::assertIsArray($confirmationMfa);
        self::assertSame(
            10,
            $confirmationMfa['recovery_codes_remaining'] ?? null,
        );
        self::assertNotEmpty($this->connection->fetchOne(
            <<<'SQL'
                SELECT mfa_verified_at
                FROM user_sessions
                WHERE token_hash = :token_hash
                SQL,
            ['token_hash' => hash('sha256', $sessionToken)],
        ));
        self::assertNotEmpty($this->connection->fetchOne(
            <<<'SQL'
                SELECT revoked_at
                FROM user_sessions
                WHERE token_hash = :token_hash
                SQL,
            [
                'token_hash' => hash(
                    'sha256',
                    $this->cookieValue($olderLogin, 'sova_session'),
                ),
            ],
        ));

        $withoutCode = $this->login(
            'member@example.test',
            'correct horse battery staple',
        );
        self::assertSame(401, $withoutCode->getStatusCode());
        self::assertSame(
            'MFA_CODE_REQUIRED',
            $this->decode($withoutCode)['code'] ?? null,
        );
        self::assertSame([], $withoutCode->getHeader('Set-Cookie'));

        $invalid = $this->login(
            'member@example.test',
            'correct horse battery staple',
            '000000',
        );
        self::assertSame(401, $invalid->getStatusCode());
        self::assertSame(
            'MFA_CODE_INVALID',
            $this->decode($invalid)['code'] ?? null,
        );

        $futureCode = $totp->codeAt($secret, $now->modify('+30 seconds'));
        $verified = $this->login(
            'member@example.test',
            'correct horse battery staple',
            $futureCode,
        );
        self::assertSame(200, $verified->getStatusCode());
        $verifiedMfa = $this->decode($verified)['mfa'] ?? null;
        self::assertIsArray($verifiedMfa);
        self::assertTrue($verifiedMfa['verified'] ?? false);

        $replayed = $this->login(
            'member@example.test',
            'correct horse battery staple',
            $futureCode,
        );
        self::assertSame(401, $replayed->getStatusCode());
        self::assertSame(
            'MFA_CODE_INVALID',
            $this->decode($replayed)['code'] ?? null,
        );

        $recoveryCode = $recoveryCodes[0] ?? null;
        self::assertIsString($recoveryCode);
        $recovered = $this->login(
            'member@example.test',
            'correct horse battery staple',
            strtolower(str_replace('-', ' ', $recoveryCode)),
        );
        self::assertSame(200, $recovered->getStatusCode());
        $recoveredMfa = $this->decode($recovered)['mfa'] ?? null;
        self::assertIsArray($recoveredMfa);
        self::assertSame(
            9,
            $recoveredMfa['recovery_codes_remaining'] ?? null,
        );
        self::assertSame(
            401,
            $this->login(
                'member@example.test',
                'correct horse battery staple',
                $recoveryCode,
            )->getStatusCode(),
        );
        self::assertSame(
            1,
            $this->connection->fetchOne(
                <<<'SQL'
                    SELECT COUNT(*)
                    FROM security_audit_events
                    WHERE actor_user_id = :user_id
                        AND event_type = 'MFA_RECOVERY_CODE_USED'
                    SQL,
                ['user_id' => $this->userId],
            ),
        );
    }

    public function testProductionSessionWithholdsUnenrolledSuperadminRole(): void
    {
        $this->connection->insert('user_system_roles', [
            'user_id' => $this->userId,
            'role_code' => 'SUPERADMIN',
        ]);
        $login = $this->login(
            'member@example.test',
            'correct horse battery staple',
        );
        $sessionToken = $this->cookieValue($login, 'sova_session');
        $impersonations = $this->app
            ->getContainer()
            ->get(ImpersonationRepository::class);

        if (!$impersonations instanceof ImpersonationRepository) {
            self::fail(
                'The container must provide an impersonation repository.',
            );
        }

        $repository = new DoctrineUserSessionRepository(
            connection: $this->connection,
            impersonations: $impersonations,
            settings: new Settings([
                'app' => ['environment' => 'production'],
            ]),
        );
        $session = $repository->findActiveByTokenHash(
            hash('sha256', $sessionToken),
        );

        self::assertNotNull($session);
        self::assertTrue($session->actorHasSuperadminRole);
        self::assertFalse($session->actorIsSuperadmin);
        self::assertFalse($session->isSuperadmin);
        self::assertTrue($session->mfaEnrollmentRequired);

        $cookieSettings = new Settings([
            'auth' => [
                'session_cookie_name' => 'sova_session',
                'csrf_cookie_name' => 'sova_csrf',
                'cookie_secure' => false,
                'cookie_same_site' => 'Lax',
            ],
        ]);
        $middleware = new SessionAuthenticationMiddleware(
            authenticator: new SessionAuthenticator(
                $repository,
                new SessionTokenGenerator(),
            ),
            cookies: new AuthCookieManager($cookieSettings),
        );
        $handler = new class implements RequestHandlerInterface {
            public function handle(
                ServerRequestInterface $request,
            ): ResponseInterface {
                return new Response(204);
            }
        };

        try {
            $middleware->process(
                $this->request('GET', '/api/v1/tenants')
                    ->withCookieParams(['sova_session' => $sessionToken]),
                $handler,
            );
            self::fail(
                'A production MFA enrollment session must not access tenants.',
            );
        } catch (DomainProblemException $exception) {
            self::assertSame(
                'MFA_ENROLLMENT_REQUIRED',
                $exception->problemCode(),
            );
        }

        self::assertSame(
            204,
            $middleware->process(
                $this->request('GET', '/api/v1/auth/mfa')
                    ->withCookieParams(['sova_session' => $sessionToken]),
                $handler,
            )->getStatusCode(),
        );
    }

    public function testLogoutRequiresCsrfAndRevokesTheSession(): void
    {
        $login = $this->login(
            'member@example.test',
            'correct horse battery staple',
        );
        $sessionToken = $this->cookieValue($login, 'sova_session');
        $csrfToken = $this->cookieValue($login, 'sova_csrf');
        $withoutCsrf = $this->request('POST', '/api/v1/auth/logout')
            ->withCookieParams(['sova_session' => $sessionToken]);

        $rejected = $this->app->handle($withoutCsrf);

        self::assertSame(403, $rejected->getStatusCode());
        self::assertSame('CSRF_TOKEN_INVALID', $this->decode($rejected)['code']);

        $withCsrf = $withoutCsrf->withHeader('X-CSRF-Token', $csrfToken);
        $logout = $this->app->handle($withCsrf);

        self::assertSame(204, $logout->getStatusCode());
        self::assertStringContainsString(
            'Max-Age=0',
            implode("\n", $logout->getHeader('Set-Cookie')),
        );

        $revokedAt = $this->connection->fetchOne(
            'SELECT revoked_at FROM user_sessions WHERE user_id = :user_id',
            ['user_id' => $this->userId],
        );
        self::assertIsString($revokedAt);
    }

    public function testCurrentSessionCanRevokeAnotherOwnedSession(): void
    {
        $currentLogin = $this->login(
            'member@example.test',
            'correct horse battery staple',
        );
        $otherLogin = $this->login(
            'member@example.test',
            'correct horse battery staple',
        );
        $currentToken = $this->cookieValue($currentLogin, 'sova_session');
        $currentCsrf = $this->cookieValue($currentLogin, 'sova_csrf');
        $otherPayload = $this->decode($otherLogin);
        $otherSession = $otherPayload['session'] ?? null;
        self::assertIsArray($otherSession);
        $otherSessionId = $otherSession['id'] ?? null;
        self::assertIsString($otherSessionId);

        $request = $this->request(
            'DELETE',
            sprintf('/api/v1/auth/sessions/%s', $otherSessionId),
        )
            ->withCookieParams(['sova_session' => $currentToken])
            ->withHeader('X-CSRF-Token', $currentCsrf);
        $response = $this->app->handle($request);

        self::assertSame(204, $response->getStatusCode());
        self::assertNotEmpty($this->connection->fetchOne(
            'SELECT revoked_at FROM user_sessions WHERE id = :id',
            ['id' => $otherSessionId],
        ));
    }

    public function testForgotPasswordUsesTheSameResponseAndEncryptedQueueForAnyEmail(): void
    {
        $known = $this->forgotPassword('member@example.test');
        $unknown = $this->forgotPassword('unknown@example.test');

        self::assertSame(202, $known->getStatusCode());
        self::assertSame(202, $unknown->getStatusCode());
        self::assertSame($this->decode($known), $this->decode($unknown));
        self::assertSame(
            2,
            $this->connection->fetchOne(
                <<<'SQL'
                    SELECT COUNT(*)
                    FROM outbox_events
                    WHERE event_name = 'IDENTITY_PASSWORD_RESET_REQUESTED'
                    SQL,
            ),
        );

        $ciphertexts = $this->connection->fetchFirstColumn(
            'SELECT ciphertext FROM outbox_sensitive_payloads',
        );
        self::assertCount(2, $ciphertexts);

        foreach ($ciphertexts as $ciphertext) {
            self::assertIsString($ciphertext);
            self::assertStringNotContainsString('example.test', $ciphertext);
        }

        $mailer = new CapturingPasswordResetMailer();
        $worker = $this->identityEmailWorker($mailer);

        self::assertSame(2, $worker->runBatch());
        self::assertCount(1, $mailer->messages);
        self::assertSame(
            'member@example.test',
            $mailer->messages[0]['email'],
        );
    }

    public function testPasswordResetRollsBackPolicyFailureThenRevokesEverySession(): void
    {
        $firstLogin = $this->login(
            'member@example.test',
            'correct horse battery staple',
        );
        $secondLogin = $this->login(
            'member@example.test',
            'correct horse battery staple',
        );
        self::assertSame(200, $firstLogin->getStatusCode());
        self::assertSame(200, $secondLogin->getStatusCode());
        self::assertSame(202, $this->forgotPassword(
            'member@example.test',
        )->getStatusCode());

        $mailer = new CapturingPasswordResetMailer();
        self::assertSame(1, $this->identityEmailWorker($mailer)->runBatch());
        self::assertCount(1, $mailer->messages);
        $token = $mailer->messages[0]['token'];

        $weakPassword = $this->resetPassword(
            $token,
            'passwordpassword',
            'passwordpassword',
        );
        self::assertSame(422, $weakPassword->getStatusCode());
        self::assertSame(
            'PASSWORD_POLICY_VIOLATION',
            $this->decode($weakPassword)['code'],
        );
        self::assertNull($this->connection->fetchOne(
            <<<'SQL'
                SELECT used_at
                FROM user_action_tokens
                WHERE user_id = :user_id
                    AND purpose = 'PASSWORD_RESET'
                SQL,
            ['user_id' => $this->userId],
        ));

        $newPassword = 'a unique SOVA passphrase for 2026';
        $reset = $this->resetPassword(
            $token,
            $newPassword,
            $newPassword,
        );

        self::assertSame(204, $reset->getStatusCode());
        self::assertSame(
            0,
            $this->connection->fetchOne(
                <<<'SQL'
                    SELECT COUNT(*)
                    FROM user_sessions
                    WHERE user_id = :user_id
                        AND revoked_at IS NULL
                    SQL,
                ['user_id' => $this->userId],
            ),
        );
        self::assertSame(
            2,
            $this->connection->fetchOne(
                <<<'SQL'
                    SELECT COUNT(*)
                    FROM user_sessions
                    WHERE user_id = :user_id
                        AND revocation_reason = 'PASSWORD_RESET'
                    SQL,
                ['user_id' => $this->userId],
            ),
        );

        $storedHash = $this->connection->fetchOne(
            'SELECT password_hash FROM users WHERE id = :user_id',
            ['user_id' => $this->userId],
        );
        self::assertIsString($storedHash);
        self::assertTrue((new Argon2idPasswordHasher())->verify(
            $newPassword,
            $storedHash,
        ));
        self::assertSame(
            401,
            $this->login(
                'member@example.test',
                'correct horse battery staple',
            )->getStatusCode(),
        );
        self::assertSame(
            200,
            $this->login('member@example.test', $newPassword)->getStatusCode(),
        );
        self::assertSame(
            410,
            $this->resetPassword($token, $newPassword, $newPassword)
                ->getStatusCode(),
        );
        self::assertSame(
            1,
            $this->connection->fetchOne(
                <<<'SQL'
                    SELECT COUNT(*)
                    FROM authentication_events
                    WHERE user_id = :user_id
                        AND event_type = 'PASSWORD_RESET_COMPLETED'
                    SQL,
                ['user_id' => $this->userId],
            ),
        );
    }

    public function testEmailVerificationRequestIsPrivateAndTokenIsIdempotent(): void
    {
        $pendingUserId = (string) UuidV7::generate();
        $hasher = new Argon2idPasswordHasher();
        $this->connection->insert('users', [
            'id' => $pendingUserId,
            'email' => 'pending@example.test',
            'normalized_email' => 'pending@example.test',
            'password_hash' => $hasher->hash('another correct horse battery staple'),
            'display_name' => 'Pending Member',
            'preferred_locale' => 'sk',
            'status' => 'PENDING_VERIFICATION',
        ]);

        $known = $this->requestEmailVerification('pending@example.test');
        $unknown = $this->requestEmailVerification('unknown@example.test');

        self::assertSame(202, $known->getStatusCode());
        self::assertSame(202, $unknown->getStatusCode());
        self::assertSame($this->decode($known), $this->decode($unknown));

        $passwordMailer = new CapturingPasswordResetMailer();
        $verificationMailer = new CapturingEmailVerificationMailer();
        $worker = $this->identityEmailWorker(
            $passwordMailer,
            $verificationMailer,
        );

        self::assertSame(2, $worker->runBatch());
        self::assertSame([], $passwordMailer->messages);
        self::assertCount(1, $verificationMailer->messages);
        self::assertSame(
            'pending@example.test',
            $verificationMailer->messages[0]['email'],
        );
        $token = $verificationMailer->messages[0]['token'];

        $verified = $this->verifyEmail($token);

        self::assertSame(200, $verified->getStatusCode());
        self::assertSame('VERIFIED', $this->decode($verified)['status']);
        self::assertSame(
            'ACTIVE',
            $this->connection->fetchOne(
                'SELECT status FROM users WHERE id = :user_id',
                ['user_id' => $pendingUserId],
            ),
        );
        self::assertNotEmpty($this->connection->fetchOne(
            'SELECT email_verified_at FROM users WHERE id = :user_id',
            ['user_id' => $pendingUserId],
        ));

        $reused = $this->verifyEmail($token);

        self::assertSame(200, $reused->getStatusCode());
        self::assertSame(
            'ALREADY_VERIFIED',
            $this->decode($reused)['status'],
        );
        self::assertSame(
            1,
            $this->connection->fetchOne(
                <<<'SQL'
                    SELECT COUNT(*)
                    FROM authentication_events
                    WHERE user_id = :user_id
                        AND event_type = 'EMAIL_VERIFIED'
                    SQL,
                ['user_id' => $pendingUserId],
            ),
        );
    }

    private function login(
        string $email,
        string $password,
        ?string $mfaCode = null,
    ): ResponseInterface {
        $payload = [
            'email' => $email,
            'password' => $password,
        ];

        if ($mfaCode !== null) {
            $payload['mfa_code'] = $mfaCode;
        }

        return $this->app->handle(
            $this->request('POST', '/api/v1/auth/login')
                ->withParsedBody($payload),
        );
    }

    private function forgotPassword(string $email): ResponseInterface
    {
        return $this->app->handle(
            $this->request('POST', '/api/v1/auth/password/forgot')
                ->withParsedBody(['email' => $email]),
        );
    }

    private function resetPassword(
        string $token,
        string $password,
        string $confirmation,
    ): ResponseInterface {
        return $this->app->handle(
            $this->request('POST', '/api/v1/auth/password/reset')
                ->withParsedBody([
                    'token' => $token,
                    'password' => $password,
                    'password_confirmation' => $confirmation,
                ]),
        );
    }

    private function requestEmailVerification(string $email): ResponseInterface
    {
        return $this->app->handle(
            $this->request('POST', '/api/v1/auth/email/verification/request')
                ->withParsedBody(['email' => $email]),
        );
    }

    private function verifyEmail(string $token): ResponseInterface
    {
        return $this->app->handle(
            $this->request('POST', '/api/v1/auth/email/verify')
                ->withParsedBody(['token' => $token]),
        );
    }

    private function identityEmailWorker(
        PasswordResetMailer $passwordResetMailer,
        ?EmailVerificationMailer $emailVerificationMailer = null,
    ): IdentityEmailOutboxWorker {
        $container = $this->app->getContainer();
        $cipher = $container->get(SensitivePayloadCipher::class);
        $users = $container->get(UserCredentialsRepository::class);
        $tokens = $container->get(OneTimeTokenRepository::class);
        $settings = $container->get(Settings::class);

        if (!$cipher instanceof SensitivePayloadCipher) {
            self::fail('The container must provide a sensitive payload cipher.');
        }

        if (!$users instanceof UserCredentialsRepository) {
            self::fail('The container must provide a user credentials repository.');
        }

        if (!$tokens instanceof OneTimeTokenRepository) {
            self::fail('The container must provide a one-time token repository.');
        }

        if (!$settings instanceof Settings) {
            self::fail('The container must provide application settings.');
        }

        return new IdentityEmailOutboxWorker(
            connection: $this->connection,
            cipher: $cipher,
            users: $users,
            tokens: $tokens,
            tokenGenerator: new OneTimeTokenGenerator(),
            passwordResetMailer: $passwordResetMailer,
            emailVerificationMailer: $emailVerificationMailer
                ?? new CapturingEmailVerificationMailer(),
            settings: $settings,
        );
    }

    private function request(string $method, string $uri): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest(
            $method,
            $uri,
            ['REMOTE_ADDR' => '203.0.113.10'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $decoded = json_decode(
            $response->getBody()->__toString(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        if (!is_array($decoded)) {
            self::fail('Expected a JSON object response.');
        }

        $payload = [];

        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                self::fail('Expected JSON object keys to be strings.');
            }

            $payload[$key] = $value;
        }

        return $payload;
    }

    private function cookieValue(
        ResponseInterface $response,
        string $cookieName,
    ): string {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            if (!str_starts_with($header, sprintf('%s=', $cookieName))) {
                continue;
            }

            $pair = explode(';', $header, 2)[0];
            $value = substr($pair, strlen($cookieName) + 1);

            return rawurldecode($value);
        }

        self::fail(sprintf('Cookie "%s" was not set.', $cookieName));
    }
}

final class CapturingPasswordResetMailer implements PasswordResetMailer
{
    /**
     * @var list<array{email: string, token: string, expires_at: DateTimeImmutable}>
     */
    public array $messages = [];

    public function send(
        UserCredentials $user,
        IssuedOneTimeToken $token,
        DateTimeImmutable $expiresAt,
    ): void {
        $this->messages[] = [
            'email' => $user->email,
            'token' => $token->plainText(),
            'expires_at' => $expiresAt,
        ];
    }
}

final class CapturingEmailVerificationMailer implements EmailVerificationMailer
{
    /**
     * @var list<array{email: string, token: string, expires_at: DateTimeImmutable}>
     */
    public array $messages = [];

    public function send(
        UserCredentials $user,
        IssuedOneTimeToken $token,
        DateTimeImmutable $expiresAt,
    ): void {
        $this->messages[] = [
            'email' => $user->email,
            'token' => $token->plainText(),
            'expires_at' => $expiresAt,
        ];
    }
}

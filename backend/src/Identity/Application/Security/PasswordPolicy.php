<?php

declare(strict_types=1);

namespace Sova\Identity\Application\Security;

use SensitiveParameter;
use Sova\Identity\Application\Authentication\UserCredentials;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;

final readonly class PasswordPolicy
{
    private const MINIMUM_CHARACTERS = 15;
    private const MAXIMUM_BYTES = 1024;

    /**
     * @var list<string>
     */
    private const BLOCKLIST = [
        'correct horse battery staple',
        'iloveyouiloveyou',
        'letmeinletmein',
        'passwordpassword',
        'password123456',
        'qwertyuiopasdfgh',
        'sovasovasovasova',
        'trustno1trustno1',
        'welcome123456789',
        'zaq12wsxzaq12wsx',
    ];

    public function assertAcceptable(
        #[SensitiveParameter]
        string $password,
        UserCredentials $user,
    ): void {
        $errors = [];

        if (
            !mb_check_encoding($password, 'UTF-8')
            || mb_strlen($password, 'UTF-8') < self::MINIMUM_CHARACTERS
        ) {
            $errors[] = sprintf(
                'Use at least %d characters.',
                self::MINIMUM_CHARACTERS,
            );
        }

        if (strlen($password) > self::MAXIMUM_BYTES) {
            $errors[] = sprintf(
                'Use at most %d bytes.',
                self::MAXIMUM_BYTES,
            );
        }

        $normalizedPassword = mb_strtolower($password, 'UTF-8');
        $emailLocalPart = explode('@', $user->email, 2)[0];
        $contextValues = [
            'sova',
            mb_strtolower($emailLocalPart, 'UTF-8'),
            mb_strtolower($user->displayName, 'UTF-8'),
        ];

        if (
            in_array($normalizedPassword, self::BLOCKLIST, true)
            || in_array($normalizedPassword, $contextValues, true)
        ) {
            $errors[] = 'Choose a password that is not common or based on account details.';
        }

        if ($errors !== []) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'PASSWORD_POLICY_VIOLATION',
                'The new password does not meet the password policy.',
                ['password' => $errors],
            );
        }
    }
}

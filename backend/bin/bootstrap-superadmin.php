<?php

declare(strict_types=1);

use Sova\Identity\Infrastructure\Bootstrap\InitialSuperadminBootstrapper;
use Sova\Shared\Infrastructure\Bootstrap\ApplicationFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', ['email:', 'display-name:', 'locale::']);
$email = $options['email'] ?? null;
$displayName = $options['display-name'] ?? null;
$locale = $options['locale'] ?? 'sk';

if (
    !is_string($email)
    || !is_string($displayName)
    || !is_string($locale)
) {
    fwrite(
        STDERR,
        "Usage: php bin/bootstrap-superadmin.php"
        . " --email=EMAIL --display-name=NAME [--locale=sk]\n"
        . "The password must be supplied as the only line on standard input.\n",
    );
    exit(64);
}

$passwordInput = stream_get_contents(STDIN, 2049);

if ($passwordInput === false || $passwordInput === '') {
    fwrite(STDERR, "A password is required on standard input.\n");
    exit(64);
}

$password = rtrim($passwordInput, "\r\n");
unset($passwordInput);

try {
    $app = ApplicationFactory::create(dirname(__DIR__));
    $bootstrapper = $app->getContainer()->get(InitialSuperadminBootstrapper::class);

    if (!$bootstrapper instanceof InitialSuperadminBootstrapper) {
        throw new RuntimeException(
            'The container must provide the initial superadmin bootstrapper.',
        );
    }

    $userId = $bootstrapper->bootstrap(
        $email,
        $displayName,
        $locale,
        $password,
    );
    unset($password);

    fwrite(
        STDOUT,
        sprintf("Initial SUPERADMIN created: %s (%s)\n", $email, $userId),
    );
} catch (Throwable $exception) {
    unset($password);
    fwrite(STDERR, sprintf("Bootstrap failed: %s\n", $exception->getMessage()));
    exit(1);
}

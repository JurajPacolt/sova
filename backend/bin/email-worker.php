<?php

declare(strict_types=1);

use Sova\Identity\Infrastructure\Background\IdentityEmailOutboxWorker;
use Sova\Shared\Infrastructure\Bootstrap\ApplicationFactory;
use Sova\Tenancy\Infrastructure\Background\InvitationOutboxWorker;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = ApplicationFactory::create(dirname(__DIR__));
$identityWorker = $app->getContainer()->get(IdentityEmailOutboxWorker::class);
$invitationWorker = $app->getContainer()->get(InvitationOutboxWorker::class);

if (!$identityWorker instanceof IdentityEmailOutboxWorker) {
    throw new RuntimeException('The container must provide the identity email worker.');
}

if (!$invitationWorker instanceof InvitationOutboxWorker) {
    throw new RuntimeException('The container must provide the invitation email worker.');
}

$runOnce = in_array('--once', $argv, true);

do {
    $processed = $identityWorker->runBatch();
    $processed += $invitationWorker->runBatch();

    if ($runOnce) {
        break;
    }

    if ($processed === 0) {
        usleep(1_000_000);
    }
} while (true);

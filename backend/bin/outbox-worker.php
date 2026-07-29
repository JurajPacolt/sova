<?php

declare(strict_types=1);

use Sova\Shared\Infrastructure\Bootstrap\ApplicationFactory;
use Sova\Shared\Infrastructure\Outbox\OutboxDispatcher;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Drains the transactional outbox for every registered handler. It is separate
 * from the email worker on purpose: those events carry encrypted single-use
 * payloads with their own expiry and purge rules, while these are ordinary
 * domain events.
 *
 * Several copies may run at once — the dispatcher claims rows with
 * `FOR UPDATE … SKIP LOCKED`, so they never collide.
 */
$app = ApplicationFactory::create(dirname(__DIR__));
$dispatcher = $app->getContainer()->get(OutboxDispatcher::class);

if (!$dispatcher instanceof OutboxDispatcher) {
    throw new RuntimeException('The container must provide the outbox dispatcher.');
}

$runOnce = in_array('--once', $argv, true);

do {
    $processed = $dispatcher->runBatch();

    if ($runOnce) {
        break;
    }

    if ($processed === 0) {
        usleep(1_000_000);
    }
} while (true);

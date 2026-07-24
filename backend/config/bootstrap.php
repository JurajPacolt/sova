<?php

declare(strict_types=1);

use Sova\Shared\Infrastructure\Bootstrap\ApplicationFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

return ApplicationFactory::create(dirname(__DIR__));

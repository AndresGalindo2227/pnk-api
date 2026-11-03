<?php

declare(strict_types=1);

/**
 * Front controller of pnk-api. Every request enters here.
 * Run locally with the PHP built-in server:  php -S 0.0.0.0:8080 -t public
 */

use Pnk\App;

require __DIR__ . '/../src/bootstrap.php';

(new App())->run();

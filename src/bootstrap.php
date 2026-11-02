<?php

declare(strict_types=1);

// PSR-4 style autoloader for the Pnk\ namespace (no Composer needed on the PANENKA hosting).
spl_autoload_register(static function (string $class): void {
    $prefix = 'Pnk\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

date_default_timezone_set('America/Bogota');

<?php

declare(strict_types=1);

namespace Pnk;

use PDO;

/** Single PDO connection to the InvFox database (utf8mb4, exceptions, real prepared statements). */
final class Database
{
    private static ?PDO $pdo = null;

    public static function connect(array $config): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = new PDO($config['dsn'], $config['user'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            self::$pdo->exec("SET time_zone = '-05:00'");
        }
        return self::$pdo;
    }
}

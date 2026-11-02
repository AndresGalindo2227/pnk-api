<?php

declare(strict_types=1);

namespace Pnk\Controllers;

use DateTimeImmutable;
use PDO;
use Pnk\Http\HttpException;
use Pnk\Http\Request;
use Pnk\Security\Jwt;

/** Shared helpers: authenticated player, business settings and ISO 8601 dates. */
abstract class Controller
{
    public function __construct(
        protected readonly PDO $db,
        protected readonly Jwt $jwt,
        protected readonly array $config,
    ) {
    }

    /** Player id from "Authorization: Bearer <jwt>" or 401. */
    protected function playerId(Request $request): int
    {
        $header = $request->header('Authorization') ?? '';
        if (!preg_match('/^Bearer\s+(\S+)$/', $header, $m)) {
            throw HttpException::unauthorized();
        }
        return (int) $this->jwt->decode($m[1])['sub'];
    }

    /** The single row of business_settings (opening hours, deposit and cancellation rules). */
    protected function settings(): array
    {
        $row = $this->db->query('SELECT * FROM business_settings WHERE id = 1')->fetch();
        if (!$row) {
            throw new HttpException(500, 'INTERNAL_ERROR', 'Business settings are missing (run migration 005).');
        }
        return $row;
    }

    protected static function iso(string|DateTimeImmutable $value): string
    {
        $date = $value instanceof DateTimeImmutable ? $value : new DateTimeImmutable($value);
        return $date->format('Y-m-d\TH:i:sP');
    }

    protected static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }
}

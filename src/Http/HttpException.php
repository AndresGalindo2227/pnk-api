<?php

declare(strict_types=1);

namespace Pnk\Http;

use RuntimeException;

/** An error that becomes an HTTP response with the standard Error body { code, message, details? }. */
final class HttpException extends RuntimeException
{
    /** @param list<array{field: string, issue: string}> $details */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $what = 'Resource'): self
    {
        return new self(404, 'NOT_FOUND', "$what not found.");
    }

    public static function unauthorized(string $code = 'UNAUTHORIZED', string $message = 'Your session has expired.'): self
    {
        return new self(401, $code, $message);
    }

    /** @param list<array{field: string, issue: string}> $details */
    public static function validation(array $details): self
    {
        return new self(422, 'VALIDATION_ERROR', 'Some fields are invalid.', $details);
    }

    public static function conflict(string $code, string $message): self
    {
        return new self(409, $code, $message);
    }
}

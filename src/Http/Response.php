<?php

declare(strict_types=1);

namespace Pnk\Http;

/** JSON response. */
final class Response
{
    public function __construct(
        public readonly int $status,
        public readonly mixed $body = null,
        public readonly array $headers = [],
    ) {
    }

    public static function json(mixed $body, int $status = 200, array $headers = []): self
    {
        return new self($status, $body, $headers);
    }

    public static function noContent(): self
    {
        return new self(204);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        if ($this->status !== 204) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($this->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }
}

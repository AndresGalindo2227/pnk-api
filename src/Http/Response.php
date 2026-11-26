<?php

declare(strict_types=1);

namespace Pnk\Http;

/** JSON response (or a small HTML page). */
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

    /** Small HTML page (the page Wompi shows after paying). */
    public static function html(string $page, int $status = 200): self
    {
        return new self($status, $page, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        if ($this->status !== 204 && is_string($this->body) && isset($this->headers['Content-Type'])) {
            echo $this->body;
        } elseif ($this->status !== 204) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($this->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }
}

<?php

declare(strict_types=1);

namespace Pnk\Http;

/** Immutable view of the incoming HTTP request. */
final class Request
{
    /** @param array<string, string> $params path parameters filled by the router */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        private readonly string $rawBody,
        public readonly array $params = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }

        return new self($_SERVER['REQUEST_METHOD'] ?? 'GET', rtrim($path, '/') ?: '/', $_GET, $headers, (string) file_get_contents('php://input'));
    }

    public function withParams(array $params): self
    {
        return new self($this->method, $this->path, $this->query, $this->headers, $this->rawBody, $params);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** JSON body as an array; invalid JSON is a 422, never a PHP warning. */
    public function json(): array
    {
        if ($this->rawBody === '') {
            return [];
        }
        $data = json_decode($this->rawBody, true);
        if (!is_array($data)) {
            throw HttpException::validation([['field' => 'body', 'issue' => 'must be valid JSON']]);
        }
        return $data;
    }

    public function param(string $name): string
    {
        return $this->params[$name] ?? '';
    }
}

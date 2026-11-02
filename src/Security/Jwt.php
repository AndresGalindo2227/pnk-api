<?php

declare(strict_types=1);

namespace Pnk\Security;

use Pnk\Http\HttpException;

/** Minimal HS256 JSON Web Token (RFC 7519) for the 15-minute access token. */
final class Jwt
{
    public function __construct(private readonly string $secret)
    {
    }

    public function encode(array $claims): string
    {
        $header = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = self::b64(json_encode($claims));
        $signature = self::b64(hash_hmac('sha256', "$header.$payload", $this->secret, true));
        return "$header.$payload.$signature";
    }

    /** Returns the claims, or throws 401 when the token is malformed, tampered with or expired. */
    public function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw HttpException::unauthorized();
        }
        [$header, $payload, $signature] = $parts;
        $expected = self::b64(hash_hmac('sha256', "$header.$payload", $this->secret, true));
        if (!hash_equals($expected, $signature)) {            // constant-time comparison
            throw HttpException::unauthorized();
        }
        $claims = json_decode(self::unb64($payload), true);
        if (!is_array($claims) || ($claims['exp'] ?? 0) < time()) {
            throw HttpException::unauthorized();
        }
        return $claims;
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function unb64(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'));
    }
}

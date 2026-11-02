<?php

declare(strict_types=1);

namespace Pnk\Security;

use PDO;
use Pnk\Http\HttpException;

/**
 * Issues the session pair (ADR-005):
 * - access token: JWT, 15 minutes, never stored;
 * - refresh token: 32 random bytes, 7 days, stored only as SHA-256, rotated on every use.
 * Reusing a revoked refresh token revokes every session of that player (theft detection).
 */
final class TokenService
{
    public function __construct(
        private readonly PDO $db,
        private readonly Jwt $jwt,
        private readonly int $accessTtl,
        private readonly int $refreshTtl,
    ) {
    }

    public function issue(int $playerId, ?string $userAgent): array
    {
        $refresh = bin2hex(random_bytes(32));
        $stmt = $this->db->prepare(
            "INSERT INTO refresh_tokens (subject_type, subject_id, token_hash, expires_at, user_agent)
             VALUES ('player', :id, :hash, DATE_ADD(NOW(), INTERVAL :ttl SECOND), :ua)"
        );
        $stmt->execute(['id' => $playerId, 'hash' => hash('sha256', $refresh), 'ttl' => $this->refreshTtl, 'ua' => substr((string) $userAgent, 0, 255)]);

        return [
            'accessToken' => $this->jwt->encode(['sub' => $playerId, 'role' => 'player', 'iat' => time(), 'exp' => time() + $this->accessTtl]),
            'accessTokenExpiresIn' => $this->accessTtl,
            'refreshToken' => $refresh,
        ];
    }

    /** Validates and rotates a refresh token. Returns the player id and the new pair. */
    public function rotate(string $refreshToken, ?string $userAgent): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, subject_id, expires_at < NOW() AS expired, revoked_at IS NOT NULL AS revoked
               FROM refresh_tokens WHERE token_hash = :hash AND subject_type = 'player'"
        );
        $stmt->execute(['hash' => hash('sha256', $refreshToken)]);
        $row = $stmt->fetch();

        if (!$row) {
            throw HttpException::unauthorized('SESSION_EXPIRED', 'Your session has expired. Log in again.');
        }
        if ((int) $row['revoked'] === 1) {
            $this->revokeAll((int) $row['subject_id']);       // a stolen, already used token: close every session
            throw HttpException::unauthorized('SESSION_EXPIRED', 'Your session has expired. Log in again.');
        }
        if ((int) $row['expired'] === 1) {
            throw HttpException::unauthorized('SESSION_EXPIRED', 'Your session has expired. Log in again.');
        }

        $this->db->prepare('UPDATE refresh_tokens SET revoked_at = NOW() WHERE id = :id')->execute(['id' => $row['id']]);
        return [(int) $row['subject_id'], $this->issue((int) $row['subject_id'], $userAgent)];
    }

    public function revoke(string $refreshToken): void
    {
        $this->db->prepare('UPDATE refresh_tokens SET revoked_at = NOW() WHERE token_hash = :hash AND revoked_at IS NULL')
            ->execute(['hash' => hash('sha256', $refreshToken)]);
    }

    private function revokeAll(int $playerId): void
    {
        $this->db->prepare("UPDATE refresh_tokens SET revoked_at = NOW() WHERE subject_type = 'player' AND subject_id = :id AND revoked_at IS NULL")
            ->execute(['id' => $playerId]);
    }
}

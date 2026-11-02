<?php

declare(strict_types=1);

namespace Pnk\Controllers;

use PDO;
use PDOException;
use Pnk\Http\HttpException;
use Pnk\Http\Request;
use Pnk\Http\Response;
use Pnk\Security\Jwt;
use Pnk\Security\TokenService;

/** HU-01 sign-up, login, HU-02 refresh and logout. */
final class AuthController extends Controller
{
    public function __construct(PDO $db, Jwt $jwt, array $config, private readonly TokenService $tokens)
    {
        parent::__construct($db, $jwt, $config);
    }

    public function register(Request $request): Response
    {
        $body = $request->json();
        $fullName = trim((string) ($body['fullName'] ?? ''));
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $phone = trim((string) ($body['phone'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        $errors = [];
        if (mb_strlen($fullName) < 3 || mb_strlen($fullName) > 150) $errors[] = ['field' => 'fullName', 'issue' => 'between 3 and 150 characters'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = ['field' => 'email', 'issue' => 'must be a valid email'];
        if (!preg_match('/^3\d{9}$/', $phone)) $errors[] = ['field' => 'phone', 'issue' => 'must be a Colombian mobile number'];
        if (strlen($password) < 8 || strlen($password) > 72) $errors[] = ['field' => 'password', 'issue' => 'between 8 and 72 characters'];
        if ($errors) throw HttpException::validation($errors);

        try {
            $this->db->prepare('INSERT INTO players (full_name, email, phone, password_hash) VALUES (:n, :e, :p, :h)')
                ->execute(['n' => $fullName, 'e' => $email, 'p' => $phone, 'h' => password_hash($password, PASSWORD_DEFAULT)]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {      // unique email or phone
                $code = str_contains($e->getMessage(), 'uq_players_phone') ? 'PHONE_ALREADY_REGISTERED' : 'EMAIL_ALREADY_REGISTERED';
                throw HttpException::conflict($code, 'This account already exists.');
            }
            throw $e;
        }
        $id = (int) $this->db->lastInsertId();
        return Response::json($this->session($id, $request), 201);
    }

    public function login(Request $request): Response
    {
        $body = $request->json();
        $stmt = $this->db->prepare('SELECT id, password_hash, is_active FROM players WHERE email = :e');
        $stmt->execute(['e' => strtolower(trim((string) ($body['email'] ?? '')))]);
        $player = $stmt->fetch();

        // Same answer for "unknown email" and "wrong password": never reveal which one failed.
        if (!$player || !(int) $player['is_active'] || !password_verify((string) ($body['password'] ?? ''), $player['password_hash'])) {
            throw HttpException::unauthorized('INVALID_CREDENTIALS', 'Email or password is incorrect.');
        }
        $this->db->prepare('UPDATE players SET last_login_at = NOW() WHERE id = :id')->execute(['id' => $player['id']]);
        return Response::json($this->session((int) $player['id'], $request));
    }

    public function refresh(Request $request): Response
    {
        $token = (string) ($request->json()['refreshToken'] ?? '');
        [$playerId, $pair] = $this->tokens->rotate($token, $request->header('User-Agent'));
        return Response::json($pair + ['player' => $this->player($playerId)]);
    }

    public function logout(Request $request): Response
    {
        $this->playerId($request);
        $this->tokens->revoke((string) ($request->json()['refreshToken'] ?? ''));
        return Response::noContent();
    }

    public function me(Request $request): Response
    {
        return Response::json($this->player($this->playerId($request)));
    }

    private function session(int $playerId, Request $request): array
    {
        return $this->tokens->issue($playerId, $request->header('User-Agent')) + ['player' => $this->player($playerId)];
    }

    private function player(int $id): array
    {
        $stmt = $this->db->prepare('SELECT id, full_name, email, phone FROM players WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $p = $stmt->fetch() ?: throw HttpException::unauthorized();
        return ['id' => (int) $p['id'], 'fullName' => $p['full_name'], 'email' => $p['email'], 'phone' => $p['phone'], 'role' => 'player'];
    }
}

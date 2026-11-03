<?php

declare(strict_types=1);

namespace Pnk;

use Pnk\Controllers\AuthController;
use Pnk\Controllers\BookingsController;
use Pnk\Controllers\HealthController;
use Pnk\Controllers\PaymentsController;
use Pnk\Http\HttpException;
use Pnk\Http\Request;
use Pnk\Http\Response;
use Pnk\Http\Router;
use Pnk\Security\Jwt;
use Pnk\Security\TokenService;
use Throwable;

/**
 * Composition root of pnk-api: configuration, CORS, routes (the OpenAPI contract in pnk-docs)
 * and one error handler that always answers with the standard Error body.
 */
final class App
{
    public const VERSION = '0.1.0';
    private const PREFIX = '/api/v1';

    public function run(): void
    {
        $config = require __DIR__ . '/../config/config.php';
        $this->cors($config['cors_origins']);

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {   // CORS preflight
            http_response_code(204);
            return;
        }

        try {
            $request = Request::fromGlobals();
            $path = str_starts_with($request->path, self::PREFIX) ? substr($request->path, strlen(self::PREFIX)) ?: '/' : $request->path;
            $request = new Request($request->method, $path, $request->query, $request->headers, (string) file_get_contents('php://input'));
            $response = $this->router($config)->dispatch($request);
        } catch (HttpException $e) {
            $body = ['code' => $e->errorCode, 'message' => $e->getMessage()] + ($e->details ? ['details' => $e->details] : []);
            $response = Response::json($body, $e->status);
        } catch (Throwable $e) {
            error_log('[pnk-api] ' . $e);                          // details go to the log, never to the client
            $response = Response::json(['code' => 'INTERNAL_ERROR', 'message' => 'Unexpected error.'], 500);
        }
        $response->send();
    }

    private function router(array $config): Router
    {
        $db = Database::connect($config['db']);
        $jwt = new Jwt($config['jwt']['secret']);
        $tokens = new TokenService($db, $jwt, $config['jwt']['access_ttl'], $config['jwt']['refresh_ttl']);

        $health = new HealthController($db, $jwt, $config);
        $auth = new AuthController($db, $jwt, $config, $tokens);
        $bookings = new BookingsController($db, $jwt, $config);
        $payments = new PaymentsController($db, $jwt, $config);

        $r = new Router();
        $r->add('GET', '/health', [$health, 'health']);
        $r->add('GET', '/config', [$health, 'config']);
        $r->add('POST', '/auth/register', [$auth, 'register']);
        $r->add('POST', '/auth/login', [$auth, 'login']);
        $r->add('POST', '/auth/refresh', [$auth, 'refresh']);
        $r->add('POST', '/auth/logout', [$auth, 'logout']);
        $r->add('GET', '/me', [$auth, 'me']);
        $r->add('GET', '/fields', [$bookings, 'fields']);
        $r->add('GET', '/availability', [$bookings, 'availability']);
        $r->add('GET', '/bookings', [$bookings, 'mine']);
        $r->add('POST', '/bookings', [$bookings, 'create']);
        $r->add('GET', '/bookings/{id}', [$bookings, 'show']);
        $r->add('POST', '/bookings/{id}/cancel', [$bookings, 'cancel']);
        $r->add('POST', '/bookings/{id}/payments/receipt', [$payments, 'uploadReceipt']);
        return $r;
    }

    private function cors(array $allowed): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (in_array($origin, $allowed, true)) {
            header("Access-Control-Allow-Origin: $origin");
            header('Vary: Origin');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Authorization, Content-Type, Idempotency-Key, X-App-Version, X-Request-Id, ngrok-skip-browser-warning');
            header('Access-Control-Max-Age: 600');
        }
    }
}

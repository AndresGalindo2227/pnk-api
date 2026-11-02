# pnk-api

REST API of **PANENKA Booking** (PHP 8, no framework), integrated with the InvFox MySQL database.
It implements the contract [`openapi.yaml`](https://github.com/AndresGalindo2227/pnk-docs/blob/main/03-api-and-data/openapi.yaml)
under `/api/v1` (ADR-005, ADR-007).

| | |
|---|---|
| Runtime | PHP 8.2+ with `pdo_mysql` and `fileinfo` (XAMPP works) |
| Database | InvFox schema + migrations of [`pnk-db`](https://github.com/AndresGalindo2227/pnk-db) (000–006) |
| Auth | JWT access token (15 min) + refresh token (7 days, stored as SHA-256, rotated) |
| Consumers | [`pnk-app`](https://github.com/AndresGalindo2227/pnk-app) (Android + PWA) |

## Structure

```
public/index.php          front controller (the only public file)
src/App.php               composition root: config, CORS, routes, error handler
src/Http/                 Request, Response, Router, HttpException
src/Security/             Jwt (HS256), TokenService (issue, rotate, revoke)
src/Domain/BookingRules   pure rules: hours, opening time, deposit, cancellation window
src/Controllers/          Health, Auth, Bookings, Payments
config/config.example.php copy to config/config.php (ignored by Git)
storage/receipts/         uploaded receipt photos (ignored by Git, not public)
tests/run.php             unit tests of BookingRules
```

## Run locally

```bash
cp config/config.example.php config/config.php   # then set the DB password and a JWT secret
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"  # JWT secret
php -S 0.0.0.0:8080 -t public                      # http://localhost:8080/api/v1/health
php tests/run.php                                  # unit tests
```

With XAMPP on Windows, use `/d/xampp/php/php.exe` instead of `php`.

## Endpoints

| Method | Path | Story |
|--------|------|-------|
| GET | `/health`, `/config` | ADR-007 |
| POST | `/auth/register`, `/auth/login`, `/auth/refresh`, `/auth/logout` | HU-01, HU-02 |
| GET | `/me` | HU-01 |
| GET | `/fields`, `/availability?date=` | HU-03 |
| GET / POST | `/bookings` (POST needs `Idempotency-Key`) | HU-05, HU-04 |
| GET | `/bookings/{id}` | HU-08 (404 for other players) |
| POST | `/bookings/{id}/cancel` | HU-06 |
| POST | `/bookings/{id}/payments/receipt` | HU-09 |

The admin endpoints (HU-11) and the Wompi webhook (HU-10) are planned for `pnk-portal` / v0.2.

## Security

- Passwords with `password_hash`; login never says whether the email or the password failed.
- The anti double-booking rule is the primary key `(unit_id, slot_start)` of `booking_slots`
  (409 `SLOT_ALREADY_TAKEN`).
- Every booking query filters by the logged-in player: other players' bookings answer 404.
- Receipts: type checked from the file bytes (`finfo`), max 2 MB, random file name, stored outside `public/`.
- Errors never expose stack traces; details go to the PHP error log.

## Branches

`develop` → `qa` → `main`, promoted with cherry-pick pull requests and squash merge.

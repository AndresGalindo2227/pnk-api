<?php

declare(strict_types=1);

namespace Pnk\Controllers;

use DateTimeImmutable;
use Exception;
use PDOException;
use Pnk\Domain\BookingRules;
use Pnk\Http\HttpException;
use Pnk\Http\Request;
use Pnk\Http\Response;

/** HU-03 availability, HU-04 booking, HU-05 my bookings, HU-06 cancellation, HU-08 privacy. */
final class BookingsController extends Controller
{
    /** GET /fields */
    public function fields(Request $request): Response
    {
        return Response::json(array_map(fn (array $f) => [
            'id' => (int) $f['id'],
            'name' => $f['name'],
            'fieldType' => $f['field_type'],
            'hourlyPrice' => (int) $f['hourly_price'],
            'sectors' => $f['sectors'] ? explode(',', $f['sectors']) : [],
        ], $this->activeFields()));
    }

    /**
     * GET /availability?date=YYYY-MM-DD
     * A slot is free only if NONE of the sectors of that field is held at that hour
     * (Cancha 9 = sectors A+B+C, so 5A booked => 9 taken).
     */
    public function availability(Request $request): Response
    {
        $date = (string) ($request->query['date'] ?? '');
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $today = new DateTimeImmutable('today');
        if (!$day || $day->format('Y-m-d') !== $date || $day < $today || $day > $today->modify('+30 days')) {
            throw HttpException::validation([['field' => 'date', 'issue' => 'must be a date between today and 30 days ahead']]);
        }

        $s = $this->settings();
        $slots = BookingRules::daySlots($date, $s['opens_at'], $s['closes_at']);

        // Every (sector, hour) already held that day.
        $stmt = $this->db->prepare('SELECT unit_id, slot_start FROM booking_slots WHERE slot_start >= :from AND slot_start < :to');
        $stmt->execute(['from' => "$date 00:00:00", 'to' => $day->modify('+1 day')->format('Y-m-d 00:00:00')]);
        $held = [];
        foreach ($stmt->fetchAll() as $row) {
            $held[$row['unit_id'] . '|' . $row['slot_start']] = true;
        }

        $now = self::now();
        $fields = [];
        foreach ($this->activeFields() as $field) {
            $units = $field['unit_ids'] ? explode(',', $field['unit_ids']) : [];
            $fields[] = [
                'fieldId' => (int) $field['id'],
                'name' => $field['name'],
                'hourlyPrice' => (int) $field['hourly_price'],
                'slots' => array_map(function (DateTimeImmutable $slot) use ($units, $held, $now) {
                    $key = $slot->format('Y-m-d H:i:s');
                    $taken = array_filter($units, fn ($u) => isset($held["$u|$key"]));
                    return ['startsAt' => self::iso($slot), 'available' => $slot > $now && !$taken];
                }, $slots),
            ];
        }
        return Response::json(['date' => $date, 'fields' => $fields]);
    }

    /** GET /bookings?scope=upcoming|past — only the bookings of the logged-in player. */
    public function mine(Request $request): Response
    {
        $playerId = $this->playerId($request);
        $past = ($request->query['scope'] ?? 'upcoming') === 'past';
        $sql = self::SELECT_BOOKING . ' WHERE b.player_id = :p AND '
            . ($past ? "(b.ends_at < NOW() OR b.status IN ('cancelled','completed','no_show')) ORDER BY b.starts_at DESC"
                     : "b.ends_at >= NOW() AND b.status IN ('pending','confirmed') ORDER BY b.starts_at ASC");
        $stmt = $this->db->prepare($sql . ' LIMIT 50');
        $stmt->execute(['p' => $playerId]);
        $minHours = (int) $this->settings()['cancellation_min_hours'];
        return Response::json(array_map(fn ($b) => $this->toJson($b, $minHours), $stmt->fetchAll()));
    }

    /** GET /bookings/{id} — 404 also when it belongs to another player (HU-08). */
    public function show(Request $request): Response
    {
        $booking = $this->ownedBooking((int) $request->param('id'), $this->playerId($request));
        return Response::json($this->toJson($booking, (int) $this->settings()['cancellation_min_hours']));
    }

    /**
     * POST /bookings — one transaction: booking row + one booking_slots row per sector and hour.
     * A duplicated (unit_id, slot_start) makes MySQL reject the insert: 409 SLOT_ALREADY_TAKEN (ADR-004).
     */
    public function create(Request $request): Response
    {
        $playerId = $this->playerId($request);
        $key = (string) $request->header('Idempotency-Key');
        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $key)) {
            throw HttpException::validation([['field' => 'Idempotency-Key', 'issue' => 'header with a UUID is required']]);
        }

        $body = $request->json();
        $fieldId = (int) ($body['fieldId'] ?? 0);
        $hours = (int) ($body['hours'] ?? 1);
        try {
            $start = (new DateTimeImmutable((string) ($body['startsAt'] ?? '')))->setTimezone(new \DateTimeZone('America/Bogota'));
        } catch (Exception) {
            throw HttpException::validation([['field' => 'startsAt', 'issue' => 'must be an ISO 8601 date-time']]);
        }

        // Same key again (the app retried after a network error): return the booking already created,
        // but only if it is the SAME request. A key reused for another field or hour is a client bug.
        $stmt = $this->db->prepare(
            'SELECT b.id, b.field_id, b.starts_at FROM booking_idempotency_keys k JOIN bookings b ON b.id = k.booking_id
              WHERE k.idempotency_key = :k AND k.player_id = :p'
        );
        $stmt->execute(['k' => $key, 'p' => $playerId]);
        if ($existing = $stmt->fetch()) {
            if ((int) $existing['field_id'] !== $fieldId || $existing['starts_at'] !== $start->format('Y-m-d H:i:s')) {
                throw new HttpException(422, 'IDEMPOTENCY_KEY_REUSED', 'This Idempotency-Key was already used for a different booking.');
            }
            return $this->createdResponse((int) $existing['id'], $playerId);
        }

        $s = $this->settings();
        if ($errors = BookingRules::violations($start, $hours, self::now(), $s['opens_at'], $s['closes_at'])) {
            throw HttpException::validation($errors);
        }
        $field = $this->field($fieldId) ?? throw HttpException::validation([['field' => 'fieldId', 'issue' => 'unknown field']]);
        $amounts = BookingRules::amounts((int) $field['hourly_price'], $hours, (int) $s['deposit_percent']);
        $end = $start->modify("+$hours hours");

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                "INSERT INTO bookings (code, field_id, player_id, starts_at, ends_at, status, total_amount, deposit_amount)
                 VALUES (:tmp, :f, :p, :s, :e, 'pending', :t, :d)"
            )->execute(['tmp' => 'TMP-' . bin2hex(random_bytes(6)), 'f' => $fieldId, 'p' => $playerId, 's' => $start->format('Y-m-d H:i:s'), 'e' => $end->format('Y-m-d H:i:s'), 't' => $amounts['total'], 'd' => $amounts['deposit']]);
            $bookingId = (int) $this->db->lastInsertId();
            $code = sprintf('PNK-%s-%04d', $start->format('ym'), $bookingId);
            $this->db->prepare('UPDATE bookings SET code = :c WHERE id = :id')->execute(['c' => $code, 'id' => $bookingId]);

            $hold = $this->db->prepare('INSERT INTO booking_slots (unit_id, slot_start, booking_id) VALUES (:u, :s, :b)');
            foreach (explode(',', $field['unit_ids']) as $unitId) {
                for ($h = 0; $h < $hours; $h++) {
                    $hold->execute(['u' => $unitId, 's' => $start->modify("+$h hours")->format('Y-m-d H:i:s'), 'b' => $bookingId]);
                }
            }
            $this->db->prepare('INSERT INTO booking_idempotency_keys (idempotency_key, player_id, booking_id) VALUES (:k, :p, :b)')
                ->execute(['k' => $key, 'p' => $playerId, 'b' => $bookingId]);
            $this->db->commit();
        } catch (PDOException $e) {
            $this->db->rollBack();
            if ($e->getCode() === '23000') {
                throw HttpException::conflict('SLOT_ALREADY_TAKEN', 'The selected time slot is no longer available.');
            }
            throw $e;
        }
        return $this->createdResponse($bookingId, $playerId);
    }

    /** POST /bookings/{id}/cancel — frees the sectors so the hours become available again (HU-06). */
    public function cancel(Request $request): Response
    {
        $playerId = $this->playerId($request);
        $booking = $this->ownedBooking((int) $request->param('id'), $playerId);
        $minHours = (int) $this->settings()['cancellation_min_hours'];

        if (!in_array($booking['status'], ['pending', 'confirmed'], true)) {
            throw HttpException::conflict('BOOKING_NOT_CANCELLABLE', 'This booking can no longer be cancelled.');
        }
        if (!BookingRules::canCancel($booking['status'], new DateTimeImmutable($booking['starts_at']), self::now(), $minHours)) {
            throw HttpException::conflict('CANCELLATION_WINDOW_CLOSED', "Bookings can only be cancelled up to $minHours hours before they start.");
        }

        $this->db->beginTransaction();
        $this->db->prepare("UPDATE bookings SET status = 'cancelled', cancelled_at = NOW() WHERE id = :id")->execute(['id' => $booking['id']]);
        $this->db->prepare('DELETE FROM booking_slots WHERE booking_id = :id')->execute(['id' => $booking['id']]);
        $this->db->commit();

        return Response::json($this->toJson($this->ownedBooking((int) $booking['id'], $playerId), $minHours));
    }

    // ------------------------------------------------------------------ helpers

    private const SELECT_BOOKING = 'SELECT b.*, f.name AS field_name,
        (SELECT p.status FROM payments p WHERE p.booking_id = b.id ORDER BY p.id DESC LIMIT 1) AS payment_status
        FROM bookings b JOIN fields f ON f.id = b.field_id';

    /** Authorization by ownership: another player's booking is reported as not found. */
    private function ownedBooking(int $id, int $playerId): array
    {
        $stmt = $this->db->prepare(self::SELECT_BOOKING . ' WHERE b.id = :id AND b.player_id = :p');
        $stmt->execute(['id' => $id, 'p' => $playerId]);
        return $stmt->fetch() ?: throw HttpException::notFound('Booking');
    }

    private function createdResponse(int $bookingId, int $playerId): Response
    {
        $booking = $this->toJson($this->ownedBooking($bookingId, $playerId), (int) $this->settings()['cancellation_min_hours']);
        return Response::json($booking, 201, ['Location' => "/api/v1/bookings/$bookingId"]);
    }

    private function toJson(array $b, int $minHours): array
    {
        return [
            'id' => (int) $b['id'],
            'code' => $b['code'],
            'field' => ['id' => (int) $b['field_id'], 'name' => $b['field_name']],
            'startsAt' => self::iso($b['starts_at']),
            'endsAt' => self::iso($b['ends_at']),
            'status' => $b['status'],
            'totalAmount' => (int) $b['total_amount'],
            'depositAmount' => (int) $b['deposit_amount'],
            'paymentStatus' => $b['payment_status'],
            'canCancel' => BookingRules::canCancel($b['status'], new DateTimeImmutable($b['starts_at']), self::now(), $minHours),
            'createdAt' => self::iso($b['created_at']),
        ];
    }

    private function activeFields(): array
    {
        return $this->db->query(
            'SELECT f.id, f.name, f.field_type, f.hourly_price,
                    GROUP_CONCAT(u.id ORDER BY u.id) AS unit_ids, GROUP_CONCAT(u.name ORDER BY u.id) AS sectors
               FROM fields f LEFT JOIN field_unit_map m ON m.field_id = f.id LEFT JOIN field_units u ON u.id = m.unit_id
              WHERE f.is_active = 1 GROUP BY f.id ORDER BY f.id'
        )->fetchAll();
    }

    private function field(int $id): ?array
    {
        foreach ($this->activeFields() as $field) {
            if ((int) $field['id'] === $id) {
                return $field;
            }
        }
        return null;
    }
}

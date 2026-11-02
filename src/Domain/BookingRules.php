<?php

declare(strict_types=1);

namespace Pnk\Domain;

use DateTimeImmutable;

/**
 * Pure business rules of a booking (no database, no HTTP), so they can be unit-tested.
 * The anti double-booking rule itself lives in the database: PRIMARY KEY (unit_id, slot_start).
 */
final class BookingRules
{
    public const MIN_HOURS = 1;
    public const MAX_HOURS = 3;

    /**
     * Returns the list of broken rules as { field, issue }; empty means the request is valid.
     * @return list<array{field: string, issue: string}>
     */
    public static function violations(DateTimeImmutable $start, int $hours, DateTimeImmutable $now, string $opensAt, string $closesAt): array
    {
        $errors = [];
        if ($hours < self::MIN_HOURS || $hours > self::MAX_HOURS) {
            $errors[] = ['field' => 'hours', 'issue' => 'must be between 1 and 3'];
        }
        if ($start->format('i:s') !== '00:00') {
            $errors[] = ['field' => 'startsAt', 'issue' => 'must be on the hour'];
        }
        if ($start <= $now) {
            $errors[] = ['field' => 'startsAt', 'issue' => 'must be in the future'];
        }
        $end = $start->modify("+$hours hours");
        $open = new DateTimeImmutable($start->format('Y-m-d') . ' ' . $opensAt);
        $close = new DateTimeImmutable($start->format('Y-m-d') . ' ' . $closesAt);
        if ($start < $open || $end > $close) {
            $errors[] = ['field' => 'startsAt', 'issue' => 'must be within opening hours'];
        }
        return $errors;
    }

    /** Total and deposit in COP (integers). */
    public static function amounts(int $hourlyPrice, int $hours, int $depositPercent): array
    {
        $total = $hourlyPrice * $hours;
        return ['total' => $total, 'deposit' => (int) round($total * $depositPercent / 100)];
    }

    /** A booking can be cancelled while it is pending or confirmed and at least N hours remain. */
    public static function canCancel(string $status, DateTimeImmutable $start, DateTimeImmutable $now, int $minHours): bool
    {
        return in_array($status, ['pending', 'confirmed'], true)
            && ($start->getTimestamp() - $now->getTimestamp()) >= $minHours * 3600;
    }

    /** Hourly slot starts between opening and closing time. @return list<DateTimeImmutable> */
    public static function daySlots(string $date, string $opensAt, string $closesAt): array
    {
        $slots = [];
        $cursor = new DateTimeImmutable("$date $opensAt");
        $close = new DateTimeImmutable("$date $closesAt");
        while ($cursor < $close) {
            $slots[] = $cursor;
            $cursor = $cursor->modify('+1 hour');
        }
        return $slots;
    }
}

<?php

declare(strict_types=1);

/**
 * Unit tests of the pure booking rules. No framework needed:  php tests/run.php
 * Exit code 1 when a test fails, so CI can use it.
 */

require __DIR__ . '/../src/bootstrap.php';

use Pnk\Domain\BookingRules;

$tests = [];
$test = function (string $name, callable $fn) use (&$tests): void { $tests[$name] = $fn; };
$assert = function (bool $condition, string $message = 'assertion failed'): void {
    if (!$condition) throw new RuntimeException($message);
};
$d = fn (string $s) => new DateTimeImmutable($s);

$test('violations_validFutureSlot_returnsNone', function () use ($assert, $d) {
    $assert(BookingRules::violations($d('2026-11-07 19:00'), 1, $d('2026-11-07 10:00'), '08:00:00', '23:00:00') === []);
});
$test('violations_slotInThePast_isRejected', function () use ($assert, $d) {
    $errors = BookingRules::violations($d('2026-11-07 09:00'), 1, $d('2026-11-07 10:00'), '08:00:00', '23:00:00');
    $assert(in_array('must be in the future', array_column($errors, 'issue'), true));
});
$test('violations_notOnTheHour_isRejected', function () use ($assert, $d) {
    $errors = BookingRules::violations($d('2026-11-07 19:30'), 1, $d('2026-11-07 10:00'), '08:00:00', '23:00:00');
    $assert(in_array('must be on the hour', array_column($errors, 'issue'), true));
});
$test('violations_endsAfterClosing_isRejected', function () use ($assert, $d) {
    $errors = BookingRules::violations($d('2026-11-07 22:00'), 2, $d('2026-11-07 10:00'), '08:00:00', '23:00:00');
    $assert(in_array('must be within opening hours', array_column($errors, 'issue'), true));
});
$test('violations_fourHours_isRejected', function () use ($assert, $d) {
    $errors = BookingRules::violations($d('2026-11-07 15:00'), 4, $d('2026-11-07 10:00'), '08:00:00', '23:00:00');
    $assert(array_column($errors, 'field')[0] === 'hours');
});
$test('amounts_cancha9TwoHours_halfDeposit', function () use ($assert) {
    $assert(BookingRules::amounts(210000, 2, 50) === ['total' => 420000, 'deposit' => 210000]);
});
$test('canCancel_threeHoursLeft_isAllowed', function () use ($assert, $d) {
    $assert(BookingRules::canCancel('pending', $d('2026-11-07 19:00'), $d('2026-11-07 16:00'), 3));
});
$test('canCancel_lessThanThreeHours_isNotAllowed', function () use ($assert, $d) {
    $assert(!BookingRules::canCancel('confirmed', $d('2026-11-07 19:00'), $d('2026-11-07 16:01'), 3));
});
$test('canCancel_alreadyCancelled_isNotAllowed', function () use ($assert, $d) {
    $assert(!BookingRules::canCancel('cancelled', $d('2026-11-08 19:00'), $d('2026-11-07 10:00'), 3));
});
$test('daySlots_8to23_returnsFifteenHours', function () use ($assert) {
    $slots = BookingRules::daySlots('2026-11-07', '08:00:00', '23:00:00');
    $assert(count($slots) === 15 && $slots[14]->format('H:i') === '22:00');
});

$failed = 0;
foreach ($tests as $name => $fn) {
    try {
        $fn();
        echo "  \u{2713} $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  \u{2717} $name: {$e->getMessage()}\n";
    }
}
printf("\n%d passed, %d failed\n", count($tests) - $failed, $failed);
exit($failed > 0 ? 1 : 0);

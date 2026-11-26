<?php

declare(strict_types=1);

namespace Pnk\Domain;

/**
 * Pure rules of the online payments with Wompi (no database, no HTTP), so they can be unit-tested.
 * Docs: https://docs.wompi.co (Web Checkout, integrity signature and events).
 */
final class OnlinePayment
{
    public const CHECKOUT_URL = 'https://checkout.wompi.co/p/';

    /**
     * What the player may pay online now, in COP: the deposit (while it is not covered) and
     * the rest of the booking. Empty when nothing is owed.
     *
     * @return array<'deposit'|'total', int>
     */
    public static function options(int $total, int $deposit, int $paid): array
    {
        $balance = max(0, $total - $paid);
        if ($balance === 0) {
            return [];
        }
        $options = [];
        if ($paid < $deposit) {
            $options['deposit'] = $deposit - $paid;
        }
        $options['total'] = $balance;
        return $options;
    }

    /** Booking status after the payments: completed when everything is paid, confirmed with the deposit. */
    public static function statusAfterPayment(string $status, int $total, int $deposit, int $paid): string
    {
        if (!in_array($status, ['pending', 'confirmed'], true)) {
            return $status;                       // cancelled / no-show: the staff decides about the money
        }
        if ($paid >= $total) {
            return 'completed';
        }
        return $paid >= $deposit && $paid > 0 ? 'confirmed' : $status;
    }

    /** Integrity signature of the checkout: SHA-256 of reference + amount in cents + currency + integrity secret. */
    public static function integritySignature(string $reference, int $amountInCents, string $currency, string $integritySecret): string
    {
        return hash('sha256', $reference . $amountInCents . $currency . $integritySecret);
    }

    /**
     * Checks the checksum of a Wompi event: SHA-256 of the values of signature.properties (read from data),
     * then the timestamp, then the events secret.
     */
    public static function eventIsAuthentic(array $event, string $eventsSecret, ?string $headerChecksum = null): bool
    {
        $properties = $event['signature']['properties'] ?? null;
        $checksum = (string) ($headerChecksum ?: ($event['signature']['checksum'] ?? ''));
        if (!is_array($properties) || $checksum === '' || !isset($event['timestamp'], $event['data'])) {
            return false;
        }
        $chain = '';
        foreach ($properties as $path) {
            $value = $event['data'];
            foreach (explode('.', (string) $path) as $key) {
                if (!is_array($value) || !array_key_exists($key, $value)) {
                    return false;
                }
                $value = $value[$key];
            }
            $chain .= is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }
        $chain .= $event['timestamp'] . $eventsSecret;
        return hash_equals(strtolower(hash('sha256', $chain)), strtolower($checksum));
    }

    /** Our reference for Wompi: booking code + random suffix, unique per attempt. */
    public static function reference(string $bookingCode): string
    {
        return $bookingCode . '-' . strtoupper(bin2hex(random_bytes(3)));
    }
}

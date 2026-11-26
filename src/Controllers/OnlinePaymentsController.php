<?php

declare(strict_types=1);

namespace Pnk\Controllers;

use PDO;
use Pnk\Domain\OnlinePayment;
use Pnk\Http\HttpException;
use Pnk\Http\Request;
use Pnk\Http\Response;
use Pnk\Payments\Wompi;
use Pnk\Security\Jwt;

/**
 * Online payment of a booking with Wompi:
 *   1. POST /bookings/{id}/payments/online  creates a pending payment and returns the signed checkout URL
 *   2. the player pays inside Wompi (Nequi, PSE, card, Bancolombia)
 *   3. POST /webhooks/wompi                 Wompi tells us the result; the payment is approved and the booking confirmed
 *   4. POST /bookings/{id}/payments/online/check  the app asks again when it comes back (in case the event is late)
 */
final class OnlinePaymentsController extends Controller
{
    private readonly Wompi $wompi;

    public function __construct(PDO $db, Jwt $jwt, array $config)
    {
        parent::__construct($db, $jwt, $config);
        $this->wompi = new Wompi($db, $config);
    }

    public function start(Request $request): Response
    {
        $playerId = $this->playerId($request);
        if (!$this->wompi->enabled()) {
            throw HttpException::conflict('ONLINE_PAYMENTS_DISABLED', 'Online payments are not available.');
        }
        $booking = $this->booking((int) $request->param('id'), $playerId);
        if (!in_array($booking['status'], ['pending', 'confirmed'], true)) {
            throw HttpException::conflict('BOOKING_NOT_PAYABLE', 'This booking can no longer be paid.');
        }
        $options = OnlinePayment::options((int) $booking['total_amount'], (int) $booking['deposit_amount'], (int) $booking['paid']);
        $option = (string) ($request->json()['option'] ?? '');
        if (!isset($options[$option])) {
            throw HttpException::validation([['field' => 'option', 'issue' => 'must be one of: ' . (implode(', ', array_keys($options)) ?: 'nothing to pay')]]);
        }
        $amount = $options[$option];
        $reference = OnlinePayment::reference($booking['code']);

        $this->db->prepare("INSERT INTO payments (booking_id, method, amount, status, referencia) VALUES (:b, 'wompi', :a, 'pending', :r)")
            ->execute(['b' => $booking['id'], 'a' => $amount, 'r' => $reference]);

        return Response::json([
            'reference' => $reference,
            'amount' => $amount,
            'checkoutUrl' => $this->wompi->checkoutUrl($reference, $amount, ['email' => $booking['email'], 'fullName' => $booking['full_name']]),
        ], 201);
    }

    /** The app came back from Wompi: ask Wompi for the attempts still pending and apply them. */
    public function check(Request $request): Response
    {
        $booking = $this->booking((int) $request->param('id'), $this->playerId($request));
        if ($this->wompi->enabled()) {
            $stmt = $this->db->prepare(
                "SELECT referencia FROM payments WHERE booking_id = :b AND method = 'wompi' AND status = 'pending'
                   AND created_at >= NOW() - INTERVAL 1 DAY ORDER BY id DESC LIMIT 3"
            );
            $stmt->execute(['b' => $booking['id']]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $reference) {
                foreach ($this->wompi->transactionsOf($reference) as $transaction) {
                    $this->apply($transaction);
                }
            }
            $booking = $this->booking((int) $booking['id'], (int) $booking['player_id']);
        }
        return Response::json(['bookingStatus' => $booking['status'], 'paidAmount' => (int) $booking['paid']]);
    }

    /** Wompi event. Always 200 for authentic events (also unknown references), so Wompi stops retrying. */
    public function webhook(Request $request): Response
    {
        $event = $request->json();
        if (!$this->wompi->enabled() || !OnlinePayment::eventIsAuthentic($event, $this->wompi->eventsSecret(), $request->header('X-Event-Checksum'))) {
            throw new HttpException(401, 'INVALID_SIGNATURE', 'Event signature is not valid.');
        }
        if (($event['event'] ?? '') === 'transaction.updated' && is_array($event['data']['transaction'] ?? null)) {
            $this->apply($event['data']['transaction']);
        }
        return Response::json(['received' => true]);
    }

    /** Page Wompi shows when the payment ends (redirect-url): the player goes back to the app. */
    public function returnPage(Request $request): Response
    {
        return Response::html(<<<'HTML'
            <!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
            <title>PANENKA · Pago</title><style>body{font-family:system-ui,sans-serif;background:#0f3d24;color:#fff;display:grid;place-items:center;min-height:100vh;margin:0;text-align:center;padding:24px}
            h1{font-size:1.4rem}p{opacity:.85}</style></head><body><main><h1>Listo, recibimos el resultado de tu pago</h1>
            <p>Cierra esta ventana y vuelve a la app de PANENKA: allí verás tu reserva confirmada.</p></main></body></html>
            HTML);
    }

    /**
     * Applies a Wompi transaction to our pending payment (idempotent: an event repeated or a check
     * after the event changes nothing). Approved: the money counts and the booking moves forward.
     */
    private function apply(array $tx): void
    {
        $reference = (string) ($tx['reference'] ?? '');
        $status = (string) ($tx['status'] ?? '');
        if ($reference === '' || !in_array($status, ['APPROVED', 'DECLINED', 'VOIDED', 'ERROR'], true)) {
            return;                                // PENDING: wait for the final state
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("SELECT * FROM payments WHERE method = 'wompi' AND referencia = :r FOR UPDATE");
            $stmt->execute(['r' => $reference]);
            $payment = $stmt->fetch();
            if (!$payment || $payment['status'] !== 'pending') {
                $this->db->commit();
                return;                            // not ours, or already applied
            }

            $amountOk = (int) ($tx['amount_in_cents'] ?? 0) === (int) round((float) $payment['amount'] * 100) && ($tx['currency'] ?? '') === 'COP';
            if ($status === 'APPROVED' && $amountOk) {
                $this->db->prepare(
                    "UPDATE payments SET status = 'approved', metodo = 'wompi', provider_reference = :t, provider_method = :m, reviewed_at = NOW() WHERE id = :id"
                )->execute(['t' => (string) $tx['id'], 'm' => substr((string) ($tx['payment_method_type'] ?? ''), 0, 30) ?: null, 'id' => $payment['id']]);
                $this->advanceBooking((int) $payment['booking_id']);
            } else {
                $reason = $status === 'APPROVED' ? 'Wompi aprobó un monto distinto: revisar en el panel de Wompi' : "Pago en línea no aprobado ($status)";
                if ($status === 'APPROVED') {
                    error_log("[pnk-api] Wompi amount mismatch on $reference");
                }
                $this->db->prepare(
                    "UPDATE payments SET status = 'rejected', provider_reference = :t, provider_method = :m, reviewed_at = NOW(), rejection_reason = :why WHERE id = :id"
                )->execute(['t' => (string) ($tx['id'] ?? '') ?: null, 'm' => substr((string) ($tx['payment_method_type'] ?? ''), 0, 30) ?: null, 'why' => $reason, 'id' => $payment['id']]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function advanceBooking(int $bookingId): void
    {
        $stmt = $this->db->prepare(
            "SELECT b.status, b.total_amount, b.deposit_amount,
                    (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.booking_id = b.id AND p.status = 'approved') AS paid
               FROM bookings b WHERE b.id = :id FOR UPDATE"
        );
        $stmt->execute(['id' => $bookingId]);
        $b = $stmt->fetch();
        $new = OnlinePayment::statusAfterPayment($b['status'], (int) $b['total_amount'], (int) $b['deposit_amount'], (int) round((float) $b['paid']));
        if ($new !== $b['status']) {
            $this->db->prepare('UPDATE bookings SET status = :s WHERE id = :id')->execute(['s' => $new, 'id' => $bookingId]);
        }
    }

    /** The player's own booking with what is already paid; another player's booking is a 404 (HU-08). */
    private function booking(int $id, int $playerId): array
    {
        $stmt = $this->db->prepare(
            "SELECT b.*, pl.email, pl.full_name,
                    (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.booking_id = b.id AND p.status = 'approved') AS paid
               FROM bookings b JOIN players pl ON pl.id = b.player_id WHERE b.id = :id AND b.player_id = :p"
        );
        $stmt->execute(['id' => $id, 'p' => $playerId]);
        return $stmt->fetch() ?: throw HttpException::notFound('Booking');
    }
}

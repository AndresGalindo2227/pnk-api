<?php

declare(strict_types=1);

namespace Pnk\Controllers;

use Pnk\App;
use Pnk\Payments\Wompi;
use Pnk\Http\Request;
use Pnk\Http\Response;

final class HealthController extends Controller
{
    public function health(Request $request): Response
    {
        return Response::json(['status' => 'ok', 'version' => App::VERSION]);
    }

    /** GET /config: business rules and minimum app version (ADR-007). */
    public function config(Request $request): Response
    {
        $s = $this->settings();
        return Response::json([
            'minAppVersion' => $this->config['min_app_version'],
            'timezone' => $s['timezone'],
            'currency' => $s['currency'],
            'opensAt' => substr($s['opens_at'], 0, 5),
            'closesAt' => substr($s['closes_at'], 0, 5),
            'slotMinutes' => (int) $s['slot_minutes'],
            'depositPercent' => (int) $s['deposit_percent'],
            'cancellationMinHours' => (int) $s['cancellation_min_hours'],
            'maxDaysAhead' => 30,
            'paymentAccounts' => $this->paymentAccounts(),
            'onlinePayments' => (new Wompi($this->db, $this->config))->enabled(),
        ]);
    }

    /**
     * Active accounts where the player pays the deposit (Nequi, Daviplata, bank, Bre-B key),
     * managed by the business in InvFox (migration 014). Empty when the table does not exist yet.
     *
     * @return list<array{entity: string, accountType: string, number: string, holder: string, holderDocument: ?string}>
     */
    private function paymentAccounts(): array
    {
        try {
            $rows = $this->db->query(
                'SELECT entity, account_type, number, holder, holder_document
                   FROM payment_accounts WHERE is_active = 1 ORDER BY id'
            )->fetchAll();
        } catch (\PDOException) {
            return [];
        }
        return array_map(static fn (array $r): array => [
            'entity' => $r['entity'],
            'accountType' => $r['account_type'],
            'number' => $r['number'],
            'holder' => $r['holder'],
            'holderDocument' => $r['holder_document'],
        ], $rows);
    }
}

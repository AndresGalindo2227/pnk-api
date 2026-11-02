<?php

declare(strict_types=1);

namespace Pnk\Controllers;

use Pnk\App;
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
        ]);
    }
}

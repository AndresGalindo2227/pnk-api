<?php

declare(strict_types=1);

namespace Pnk\Payments;

use PDO;
use Pnk\Domain\OnlinePayment;
use Pnk\Security\InvfoxCipher;
use RuntimeException;

/**
 * Wompi account of the business, configured in InvFox (Configuración → Pagos en línea),
 * and the few calls pnk-api makes to it. Secrets are decrypted only when needed.
 */
final class Wompi
{
    private ?array $settings = null;

    public function __construct(private readonly PDO $db, private readonly array $config)
    {
    }

    /** Online payments switched on and with every key saved. */
    public function enabled(): bool
    {
        try {
            $s = $this->row();
        } catch (\PDOException) {
            return false;                          // migration 015 not applied yet
        }
        return (int) ($s['wompi_activo'] ?? 0) === 1 && !empty($s['wompi_llave_publica']) && !empty($s['wompi_llave_privada'])
            && !empty($s['wompi_secreto_integridad']) && !empty($s['wompi_secreto_eventos']) && !empty($this->config['invfox_app_key']);
    }

    public function sandbox(): bool
    {
        return ($this->row()['wompi_modo'] ?? 'pruebas') !== 'produccion';
    }

    /** URL of the Wompi Web Checkout for one payment, signed so the amount cannot be changed. */
    public function checkoutUrl(string $reference, int $amount, array $customer): string
    {
        $cents = $amount * 100;
        $params = [
            'public-key' => $this->row()['wompi_llave_publica'],
            'currency' => 'COP',
            'amount-in-cents' => $cents,
            'reference' => $reference,
            'signature:integrity' => OnlinePayment::integritySignature($reference, $cents, 'COP', $this->secret('wompi_secreto_integridad')),
        ];
        if (!empty($this->config['public_url'])) {
            $params['redirect-url'] = rtrim($this->config['public_url'], '/') . '/api/v1/payments/wompi/return';
        }
        if (!empty($customer['email'])) {
            $params['customer-data:email'] = $customer['email'];
        }
        if (!empty($customer['fullName'])) {
            $params['customer-data:full-name'] = $customer['fullName'];
        }
        return OnlinePayment::CHECKOUT_URL . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public function eventsSecret(): string
    {
        return $this->secret('wompi_secreto_eventos');
    }

    /**
     * Transactions of one of our references, asked to the Wompi API (fallback when the event is late).
     * Returns [] when Wompi cannot be reached: the event will still arrive.
     */
    public function transactionsOf(string $reference): array
    {
        $base = $this->sandbox() ? 'https://sandbox.wompi.co/v1' : 'https://production.wompi.co/v1';
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => 'Authorization: Bearer ' . $this->secret('wompi_llave_privada') . "\r\nAccept: application/json\r\n",
            'timeout' => 8,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($base . '/transactions?reference=' . rawurlencode($reference), false, $context);
        if ($body === false) {
            error_log('[pnk-api] Wompi API not reachable for ' . $reference);
            return [];
        }
        $json = json_decode($body, true);
        return is_array($json['data'] ?? null) ? $json['data'] : [];
    }

    private function secret(string $column): string
    {
        $stored = (string) ($this->row()[$column] ?? '');
        if ($stored === '') {
            throw new RuntimeException("Wompi is not configured ($column).");
        }
        return (new InvfoxCipher((string) ($this->config['invfox_app_key'] ?? '')))->decrypt($stored);
    }

    private function row(): array
    {
        return $this->settings ??= $this->db->query(
            'SELECT wompi_activo, wompi_modo, wompi_llave_publica, wompi_llave_privada, wompi_secreto_integridad, wompi_secreto_eventos
               FROM business_settings WHERE id = 1'
        )->fetch() ?: [];
    }
}

<?php

declare(strict_types=1);

namespace Pnk\Security;

use RuntimeException;

/**
 * Reads the secrets InvFox stores encrypted in business_settings (Wompi keys):
 * AES-256-GCM with the installation key (InvFox config app.key), stored as
 * base64(iv 12 bytes + tag 16 bytes + ciphertext). Same format as InvFox Cifrado.
 */
final class InvfoxCipher
{
    private readonly string $key;

    public function __construct(string $hexKey)
    {
        if (!preg_match('/^[0-9a-f]{64}$/i', $hexKey)) {
            throw new RuntimeException('Missing invfox_app_key in config/config.php (copy app.key from the InvFox config).');
        }
        $this->key = (string) hex2bin($hexKey);
    }

    public function decrypt(string $stored): string
    {
        $data = base64_decode($stored, true);
        if ($data === false || strlen($data) < 29) {
            throw new RuntimeException('Encrypted value is damaged.');
        }
        $text = openssl_decrypt(substr($data, 28), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, substr($data, 0, 12), substr($data, 12, 16));
        if ($text === false) {
            throw new RuntimeException('Could not decrypt: invfox_app_key is not the app.key of InvFox.');
        }
        return $text;
    }
}

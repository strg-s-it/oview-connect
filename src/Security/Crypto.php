<?php

declare(strict_types=1);

namespace Connect\Security;

/**
 * Verschluesselung fuer gespeicherte Connection-Zugangsdaten
 * (connect_connections.credentials). Gleiches Verfahren wie
 * inc/crypto_helper.php im Hauptprojekt (AES-256-CBC, zufaellige IV vor den
 * Ciphertext gestellt, Base64-kodiert) -- aber eigenstaendig implementiert
 * mit eigenem Key aus connect/.env, keine Code-Abhaengigkeit zur alten Welt
 * (siehe Plan, Abschnitt "Verhaeltnis zur bestehenden Codebasis").
 */
final class Crypto
{
    private const CIPHER = 'aes-256-cbc';

    public function __construct(private readonly string $key)
    {
        if ($key === '') {
            throw new \InvalidArgumentException('Crypto: Verschluesselungs-Key darf nicht leer sein.');
        }
    }

    public function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = openssl_random_pseudo_bytes($ivLength);

        $cipherText = openssl_encrypt($plain, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv);
        if ($cipherText === false) {
            throw new \RuntimeException('Crypto: Verschluesselung fehlgeschlagen.');
        }

        return base64_encode($iv . $cipherText);
    }

    public function decrypt(string $encoded): string
    {
        if ($encoded === '') {
            return '';
        }

        $raw = base64_decode($encoded, true);
        if ($raw === false) {
            throw new \RuntimeException('Crypto: Ungueltig kodierter Ciphertext.');
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = substr($raw, 0, $ivLength);
        $cipherText = substr($raw, $ivLength);

        $plain = openssl_decrypt($cipherText, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            throw new \RuntimeException('Crypto: Entschluesselung fehlgeschlagen (falscher Key oder beschaedigte Daten).');
        }

        return $plain;
    }

    /**
     * Verschluesselt ein Array (z.B. Zugangsdaten) als JSON.
     *
     * @param array<string, mixed> $data
     */
    public function encryptArray(array $data): string
    {
        return $this->encrypt(json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    public function decryptArray(string $encoded): array
    {
        if ($encoded === '') {
            return [];
        }

        $decoded = json_decode($this->decrypt($encoded), true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}

<?php
/**
 * AES-256-CBC encryption for data at rest, extracted from the byte-identical
 * copies in FormFlow Pro and FormFlow Lite.
 *
 * THE CONSTRAINT THAT SHAPES THIS FILE: existing ciphertext must keep
 * decrypting. Stored records were written by the previous implementation, so
 * the key derivation and the wire format are reproduced EXACTLY — same cipher,
 * same 16-byte IV prepended to the ciphertext, same base64 envelope, same
 * substr(...,0,32) key truncation. A "cleaner" derivation here would silently
 * make every stored record unreadable, which is why the test-suite proves
 * round-tripping against a verbatim copy of the legacy algorithm rather than
 * only against itself.
 *
 * Key derivation is a pure function of (constant value, fallback salt) so it is
 * testable without WordPress.
 *
 * @package Peanut\FormCore
 * @since 0.5.0
 */

namespace Peanut\FormCore\Crypto;

if (!defined('ABSPATH')) {
    exit;
}

final class Encryptor
{
    private const METHOD     = 'AES-256-CBC';
    private const IV_LENGTH  = 16;
    private const MIN_KEY_LENGTH = 32;

    private string $key;

    /**
     * @param string $key Derived 32-byte key (see deriveKey()).
     */
    public function __construct(string $key)
    {
        $this->key = $key;
    }

    /**
     * Derive the encryption key exactly as the plugins always have.
     *
     * A configured key is TRUNCATED to 32 bytes (not hashed); only the
     * wp_salt fallback is hashed. Preserved verbatim — changing which branch
     * hashes would invalidate every record already written.
     *
     * @param string|null $constantValue Value of the plugin's *_ENCRYPTION_KEY, or null.
     * @param string      $fallbackSalt  wp_salt('auth') equivalent.
     * @return string 32-byte key.
     */
    public static function deriveKey(?string $constantValue, string $fallbackSalt): string
    {
        if ($constantValue !== null && strlen($constantValue) >= self::MIN_KEY_LENGTH) {
            return substr($constantValue, 0, self::MIN_KEY_LENGTH);
        }

        return substr(hash('sha256', $fallbackSalt), 0, self::MIN_KEY_LENGTH);
    }

    /**
     * Build from a plugin's key constant, falling back to wp_salt('auth').
     *
     * @param string $keyConstant e.g. 'ISF_ENCRYPTION_KEY'.
     */
    public static function fromKeyConstant(string $keyConstant): self
    {
        $configured = defined($keyConstant) ? (string) constant($keyConstant) : null;
        $fallback   = function_exists('wp_salt') ? (string) wp_salt('auth') : '';

        return new self(self::deriveKey($configured, $fallback));
    }

    /**
     * Encrypt a string. Returns '' for empty input (preserved behaviour).
     *
     * @throws \RuntimeException when the cipher fails.
     */
    public function encrypt(string $data): string
    {
        if ($data === '') {
            return '';
        }

        $iv = openssl_random_pseudo_bytes(self::IV_LENGTH);

        $encrypted = openssl_encrypt($data, self::METHOD, $this->key, OPENSSL_RAW_DATA, $iv);

        if ($encrypted === false) {
            throw new \RuntimeException('Encryption failed');
        }

        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt a string. Returns '' on any failure — callers treat empty as
     * "unavailable" rather than surfacing a partial/garbled value.
     */
    public function decrypt(string $data): string
    {
        if ($data === '') {
            return '';
        }

        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            return '';
        }

        $iv        = substr($decoded, 0, self::IV_LENGTH);
        $encrypted = substr($decoded, self::IV_LENGTH);

        if (strlen($iv) !== self::IV_LENGTH) {
            return '';
        }

        $decrypted = openssl_decrypt($encrypted, self::METHOD, $this->key, OPENSSL_RAW_DATA, $iv);

        return $decrypted !== false ? $decrypted : '';
    }

    /**
     * Encrypt an array by JSON-encoding it first.
     */
    public function encryptArray(array $data): string
    {
        return $this->encrypt((string) json_encode($data));
    }

    /**
     * Decrypt to an array; [] when the payload is unreadable or not an array.
     */
    public function decryptArray(string $data): array
    {
        $json = $this->decrypt($data);
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}

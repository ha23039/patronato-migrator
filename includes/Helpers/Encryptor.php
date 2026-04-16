<?php

declare(strict_types=1);

namespace PatronatoMigrator\Helpers;

use RuntimeException;

/**
 * Cifrado simétrico AES-256-CBC para credenciales sensibles persistidas
 * en wp_options. La clave se deriva de las constantes AUTH_KEY y
 * SECURE_AUTH_SALT definidas en wp-config.php; el IV se genera de forma
 * aleatoria en cada operacion y se prepende al texto cifrado antes de
 * codificar en base64.
 */
final class Encryptor
{
    private const CIPHER   = 'aes-256-cbc';
    private const IV_BYTES = 16;

    /**
     * Cifra el texto plano y devuelve la cadena base64 con el IV
     * concatenado al inicio del payload binario.
     *
     * @throws RuntimeException Si las constantes de WP no estan definidas,
     *                          la extension OpenSSL falta o el cifrado falla.
     */
    public static function encrypt(string $plaintext): string
    {
        $key = self::deriveKey();
        $iv  = random_bytes(self::IV_BYTES);

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($ciphertext === false) {
            throw new RuntimeException('No se pudo cifrar el contenido proporcionado.');
        }

        return base64_encode($iv . $ciphertext);
    }

    /**
     * Descifra una cadena producida por self::encrypt() y devuelve el
     * texto plano original.
     *
     * @throws RuntimeException Si el payload es invalido, esta corrupto
     *                          o el descifrado falla.
     */
    public static function decrypt(string $cipherBase64): string
    {
        $payload = base64_decode($cipherBase64, true);

        if ($payload === false) {
            throw new RuntimeException('Payload cifrado invalido: base64 corrupto.');
        }

        if (strlen($payload) <= self::IV_BYTES) {
            throw new RuntimeException('Payload cifrado invalido: longitud insuficiente.');
        }

        $iv         = substr($payload, 0, self::IV_BYTES);
        $ciphertext = substr($payload, self::IV_BYTES);

        $key = self::deriveKey();

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($plaintext === false) {
            throw new RuntimeException('No se pudo descifrar el contenido: clave o datos invalidos.');
        }

        return $plaintext;
    }

    /**
     * Deriva la clave de 32 bytes a partir de AUTH_KEY y SECURE_AUTH_SALT.
     *
     * @throws RuntimeException Si alguna constante no esta definida o si
     *                          la extension OpenSSL no esta disponible.
     */
    private static function deriveKey(): string
    {
        if (!extension_loaded('openssl')) {
            throw new RuntimeException('La extension OpenSSL es requerida para el cifrado.');
        }

        if (!defined('AUTH_KEY') || !defined('SECURE_AUTH_SALT')) {
            throw new RuntimeException(
                'Las constantes AUTH_KEY y SECURE_AUTH_SALT deben estar definidas en wp-config.php.'
            );
        }

        $authKey  = (string) constant('AUTH_KEY');
        $authSalt = (string) constant('SECURE_AUTH_SALT');

        if ($authKey === '' || $authSalt === '') {
            throw new RuntimeException(
                'AUTH_KEY y SECURE_AUTH_SALT no pueden estar vacias.'
            );
        }

        return hash('sha256', $authKey . $authSalt, true);
    }
}

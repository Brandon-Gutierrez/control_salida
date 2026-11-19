<?php

namespace App\Support;

/**
 * Código corto de un dispositivo (p. ej. «K7M4-Q9XB»), pensado para dictarlo
 * por teléfono a Recursos Humanos o a TI.
 *
 * Se deriva del hash del identificador del dispositivo (los primeros 40 bits,
 * en 8 caracteres): no se guarda en ninguna parte, es el mismo antes y después
 * de vincular la cuenta, y el panel lo busca por el prefijo del hash. El
 * identificador real tiene 256 bits aleatorios, así que el código no revela
 * nada utilizable. Usa un alfabeto sin 0, 1, I ni O para evitar confusiones.
 */
final class DeviceCode
{
    private const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const LENGTH = 8;

    /** Código del dispositivo con ese identificador. */
    public static function fromDeviceId(string $deviceId): string
    {
        return self::fromHash(hash('sha256', $deviceId));
    }

    /** Código a partir del hash SHA-256 (hexadecimal) guardado de un dispositivo. */
    public static function fromHash(string $hash): string
    {
        $bits = hexdec(substr($hash, 0, 10));

        $chars = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $chars = self::ALPHABET[$bits & 31].$chars;
            $bits >>= 5;
        }

        return substr($chars, 0, 4).'-'.substr($chars, 4);
    }

    /**
     * Prefijo hexadecimal (10 caracteres) del hash que corresponde a un código
     * escrito por una persona, o null si no es un código válido. Acepta
     * minúsculas, espacios y guion.
     */
    public static function hashPrefix(string $code): ?string
    {
        $clean = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
        if (strlen($clean) !== self::LENGTH) {
            return null;
        }

        $bits = 0;
        foreach (str_split($clean) as $char) {
            $position = strpos(self::ALPHABET, $char);
            if ($position === false) {
                return null;
            }
            $bits = ($bits << 5) | $position;
        }

        return str_pad(dechex($bits), 10, '0', STR_PAD_LEFT);
    }
}

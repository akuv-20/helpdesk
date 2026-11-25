<?php

namespace App\Services\Entra;

/**
 * País de Entra → nombre unificado y zona horaria IANA. Lo usan el login
 * (users.timezone + alta JIT en GLPI) y la importación de usuarios a GLPI, para
 * que ambos apliquen exactamente la misma regla.
 *
 * Entra trae el país "sucio" ("CL", "CHILE", "Unifrutti Chile", "Perú", "Per�"…),
 * por eso primero se normaliza y luego se busca la zona.
 */
class Country
{
    /** País normalizado => zona horaria IANA. Países sin entrada: sin zona. */
    private const TIMEZONES = [
        'Chile' => 'America/Santiago',
        'Perú' => 'America/Lima',
        'South Africa' => 'Africa/Johannesburg',
        'Philippines' => 'Asia/Manila',
        'Japón' => 'Asia/Tokyo',
        'Italia' => 'Europe/Rome',
        'United Arab Emirates' => 'Asia/Dubai',
    ];

    /** Unifica las variantes de país. Los no mapeados se dejan tal cual. */
    public static function normalize(?string $country): string
    {
        $raw = trim((string) $country);
        if ($raw === '') {
            return '';
        }

        // Solo letras A-Z en mayúsculas: "Perú"/"Per�" → "PERU"/"PER".
        $key = preg_replace('/[^A-Z]/', '', strtoupper(strtr($raw, ['ú' => 'u', 'Ú' => 'U', 'é' => 'e', 'á' => 'a', 'í' => 'i', 'ó' => 'o'])));

        return match (true) {
            in_array($key, ['CL', 'CHILE', 'UNIFRUTTICHILE'], true) => 'Chile',
            in_array($key, ['PE', 'PER', 'PERU', 'UNIFRUTTIPERU'], true) => 'Perú',
            in_array($key, ['ZA', 'SOUTHAFRICA'], true) => 'South Africa',
            in_array($key, ['PH', 'PHILIPPINES'], true) => 'Philippines',
            in_array($key, ['IT', 'ITALY', 'ITALIA'], true) => 'Italia',
            in_array($key, ['JP', 'JAPAN'], true) || $raw === '日本' => 'Japón',
            in_array($key, ['AE', 'UAE', 'UNITEDARABEMIRATES'], true) => 'United Arab Emirates',
            default => $raw,
        };
    }

    /** Zona horaria IANA del país (acepta el valor crudo de Entra), o null. */
    public static function timezone(?string $country): ?string
    {
        return self::TIMEZONES[self::normalize($country)] ?? null;
    }
}

<?php

namespace App\Services\Ip;

use App\Models\IpLocationRule;

/**
 * Resuelve la ubicación de GLPI a partir de una IP, según las reglas
 * segmento→ubicación del módulo admin. Solo IPv4. Si varias reglas matchean,
 * gana la más específica (prefijo más largo). Sin match → null.
 */
class IpLocationResolver
{
    /**
     * @return array{id:int, name:?string}|null
     */
    public function resolve(?string $ip): ?array
    {
        if (! $this->isIpv4($ip)) {
            return null;
        }

        $best = null;
        $bestPrefix = -1;

        foreach (IpLocationRule::where('enabled', true)->get() as $rule) {
            $cidr = self::normalizeCidr($rule->cidr);
            if ($cidr === null) {
                continue;
            }

            [$network, $prefix] = explode('/', $cidr);
            if (self::ipInCidr($ip, $network, (int) $prefix) && (int) $prefix > $bestPrefix) {
                $bestPrefix = (int) $prefix;
                $best = ['id' => (int) $rule->locations_id, 'name' => $rule->location_name];
            }
        }

        return $best;
    }

    /**
     * Normaliza la entrada del usuario a un CIDR IPv4 canónico "red/prefijo".
     * Acepta: 192.168.32.0/24 · 192.168.32.x · 192.168.32.* · 192.168.32 ·
     * 192.168 · 192.168.32.5 (→ /32). Devuelve null si no es válido.
     */
    public static function normalizeCidr(?string $input): ?string
    {
        $s = trim((string) $input);
        if ($s === '') {
            return null;
        }

        // Comodines → /24 (el caso típico "192.168.32.x").
        $s = str_replace(['.x', '.X', '.*'], '', $s);

        $prefix = null;
        if (str_contains($s, '/')) {
            [$s, $p] = explode('/', $s, 2);
            if (! is_numeric($p)) {
                return null;
            }
            $prefix = (int) $p;
        }

        $parts = array_values(array_filter(explode('.', $s), fn ($p) => $p !== ''));
        foreach ($parts as $p) {
            if (! ctype_digit($p) || (int) $p > 255) {
                return null;
            }
        }

        // Rellena la red a 4 octetos; el prefijo por defecto deriva de cuántos
        // octetos escribió el usuario (3 → /24, 2 → /16, 1 → /8, 4 → /32).
        $octetCount = count($parts);
        if ($octetCount < 1 || $octetCount > 4) {
            return null;
        }
        $prefix ??= $octetCount * 8;
        if ($prefix < 0 || $prefix > 32) {
            return null;
        }

        $network = implode('.', array_pad($parts, 4, '0'));
        $long = ip2long($network);
        if ($long === false) {
            return null;
        }

        // Alinea la red al prefijo (p.ej. 192.168.32.5/24 → 192.168.32.0/24).
        $mask = $prefix === 0 ? 0 : (-1 << (32 - $prefix)) & 0xFFFFFFFF;
        $network = long2ip($long & $mask);

        return $network.'/'.$prefix;
    }

    private static function ipInCidr(string $ip, string $network, int $prefix): bool
    {
        $ipLong = ip2long($ip);
        $netLong = ip2long($network);
        if ($ipLong === false || $netLong === false) {
            return false;
        }
        if ($prefix === 0) {
            return true;
        }
        $mask = (-1 << (32 - $prefix)) & 0xFFFFFFFF;

        return ($ipLong & $mask) === ($netLong & $mask);
    }

    private function isIpv4(?string $ip): bool
    {
        return $ip !== null && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }
}

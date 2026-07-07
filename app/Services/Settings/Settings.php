<?php

namespace App\Services\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Repositorio simple de ajustes persistidos en BD (key/value), con caché y
 * cifrado en reposo para secretos. Permite configurar la conexión a GLPI
 * desde la interfaz en vez del .env.
 */
class Settings
{
    private const CACHE_KEY = 'app.settings';

    /** Claves cuyo valor se cifra en reposo. */
    public const SECRET_KEYS = [
        'glpi.oauth.client_secret',
        'glpi.oauth.password',
        'glpi.legacy.app_token',
        'glpi.legacy.user_token',
    ];

    /** @return array<string, string|null> */
    public function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            return Setting::all()->mapWithKeys(function (Setting $s) {
                return [$s->key => $this->decode($s)];
            })->all();
        });
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return filled($this->all()[$key] ?? null);
    }

    /**
     * Guarda varios ajustes. Un valor null elimina la clave.
     *
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            if ($value === null || $value === '') {
                Setting::where('key', $key)->delete();

                continue;
            }

            $encrypted = in_array($key, self::SECRET_KEYS, true);

            Setting::updateOrCreate(
                ['key' => $key],
                [
                    'value' => $encrypted ? Crypt::encryptString((string) $value) : (string) $value,
                    'is_encrypted' => $encrypted,
                ],
            );
        }

        Cache::forget(self::CACHE_KEY);
    }

    private function decode(Setting $s): ?string
    {
        if (! $s->is_encrypted) {
            return $s->value;
        }

        try {
            return Crypt::decryptString((string) $s->value);
        } catch (Throwable) {
            return null;
        }
    }
}

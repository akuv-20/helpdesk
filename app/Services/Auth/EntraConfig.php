<?php

namespace App\Services\Auth;

use App\Services\Settings\Settings;

/**
 * Config efectiva de Entra ID (Puerta A) combinando config/services.php (.env)
 * con los ajustes guardados desde la interfaz (BD), que tienen prioridad.
 */
class EntraConfig
{
    public static function resolve(Settings $settings): array
    {
        $cfg = config('services.microsoft');

        $cfg['client_id'] = $settings->get('entra.client_id', $cfg['client_id']);
        $cfg['client_secret'] = $settings->get('entra.client_secret', $cfg['client_secret']);
        $cfg['tenant'] = $settings->get('entra.tenant_id', $cfg['tenant']);
        $cfg['redirect'] = $settings->get('entra.redirect_uri') ?: $cfg['redirect'];

        return $cfg;
    }
}

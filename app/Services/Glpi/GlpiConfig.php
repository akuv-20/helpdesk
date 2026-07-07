<?php

namespace App\Services\Glpi;

use App\Services\Settings\Settings;

/**
 * Construye la configuración efectiva de GLPI combinando los defaults de
 * config/glpi.php (.env) con los ajustes guardados desde la interfaz (BD),
 * que tienen prioridad.
 */
class GlpiConfig
{
    public static function resolve(Settings $settings): array
    {
        $cfg = config('glpi');

        $base = rtrim((string) $settings->get('glpi.base_url', $cfg['base_url']), '/');
        $cfg['base_url'] = $base;
        $cfg['driver'] = $settings->get('glpi.driver', $cfg['driver']);

        $cfg['oauth']['client_id'] = $settings->get('glpi.oauth.client_id', $cfg['oauth']['client_id']);
        $cfg['oauth']['client_secret'] = $settings->get('glpi.oauth.client_secret', $cfg['oauth']['client_secret']);
        $cfg['oauth']['username'] = $settings->get('glpi.oauth.username', $cfg['oauth']['username']);
        $cfg['oauth']['password'] = $settings->get('glpi.oauth.password', $cfg['oauth']['password']);
        $cfg['oauth']['scope'] = $settings->get('glpi.oauth.scope', $cfg['oauth']['scope']);
        $cfg['oauth']['token_url'] = $base.'/api.php/token';
        $cfg['oauth']['api_url'] = $base.'/api.php/v2';

        $cfg['legacy']['app_token'] = $settings->get('glpi.legacy.app_token', $cfg['legacy']['app_token']);
        $cfg['legacy']['user_token'] = $settings->get('glpi.legacy.user_token', $cfg['legacy']['user_token']);
        $cfg['legacy']['api_url'] = $base.'/apirest.php';

        return $cfg;
    }
}

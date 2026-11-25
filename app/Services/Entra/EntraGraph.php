<?php

namespace App\Services\Entra;

use App\Services\Auth\EntraConfig;
use App\Services\Settings\Settings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Acceso a Microsoft Graph con permisos de APLICACIÓN (client_credentials):
 * sirve para consultar CUALQUIER usuario del directorio (no solo el logueado).
 * Exige "User.Read.All" (aplicación) con consentimiento de administrador en el
 * App Registration de Entra. Lo usan el Explorador y la importación a GLPI.
 */
class EntraGraph
{
    public function __construct(private Settings $settings)
    {
    }

    /** ¿Hay Client ID, Client Secret y Tenant para pedir un token de aplicación? */
    public function isConfigured(): bool
    {
        $cfg = EntraConfig::resolve($this->settings);

        return filled($cfg['client_id']) && filled($cfg['client_secret']) && filled($cfg['tenant']);
    }

    /** Token de aplicación (client_credentials) para Graph, cacheado ~50 min. */
    public function appToken(): string
    {
        $cfg = EntraConfig::resolve($this->settings);

        return Cache::remember('entra:graph_app_token', now()->addMinutes(50), function () use ($cfg) {
            $resp = Http::asForm()->timeout(15)->post(
                "https://login.microsoftonline.com/{$cfg['tenant']}/oauth2/v2.0/token",
                [
                    'grant_type' => 'client_credentials',
                    'client_id' => $cfg['client_id'],
                    'client_secret' => $cfg['client_secret'],
                    'scope' => 'https://graph.microsoft.com/.default',
                ]
            );

            if ($resp->failed()) {
                $desc = $resp->json('error_description') ?? $resp->body();

                throw new \RuntimeException('No se pudo obtener token de Graph: '.mb_substr((string) $desc, 0, 300));
            }

            return (string) $resp->json('access_token');
        });
    }

    /** Cliente HTTP autenticado contra https://graph.microsoft.com/v1.0. */
    public function http(int $timeout = 30): PendingRequest
    {
        return Http::withToken($this->appToken())
            ->acceptJson()
            ->timeout($timeout)
            ->baseUrl('https://graph.microsoft.com/v1.0');
    }
}

<?php

namespace App\Services\Glpi;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Flujo OAuth2 authorization_code contra GLPI para obtener un token del PROPIO
 * usuario. Necesario para acciones que GLPI ata a la sesión del usuario (aprobar
 * validaciones): la cuenta de servicio no puede hacerlas en su nombre.
 *
 * El login en GLPI pasa por SAML/Entra, así que para un usuario ya autenticado
 * el rebote es casi transparente (sin pedir credenciales de nuevo).
 */
class GlpiUserOAuth
{
    public function __construct(protected array $config)
    {
    }

    public function isConfigured(): bool
    {
        return filled($this->config['oauth_ac']['client_id'] ?? null)
            && filled($this->config['oauth_ac']['client_secret'] ?? null)
            && filled($this->config['base_url'] ?? null);
    }

    /**
     * Guarda el intent + PKCE/state en sesión y devuelve la URL de autorización
     * a la que redirigir el navegador.
     *
     * @param  array<string, mixed>  $intent  Acción a completar tras el callback.
     */
    public function authorizeUrl(Request $request, string $redirectUri, array $intent): string
    {
        $state = Str::random(40);
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $request->session()->put('glpi_user_oauth', [
            'state' => $state,
            'verifier' => $verifier,
            'redirect_uri' => $redirectUri,
            'intent' => $intent,
        ]);

        return $this->config['oauth']['authorize_url'].'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->config['oauth_ac']['client_id'],
            'redirect_uri' => $redirectUri,
            'scope' => 'api',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * Canjea el code recibido en el callback por el juego de tokens del usuario.
     *
     * @return array{0:array{access:string, refresh:?string, expires_at:\Illuminate\Support\Carbon}, 1:array<string, mixed>}  [tokens, intent]
     */
    public function exchange(Request $request): array
    {
        $ctx = $request->session()->pull('glpi_user_oauth');

        if (! $ctx || ! hash_equals((string) ($ctx['state'] ?? ''), (string) $request->query('state'))) {
            throw new GlpiException('La sesión de autorización expiró o no es válida. Intenta de nuevo.');
        }

        if ($request->filled('error')) {
            throw new GlpiException('GLPI no autorizó la acción ('.$request->query('error').').');
        }

        $resp = Http::asForm()
            ->withOptions(['verify' => $this->config['verify'] ?? true])
            ->timeout($this->config['timeout'] ?? 20)
            ->post($this->config['oauth']['token_url'], [
                'grant_type' => 'authorization_code',
                'client_id' => $this->config['oauth_ac']['client_id'],
                'client_secret' => $this->config['oauth_ac']['client_secret'],
                'redirect_uri' => $ctx['redirect_uri'],
                'code' => (string) $request->query('code'),
                'code_verifier' => $ctx['verifier'],
            ]);

        if ($resp->failed() || blank($resp->json('access_token'))) {
            throw new GlpiException('No se pudo completar la autorización con GLPI.');
        }

        return [$this->normalizeTokens($resp), $ctx['intent'] ?? []];
    }

    /**
     * Renueva el access token con el refresh token (silencioso, sin redirección).
     * Devuelve el nuevo juego de tokens, o null si el refresh ya no sirve (habrá
     * que volver a pasar por el flujo de autorización).
     *
     * @return array{access:string, refresh:?string, expires_at:\Illuminate\Support\Carbon}|null
     */
    public function refresh(string $refreshToken): ?array
    {
        if (blank($refreshToken)) {
            return null;
        }

        $resp = Http::asForm()
            ->withOptions(['verify' => $this->config['verify'] ?? true])
            ->timeout($this->config['timeout'] ?? 20)
            ->post($this->config['oauth']['token_url'], [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => $this->config['oauth_ac']['client_id'],
                'client_secret' => $this->config['oauth_ac']['client_secret'],
                'scope' => 'api',
            ]);

        if ($resp->failed() || blank($resp->json('access_token'))) {
            return null;
        }

        return $this->normalizeTokens($resp);
    }

    /**
     * @return array{access:string, refresh:?string, expires_at:\Illuminate\Support\Carbon}
     */
    protected function normalizeTokens(\Illuminate\Http\Client\Response $resp): array
    {
        return [
            'access' => (string) $resp->json('access_token'),
            'refresh' => $resp->json('refresh_token'),
            'expires_at' => now()->addSeconds((int) ($resp->json('expires_in') ?: 3600)),
        ];
    }
}

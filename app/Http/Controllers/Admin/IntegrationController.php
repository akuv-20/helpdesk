<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Glpi\GlpiClient;
use App\Services\Glpi\GlpiConfig;
use App\Services\Settings\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationController extends Controller
{
    public function __construct(private Settings $settings)
    {
    }

    public function edit(): Response
    {
        return Inertia::render('Admin/Settings/Glpi', [
            // Valores no secretos se muestran; secretos solo como "definido o no".
            'values' => [
                'base_url' => $this->settings->get('glpi.base_url', config('glpi.base_url')),
                'driver' => $this->settings->get('glpi.driver', config('glpi.driver')),
                'oauth_client_id' => $this->settings->get('glpi.oauth.client_id'),
                'oauth_username' => $this->settings->get('glpi.oauth.username'),
                'oauth_scope' => $this->settings->get('glpi.oauth.scope', 'api'),
            ],
            'secretsSet' => [
                'oauth_client_secret' => $this->settings->has('glpi.oauth.client_secret'),
                'oauth_password' => $this->settings->has('glpi.oauth.password'),
                'legacy_app_token' => $this->settings->has('glpi.legacy.app_token'),
                'legacy_user_token' => $this->settings->has('glpi.legacy.user_token'),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $this->validateInput($request);

        $values = [
            'glpi.base_url' => $data['base_url'] ?? null,
            'glpi.driver' => $data['driver'] ?: null,
            'glpi.oauth.client_id' => $data['oauth_client_id'] ?? null,
            'glpi.oauth.username' => $data['oauth_username'] ?? null,
            'glpi.oauth.scope' => $data['oauth_scope'] ?? null,
        ];

        // Secretos: solo se actualizan si se envió un valor (en blanco = conservar).
        foreach ([
            'oauth_client_secret' => 'glpi.oauth.client_secret',
            'oauth_password' => 'glpi.oauth.password',
            'legacy_app_token' => 'glpi.legacy.app_token',
            'legacy_user_token' => 'glpi.legacy.user_token',
        ] as $input => $key) {
            if ($request->filled($input)) {
                $values[$key] = $request->input($input);
            }
        }

        $this->settings->setMany($values);

        return back()->with('success', 'Configuración de GLPI guardada.');
    }

    public function test(Request $request): JsonResponse
    {
        $this->validateInput($request);

        $client = new GlpiClient($this->configFromRequest($request));

        return response()->json($client->ping());
    }

    private function validateInput(Request $request): array
    {
        return $request->validate([
            'base_url' => ['nullable', 'url'],
            'driver' => ['nullable', 'in:oauth,legacy'],
            'oauth_client_id' => ['nullable', 'string'],
            'oauth_client_secret' => ['nullable', 'string'],
            'oauth_username' => ['nullable', 'string'],
            'oauth_password' => ['nullable', 'string'],
            'oauth_scope' => ['nullable', 'string'],
            'legacy_app_token' => ['nullable', 'string'],
            'legacy_user_token' => ['nullable', 'string'],
        ]);
    }

    /** Config efectiva = lo guardado, con override de lo que venga en el request. */
    private function configFromRequest(Request $request): array
    {
        $cfg = GlpiConfig::resolve($this->settings);

        $base = rtrim($request->input('base_url') ?: $cfg['base_url'], '/');
        $cfg['base_url'] = $base;
        $cfg['driver'] = $request->input('driver') ?: $cfg['driver'];

        $cfg['oauth']['client_id'] = $request->input('oauth_client_id') ?: $cfg['oauth']['client_id'];
        $cfg['oauth']['username'] = $request->input('oauth_username') ?: $cfg['oauth']['username'];
        $cfg['oauth']['scope'] = $request->input('oauth_scope') ?: $cfg['oauth']['scope'];
        $cfg['oauth']['token_url'] = $base.'/api.php/token';
        $cfg['oauth']['api_url'] = $base.'/api.php/v2';
        $cfg['legacy']['api_url'] = $base.'/apirest.php';

        // Secretos: usa el enviado, o el guardado si viene en blanco.
        if ($request->filled('oauth_client_secret')) {
            $cfg['oauth']['client_secret'] = $request->input('oauth_client_secret');
        }
        if ($request->filled('oauth_password')) {
            $cfg['oauth']['password'] = $request->input('oauth_password');
        }
        if ($request->filled('legacy_app_token')) {
            $cfg['legacy']['app_token'] = $request->input('legacy_app_token');
        }
        if ($request->filled('legacy_user_token')) {
            $cfg['legacy']['user_token'] = $request->input('legacy_user_token');
        }

        return $cfg;
    }
}

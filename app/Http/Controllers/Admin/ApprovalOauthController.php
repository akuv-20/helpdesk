<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Settings\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mantenedor del 2º cliente OAuth de GLPI: el que se usa en el flujo
 * authorization_code para aprobar/rechazar validaciones como el propio usuario
 * (Puerta C). Distinto del de la cuenta de servicio (password grant) que vive
 * en /admin/conexion. Guarda client_id/secret en la tabla `settings` (secreto
 * cifrado); `GlpiConfig::resolve()` los toma con prioridad sobre el .env.
 */
class ApprovalOauthController extends Controller
{
    public function __construct(private Settings $settings)
    {
    }

    public function edit(): Response
    {
        return Inertia::render('Admin/Settings/ApprovalOauth', [
            'values' => [
                'client_id' => $this->settings->get('glpi.oauth_ac.client_id', config('glpi.oauth_ac.client_id')),
                // Secreto real precargado para poder verlo/copiarlo (admin only).
                'client_secret' => $this->settings->get('glpi.oauth_ac.client_secret', config('glpi.oauth_ac.client_secret')),
            ],
            // URI que debe registrarse EXACTA en el cliente OAuth de GLPI.
            'redirectUri' => route('tickets.validation.callback'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['nullable', 'string'],
            'client_secret' => ['nullable', 'string'],
        ]);

        $values = ['glpi.oauth_ac.client_id' => $data['client_id'] ?? null];

        // El secreto solo se actualiza si se envió (en blanco = conservar).
        if ($request->filled('client_secret')) {
            $values['glpi.oauth_ac.client_secret'] = $request->input('client_secret');
        }

        $this->settings->setMany($values);

        return back()->with('success', 'Configuración de aprobaciones (OAuth) guardada.');
    }
}

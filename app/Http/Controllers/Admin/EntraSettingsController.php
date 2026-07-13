<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Settings\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

class EntraSettingsController extends Controller
{
    public function __construct(private Settings $settings)
    {
    }

    public function edit(): Response
    {
        return Inertia::render('Admin/Settings/Entra', [
            'values' => [
                'client_id' => $this->settings->get('entra.client_id', config('services.microsoft.client_id')),
                'tenant_id' => $this->settings->get('entra.tenant_id', config('services.microsoft.tenant')),
                // Secreto real precargado para poder verlo/copiarlo (admin only).
                'client_secret' => $this->settings->get('entra.client_secret', config('services.microsoft.client_secret')),
                // URI efectiva a registrar en Entra (BD o la derivada de APP_URL).
                'redirect_uri' => $this->settings->get('entra.redirect_uri') ?: config('services.microsoft.redirect'),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $this->validateInput($request);

        $values = [
            'entra.client_id' => $data['client_id'] ?? null,
            'entra.tenant_id' => $data['tenant_id'] ?? null,
            'entra.redirect_uri' => $data['redirect_uri'] ?? null,
        ];

        // El secreto solo se actualiza si se envió (en blanco = conservar).
        if ($request->filled('client_secret')) {
            $values['entra.client_secret'] = $request->input('client_secret');
        }

        $this->settings->setMany($values);

        return back()->with('success', 'Configuración de Entra ID guardada.');
    }

    /** Valida el tenant consultando el OIDC discovery de Microsoft. */
    public function test(Request $request): JsonResponse
    {
        $this->validateInput($request);

        $tenant = $request->input('tenant_id')
            ?: $this->settings->get('entra.tenant_id', config('services.microsoft.tenant'));

        if (blank($tenant)) {
            return response()->json(['ok' => false, 'message' => 'Falta el Tenant ID.']);
        }

        try {
            $resp = Http::timeout(10)
                ->get("https://login.microsoftonline.com/{$tenant}/v2.0/.well-known/openid-configuration");

            if ($resp->successful() && $resp->json('issuer')) {
                return response()->json(['ok' => true, 'message' => 'Tenant válido. Issuer: '.$resp->json('issuer')]);
            }

            return response()->json(['ok' => false, 'message' => 'El tenant respondió '.$resp->status().'.']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'No se pudo validar el tenant: '.$e->getMessage()]);
        }
    }

    private function validateInput(Request $request): array
    {
        return $request->validate([
            'client_id' => ['nullable', 'string'],
            'client_secret' => ['nullable', 'string'],
            'tenant_id' => ['nullable', 'string'],
            'redirect_uri' => ['nullable', 'url'],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Auth\EntraConfig;
use App\Services\Settings\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\Request;

/**
 * Herramienta de diagnóstico (solo admin): consulta Microsoft Graph por un
 * usuario y muestra el objeto crudo que devuelve, para ver qué campos existen
 * y decidir cuáles agregar a la config de Entra (login delegado).
 *
 * A diferencia del login (que trae solo los datos del usuario autenticado vía
 * flujo delegado /me), aquí consultamos CUALQUIER usuario por su UPN/correo, lo
 * que exige el permiso de APLICACIÓN "User.Read.All" (client credentials) con
 * consentimiento de administrador en el App Registration de Entra.
 */
class EntraExplorerController extends Controller
{
    /** Set amplio de propiedades estándar, precargado para explorar. */
    private const SUGGESTED_SELECT = 'displayName,givenName,surname,userPrincipalName,mail,id,jobTitle,department,companyName,officeLocation,city,state,country,usageLocation,preferredLanguage,mobilePhone,businessPhones,employeeId,employeeType,streetAddress,postalCode,accountEnabled,userType,onPremisesSamAccountName,createdDateTime';

    public function __construct(private Settings $settings)
    {
    }

    public function show(): Response
    {
        return Inertia::render('Admin/Entra/Explorer', [
            'suggestedSelect' => self::SUGGESTED_SELECT,
        ]);
    }

    /**
     * Consulta un usuario en Graph y devuelve el JSON tal cual (o el error de
     * Graph, que también es informativo para depurar permisos).
     */
    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'upn' => ['required', 'string', 'max:255'],
            // Lista de campos $select opcional; en blanco = propiedades por defecto de Graph.
            'select' => ['nullable', 'string', 'max:4000'],
        ]);

        $cfg = EntraConfig::resolve($this->settings);

        if (blank($cfg['client_id']) || blank($cfg['client_secret']) || blank($cfg['tenant'])) {
            return response()->json([
                'ok' => false,
                'message' => 'Faltan Client ID, Client Secret o Tenant en la configuración de Entra (/admin/acceso).',
            ]);
        }

        try {
            $token = $this->graphAppToken($cfg);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()]);
        }

        $upn = trim($data['upn']);
        $select = $this->normalizeSelect($data['select'] ?? null);

        $query = [];
        if ($select !== '') {
            $query['$select'] = $select;
        }

        $resp = Http::withToken($token)
            ->acceptJson()
            ->timeout(15)
            ->get('https://graph.microsoft.com/v1.0/users/'.rawurlencode($upn), $query);

        return response()->json([
            'ok' => $resp->successful(),
            'status' => $resp->status(),
            'requestedSelect' => $select,
            'data' => $resp->json(),
        ]);
    }

    /** Token de aplicación (client_credentials) para Graph, cacheado ~50 min. */
    private function graphAppToken(array $cfg): string
    {
        return \Illuminate\Support\Facades\Cache::remember('entra:graph_app_token', now()->addMinutes(50), function () use ($cfg) {
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

    /**
     * Limpia la lista de $select: separa por comas/espacios/saltos, deja solo
     * nombres de propiedad válidos y los une con comas.
     */
    private function normalizeSelect(?string $select): string
    {
        if (blank($select)) {
            return '';
        }

        $fields = preg_split('/[\s,]+/', $select, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // Nombres de propiedad de Graph: letras/números (evita inyección en la query).
        $fields = array_filter($fields, fn ($f) => preg_match('/^[A-Za-z0-9_]+$/', $f));

        return implode(',', array_unique($fields));
    }
}

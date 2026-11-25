<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Entra\EntraGraph;
use Illuminate\Http\JsonResponse;
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

    public function __construct(private EntraGraph $graph)
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

        if (! $this->graph->isConfigured()) {
            return response()->json([
                'ok' => false,
                'message' => 'Faltan Client ID, Client Secret o Tenant en la configuración de Entra (/admin/acceso).',
            ]);
        }

        try {
            $http = $this->graph->http(15);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()]);
        }

        $upn = trim($data['upn']);
        $select = $this->normalizeSelect($data['select'] ?? null);

        $query = [];
        if ($select !== '') {
            $query['$select'] = $select;
        }

        $resp = $http->get('/users/'.rawurlencode($upn), $query);

        return response()->json([
            'ok' => $resp->successful(),
            'status' => $resp->status(),
            'requestedSelect' => $select,
            'data' => $resp->json(),
        ]);
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

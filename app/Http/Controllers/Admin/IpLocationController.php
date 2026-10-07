<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IpLocationRule;
use App\Services\Glpi\GlpiClient;
use App\Services\Ip\IpLocationResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mantenedor de reglas segmento de red (CIDR) → ubicación de GLPI. Al crear un
 * ticket, el portal usa estas reglas para asignar la ubicación según la IP.
 */
class IpLocationController extends Controller
{
    public function index(GlpiClient $glpi): Response
    {
        Cache::forget('glpi:locations'); // mostrar siempre el estado actual de GLPI
        $locations = collect($glpi->locations())->keyBy('id');

        // Refresca el nombre mostrado con el completename ACTUAL de GLPI (por si
        // cambió la jerarquía desde que se creó la regla); cae al cache si el id
        // ya no está en la lista. No persiste: es solo para mostrar.
        $rules = IpLocationRule::orderBy('cidr')->get()->map(function (IpLocationRule $r) use ($locations) {
            $r->location_name = $locations[$r->locations_id]['name'] ?? $r->location_name;

            return $r;
        });

        return Inertia::render('Admin/IpLocations/Index', [
            'rules' => $rules,
            'locations' => $locations->values()->all(),
            'glpiConfigured' => $glpi->isConfigured(),
        ]);
    }

    public function store(Request $request, GlpiClient $glpi): RedirectResponse
    {
        $data = $this->validated($request);

        IpLocationRule::create([
            'cidr' => $data['cidr'],
            'locations_id' => $data['locations_id'],
            'location_name' => $glpi->locationName($data['locations_id']),
            'label' => $data['label'],
            'enabled' => $data['enabled'],
        ]);

        return back()->with('success', 'Regla agregada.');
    }

    public function update(Request $request, IpLocationRule $rule, GlpiClient $glpi): RedirectResponse
    {
        $data = $this->validated($request, $rule->id);

        $rule->update([
            'cidr' => $data['cidr'],
            'locations_id' => $data['locations_id'],
            'location_name' => $glpi->locationName($data['locations_id']),
            'label' => $data['label'],
            'enabled' => $data['enabled'],
        ]);

        return back()->with('success', 'Regla actualizada.');
    }

    public function destroy(IpLocationRule $rule): RedirectResponse
    {
        $rule->delete();

        return back()->with('success', 'Regla eliminada.');
    }

    /** Prueba: dada una IP, devuelve qué ubicación resolvería (AJAX). */
    public function test(Request $request, IpLocationResolver $resolver): array
    {
        $ip = trim((string) $request->input('ip'));
        $match = $resolver->resolve($ip);

        return [
            'ip' => $ip,
            'matched' => $match !== null,
            'location' => $match['name'] ?? null,
            'locations_id' => $match['id'] ?? null,
        ];
    }

    /**
     * Valida y normaliza el CIDR. $ignoreId permite reusar en update sin chocar
     * con la propia fila en el unique.
     *
     * @return array{cidr:string, locations_id:int, label:?string, enabled:bool}
     */
    protected function validated(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'cidr' => ['required', 'string', 'max:64'],
            'locations_id' => ['required', 'integer', 'min:1'],
            'label' => ['nullable', 'string', 'max:255'],
            'enabled' => ['boolean'],
        ]);

        $normalized = IpLocationResolver::normalizeCidr($data['cidr']);
        if ($normalized === null) {
            throw ValidationException::withMessages([
                'cidr' => 'Segmento inválido. Usa por ejemplo 192.168.32.0/24 o 192.168.32.',
            ]);
        }

        $exists = IpLocationRule::where('cidr', $normalized)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages([
                'cidr' => "Ya existe una regla para el segmento {$normalized}.",
            ]);
        }

        return [
            'cidr' => $normalized,
            'locations_id' => (int) $data['locations_id'],
            'label' => $data['label'] ?? null,
            'enabled' => (bool) ($data['enabled'] ?? true),
        ];
    }
}

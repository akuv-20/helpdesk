<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Glpi\GlpiClient;
use App\Services\Glpi\GlpiException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vista de solo lectura (admin) del árbol de categorías ITIL, tal como lo ve el
 * usuario en el wizard de creación pero desplegado por completo y de una sola
 * vez —para revisar de un vistazo qué categorías están dadas de alta en GLPI—
 * en lugar de tener que recorrerlo paso a paso.
 *
 * Reutiliza `GlpiClient::categoriesByType()` (mismo árbol del wizard, con el
 * nivel 2 "Incidente/Solicitud" ya usado como filtro y removido de la ruta).
 */
class CategoryTreeController extends Controller
{
    public function show(GlpiClient $glpi): Response
    {
        // El wizard cachea las ITILCategory 30 min; aquí forzamos un refresco
        // para que el admin siempre vea el estado actual de GLPI (lo recién
        // agregado). categoriesByType() volverá a poblar la caché.
        Cache::forget('glpi:itilcategories');

        return Inertia::render('Admin/Categories/Tree', [
            'glpiConfigured' => $glpi->isConfigured(),
            'trees' => [
                'incident' => $glpi->categoriesByType('incident'),
                'request' => $glpi->categoriesByType('request'),
            ],
        ]);
    }

    /**
     * Crea una subcategoría bajo un nodo existente (el "+" del árbol). El
     * parent_id es el id GLPI del nodo donde se pulsó "+"; GLPI la anida en la
     * rama correcta. Escribe en el GLPI compartido (lo ven también los técnicos).
     */
    public function store(Request $request, GlpiClient $glpi): RedirectResponse
    {
        $data = $request->validate([
            'path' => ['required', 'array', 'min:1'],
            'path.*' => ['required', 'string', 'max:255'],
            'branch' => ['required', 'in:incident,request'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        try {
            $glpi->createCategory($data['path'], $data['branch'], $data['name']);
        } catch (GlpiException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Categoría creada en GLPI.');
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Entra\EntraGraph;
use App\Services\Entra\EntraUserImport;
use App\Services\Glpi\GlpiClient;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Importación de usuarios Entra ID → GLPI (de a uno o masiva, por filtros).
 * La página carga el directorio por AJAX (tarda ~12 s la primera vez) y la
 * importación masiva la conduce el navegador en lotes (sin colas/worker).
 */
class EntraImportController extends Controller
{
    /** Usuarios por lote en /importar (cada uno son varias llamadas a GLPI). */
    public const BATCH = 20;

    public function __construct(private EntraUserImport $import, private EntraGraph $graph, private GlpiClient $glpi)
    {
    }

    public function index(): Response
    {
        return Inertia::render('Admin/EntraImport/Index', [
            'graphConfigured' => $this->graph->isConfigured(),
            'glpiWritable' => $this->glpi->canWriteUsers(),
            'batchSize' => self::BATCH,
        ]);
    }

    /**
     * Directorio filtrado + estado en GLPI, paginado. Devuelve además los ids de
     * TODOS los que coinciden (para "importar todos los filtrados").
     */
    public function directory(Request $request): JsonResponse
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'country' => ['nullable', 'string', 'max:100'],
            'domain' => ['nullable', 'string', 'max:200'],
            'department' => ['nullable', 'string', 'max:200'],
            'glpi_status' => ['nullable', 'in:new,exists,disabled'],
            'only_enabled' => ['boolean'],
            'only_named' => ['boolean'],
            'only_mail' => ['boolean'],
            'page' => ['integer', 'min:1'],
            'refresh' => ['boolean'],
        ]);

        if (! $this->graph->isConfigured() || ! $this->glpi->canWriteUsers()) {
            return response()->json(['ok' => false, 'message' => 'Falta configurar Entra (/admin/acceso) o los tokens legacy de GLPI (/admin/conexion).']);
        }

        try {
            $dir = $this->import->directory((bool) ($f['refresh'] ?? false));
            // Primero se filtra sin estado GLPI (barato) y luego se cruza solo lo filtrado.
            $pre = $this->import->filter($dir['users'], array_merge($f, ['glpi_status' => null]));
            $statuses = $this->import->statuses($pre);
            $rows = $this->import->filter($pre, $f, array_map(fn ($s) => $s['status'], $statuses));
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'message' => $e->getMessage()]);
        }

        $perPage = 50;
        $page = max(1, (int) ($f['page'] ?? 1));
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return response()->json([
            'ok' => true,
            'fetched_at' => Carbon::parse($dir['fetched_at'])->timezone($request->user()->timezone ?: config('app.timezone'))->format('d-m-Y H:i'),
            'directory_total' => count($dir['users']),
            'facets' => $this->import->facets($dir['users']),
            'total' => count($rows),
            'page' => $page,
            'last_page' => max(1, (int) ceil(count($rows) / $perPage)),
            'counts' => array_count_values(array_map(fn ($u) => $statuses[$u['id']]['status'], $rows)),
            'ids' => array_column($rows, 'id'),
            'rows' => array_map(fn ($u) => [
                'id' => $u['id'],
                'name' => $u['name'],
                'email' => $u['email'],
                'upn' => $u['upn'] !== $u['email'] ? $u['upn'] : null,
                'job_title' => $u['job_title'],
                'department' => $u['department'],
                'country' => $u['country'],
                'enabled' => $u['enabled'],
                'glpi_status' => $statuses[$u['id']]['status'],
                'glpi_id' => $statuses[$u['id']]['glpi_id'],
            ], $slice),
        ]);
    }

    /** Importa (o simula) un lote de usuarios de Entra. */
    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.self::BATCH],
            'ids.*' => ['string', 'max:64'],
            'dry_run' => ['boolean'],
            'update_existing' => ['boolean'],
        ]);

        if (! $this->glpi->canWriteUsers()) {
            return response()->json(['ok' => false, 'message' => 'Faltan los tokens legacy de GLPI en /admin/conexion.'], 422);
        }

        return response()->json([
            'ok' => true,
            'results' => $this->import->import(
                $data['ids'],
                (bool) ($data['dry_run'] ?? false),
                (bool) ($data['update_existing'] ?? true),
            ),
        ]);
    }
}

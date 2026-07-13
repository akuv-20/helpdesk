<?php

namespace App\Http\Controllers;

use App\Services\Glpi\GlpiClient;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, GlpiClient $glpi): Response
    {
        $email = $request->user()->email;

        $q = $request->query('q');
        $status = $request->query('status');
        $page = (int) $request->query('page', 1);

        // Paginación + búsqueda + filtro resueltos en el servidor (escala).
        $result = $glpi->ticketsForRequesterPaged($email, $page, 20, $q ?: null, $status ?: null);

        return Inertia::render('Dashboard', [
            'tickets' => $result['data'],
            'pagination' => [
                'total' => $result['total'],
                'page' => $result['page'],
                'per_page' => $result['per_page'],
                'last_page' => $result['last_page'],
            ],
            'filters' => ['q' => $q ?? '', 'status' => $status ?: 'all'],
            // Aprobaciones (validaciones) que el usuario tiene pendientes de responder.
            'pendingApprovals' => $glpi->pendingApprovalsForUser($email),
            'glpiConfigured' => $glpi->isConfigured(),
        ]);
    }
}

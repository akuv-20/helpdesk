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
        $tickets = $glpi->ticketsForRequester($request->user()->email);

        return Inertia::render('Dashboard', [
            'tickets' => $tickets,
            'glpiConfigured' => $glpi->isConfigured(),
        ]);
    }
}

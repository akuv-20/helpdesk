<?php

namespace App\Http\Controllers;

use App\Services\Glpi\GlpiClient;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Hub de aprobaciones (validaciones) del usuario: las pendientes de responder
 * y el historial de las que ya respondió.
 */
class ApprovalController extends Controller
{
    public function __invoke(Request $request, GlpiClient $glpi): Response
    {
        $email = $request->user()->email;

        return Inertia::render('Approvals/Index', [
            'pending' => $glpi->pendingApprovalsForUser($email),
            'responded' => $glpi->respondedApprovalsForUser($email),
            'glpiConfigured' => $glpi->isConfigured(),
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\Glpi\GlpiClient;
use App\Services\Glpi\GlpiException;
use App\Services\Glpi\GlpiUserOAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TicketController extends Controller
{
    public function create(GlpiClient $glpi): Response
    {
        return Inertia::render('Tickets/Create', [
            'glpiConfigured' => $glpi->isConfigured(),
            'types' => [
                ['value' => 'incident', 'label' => 'Incidente', 'hint' => 'Algo que dejó de funcionar.'],
                ['value' => 'request', 'label' => 'Solicitud', 'hint' => 'Necesitas algo nuevo o un acceso.'],
            ],
        ]);
    }

    /**
     * Devuelve las categorías ITIL agrupadas por Área para el tipo elegido.
     * El nivel "Incidente/Solicitud" del árbol se usa como filtro y no se
     * expone: solo viajan las categorías reales (hojas) de esa rama.
     */
    public function categories(Request $request, GlpiClient $glpi): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:incident,request'],
        ]);

        return response()->json([
            'areas' => $glpi->categoriesByType($data['type']),
        ]);
    }

    public function show(int $id, Request $request, GlpiClient $glpi): Response
    {
        $ticket = $glpi->ticketDetail($id, $request->user()->email);

        // 404 (no 403) si no existe o no es suyo: no revela tickets ajenos.
        abort_if($ticket === null, 404);

        return Inertia::render('Tickets/Show', ['ticket' => $ticket]);
    }

    public function download(int $id, int $docId, Request $request, GlpiClient $glpi): \Illuminate\Http\Response
    {
        $file = $glpi->downloadDocument($id, $docId, $request->user()->email);

        abort_if($file === null, 404);

        // ?view=1 (imágenes inline) → mostrar en el navegador; si no, descargar.
        $disposition = $request->boolean('view') ? 'inline' : 'attachment';

        return response($file['content'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => $disposition.'; filename="'.addslashes($file['name']).'"',
        ]);
    }

    public function reply(int $id, Request $request, GlpiClient $glpi): RedirectResponse
    {
        $data = $request->validate([
            'content' => ['nullable', 'string', 'max:50000'],
            'attachments' => ['array', 'max:5'],
            'attachments.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,txt,csv,zip'],
            'inline_images' => ['array', 'max:10'],
            'inline_images.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,gif'],
        ]);

        $hasText = filled(trim(strip_tags((string) ($data['content'] ?? ''))));
        if (! $hasText && ! $request->hasFile('attachments') && ! $request->hasFile('inline_images')) {
            return back()->with('error', 'Escribe una respuesta o adjunta un archivo.');
        }

        try {
            $glpi->replyToTicket(
                $id,
                $request->user()->email,
                $data['content'] ?? null,
                $request->file('inline_images', []),
                $request->file('attachments', []),
            );
        } catch (GlpiException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Tu respuesta fue enviada.');
    }

    public function solution(int $id, Request $request, GlpiClient $glpi): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:approve,reject'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($data['action'] === 'reject' && blank($data['comment'] ?? null)) {
            return back()->with('error', 'Indica un motivo para rechazar la solución.');
        }

        try {
            $glpi->respondSolution($id, $request->user()->email, $data['action'], $data['comment'] ?? null);
        } catch (GlpiException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $data['action'] === 'approve'
            ? 'Solución aprobada. El ticket se cerró.'
            : 'Solución rechazada. El ticket se reabrió para seguir atendiéndolo.');
    }

    /**
     * Inicia la respuesta a una validación. Como GLPI solo permite que el
     * validador responda desde SU sesión, redirigimos al usuario por OAuth
     * (authorization_code) para obtener su token; el callback completa la acción.
     */
    public function validation(int $id, Request $request, GlpiUserOAuth $oauth, GlpiClient $glpi)
    {
        $data = $request->validate([
            'action' => ['required', 'in:approve,reject'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($data['action'] === 'reject' && blank($data['comment'] ?? null)) {
            return back()->with('error', 'Indica un motivo para rechazar la aprobación.');
        }

        if (! $oauth->isConfigured()) {
            return back()->with('error', 'La aprobación desde el portal no está configurada todavía.');
        }

        // Si ya tenemos un token válido del usuario (o lo podemos renovar con el
        // refresh), respondemos directo, SIN volver a pasar por GLPI. Solo la
        // primera vez (o si el refresh caducó) redirigimos para autorizar.
        if ($token = $this->currentGlpiToken($request->user(), $oauth)) {
            try {
                $glpi->respondValidationWithToken($token, $id, $data['action'], $data['comment'] ?? null);
            } catch (GlpiException $e) {
                return back()->with('error', $e->getMessage());
            }

            GlpiClient::forgetPendingApprovalsCount($request->user()->id);

            return back()->with('success', $this->validationSuccessMessage($data['action']));
        }

        $url = $oauth->authorizeUrl($request, route('tickets.validation.callback'), [
            'ticket' => $id,
            'action' => $data['action'],
            'comment' => $data['comment'] ?? null,
        ]);

        // Inertia hace una redirección de página completa a GLPI (rebote fluido).
        return Inertia::location($url);
    }

    /**
     * Callback del OAuth de GLPI: canjea el token del usuario, lo guarda para
     * reusarlo, y aplica la aprobación/rechazo actuando como él.
     */
    public function validationCallback(Request $request, GlpiUserOAuth $oauth, GlpiClient $glpi): RedirectResponse
    {
        $intent = [];

        try {
            [$tokens, $intent] = $oauth->exchange($request);
            $request->user()->storeGlpiTokens($tokens);

            $ticketId = (int) ($intent['ticket'] ?? 0);
            $glpi->respondValidationWithToken($tokens['access'], $ticketId, $intent['action'] ?? '', $intent['comment'] ?? null);
            GlpiClient::forgetPendingApprovalsCount($request->user()->id);
        } catch (GlpiException $e) {
            $ticketId = $intent['ticket'] ?? null;

            return redirect($ticketId ? "/tickets/{$ticketId}" : route('dashboard'))
                ->with('error', $e->getMessage());
        }

        return redirect("/tickets/{$ticketId}")->with('success', $this->validationSuccessMessage($intent['action'] ?? ''));
    }

    /**
     * Access token de GLPI vigente para el usuario: el guardado si sirve, o uno
     * renovado con el refresh token. null si no hay forma sin re-autorizar.
     */
    private function currentGlpiToken(\App\Models\User $user, GlpiUserOAuth $oauth): ?string
    {
        if ($user->hasValidGlpiToken()) {
            return $user->glpi_access_token;
        }

        if (filled($user->glpi_refresh_token) && ($tokens = $oauth->refresh($user->glpi_refresh_token))) {
            $user->storeGlpiTokens($tokens);

            return $tokens['access'];
        }

        return null;
    }

    private function validationSuccessMessage(string $action): string
    {
        return $action === 'approve'
            ? 'Aprobación registrada. Gracias por responder.'
            : 'Rechazo registrado. El equipo será notificado.';
    }

    public function store(Request $request, GlpiClient $glpi): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:incident,request'],
            'itil_category_id' => ['required', 'integer'],
            'subject' => ['required', 'string', 'min:5', 'max:255'],
            'description' => ['required', 'string', 'max:50000'],
            'attachments' => ['array', 'max:5'],
            'attachments.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,txt,csv,zip'],
            'inline_images' => ['array', 'max:10'],
            'inline_images.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,gif'],
        ]);

        try {
            $ticket = $glpi->createTicket([
                'title' => $data['subject'],
                'content' => $data['description'],
                'type' => $data['type'],
                'category_id' => $data['itil_category_id'],
                'requester_name' => $request->user()->name,
                'requester_timezone' => $request->user()->timezone,
                'inline_images' => $request->file('inline_images', []),
                'attachments' => $request->file('attachments', []),
            ], $request->user()->email);
        } catch (GlpiException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        $number = $ticket['id'] ?? null;

        // Si GLPI devolvió el número, lo pasamos para mostrar el modal de
        // confirmación en el dashboard. Si no (caso raro), mensaje simple.
        return $number
            ? redirect()->route('dashboard')->with('createdTicket', $number)
            : redirect()->route('dashboard')->with('success', 'Tu solicitud fue creada. Te avisaremos cuando haya novedades.');
    }
}

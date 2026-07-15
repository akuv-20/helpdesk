<?php

namespace App\Services\Glpi;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente del backend (BFF) contra la API de GLPI 11.
 *
 * Sólo el servidor usa esta clase. Soporta el driver "oauth" (API v2) y
 * "legacy" (apirest.php). El modelo es de cuenta de servicio + impersonación:
 * el backend autentica con UNA credencial y fija el solicitante por email.
 *
 * NOTA: los nombres exactos de recursos/campos de la API v2 deben confirmarse
 * contra el Swagger de la instancia (api.php/v2.3/doc). Donde haya incertidumbre
 * lo dejo marcado con // TODO[api].
 */
class GlpiClient
{
    public function __construct(
        protected array $config
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(config('glpi'));
    }

    /** ¿Hay un driver y credenciales mínimas configuradas? */
    public function isConfigured(): bool
    {
        $driver = $this->config['driver'] ?? null;

        return match ($driver) {
            'oauth' => filled($this->config['oauth']['client_id'] ?? null)
                && filled($this->config['oauth']['client_secret'] ?? null)
                && filled($this->config['oauth']['username'] ?? null)
                && filled($this->config['oauth']['password'] ?? null)
                && filled($this->config['base_url'] ?? null),
            'legacy' => filled($this->config['legacy']['app_token'] ?? null)
                && filled($this->config['legacy']['user_token'] ?? null)
                && filled($this->config['base_url'] ?? null),
            default => false,
        };
    }

    /**
     * Prueba la conexión con GLPI usando el driver configurado.
     *
     * @return array{ok:bool, message:string}
     */
    public function ping(): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'message' => 'Faltan datos para el driver seleccionado.'];
        }

        // Forzamos credenciales frescas (no usar token/sesión cacheados).
        Cache::forget('glpi:oauth_token');
        Cache::forget('glpi:session_token');

        try {
            if ($this->driver() === 'oauth') {
                $this->oauthToken();
                // Chequeo con un recurso que el scope "api" sí permite (User/Me
                // exige el scope "user"). Un 2xx (incluye 206 parcial) = OK.
                $response = $this->oauthHttp()->get('/Administration/User', ['limit' => 1]);

                if ($response->successful()) {
                    return ['ok' => true, 'message' => 'Conexión OAuth correcta. Token válido y API accesible.'];
                }

                return ['ok' => false, 'message' => 'Token obtenido, pero la API respondió '.$response->status().'.'];
            }

            $this->legacySessionToken();
            $response = $this->legacyHttp()->get('/getActiveProfile');

            return $response->successful()
                ? ['ok' => true, 'message' => 'Conexión legacy correcta.']
                : ['ok' => false, 'message' => 'Sesión iniciada, pero la API respondió '.$response->status().'.'];
        } catch (GlpiException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Error de conexión: '.$e->getMessage()];
        }
    }

    /* ===================================================================
     |  Operaciones de alto nivel usadas por los controladores
     * =================================================================== */

    /** ¿Hay App-Token + User-Token legacy configurados? */
    protected function hasLegacyTokens(): bool
    {
        return filled($this->config['legacy']['app_token'] ?? null)
            && filled($this->config['legacy']['user_token'] ?? null);
    }

    /**
     * Listado paginado de "mis solicitudes" con búsqueda y filtro de estado,
     * resuelto en el SERVIDOR cuando hay tokens legacy (escala a miles). Si no,
     * cae a oauth (baja hasta 200 y filtra/pagina en memoria).
     *
     * @return array{data:array<int,array<string,mixed>>, total:int, page:int, per_page:int, last_page:int}
     */
    public function ticketsForRequesterPaged(string $email, int $page = 1, int $perPage = 20, ?string $q = null, ?string $statusFilter = null): array
    {
        $page = max(1, $page);
        $empty = ['data' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'last_page' => 1];

        if (! $this->isConfigured()) {
            return $empty;
        }

        $userId = $this->findUserId($email);
        if ($userId === null) {
            return $empty;
        }

        return $this->hasLegacyTokens()
            ? $this->legacyTicketsPage($userId, $page, $perPage, $q, $statusFilter)
            : $this->oauthTicketsPage($userId, $page, $perPage, $q, $statusFilter);
    }

    /** Valores de estado GLPI (field 12) según el filtro del front. */
    protected function statusFilterValues(?string $filter): array
    {
        return match ($filter) {
            '1' => [1],       // Nuevos
            'curso' => [2, 3], // En curso (asignado/planificado)
            '4' => [4],       // En espera
            '5' => [5],       // Resueltos
            '6' => [6],       // Cerrados
            default => [],
        };
    }

    /** Página de tickets por el search legacy (filtra por solicitante en el servidor). */
    protected function legacyTicketsPage(int $userId, int $page, int $perPage, ?string $q, ?string $statusFilter): array
    {
        $criteria = [['field' => 4, 'searchtype' => 'equals', 'value' => $userId]];

        // Filtro de estado (agrupado con OR si son varios, p. ej. "en curso").
        $statuses = $this->statusFilterValues($statusFilter);
        if ($statuses) {
            $group = [];
            foreach ($statuses as $i => $st) {
                $group[] = $i === 0
                    ? ['field' => 12, 'searchtype' => 'equals', 'value' => $st]
                    : ['link' => 'OR', 'field' => 12, 'searchtype' => 'equals', 'value' => $st];
            }
            $criteria[] = ['link' => 'AND', 'criteria' => $group];
        }

        // Búsqueda: título (1) O descripción (21) contienen el texto.
        if (filled($q)) {
            $criteria[] = ['link' => 'AND', 'criteria' => [
                ['field' => 1, 'searchtype' => 'contains', 'value' => $q],
                ['link' => 'OR', 'field' => 21, 'searchtype' => 'contains', 'value' => $q],
            ]];
        }

        $start = ($page - 1) * $perPage;
        $response = $this->legacyHttp()->get('/search/Ticket', [
            'criteria' => $criteria,
            'forcedisplay' => [2, 1, 12, 15, 19], // + última actualización (19)
            'sort' => 19, // orden por última actualización
            'order' => 'DESC',
            'range' => $start.'-'.($start + $perPage - 1),
        ]);

        $total = (int) ($response->json('totalcount') ?? 0);

        return [
            'data' => $this->normalizeTickets($response->json('data') ?? []),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /** Página de tickets vía oauth (baja hasta 200 y filtra/pagina en memoria). */
    protected function oauthTicketsPage(int $userId, int $page, int $perPage, ?string $q, ?string $statusFilter): array
    {
        $all = collect($this->oauthTicketsForUser($userId));

        $statuses = $this->statusFilterValues($statusFilter);
        if ($statuses) {
            $all = $all->filter(fn ($t) => in_array((int) ($t['status'] ?? 0), $statuses, true));
        }
        if (filled($q)) {
            $needle = mb_strtolower($q);
            $all = $all->filter(fn ($t) => str_contains($t['search'] ?? '', $needle));
        }

        $total = $all->count();

        return [
            'data' => $all->slice(($page - 1) * $perPage, $perPage)->values()->all(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /**
     * Crea un ticket fijando como solicitante a la persona autenticada.
     *
     * @param  array{title:string, content:string, type?:string, urgency?:int, category_id?:int}  $data
     */
    public function createTicket(array $data, string $requesterEmail): array
    {
        if (! $this->isConfigured()) {
            throw new GlpiException('La conexión con GLPI no está configurada todavía.');
        }
        $this->requireLegacy();

        // GLPI crea a sus usuarios en el primer login SAML; como el portal entra
        // por Entra (no por GLPI), el solicitante puede no existir aún. Lo damos
        // de alta al vuelo (provisioning JIT) para no romper la creación.
        $userId = $this->ensureUser($requesterEmail, $data['requester_name'] ?? null, $data['requester_timezone'] ?? null);
        if ($userId === null) {
            throw new GlpiException("No se pudo resolver ni crear el usuario {$requesterEmail} en GLPI.");
        }

        // Contenido con imágenes inline (marcadores #INLINE_N# → tags de GLPI).
        [$content, $tags, $inlineFiles] = $this->prepareInlineContent($data['content'] ?? '', $data['inline_images'] ?? []);

        $input = [
            'name' => $data['title'],
            'content' => $content,
            'type' => $this->glpiType($data['type'] ?? null),
            'urgency' => 1,
            'impact' => 1,
            'itilcategories_id' => (int) ($data['category_id'] ?? 0),
            '_users_id_requester' => $userId,
            // "Abierto por"/Redactor = el solicitante, no la cuenta de servicio.
            // GLPI respeta users_id_recipient si viene explícito (solo lo
            // autocompleta con el usuario del token cuando no se envía).
            'users_id_recipient' => $userId,
        ];
        if ($tags) {
            $input['_filename'] = array_map(fn ($f) => $f->getClientOriginalName(), $inlineFiles);
            $input['_tag_filename'] = $tags;
        }

        // Creación por legacy: en un POST deja solicitante, categoría, dispara
        // reglas y embebe las imágenes inline. (v2 no soporta actores/inline.)
        // Con archivos → multipart (uploadManifest); sin archivos → JSON plano.
        $resp = $this->legacyPost('/Ticket', $input, $inlineFiles);

        if ($resp->failed()) {
            throw new GlpiException('GLPI rechazó la creación del ticket: '.$resp->body());
        }

        $ticket = $resp->json() ?? [];
        $ticketId = $ticket['id'] ?? null;

        if ($ticketId) {
            // GLPI fija users_id_recipient con el usuario del token al CREAR
            // (ignora el del input), así que corregimos el "Abierto por" al
            // solicitante con un update posterior.
            $this->fixTicketRecipient((int) $ticketId, $userId);

            // Adjuntos normales (no inline) tras crear.
            foreach ($data['attachments'] ?? [] as $file) {
                $this->uploadDocument($ticketId, $file);
            }
        }

        return $ticket;
    }

    /**
     * Fuerza el "Abierto por" (users_id_recipient) al solicitante mediante un
     * update, porque GLPI lo fija con el usuario del token al crear e ignora el
     * del input. Relee el ticket y avisa por log si GLPI igual lo ignora (para
     * saber si es un límite de la instancia). No lanza excepción: es cosmético.
     */
    protected function fixTicketRecipient(int $ticketId, int $userId): void
    {
        try {
            $this->legacyHttp()->put("/Ticket/{$ticketId}", ['input' => [
                'id' => $ticketId,
                'users_id_recipient' => $userId,
            ]]);

            $after = $this->legacyHttp()->get("/Ticket/{$ticketId}")->json();
            $got = (int) ($after['users_id_recipient'] ?? 0);

            if ($got !== $userId) {
                Log::warning('GLPI: no se pudo fijar users_id_recipient al solicitante (GLPI lo sobreescribe).', [
                    'ticket' => $ticketId, 'want' => $userId, 'got' => $got,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('GLPI: excepción al fijar users_id_recipient', [
                'ticket' => $ticketId, 'error' => $e->getMessage(),
            ]);
        }
    }

    /** Verifica que estén los tokens legacy (necesarios para escribir en GLPI). */
    protected function requireLegacy(): void
    {
        if (! $this->hasLegacyTokens()) {
            throw new GlpiException('Faltan App-Token y User-Token (Legacy) en /admin/conexion para crear o responder tickets.');
        }
    }

    /**
     * Reemplaza los marcadores #INLINE_N# del contenido por tags de GLPI y
     * devuelve [contenido, tags, archivos] para el manifest legacy.
     *
     * @param  array<int, \Illuminate\Http\UploadedFile>  $inlineImages
     * @return array{0:string, 1:array<int,string>, 2:array<int,\Illuminate\Http\UploadedFile>}
     */
    protected function prepareInlineContent(string $content, array $inlineImages): array
    {
        $tags = [];
        $files = [];

        foreach (array_values($inlineImages) as $i => $file) {
            if (! str_contains($content, "#INLINE_{$i}#")) {
                continue; // marcador ausente: ignoramos ese archivo
            }
            $tag = 'inline'.bin2hex(random_bytes(8));
            $content = str_replace("#INLINE_{$i}#", $tag, $content);
            $tags[] = $tag;
            $files[] = $file;
        }

        return [$content, $tags, $files];
    }

    /**
     * Detalle completo de un ticket para su dueño (el solicitante). Devuelve
     * null si no existe, está eliminado, o NO pertenece al usuario (para que
     * nadie vea tickets ajenos adivinando el id).
     *
     * @return array<string, mixed>|null
     */
    public function ticketDetail(int $id, string $requesterEmail): ?array
    {
        if (! $this->isConfigured() || $this->driver() !== 'oauth') {
            return null;
        }

        $userId = $this->findUserId($requesterEmail);
        if ($userId === null) {
            return null;
        }

        $resp = $this->oauthHttp()->get("/Assistance/Ticket/{$id}");
        if (! $resp->successful()) {
            return null;
        }

        $t = $resp->json();
        if (! is_array($t) || ! empty($t['is_deleted'])) {
            return null;
        }

        $timeline = $this->oauthHttp()->get("/Assistance/Ticket/{$id}/Timeline")->json() ?? [];
        $timeline = is_array($timeline) ? $timeline : [];

        // Acceso: el solicitante SIEMPRE ve su ticket; además, quien sea (o haya
        // sido) validador del ticket puede abrirlo —aunque no sea el solicitante—
        // para responder su aprobación y para poder volver a verlo después de
        // responderla (si no, tras aprobar recibiría un 404).
        $isRequester = $this->isRequester($t, $userId);
        $isValidator = $this->isValidatorOnTicket($timeline, $userId);

        if (! $isRequester && ! $isValidator) {
            return null;
        }

        $detail = $this->normalizeDetail($t, $timeline);
        $detail['is_requester'] = $isRequester;
        // El recuadro de aprobar/rechazar solo si hay una validación PENDIENTE suya.
        $detail['pending_validation'] = $this->pendingValidationFor($timeline, $userId);

        return $detail;
    }

    /** Nombre completo de un usuario de GLPI por id (cacheado); cae al login/email. */
    protected function resolveUserName(int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }

        return Cache::remember("glpi:username:{$userId}", now()->addMinutes(30), function () use ($userId) {
            $u = $this->oauthHttp()->get("/Administration/User/{$userId}")->json();
            if (! is_array($u)) {
                return null;
            }

            $full = trim(($u['firstname'] ?? '').' '.($u['realname'] ?? ''));

            return $u['display_name'] ?? ($full !== '' ? $full : ($u['name'] ?? null));
        });
    }

    /** Nombre para mostrar de un actor {id, name} de una validación. */
    protected function validationActorName(array $actor): ?string
    {
        return $this->resolveUserName((int) ($actor['id'] ?? 0)) ?? ($actor['name'] ?? null);
    }

    /** ¿El usuario es (o fue) validador en alguna validación del ticket? */
    protected function isValidatorOnTicket(array $timeline, int $userId): bool
    {
        foreach ($timeline as $entry) {
            $v = $entry['item'] ?? [];
            if (($entry['type'] ?? null) === 'Validation'
                && ($v['requested_approver_type'] ?? null) === 'User'
                && (int) ($v['requested_approver_id'] ?? 0) === $userId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Devuelve la validación pendiente (status 2) asignada a $userId dentro del
     * timeline, normalizada para la UI; o null si no hay ninguna para él. Las
     * validaciones las solicita el técnico en GLPI; aquí solo se responden.
     *
     * @param  array<int, array<string, mixed>>  $timeline
     * @return array{id:int, comment:?string, requester:?string, date:?string}|null
     */
    protected function pendingValidationFor(array $timeline, int $userId): ?array
    {
        foreach ($timeline as $entry) {
            if (($entry['type'] ?? null) !== 'Validation') {
                continue;
            }

            $v = $entry['item'] ?? [];
            if (($v['requested_approver_type'] ?? null) === 'User'
                && (int) ($v['requested_approver_id'] ?? 0) === $userId
                && (int) ($v['status'] ?? 0) === 2) {
                return [
                    'id' => (int) $v['id'],
                    'comment' => $this->plainText($v['submission_comment'] ?? '') ?: null,
                    'requester' => $this->validationActorName($v['requester'] ?? []),
                    'date' => $this->fmtDate($v['submission_date'] ?? null, 'd-m-Y H:i'),
                ];
            }
        }

        return null;
    }

    /**
     * Tickets con una validación pendiente asignada al usuario (aprobaciones que
     * los técnicos solicitaron en GLPI). Escaneo acotado: solo mira tickets con
     * global_validation == 2 (a la espera de validación) y confirma cuáles le
     * tocan. No escala a miles (igual que el listado de "mis tickets").
     *
     * @return array<int, array{id:int, title:string, requested_by:?string, requested_at:?string}>
     */
    public function pendingApprovalsForUser(string $email): array
    {
        if (! $this->isConfigured() || $this->driver() !== 'oauth') {
            return [];
        }

        $userId = $this->findUserId($email);
        if ($userId === null) {
            return [];
        }

        $rows = collect($this->oauthHttp()->get('/Assistance/Ticket', [
            'sort' => 'date:desc',
            'limit' => 200,
        ])->json() ?? [])
            ->filter(fn ($t) => is_array($t) && empty($t['is_deleted']) && (int) ($t['global_validation'] ?? 0) === 2);

        $result = [];
        foreach ($rows as $t) {
            $timeline = $this->oauthHttp()->get("/Assistance/Ticket/{$t['id']}/Timeline/Validation")->json() ?? [];
            $pending = $this->pendingValidationFor(is_array($timeline) ? $timeline : [], $userId);

            if ($pending !== null) {
                $result[] = [
                    'id' => (int) $t['id'],
                    'title' => $t['name'] ?? '(sin título)',
                    'requested_by' => $pending['requester'],
                    'requested_at' => $pending['date'],
                ];
            }
        }

        return $result;
    }

    /**
     * Cantidad de aprobaciones pendientes del usuario, cacheada ~60s por usuario
     * para no escanear GLPI en cada navegación (se usa en el badge global del
     * navbar). Se invalida al responder una validación (forgetPendingApprovalsCount).
     */
    public function cachedPendingApprovalsCount(string $email, int|string $userKey): int
    {
        return Cache::remember(
            self::pendingApprovalsCountCacheKey($userKey),
            now()->addSeconds(60),
            fn () => count($this->pendingApprovalsForUser($email)),
        );
    }

    /** Invalida el contador cacheado de aprobaciones pendientes de un usuario. */
    public static function forgetPendingApprovalsCount(int|string $userKey): void
    {
        Cache::forget(self::pendingApprovalsCountCacheKey($userKey));
    }

    protected static function pendingApprovalsCountCacheKey(int|string $userKey): string
    {
        return "glpi:pending_approvals_count:{$userKey}";
    }

    /**
     * Historial de validaciones que el usuario YA respondió (aprobó/rechazó).
     * Escaneo acotado: mira tickets con global_validation != 1 (los que tienen
     * validaciones) y recoge las respondidas por él. No escala a miles.
     *
     * @return array<int, array{id:int, title:string, outcome:string, responded_at:?string, requested_by:?string}>
     */
    public function respondedApprovalsForUser(string $email): array
    {
        if (! $this->isConfigured() || $this->driver() !== 'oauth') {
            return [];
        }

        $userId = $this->findUserId($email);
        if ($userId === null) {
            return [];
        }

        $rows = collect($this->oauthHttp()->get('/Assistance/Ticket', [
            'sort' => 'date:desc',
            'limit' => 200,
        ])->json() ?? [])
            ->filter(fn ($t) => is_array($t) && empty($t['is_deleted']) && in_array((int) ($t['global_validation'] ?? 0), [2, 3, 4], true));

        $entries = collect();
        foreach ($rows as $t) {
            $vals = $this->oauthHttp()->get("/Assistance/Ticket/{$t['id']}/Timeline/Validation")->json() ?? [];

            foreach (is_array($vals) ? $vals : [] as $e) {
                $v = $e['item'] ?? [];
                $status = (int) ($v['status'] ?? 0);

                if (is_array($v)
                    && ($v['requested_approver_type'] ?? null) === 'User'
                    && (int) ($v['requested_approver_id'] ?? 0) === $userId
                    && in_array($status, [3, 4], true)) {
                    $entries->push([
                        'id' => (int) $t['id'],
                        'title' => $t['name'] ?? '(sin título)',
                        'outcome' => $status === 3 ? 'approved' : 'rejected',
                        'responded_at' => $this->fmtDate($v['approval_date'] ?? null, 'd-m-Y H:i'),
                        'requested_by' => $this->validationActorName($v['requester'] ?? []),
                        '_date' => $v['approval_date'] ?? '',
                    ]);
                }
            }
        }

        return $entries->sortByDesc('_date')
            ->map(function ($r) {
                unset($r['_date']);

                return $r;
            })
            ->values()->all();
    }

    /**
     * Responde (aprueba/rechaza) una validación usando el token OAuth DEL PROPIO
     * usuario (no la cuenta de servicio): GLPI solo permite que el validador
     * responda desde su sesión. approve → status 3; reject → status 4.
     *
     * El token se obtiene con el flujo authorization_code (ver GlpiUserOAuth).
     */
    public function respondValidationWithToken(string $userAccessToken, int $ticketId, string $action, ?string $comment = null): void
    {
        if ($action === 'reject' && blank($comment)) {
            throw new GlpiException('Indica un motivo para rechazar la aprobación.');
        }

        // Cliente v2 autenticado COMO EL USUARIO (su propio bearer token).
        $http = Http::withToken($userAccessToken)
            ->acceptJson()
            ->timeout($this->config['timeout'])
            ->withOptions(['verify' => $this->config['verify']])
            ->baseUrl($this->config['oauth']['api_url']);

        // Su validación pendiente (status 2) en el ticket.
        $vals = $http->get("/Assistance/Ticket/{$ticketId}/Timeline/Validation")->json();
        $pending = collect(is_array($vals) ? $vals : [])
            ->map(fn ($e) => $e['item'] ?? [])
            ->first(fn ($v) => is_array($v) && (int) ($v['status'] ?? 0) === 2);

        if (! $pending) {
            throw new GlpiException('No tienes una aprobación pendiente en este ticket.');
        }

        $vid = (int) $pending['id'];
        $r = $http->patch("/Assistance/Ticket/{$ticketId}/Timeline/Validation/{$vid}", [
            'status' => $action === 'approve' ? 3 : 4,
            'approval_comment' => (string) $comment,
        ]);

        if ($r->failed()) {
            throw new GlpiException('GLPI rechazó la respuesta ('.$r->status().').');
        }

        // Confirmamos que el estado cambió de verdad (no un 2xx sin efecto).
        $after = $http->get("/Assistance/Ticket/{$ticketId}/Timeline/Validation")->json();
        $still = collect(is_array($after) ? $after : [])
            ->map(fn ($e) => $e['item'] ?? [])
            ->first(fn ($v) => (int) ($v['id'] ?? 0) === $vid && (int) ($v['status'] ?? 0) === 2);

        if ($still) {
            throw new GlpiException('GLPI no registró la respuesta. Intenta de nuevo.');
        }
    }

    /**
     * Descarga un adjunto de un ticket, verificando que el ticket sea del
     * usuario Y que el documento realmente cuelgue de ese ticket (para que
     * nadie baje archivos ajenos adivinando ids).
     *
     * @return array{content:string, mime:string, name:string}|null
     */
    public function downloadDocument(int $ticketId, int $documentId, string $requesterEmail): ?array
    {
        if (! $this->isConfigured() || $this->driver() !== 'oauth') {
            return null;
        }

        $userId = $this->findUserId($requesterEmail);
        if ($userId === null) {
            return null;
        }

        // Acceso: el ticket debe ser suyo, o debe ser (o haber sido) validador
        // del ticket (para poder revisar adjuntos/imágenes al aprobar).
        $resp = $this->oauthHttp()->get("/Assistance/Ticket/{$ticketId}");
        $t = $resp->successful() ? $resp->json() : null;
        if (! is_array($t) || ! empty($t['is_deleted'])) {
            return null;
        }
        if (! $this->isRequester($t, $userId)) {
            $vals = $this->oauthHttp()->get("/Assistance/Ticket/{$ticketId}/Timeline/Validation")->json() ?? [];
            if (! $this->isValidatorOnTicket(is_array($vals) ? $vals : [], $userId)) {
                return null;
            }
        }

        // El documento debe estar vinculado a ESE ticket.
        $docs = $this->oauthHttp()->get("/Assistance/Ticket/{$ticketId}/Timeline/Document")->json() ?? [];
        $match = collect($docs)->first(function ($entry) use ($documentId) {
            $item = $entry['item'] ?? [];

            return (int) ($item['documents_id'] ?? 0) === $documentId;
        });
        if (! $match) {
            return null;
        }

        $name = $match['item']['document']['name'] ?? 'archivo';

        // Descarga binaria (sin acceptJson, para recibir el archivo tal cual).
        $dl = Http::withToken($this->oauthToken())
            ->timeout($this->config['timeout'])
            ->withOptions(['verify' => $this->config['verify']])
            ->get($this->config['oauth']['api_url']."/Management/Document/{$documentId}/Download");

        if (! $dl->successful()) {
            return null;
        }

        return [
            'content' => $dl->body(),
            'mime' => $dl->header('Content-Type') ?: 'application/octet-stream',
            'name' => $name,
        ];
    }

    /**
     * Agrega un seguimiento (respuesta del usuario) y/o adjuntos a un ticket
     * existente. Verifica que el ticket sea del solicitante antes de escribir.
     *
     * @param  array<int, \Illuminate\Http\UploadedFile>  $attachments
     */
    public function replyToTicket(int $ticketId, string $requesterEmail, ?string $content, array $inlineImages = [], array $attachments = []): void
    {
        if (! $this->isConfigured() || $this->driver() !== 'oauth') {
            throw new GlpiException('La conexión con GLPI no está configurada.');
        }
        $this->requireLegacy();

        $userId = $this->findUserId($requesterEmail);
        if ($userId === null) {
            throw new GlpiException('No se encontró tu usuario en GLPI.');
        }

        // Seguridad: solo el solicitante puede responder su ticket (lectura v2).
        $resp = $this->oauthHttp()->get("/Assistance/Ticket/{$ticketId}");
        $t = $resp->successful() ? $resp->json() : null;
        if (! is_array($t) || ! empty($t['is_deleted']) || ! $this->isRequester($t, $userId)) {
            throw new GlpiException('No puedes responder este ticket.');
        }

        // Resuelto (5) o Cerrado (6): no se admiten más seguimientos.
        $st = is_array($t['status'] ?? null) ? (int) ($t['status']['id'] ?? 0) : (int) ($t['status'] ?? 0);
        if (in_array($st, [5, 6], true)) {
            throw new GlpiException('Este ticket está resuelto o cerrado; no se pueden agregar más seguimientos.');
        }

        $hasContent = filled(trim(strip_tags((string) $content))) || ! empty($inlineImages);
        if ($hasContent) {
            // Seguimiento por legacy: atribuido al usuario real (users_id) y con
            // imágenes inline embebidas (tags). v2 no soporta el mecanismo inline.
            [$body, $tags, $files] = $this->prepareInlineContent((string) $content, $inlineImages);

            $input = [
                'itemtype' => 'Ticket',
                'items_id' => $ticketId,
                'content' => $body,
                'users_id' => $userId,
            ];
            if ($tags) {
                $input['_filename'] = array_map(fn ($f) => $f->getClientOriginalName(), $files);
                $input['_tag_filename'] = $tags;
            }

            $r = $this->legacyPost('/ITILFollowup', $input, $files);

            if ($r->failed()) {
                throw new GlpiException('No se pudo agregar tu respuesta: '.$r->body());
            }
        }

        foreach ($attachments as $file) {
            $this->uploadDocument($ticketId, $file);
        }
    }

    /**
     * Aprueba o rechaza la solución propuesta de un ticket (acción del
     * solicitante). Aprobar → cierra el ticket. Rechazar → lo reabre y deja el
     * motivo como seguimiento. Verifica que el ticket sea del usuario.
     */
    public function respondSolution(int $ticketId, string $requesterEmail, string $action, ?string $comment = null): void
    {
        if (! $this->isConfigured() || $this->driver() !== 'oauth') {
            throw new GlpiException('La conexión con GLPI no está configurada.');
        }

        $userId = $this->findUserId($requesterEmail);
        if ($userId === null) {
            throw new GlpiException('No se encontró tu usuario en GLPI.');
        }

        // Seguridad: solo el solicitante puede responder su ticket.
        $resp = $this->oauthHttp()->get("/Assistance/Ticket/{$ticketId}");
        $t = $resp->successful() ? $resp->json() : null;
        if (! is_array($t) || ! empty($t['is_deleted']) || ! $this->isRequester($t, $userId)) {
            throw new GlpiException('No puedes responder este ticket.');
        }

        // Buscar la solución en espera (status 2).
        $sols = $this->oauthHttp()->get("/Assistance/Ticket/{$ticketId}/Timeline/Solution")->json() ?? [];
        $waiting = collect($sols)
            ->map(fn ($e) => $e['item'] ?? $e)
            ->first(fn ($s) => is_array($s) && (int) ($s['status'] ?? 0) === 2);

        if (! $waiting) {
            throw new GlpiException('Este ticket no tiene una solución pendiente de aprobación.');
        }
        $solutionId = $waiting['id'];

        if ($action === 'approve') {
            // Comentario opcional al aprobar → seguimiento a nombre del usuario.
            if (filled($comment)) {
                $this->addUserFollowup($ticketId, $userId, $comment);
            }
            // Solución aceptada (status 3) + ticket cerrado (6). Verificamos cada
            // escritura: si GLPI la rechaza, avisamos en vez de fingir éxito.
            $this->patchOrFail("/Assistance/Ticket/{$ticketId}/Timeline/Solution/{$solutionId}", [
                'status' => 3,
                'approver' => ['id' => $userId],
            ], 'No se pudo registrar la aprobación de la solución.');

            $this->patchOrFail("/Assistance/Ticket/{$ticketId}", ['status' => 6],
                'La solución se aprobó, pero no se pudo cerrar el ticket.');

            return;
        }

        // Rechazo: exige comentario. Solución rechazada (4) + comentario como
        // seguimiento (a nombre del usuario) + ticket reabierto (2).
        if (blank($comment)) {
            throw new GlpiException('Indica un motivo para rechazar la solución.');
        }

        $this->addUserFollowup($ticketId, $userId, 'Solución rechazada: '.$comment);
        $this->patchOrFail("/Assistance/Ticket/{$ticketId}/Timeline/Solution/{$solutionId}", [
            'status' => 4,
            'approver' => ['id' => $userId],
        ], 'No se pudo registrar el rechazo de la solución.');

        $this->patchOrFail("/Assistance/Ticket/{$ticketId}", ['status' => 2],
            'Se registró el rechazo, pero no se pudo reabrir el ticket.');
    }

    /** PATCH v2 que lanza GlpiException con un mensaje claro si falla. */
    protected function patchOrFail(string $path, array $payload, string $errorMessage): void
    {
        $r = $this->oauthHttp()->patch($path, $payload);
        if ($r->failed()) {
            throw new GlpiException($errorMessage.' (GLPI '.$r->status().')');
        }
    }

    /** Seguimiento simple (texto) atribuido al usuario, vía v2. */
    protected function addUserFollowup(int $ticketId, int $userId, string $text): void
    {
        $r = $this->oauthHttp()->post("/Assistance/Ticket/{$ticketId}/Timeline/Followup", [
            'content' => nl2br(e($text)),
            'user' => ['id' => $userId],
        ]);
        if ($r->failed()) {
            throw new GlpiException('No se pudo registrar tu comentario: '.$r->status());
        }
    }

    /**
     * Normaliza el ticket + timeline a una forma estable para la UI.
     *
     * @return array<string, mixed>
     */
    protected function normalizeDetail(array $t, array $timeline): array
    {
        $team = collect($t['team'] ?? []);
        $status = $t['status'] ?? null;
        $name = fn ($m) => $m['display_name'] ?? $m['name'] ?? null;

        $statusId = is_array($status) ? (int) ($status['id'] ?? 0) : (int) $status;

        return [
            'id' => $t['id'] ?? null,
            'title' => $t['name'] ?? '(sin título)',
            'status' => $statusId,
            // Resuelto (5) = hay una solución propuesta esperando tu aprobación.
            'can_respond_solution' => $statusId === 5,
            'type' => (int) ($t['type'] ?? 0),
            'category' => $t['category']['name'] ?? null,
            'opened_at' => $this->fmtDate($t['date'] ?? null, 'd-m-Y H:i'),
            'updated_at' => $this->fmtDate($t['date_mod'] ?? null, 'd-m-Y H:i'),
            'requester' => $name($team->firstWhere('role', 'requester') ?? []),
            'technicians' => $team->where('role', 'assigned')->where('type', 'User')->map($name)->filter()->values()->all(),
            'groups' => $team->where('role', 'assigned')->where('type', 'Group')->map($name)->filter()->values()->all(),
            'timeline' => $this->buildTimeline($t, $timeline),
        ];
    }

    /**
     * Conversación unificada estilo GLPI: seguimientos, soluciones y documentos
     * del timeline + la descripción original como entrada más antigua. Orden de
     * más nuevo a más antiguo. Oculta notas privadas y tareas internas.
     *
     * @return array<int, array{kind:string, author:?string, date:?string, content:?string, file:?string, doc_id:?int}>
     */
    protected function buildTimeline(array $ticket, array $items): array
    {
        $ticketId = (int) ($ticket['id'] ?? 0);

        // Ids de documentos que ya van embebidos como imagen inline en algún
        // contenido, para NO listarlos además como adjunto suelto.
        $inlineDocIds = [];
        $scan = function ($html) use (&$inlineDocIds) {
            if (preg_match_all('/docid=(\d+)/', (string) $html, $m)) {
                foreach ($m[1] as $id) {
                    $inlineDocIds[(int) $id] = true;
                }
            }
        };
        $scan($ticket['content'] ?? '');
        foreach ($items as $entry) {
            if (in_array($entry['type'] ?? '', ['Followup', 'Solution'], true)) {
                $scan($entry['item']['content'] ?? '');
            }
        }

        // Mapa id → nombre completo (desde el team: trae display_name). El
        // user de los seguimientos solo trae {id, name(=email)}, así que lo
        // resolvemos con esto para mostrar siempre el nombre completo.
        $people = collect($ticket['team'] ?? [])
            ->filter(fn ($m) => ($m['type'] ?? '') === 'User')
            ->mapWithKeys(fn ($m) => [(int) ($m['id'] ?? 0) => $m['display_name'] ?? $m['name'] ?? null]);
        $authorOf = fn ($u) => $people[(int) ($u['id'] ?? 0)] ?? ($u['name'] ?? null);

        $entries = collect($items)->map(function ($entry) use ($ticketId, $inlineDocIds, $authorOf) {
            $type = $entry['type'] ?? null;
            $item = $entry['item'] ?? [];

            if (! is_array($item) || ! empty($item['is_private'])) {
                return null;
            }

            if ($type === 'Followup' || $type === 'Solution') {
                // El comentario de rechazo se pinta distinto (rojo).
                $isRejection = str_starts_with(trim(strip_tags((string) ($item['content'] ?? ''))), 'Solución rechazada');

                return [
                    '_date' => $item['date'] ?? $item['date_creation'] ?? '',
                    'kind' => $type === 'Solution' ? 'solution' : ($isRejection ? 'rejection' : 'followup'),
                    'author' => $authorOf($item['user'] ?? []),
                    'content' => $this->sanitizeHtml($item['content'] ?? '', $ticketId),
                    'file' => null,
                    'doc_id' => null,
                ];
            }

            if ($type === 'Document') {
                $docId = (int) ($item['documents_id'] ?? ($item['document']['id'] ?? 0));
                if (! $docId || isset($inlineDocIds[$docId])) {
                    return null; // imagen inline: ya se ve dentro del texto
                }

                return [
                    '_date' => $item['date_creation'] ?? $item['date'] ?? '',
                    'kind' => 'document',
                    'author' => $authorOf($item['user'] ?? []),
                    'content' => null,
                    'file' => $item['document']['name'] ?? 'archivo',
                    'doc_id' => $docId,
                ];
            }

            return null; // tareas, validaciones, etc.
        })->filter();

        // Validaciones (aprobaciones): una entrada por la solicitud del técnico y
        // otra por la respuesta (si ya fue respondida), como las muestra GLPI.
        foreach ($items as $entry) {
            if (($entry['type'] ?? null) !== 'Validation' || ! is_array($entry['item'] ?? null)) {
                continue;
            }

            $v = $entry['item'];
            $status = (int) ($v['status'] ?? 0);

            $entries->push([
                '_date' => $v['submission_date'] ?? '',
                'kind' => 'validation_request',
                'author' => $this->validationActorName($v['requester'] ?? []),
                'content' => $this->plainText($v['submission_comment'] ?? '') ?: null,
                'file' => null,
                'doc_id' => null,
            ]);

            if (in_array($status, [3, 4], true)) {
                $entries->push([
                    '_date' => $v['approval_date'] ?? '',
                    'kind' => $status === 3 ? 'validation_approved' : 'validation_rejected',
                    'author' => $this->validationActorName($v['approver'] ?? []),
                    'content' => $this->plainText($v['approval_comment'] ?? '') ?: null,
                    'file' => null,
                    'doc_id' => null,
                ]);
            }
        }

        // Descripción original como la entrada más antigua (autor = solicitante).
        $requester = collect($ticket['team'] ?? [])->firstWhere('role', 'requester') ?? [];
        $entries->push([
            '_date' => $ticket['date'] ?? '',
            'kind' => 'description',
            'author' => $requester['display_name'] ?? $requester['name'] ?? null,
            'content' => $this->sanitizeHtml($ticket['content'] ?? '', $ticketId),
            'file' => null,
            'doc_id' => null,
        ]);

        return $entries
            ->sortByDesc('_date') // ISO ordena lexicográficamente bien
            ->map(function ($e) {
                $e['date'] = $this->fmtDate($e['_date'], 'd-m-Y H:i');
                unset($e['_date']);

                return $e;
            })
            ->values()->all();
    }

    /** Convierte el HTML de GLPI a texto plano seguro (evita XSS en el portal). */
    protected function plainText(?string $html): string
    {
        if (blank($html)) {
            return '';
        }

        $s = preg_replace('/<br\s*\/?>/i', "\n", (string) $html);
        $s = preg_replace('/<\/(p|div|li)>/i', "\n", (string) $s);

        return trim(html_entity_decode(strip_tags((string) $s), ENT_QUOTES | ENT_HTML5));
    }

    /**
     * Sanea el HTML de GLPI para mostrarlo en el portal: deja solo etiquetas
     * seguras, elimina atributos peligrosos (on*, javascript:) y reescribe el
     * src de las imágenes inline al proxy del portal (docid → /tickets/..).
     * Evita XSS y permite que las capturas pegadas se vean.
     */
    protected function sanitizeHtml(?string $html, int $ticketId): string
    {
        if (blank($html)) {
            return '';
        }

        $allowed = ['p', 'br', 'a', 'img', 'strong', 'b', 'em', 'i', 'u', 's', 'ul', 'ol', 'li', 'span', 'div', 'blockquote', 'pre', 'code', 'h1', 'h2', 'h3'];

        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="__root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        foreach (iterator_to_array($xpath->query('//*')) as $el) {
            $tag = strtolower($el->nodeName);

            if ($tag === 'div' && $el->getAttribute('id') === '__root') {
                continue; // contenedor auxiliar
            }

            if (! in_array($tag, $allowed, true)) {
                $el->parentNode?->replaceChild($dom->createTextNode($el->textContent), $el);

                continue;
            }

            foreach (iterator_to_array($el->attributes ?? []) as $attr) {
                $name = strtolower($attr->name);
                $keep = false;

                if ($tag === 'a' && $name === 'href' && preg_match('#^https?://#i', $attr->value)) {
                    $keep = true;
                } elseif ($tag === 'img' && $name === 'src' && preg_match('/docid=(\d+)/', $attr->value, $m)) {
                    $el->setAttribute('src', "/tickets/{$ticketId}/adjuntos/{$m[1]}?view=1");
                    $keep = true;
                }

                if (! $keep) {
                    $el->removeAttribute($attr->name);
                }
            }

            if ($tag === 'a') {
                $el->setAttribute('target', '_blank');
                $el->setAttribute('rel', 'noopener noreferrer');
            }
        }

        $root = $xpath->query('//div[@id="__root"]')->item(0);
        $out = '';
        foreach ($root?->childNodes ?? [] as $child) {
            $out .= $dom->saveHTML($child);
        }

        return trim($out);
    }

    /** Formatea una fecha ISO de GLPI de forma segura. */
    protected function fmtDate(?string $date, string $format = 'd-m-Y'): ?string
    {
        return $date ? rescue(fn () => \Illuminate\Support\Carbon::parse($date)->format($format), $date, false) : null;
    }

    /**
     * Árbol de categorías ITIL para el wizard, filtrado por el tipo elegido
     * en el paso 1 (incident|request).
     *
     * El árbol de GLPI es: Área (nivel 1) > Incidente|Solicitud (nivel 2) >
     * … (uno o más niveles). El nivel 2 NO se muestra: se usa como filtro y
     * se elimina de la ruta. Devolvemos un árbol anidado de profundidad
     * variable donde el usuario baja hasta una HOJA (nodo sin hijos), que es
     * la categoría real que se envía a GLPI. Los nodos con hijos son solo
     * navegación (p. ej. Sistemas > Frusys > Error).
     *
     * @return array<int, array{id:?int, name:string, children:array}>
     */
    public function categoriesByType(string $type): array
    {
        // El nivel 2 del árbol se llama "Incidente" para tickets de tipo
        // incident y "Solicitud" para request.
        $branch = $type === 'incident' ? 'incidente' : 'solicitud';

        $rows = $this->isConfigured()
            ? $this->fetchCategoryRows()
            : $this->demoCategoryRows();

        return $this->buildCategoryTree($rows, $branch);
    }

    /**
     * Crea una subcategoría ITIL bajo el nodo indicado por su RUTA en el árbol
     * del portal (p. ej. ["Sistemas","Frusys"]) dentro de la rama $branch
     * (incident|request).
     *
     * Ojo: el endpoint de categorías que alimenta el árbol NO devuelve las
     * categorías contenedoras (solo las hojas asignables), por eso los nodos
     * intermedios llegan sin id. Aquí resolvemos el id real del padre a partir
     * de su `completename` reconstruido —insertando el nivel Incidente/Solicitud
     * que el árbol pliega— contra el listado legacy COMPLETO (que sí incluye los
     * contenedores). Luego GLPI calcula solo el completename/level del hijo.
     * Requiere tokens legacy (escritura por apirest.php).
     *
     * @param  array<int, string>  $path  Ruta visible del nodo padre (sin el nivel Incidente/Solicitud).
     * @return array{id:int, name:string}
     */
    public function createCategory(array $path, string $branch, string $name): array
    {
        if (! $this->isConfigured()) {
            throw new GlpiException('La conexión con GLPI no está configurada.');
        }
        $this->requireLegacy();

        $name = trim($name);
        if ($name === '') {
            throw new GlpiException('Indica un nombre para la categoría.');
        }

        $path = array_values(array_filter(array_map('trim', $path), fn ($s) => $s !== ''));
        if (! $path) {
            throw new GlpiException('No se pudo determinar la categoría padre.');
        }

        // Ruta REAL en GLPI: Área > Incidente|Solicitud > (resto de la ruta…).
        $branchLabel = $branch === 'incident' ? 'Incidente' : 'Solicitud';
        $realParent = $path[0].' > '.$branchLabel;
        if (count($path) > 1) {
            $realParent .= ' > '.implode(' > ', array_slice($path, 1));
        }

        // Mapa completename(minúsculas) => id de TODAS las categorías (incluye
        // contenedores), para resolver el padre y detectar duplicados.
        $map = $this->allCategoriesByCompletename();

        $parentId = $map[mb_strtolower($realParent)] ?? null;
        if ($parentId === null) {
            throw new GlpiException("No se encontró la categoría padre «{$realParent}» en GLPI.");
        }

        if (isset($map[mb_strtolower($realParent.' > '.$name)])) {
            throw new GlpiException("Ya existe una categoría «{$name}» en esa rama.");
        }

        $resp = $this->legacyHttp()->post('/ITILCategory', ['input' => [
            'name' => $name,
            'itilcategories_id' => $parentId,
        ]]);

        if ($resp->failed()) {
            throw new GlpiException('GLPI rechazó la creación de la categoría: '.mb_substr($resp->body(), 0, 300));
        }

        // Refrescamos el árbol cacheado para que la nueva categoría aparezca ya.
        Cache::forget('glpi:itilcategories');

        return [
            'id' => (int) ($resp->json('id') ?? 0),
            'name' => $name,
        ];
    }

    /**
     * Mapa `completename` (en minúsculas) => id de TODAS las ITILCategory por el
     * API legacy (apirest.php), que sí devuelve las categorías contenedoras
     * (a diferencia del endpoint que alimenta el árbol). Se usa para resolver el
     * id real de un nodo padre y para detectar duplicados al crear.
     *
     * @return array<string, int>
     */
    protected function allCategoriesByCompletename(): array
    {
        $rows = $this->legacyHttp()->get('/ITILCategory', ['range' => '0-9999'])->json() ?? [];

        $map = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            $cn = trim((string) ($r['completename'] ?? ''));
            $id = (int) ($r['id'] ?? 0);
            if ($cn !== '' && $id > 0) {
                $map[mb_strtolower($cn)] = $id;
            }
        }

        return $map;
    }

    /**
     * Filas crudas de ITILCategory (id + completename) desde GLPI.
     *
     * @return array<int, array{id:int, completename:string}>
     */
    protected function fetchCategoryRows(): array
    {
        return Cache::remember('glpi:itilcategories', now()->addMinutes(30), function () {
            $rows = $this->driver() === 'oauth'
                ? ($this->oauthHttp()->get('/Dropdowns/ITILCategory', ['limit' => 500])->json() ?? [])
                : ($this->legacyHttp()->get('/ITILCategory')->json() ?? []);

            return collect($rows)->map(fn ($r) => [
                'id' => (int) ($r['id'] ?? 0),
                'completename' => (string) ($r['completename'] ?? $r['name'] ?? ''),
            ])->filter(fn ($r) => $r['id'] > 0 && $r['completename'] !== '')->values()->all();
        });
    }

    /**
     * Construye un árbol anidado (profundidad variable) con la rama del tipo
     * pedido. Quita el nivel 2 (Incidente/Solicitud) de la ruta y crea los
     * nodos ancestros que hagan falta. El separador de GLPI es " > ".
     *
     * @param  array<int, array{id:int, completename:string}>  $rows
     * @return array<int, array{id:?int, name:string, children:array}>
     */
    protected function buildCategoryTree(array $rows, string $branch): array
    {
        $roots = [];
        $byPath = []; // ruta efectiva "Área|Sub|…" => nodo (stdClass, por referencia)

        // Ordenamos por profundidad para crear ancestros antes que hijos.
        usort($rows, fn ($a, $b) => substr_count($a['completename'], '>') <=> substr_count($b['completename'], '>'));

        foreach ($rows as $row) {
            $parts = array_map('trim', explode('>', $row['completename']));

            // Necesitamos al menos Área > Tipo, y que la rama coincida.
            if (count($parts) < 2 || mb_strtolower($parts[1]) !== $branch) {
                continue;
            }

            // Ruta "efectiva": Área + lo que cuelga del nivel Tipo (sin el nivel 2).
            $effective = array_merge([$parts[0]], array_slice($parts, 2));

            $accum = [];
            $parentKey = null;
            foreach ($effective as $segment) {
                $accum[] = $segment;
                $key = implode('|', $accum);

                if (! isset($byPath[$key])) {
                    $node = (object) ['id' => null, 'name' => $segment, 'children' => []];
                    $byPath[$key] = $node;

                    if ($parentKey === null) {
                        $roots[] = $node;
                    } else {
                        $byPath[$parentKey]->children[] = $node;
                    }
                }

                $parentKey = $key;
            }

            // El id de GLPI corresponde al último segmento de esta fila.
            $byPath[$parentKey]->id = (int) $row['id'];
        }

        return $this->treeToArray($roots);
    }

    /**
     * Convierte los nodos stdClass del árbol a arrays planos para el JSON.
     *
     * @param  array<int, object>  $nodes
     * @return array<int, array{id:?int, name:string, children:array}>
     */
    protected function treeToArray(array $nodes): array
    {
        // Orden alfabético por nombre en cada nivel (afecta al árbol admin y al
        // wizard). Collator para respetar acentos/ñ del español; si no está la
        // extensión intl, caemos a una comparación case-insensitive.
        usort($nodes, fn ($a, $b) => $this->compareNames($a->name, $b->name));

        return array_map(fn ($n) => [
            'id' => $n->id,
            'name' => $n->name,
            'children' => $this->treeToArray($n->children),
        ], $nodes);
    }

    /** Compara dos nombres para ordenar alfabéticamente (locale español). */
    protected function compareNames(string $a, string $b): int
    {
        static $collator = null;
        if ($collator === null) {
            $collator = class_exists(\Collator::class) ? new \Collator('es_ES') : false;
        }

        return $collator ? $collator->compare($a, $b) : strcasecmp($a, $b);
    }

    /**
     * Árbol de ejemplo (modo demo, sin GLPI configurado) que replica la
     * estructura real de Verfrut para poder recorrer el wizard.
     *
     * @return array<int, array{id:int, completename:string}>
     */
    protected function demoCategoryRows(): array
    {
        return [
            // Ramas de 3 niveles (Área > Tipo > Categoría hoja).
            ['id' => 11, 'completename' => 'Infraestructura y Ciberseguridad > Incidente > Enlaces'],
            ['id' => 12, 'completename' => 'Infraestructura y Ciberseguridad > Incidente > Servidores'],
            ['id' => 13, 'completename' => 'Infraestructura y Ciberseguridad > Solicitud > Desbloqueo Web'],
            ['id' => 14, 'completename' => 'Infraestructura y Ciberseguridad > Solicitud > Recuperacion de datos'],
            ['id' => 21, 'completename' => 'Soporte > Incidente > Equipos Computacionales'],
            ['id' => 22, 'completename' => 'Soporte > Incidente > Redes y Comunicaciones'],
            ['id' => 23, 'completename' => 'Soporte > Solicitud > Creacion/Modificacion Usuario (AD-ERP)'],
            // Ramas de 4 niveles (Área > Tipo > Sistema > Categoría hoja).
            ['id' => 31, 'completename' => 'Sistemas > Incidente > Frusys > Error'],
            ['id' => 32, 'completename' => 'Sistemas > Incidente > Frusys > Modificacion'],
            ['id' => 33, 'completename' => 'Sistemas > Incidente > Adam > Error'],
            ['id' => 34, 'completename' => 'Sistemas > Solicitud > Frusys > Desarrollo'],
            ['id' => 35, 'completename' => 'Sistemas > Solicitud > Adam > Desarrollo'],
        ];
    }

    /* ===================================================================
     |  Resolución de usuario (mapeo Entra -> GLPI)
     * =================================================================== */

    public function findUserId(string $email): ?int
    {
        return Cache::remember(
            $this->userIdCacheKey($email),
            now()->addMinutes(30),
            fn () => $this->driver() === 'oauth'
                ? $this->oauthFindUserId($email)
                : $this->legacyFindUserId($email)
        );
    }

    /** Clave de caché para el id de GLPI de un correo (según el modo de match). */
    protected function userIdCacheKey(string $email): string
    {
        $field = $this->config['requester_match'] === 'login' ? 'name' : 'useremails';

        return "glpi:userid:{$field}:".sha1($email);
    }

    /**
     * Devuelve el id del usuario en GLPI, creándolo (provisioning JIT) si aún no
     * existe. GLPI da de alta a sus usuarios en el primer login SAML; el portal
     * entra por Entra (no por GLPI), así que el usuario puede no existir todavía.
     * Lo creamos con login (name) = email —igual que hace SAML en esta instancia—
     * y con el correo en glpi_useremails, de modo que cuando la persona entre
     * luego por SAML, GLPI reutilice ESTE registro en vez de duplicarlo.
     *
     * Requiere tokens legacy (la creación se hace vía apirest.php).
     *
     * La zona horaria (si viene) se fija SOLO en el alta: si el usuario ya
     * existe en GLPI, este método no lo modifica.
     */
    public function ensureUser(string $email, ?string $name = null, ?string $timezone = null): ?int
    {
        $userId = $this->findUserId($email);
        if ($userId !== null) {
            return $userId;
        }

        $this->requireLegacy();

        $userId = $this->legacyCreateUser($email, $name, $timezone);
        if ($userId !== null) {
            // Invalidamos la caché negativa (findUserId cachea el null 30 min).
            Cache::forget($this->userIdCacheKey($email));
        }

        return $userId;
    }

    /**
     * Completa la zona horaria del usuario EN GLPI si aún no tiene ninguna.
     * Se llama en cada login: los usuarios creados por SAML (no por el alta JIT
     * del portal) no traen timezone, y GLPI muestra sus horas mal.
     *
     * Solo escribe si el campo está VACÍO: si el usuario ya tiene una zona
     * (puesta por él o por un técnico), no se toca. Si el usuario todavía no
     * existe en GLPI, no se crea: ya se fijará en el alta JIT del primer ticket.
     *
     * Nunca lanza excepción ni bloquea el login: si GLPI falla, solo se registra.
     * Cachea el resultado para no consultar GLPI en cada inicio de sesión.
     */
    public function ensureUserTimezone(string $email, ?string $timezone): void
    {
        if (blank($timezone) || ! $this->isConfigured() || ! $this->hasLegacyTokens()) {
            return;
        }

        try {
            $userId = $this->findUserId($email);
            if ($userId === null) {
                return; // aún no existe en GLPI (lo creará el primer ticket)
            }

            // Ya verificado hace poco: no volvemos a preguntar en cada login.
            $cacheKey = "glpi:tz_checked:{$userId}";
            if (Cache::has($cacheKey)) {
                return;
            }

            $user = $this->legacyHttp()->get("/User/{$userId}")->json();
            $current = is_array($user) ? trim((string) ($user['timezone'] ?? '')) : '';

            if ($current !== '') {
                // Ya tiene zona horaria: no hacemos nada.
                Cache::put($cacheKey, true, now()->addDays(7));

                return;
            }

            $resp = $this->legacyHttp()->put("/User/{$userId}", ['input' => [
                'id' => $userId,
                'timezone' => $timezone,
            ]]);

            if ($resp->successful()) {
                Cache::put($cacheKey, true, now()->addDays(7));
            } else {
                // Suele fallar si las zonas horarias no están habilitadas en la BD de GLPI.
                Log::warning('GLPI: no se pudo fijar la zona horaria del usuario', [
                    'users_id' => $userId, 'timezone' => $timezone,
                    'status' => $resp->status(), 'body' => mb_substr($resp->body(), 0, 300),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('GLPI: excepción al fijar la zona horaria del usuario', [
                'email' => $email, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Crea el usuario en GLPI vía API legacy y le vincula su correo. Devuelve el
     * id creado, o null si GLPI rechazó el alta. No lanza excepción: si el alta
     * falla, createTicket ya reporta un error claro al usuario.
     */
    protected function legacyCreateUser(string $email, ?string $name, ?string $timezone = null): ?int
    {
        [$firstname, $realname] = $this->splitName($name);

        // login = email (como SAML en esta instancia). is_active para que GLPI
        // lo acepte como solicitante y le pueda notificar. La timezone (IANA)
        // solo se envía si viene; requiere zonas habilitadas en la BD de GLPI.
        $resp = $this->legacyHttp()->post('/User', ['input' => array_filter([
            'name' => $email,
            'firstname' => $firstname,
            'realname' => $realname,
            'timezone' => $timezone,
            'is_active' => 1,
        ], fn ($v) => $v !== null && $v !== '')]);

        if ($resp->failed()) {
            Log::error('GLPI: no se pudo crear el usuario (JIT)', [
                'email' => $email,
                'status' => $resp->status(),
                'body' => mb_substr($resp->body(), 0, 300),
            ]);

            return null;
        }

        $userId = (int) ($resp->json('id') ?? 0);
        if ($userId <= 0) {
            return null;
        }

        // El correo vive en glpi_useremails (tabla aparte). Sin esto, el match
        // por email de findUserId no encontraría al usuario recién creado.
        $mail = $this->legacyHttp()->post('/UserEmail', ['input' => [
            'users_id' => $userId,
            'email' => $email,
            'is_default' => 1,
        ]]);

        if ($mail->failed()) {
            Log::warning('GLPI: usuario creado pero no se pudo vincular su email', [
                'email' => $email, 'users_id' => $userId,
                'status' => $mail->status(), 'body' => mb_substr($mail->body(), 0, 300),
            ]);
        }

        return $userId;
    }

    /**
     * Parte el nombre para mostrar de Entra en [firstname, realname(apellidos)].
     * "Juan Pérez Soto" → ["Juan", "Pérez Soto"]. Sin nombre → ['', ''].
     *
     * @return array{0:string, 1:string}
     */
    protected function splitName(?string $name): array
    {
        $name = trim((string) $name);
        if ($name === '' || str_contains($name, '@')) {
            return ['', '']; // sin nombre real (o solo el email): dejamos vacío
        }

        $parts = preg_split('/\s+/', $name, 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    /* ===================================================================
     |  Driver OAuth2 (API v2)
     * =================================================================== */

    protected function oauthToken(): string
    {
        return Cache::remember('glpi:oauth_token', now()->addMinutes(50), function () {
            $cfg = $this->config['oauth'];

            // GLPI v2 no soporta client_credentials: usamos "password" grant
            // con el usuario de servicio (confirmado en el Swagger 2.3.0).
            $response = Http::asForm()
                ->timeout($this->config['timeout'])
                ->withOptions(['verify' => $this->config['verify']])
                ->post($cfg['token_url'], array_filter([
                    'grant_type' => 'password',
                    'client_id' => $cfg['client_id'],
                    'client_secret' => $cfg['client_secret'],
                    'username' => $cfg['username'],
                    'password' => $cfg['password'],
                    'scope' => $cfg['scope'] ?: null,
                ]));

            if ($response->failed()) {
                Log::error('GLPI OAuth token failed', ['status' => $response->status(), 'body' => $response->body()]);
                throw new GlpiException('No se pudo obtener token OAuth de GLPI.');
            }

            return (string) $response->json('access_token');
        });
    }

    protected function oauthHttp(): PendingRequest
    {
        return Http::withToken($this->oauthToken())
            ->acceptJson()
            ->timeout($this->config['timeout'])
            ->withOptions(['verify' => $this->config['verify']])
            ->baseUrl($this->config['oauth']['api_url']);
    }

    protected function oauthFindUserId(string $email): ?int
    {
        // Confirmado con token real: el email vive en la subpropiedad
        // "emails.email" (no "emails" a secas, que no filtra).
        $field = $this->config['requester_match'] === 'login' ? 'name' : 'emails.email';

        $response = $this->oauthHttp()->get('/Administration/User', [
            'filter' => "$field==$email",
            'limit' => 1,
        ]);

        $rows = $response->json();

        return is_array($rows) ? ($rows[0]['id'] ?? null) : null;
    }

    protected function oauthTicketsForUser(int $userId): array
    {
        // El API v2 NO permite filtrar por solicitante vía RSQL: los paths de
        // "team" (team.role/team.id) dan 500, y propiedades como
        // users_id_requester se ignoran devolviendo TODOS los tickets. Por
        // seguridad filtramos en el backend: cada ticket ya trae su "team",
        // así que nos quedamos con aquellos donde el usuario es requester.
        // NOTA: no escala a miles de tickets; para eso conviene el driver
        // legacy (apirest.php), cuyo buscador sí filtra por solicitante.
        $response = $this->oauthHttp()->get('/Assistance/Ticket', [
            'sort' => 'date_mod:desc', // orden por última actualización
            'limit' => 200,
        ]);

        $rows = collect($response->json() ?? [])
            // Excluimos los eliminados: GLPI hace soft-delete (is_deleted=true)
            // y el listado v2 los sigue devolviendo.
            ->filter(fn ($t) => is_array($t) && empty($t['is_deleted']) && $this->isRequester($t, $userId))
            ->all();

        return $this->normalizeTickets($rows);
    }

    /** ¿El usuario aparece como solicitante (requester) en el team del ticket? */
    protected function isRequester(array $ticket, int $userId): bool
    {
        foreach ($ticket['team'] ?? [] as $member) {
            if (($member['role'] ?? null) === 'requester'
                && ($member['type'] ?? null) === 'User'
                && (int) ($member['id'] ?? 0) === $userId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sube un archivo y lo vincula al ticket vía el API legacy (apirest.php).
     * El API v2 no permite vincular documentos a tickets, por eso este paso
     * usa siempre el driver legacy (necesita App-Token + User-Token).
     */
    protected function uploadDocument(int $ticketId, \Illuminate\Http\UploadedFile $file): void
    {
        if (blank($this->config['legacy']['app_token'] ?? null) || blank($this->config['legacy']['user_token'] ?? null)) {
            Log::warning('GLPI: adjuntos omitidos, faltan App-Token/User-Token legacy', ['ticket' => $ticketId]);

            return;
        }

        $name = $file->getClientOriginalName();

        try {
            // El manifest debe declarar el nombre del archivo y el ítem al que
            // se vincula; la parte binaria va como filename[0].
            $manifest = json_encode(['input' => [
                'name' => $name,
                '_filename' => [$name],
                'itemtype' => 'Ticket',
                'items_id' => $ticketId,
            ]]);

            $resp = $this->legacyHttp()
                ->attach('filename[0]', file_get_contents($file->getRealPath()), $name)
                ->post('/Document/', ['uploadManifest' => $manifest]);

            if ($resp->failed()) {
                Log::warning('GLPI: no se pudo adjuntar archivo (legacy)', [
                    'ticket' => $ticketId, 'file' => $name,
                    'status' => $resp->status(), 'body' => mb_substr($resp->body(), 0, 300),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('GLPI: excepción al adjuntar archivo', [
                'ticket' => $ticketId, 'file' => $name, 'error' => $e->getMessage(),
            ]);
        }
    }

    /* ===================================================================
     |  Driver legacy (apirest.php)
     * =================================================================== */

    protected function legacySessionToken(): string
    {
        return Cache::remember('glpi:session_token', now()->addMinutes(10), function () {
            $cfg = $this->config['legacy'];

            $response = Http::withHeaders([
                'Authorization' => 'user_token '.$cfg['user_token'],
                'App-Token' => $cfg['app_token'],
            ])
                ->acceptJson()
                ->timeout($this->config['timeout'])
                ->withOptions(['verify' => $this->config['verify']])
                ->get($cfg['api_url'].'/initSession');

            if ($response->failed()) {
                Log::error('GLPI initSession failed', ['status' => $response->status(), 'body' => $response->body()]);
                throw new GlpiException('No se pudo iniciar sesión en la API legacy de GLPI.');
            }

            return (string) $response->json('session_token');
        });
    }

    protected function legacyHttp(): PendingRequest
    {
        return Http::withHeaders([
            'Session-Token' => $this->legacySessionToken(),
            'App-Token' => $this->config['legacy']['app_token'],
        ])
            ->acceptJson()
            ->timeout($this->config['timeout'])
            ->withOptions(['verify' => $this->config['verify']])
            ->baseUrl($this->config['legacy']['api_url']);
    }

    /**
     * POST al API legacy. Con archivos → multipart (uploadManifest, para
     * imágenes inline/adjuntos); sin archivos → JSON plano {input}.
     *
     * @param  array<int, \Illuminate\Http\UploadedFile>  $files
     */
    protected function legacyPost(string $path, array $input, array $files = []): \Illuminate\Http\Client\Response
    {
        if ($files) {
            $req = $this->legacyHttp();
            foreach (array_values($files) as $i => $file) {
                $req = $req->attach("filename[$i]", file_get_contents($file->getRealPath()), $file->getClientOriginalName());
            }

            return $req->post($path, ['uploadManifest' => json_encode(['input' => $input])]);
        }

        return $this->legacyHttp()->post($path, ['input' => $input]);
    }

    protected function legacyFindUserId(string $email): ?int
    {
        // Búsqueda por email: criterio sobre el campo 5 (emails) del searchengine.
        $response = $this->legacyHttp()->get('/search/User', [
            'criteria' => [
                ['field' => 5, 'searchtype' => 'equals', 'value' => $email],
            ],
            'forcedisplay' => [2], // id
        ]);

        return $response->json('data.0.2');
    }

    /* ===================================================================
     |  Helpers
     * =================================================================== */

    protected function driver(): ?string
    {
        return $this->config['driver'] ?? null;
    }

    /**
     * Traduce el tipo del wizard al entero de GLPI (1=Incidencia, 2=Requerimiento).
     * Si no viene, usa el default de config.
     */
    protected function glpiType(?string $type): int
    {
        return match ($type) {
            'incident' => 1,
            'request' => 2,
            default => (int) $this->config['defaults']['type'],
        };
    }

    /**
     * Normaliza la forma de un ticket a una estructura estable para la UI,
     * independiente del driver/forma cruda de GLPI.
     */
    protected function normalizeTickets(array $rows): array
    {
        // Si GLPI devuelve un solo ticket (objeto), lo envolvemos en lista.
        if (isset($rows['id']) || isset($rows['name'])) {
            $rows = [$rows];
        }

        return collect($rows)
            ->filter(fn ($row) => is_array($row))
            ->map(function ($row) {
                // En v2 "status" es un objeto {id,name}; en legacy es un entero.
                $status = $row['status'] ?? $row['12'] ?? null;
                if (is_array($status)) {
                    $status = $status['id'] ?? null;
                }

                $date = $row['date'] ?? $row['date_creation'] ?? $row['15'] ?? null;
                $updated = $row['date_mod'] ?? $row['19'] ?? null;

                $title = $row['name'] ?? $row['1'] ?? '(sin título)';
                $fmt = fn ($d) => $d ? rescue(fn () => \Illuminate\Support\Carbon::parse($d)->format('d-m-Y'), $d, false) : null;

                return [
                    'id' => $row['id'] ?? $row['2'] ?? null,
                    'title' => $title,
                    'status' => $status !== null ? (int) $status : null,
                    'opened_at' => $fmt($date),
                    'updated_at' => $fmt($updated),
                    // Texto para el buscador del front: nombre + descripción (plano).
                    // Legacy trae la descripción en la clave '21'.
                    'search' => mb_strtolower($title.' '.mb_substr($this->plainText($row['content'] ?? $row['21'] ?? ''), 0, 1000)),
                ];
            })
            ->filter(fn ($t) => $t['id'] !== null)
            ->values()->all();
    }
}

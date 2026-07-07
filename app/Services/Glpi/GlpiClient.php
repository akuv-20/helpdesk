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
                $response = $this->oauthHttp()->get('/Administration/User/Me');

                if ($response->successful()) {
                    $who = $response->json('username') ?? $response->json('name') ?? 'usuario de servicio';

                    return ['ok' => true, 'message' => "Conexión OAuth correcta (autenticado como {$who})."];
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

    /**
     * Tickets cuyo solicitante es la persona autenticada.
     *
     * @return array<int, array<string, mixed>>
     */
    public function ticketsForRequester(string $email): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $userId = $this->findUserId($email);
        if ($userId === null) {
            return [];
        }

        return $this->driver() === 'oauth'
            ? $this->oauthTicketsForUser($userId)
            : $this->legacyTicketsForUser($userId);
    }

    /**
     * Crea un ticket fijando como solicitante a la persona autenticada.
     *
     * @param  array{title:string, content:string, urgency?:int, category_id?:int}  $data
     */
    public function createTicket(array $data, string $requesterEmail): array
    {
        if (! $this->isConfigured()) {
            throw new GlpiException('La conexión con GLPI no está configurada todavía.');
        }

        $userId = $this->findUserId($requesterEmail);
        if ($userId === null) {
            throw new GlpiException("No se encontró el usuario {$requesterEmail} en GLPI.");
        }

        return $this->driver() === 'oauth'
            ? $this->oauthCreateTicket($data, $userId)
            : $this->legacyCreateTicket($data, $userId);
    }

    /**
     * Categorías ITIL para el segundo paso del wizard.
     * En modo demo devuelve las 4 categorías conocidas de Verfrut.
     *
     * @return array<int, array{id:int, name:string}>
     */
    public function categories(): array
    {
        if (! $this->isConfigured()) {
            return [
                ['id' => 1, 'name' => 'Soporte'],
                ['id' => 2, 'name' => 'Sistemas'],
                ['id' => 3, 'name' => 'Enterprise Solutions'],
                ['id' => 4, 'name' => 'Infraestructure & Cybersecurity'],
            ];
        }

        return Cache::remember('glpi:itilcategories', now()->addMinutes(30), function () {
            $rows = $this->driver() === 'oauth'
                ? ($this->oauthHttp()->get('/Dropdowns/ITILCategory', ['limit' => 200])->json() ?? [])
                : ($this->legacyHttp()->get('/ITILCategory')->json() ?? []);

            return collect($rows)->map(fn ($r) => [
                'id' => (int) ($r['id'] ?? 0),
                'name' => $r['completename'] ?? $r['name'] ?? '—',
            ])->filter(fn ($c) => $c['id'] > 0)->values()->all();
        });
    }

    /* ===================================================================
     |  Resolución de usuario (mapeo Entra -> GLPI)
     * =================================================================== */

    public function findUserId(string $email): ?int
    {
        $field = $this->config['requester_match'] === 'login' ? 'name' : 'useremails';

        return Cache::remember(
            "glpi:userid:{$field}:".sha1($email),
            now()->addMinutes(30),
            fn () => $this->driver() === 'oauth'
                ? $this->oauthFindUserId($email)
                : $this->legacyFindUserId($email)
        );
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
        // Confirmado: recurso /Administration/User, filtrado RSQL (param "filter").
        $field = $this->config['requester_match'] === 'login' ? 'username' : 'emails';

        $response = $this->oauthHttp()->get('/Administration/User', [
            'filter' => "$field==$email",
            'limit' => 1,
        ]);

        $rows = $response->json();

        return is_array($rows) ? ($rows[0]['id'] ?? null) : null;
    }

    protected function oauthTicketsForUser(int $userId): array
    {
        // Confirmado: recurso /Assistance/Ticket, filtro RSQL + sort "campo:dir".
        // TODO[api]: confirmar con un token real la propiedad RSQL del solicitante
        // (el solicitante vive en "team" con role=requester).
        $response = $this->oauthHttp()->get('/Assistance/Ticket', [
            'filter' => "team.role==requester;team.id==$userId",
            'sort' => 'date:desc',
            'limit' => 100,
        ]);

        return $this->normalizeTickets($response->json() ?? []);
    }

    protected function oauthCreateTicket(array $data, int $userId): array
    {
        // Confirmado: POST /Assistance/Ticket. Categoría es objeto y el
        // solicitante se fija vía "team" (role=requester).
        // TODO[api]: confirmar con un token real la forma exacta del item de team.
        $payload = [
            'name' => $data['title'],
            'content' => $data['content'],
            'type' => $this->config['defaults']['type'],
            'urgency' => $data['urgency'] ?? $this->config['defaults']['urgency'],
            'team' => [
                ['type' => 'User', 'id' => $userId, 'role' => 'requester'],
            ],
        ];

        if (! empty($data['category_id'])) {
            $payload['category'] = ['id' => (int) $data['category_id']];
        }

        $response = $this->oauthHttp()->post('/Assistance/Ticket', $payload);

        if ($response->failed()) {
            throw new GlpiException('GLPI rechazó la creación del ticket: '.$response->body());
        }

        return $response->json() ?? [];
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

    protected function legacyTicketsForUser(int $userId): array
    {
        $response = $this->legacyHttp()->get('/search/Ticket', [
            'criteria' => [
                // 4 = solicitante (requester) en el searchengine de GLPI
                ['field' => 4, 'searchtype' => 'equals', 'value' => $userId],
            ],
            'forcedisplay' => [2, 1, 12, 15, 19], // id, título, estado, fecha apertura, última actualización
            'sort' => 15,
            'order' => 'DESC',
        ]);

        return $this->normalizeTickets($response->json('data') ?? []);
    }

    protected function legacyCreateTicket(array $data, int $userId): array
    {
        $response = $this->legacyHttp()->post('/Ticket', [
            'input' => [
                'name' => $data['title'],
                'content' => $data['content'],
                'type' => $this->config['defaults']['type'],
                'urgency' => $data['urgency'] ?? $this->config['defaults']['urgency'],
                'itilcategories_id' => $data['category_id'] ?? 0,
                '_users_id_requester' => $userId,
            ],
        ]);

        if ($response->failed()) {
            throw new GlpiException('GLPI rechazó la creación del ticket: '.$response->body());
        }

        return $response->json() ?? [];
    }

    /* ===================================================================
     |  Helpers
     * =================================================================== */

    protected function driver(): ?string
    {
        return $this->config['driver'] ?? null;
    }

    /**
     * Normaliza la forma de un ticket a una estructura estable para la UI,
     * independiente del driver/forma cruda de GLPI.
     */
    protected function normalizeTickets(array $rows): array
    {
        return collect($rows)->map(function ($row) {
            // En v2 "status" es un objeto {id,name}; en legacy es un entero.
            $status = $row['status'] ?? $row['12'] ?? null;
            if (is_array($status)) {
                $status = $status['id'] ?? $status['name'] ?? null;
            }

            return [
                'id' => $row['id'] ?? $row['2'] ?? null,
                'title' => $row['name'] ?? $row['1'] ?? '(sin título)',
                'status' => $status,
                'opened_at' => $row['date'] ?? $row['15'] ?? null,
            ];
        })->filter(fn ($t) => $t['id'] !== null)->values()->all();
    }
}

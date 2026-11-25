<?php

namespace App\Services\Entra;

use App\Models\EntraUserLink;
use App\Services\Glpi\GlpiClient;
use App\Services\Glpi\GlpiException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Importación de usuarios Entra ID → GLPI (módulo admin /admin/usuarios-entra).
 *
 * Flujo: se baja el directorio COMPLETO de Entra (≈6.300 usuarios, ~12 s) y se
 * cachea 30 min; los filtros (país, dominio, departamento, texto…) se aplican
 * en memoria, porque los datos de Entra vienen sucios (país "CL"/"Chile"/
 * "CHILE"…) y así se normalizan. Cada usuario se cruza con GLPI (vínculo por
 * object id → correo → UPN) y se decide: crear, actualizar, sin cambios u omitir.
 *
 * Reglas:
 *  - Login en GLPI = correo (mail, o el UPN si no tiene), como el resto del portal.
 *  - Solo se CREAN cuentas habilitadas de tipo miembro (no invitados).
 *  - Al actualizar solo se pisan campos que en Entra vienen con valor.
 *  - Nunca se REACTIVA a alguien deshabilitado en GLPI (TI desactiva ahí cuentas
 *    genéricas). Si Entra lo tiene deshabilitado, sí se desactiva en GLPI.
 *  - Zona horaria según el país (Country::timezone, la misma regla del login): en
 *    el alta siempre; en existentes solo si en GLPI está vacía.
 */
class EntraUserImport
{
    public const CACHE_KEY = 'entra:directory';

    private const SELECT = 'id,userPrincipalName,mail,displayName,givenName,surname,jobTitle,department,city,country,mobilePhone,businessPhones,accountEnabled,userType,employeeId';

    /** Campo de GLPI => etiqueta visible en el detalle de cambios. */
    public const FIELD_LABELS = [
        'firstname' => 'Nombre',
        'realname' => 'Apellidos',
        'mobile' => 'Móvil',
        'phone' => 'Teléfono',
        'registration_number' => 'N° de empleado',
        'usertitles_id' => 'Cargo',
        'usercategories_id' => 'Categoría (departamento)',
        'timezone' => 'Zona horaria',
        'is_active' => 'Activo',
    ];

    public function __construct(private EntraGraph $graph, private GlpiClient $glpi)
    {
    }

    /* ---------------------------------------------------------------
     |  Directorio de Entra
     * --------------------------------------------------------------- */

    /**
     * Directorio completo de Entra, normalizado y cacheado 30 min.
     *
     * @return array{fetched_at: string, users: array<int, array<string, mixed>>}
     */
    public function directory(bool $refresh = false): array
    {
        if ($refresh) {
            Cache::forget(self::CACHE_KEY);
        }

        return Cache::remember(self::CACHE_KEY, now()->addMinutes(30), function () {
            $users = [];
            $resp = $this->graph->http(60)->get('/users', ['$select' => self::SELECT, '$top' => 999]);

            for ($guard = 0; $guard < 100; $guard++) {
                if ($resp->failed()) {
                    throw new \RuntimeException('Graph respondió '.$resp->status().': '.mb_substr((string) ($resp->json('error.message') ?? $resp->body()), 0, 300));
                }

                $page = (array) $resp->json();
                foreach ($page['value'] ?? [] as $u) {
                    $users[] = $this->normalize($u);
                }

                // Ojo: "@odata.nextLink" tiene un punto → no usar json('…') (dot notation).
                $next = $page['@odata.nextLink'] ?? null;
                if (! $next) {
                    break;
                }
                $resp = $this->graph->http(60)->get($next);
            }

            usort($users, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

            return ['fetched_at' => now()->toIso8601String(), 'users' => $users];
        });
    }

    /** Fila compacta y normalizada de un usuario de Graph. */
    public function normalize(array $u): array
    {
        $upn = (string) ($u['userPrincipalName'] ?? '');
        $mail = (string) ($u['mail'] ?? '');
        $email = mb_strtolower($mail !== '' ? $mail : $upn);
        $department = self::clean($u['department'] ?? null, fixCase: true);

        return [
            'id' => (string) $u['id'],
            'upn' => mb_strtolower($upn),
            'email' => $email,
            'domain' => (string) mb_substr(mb_strrchr($email, '@') ?: '', 1),
            'name' => trim((string) ($u['displayName'] ?? '')) ?: $email,
            'given' => self::clean($u['givenName'] ?? null, fixCase: true),
            'surname' => self::clean($u['surname'] ?? null, fixCase: true),
            'job_title' => self::clean($u['jobTitle'] ?? null, fixCase: true),
            'department' => $department,
            'department_key' => mb_strtolower($department),
            'country' => self::normalizeCountry($u['country'] ?? null),
            'timezone' => Country::timezone($u['country'] ?? null) ?? '',
            'city' => self::clean($u['city'] ?? null, fixCase: true),
            'mobile' => self::clean($u['mobilePhone'] ?? null),
            'phone' => self::clean(($u['businessPhones'] ?? [])[0] ?? null),
            'employee_id' => self::clean($u['employeeId'] ?? null),
            'enabled' => (bool) ($u['accountEnabled'] ?? false),
            'member' => ($u['userType'] ?? 'Member') === 'Member',
            'has_mail' => $mail !== '',
        ];
    }

    /** Valores de relleno que en Entra significan "sin dato". */
    private const PLACEHOLDERS = ['0', '-', '--', '.', 'na', 'n/a', '(en blanco)', 'en blanco', 'sin informacion', 'sin información', 'null'];

    /** Partículas que van en minúscula al corregir nombres en MAYÚSCULAS. */
    private const PARTICLES = ['de', 'del', 'la', 'las', 'los', 'y', 'e', 'van', 'von', 'da', 'di', 'do', 'dos'];

    /**
     * Limpia un valor de Entra: quita espacios y los rellenos tipo "0" o
     * "(en blanco)". Con $fixCase, un texto TODO EN MAYÚSCULAS ("ADOLFO DEL
     * ROSARIO") pasa a "Adolfo del Rosario"; si ya viene mezclado, se respeta.
     */
    public static function clean(?string $value, bool $fixCase = false): string
    {
        $v = trim(preg_replace('/\s+/u', ' ', (string) $value));
        if ($v === '' || in_array(mb_strtolower($v), self::PLACEHOLDERS, true)) {
            return '';
        }

        if ($fixCase && preg_match('/\p{Lu}/u', $v) && mb_strtoupper($v) === $v && mb_strlen($v) > 3) {
            $words = explode(' ', mb_convert_case(mb_strtolower($v), MB_CASE_TITLE));
            foreach ($words as $i => $w) {
                if ($i > 0 && in_array(mb_strtolower($w), self::PARTICLES, true)) {
                    $words[$i] = mb_strtolower($w);
                }
            }
            $v = implode(' ', $words);
        }

        return $v;
    }

    /** ¿El valor trae caracteres dañados en origen ("LABRA�A")? */
    public static function isDamaged(string $value): bool
    {
        return str_contains($value, "\u{FFFD}");
    }

    /**
     * Unifica las variantes de país que trae Entra ("CL", "CHILE", "Unifrutti
     * Chile", "Perú", "Per�"…). Los no mapeados se dejan tal cual.
     */
    public static function normalizeCountry(?string $country): string
    {
        return Country::normalize($country);
    }

    /* ---------------------------------------------------------------
     |  Filtros y facetas
     * --------------------------------------------------------------- */

    /**
     * @param  array<int, array<string, mixed>>  $users
     * @param  array<string, mixed>  $f  q, country, domain, department, only_enabled, only_named, only_mail, glpi_status
     * @param  array<string, string>|null  $statuses  id Entra => estado GLPI (para filtrar por glpi_status)
     * @return array<int, array<string, mixed>>
     */
    public function filter(array $users, array $f, ?array $statuses = null): array
    {
        $q = mb_strtolower(trim((string) ($f['q'] ?? '')));

        return array_values(array_filter($users, function ($u) use ($f, $q, $statuses) {
            if (! $u['member']) {
                return false; // invitados nunca
            }
            if (! empty($f['only_enabled']) && ! $u['enabled']) {
                return false;
            }
            if (! empty($f['only_named']) && ($u['given'] === '' || $u['surname'] === '')) {
                return false;
            }
            if (! empty($f['only_mail']) && ! $u['has_mail']) {
                return false;
            }
            if (filled($f['country'] ?? null) && $u['country'] !== $f['country']) {
                return false;
            }
            if (filled($f['domain'] ?? null) && $u['domain'] !== $f['domain']) {
                return false;
            }
            if (filled($f['department'] ?? null) && $u['department_key'] !== mb_strtolower($f['department'])) {
                return false;
            }
            if ($q !== '' && ! str_contains(mb_strtolower($u['name'].' '.$u['email'].' '.$u['upn'].' '.$u['job_title']), $q)) {
                return false;
            }
            if ($statuses !== null && filled($f['glpi_status'] ?? null) && ($statuses[$u['id']] ?? 'new') !== $f['glpi_status']) {
                return false;
            }

            return true;
        }));
    }

    /**
     * Opciones (con conteo) para los selects de filtro, sobre los miembros
     * habilitados: país, dominio y departamento (agrupado sin mayúsculas).
     *
     * @return array{countries: array<int, array{value:string,count:int}>, domains: array<int, array{value:string,count:int}>, departments: array<int, array{value:string,count:int}>}
     */
    public function facets(array $users): array
    {
        $base = array_filter($users, fn ($u) => $u['member'] && $u['enabled']);

        $count = function (string $field, ?string $groupBy = null) use ($base) {
            return collect($base)
                ->filter(fn ($u) => $u[$field] !== '')
                ->groupBy(fn ($u) => $groupBy ? $u[$groupBy] : $u[$field])
                ->map(fn ($g) => ['value' => $g->countBy($field)->sortDesc()->keys()->first(), 'count' => $g->count()])
                ->sortByDesc('count')
                ->values()
                ->all();
        };

        return [
            'countries' => $count('country'),
            'domains' => $count('domain'),
            'departments' => $count('department', 'department_key'),
        ];
    }

    /* ---------------------------------------------------------------
     |  Cruce con GLPI
     * --------------------------------------------------------------- */

    /**
     * Id de GLPI de un usuario de Entra: vínculo guardado → correo → UPN.
     *
     * @param  array{users: array<int, array>, byEmail: array<string, int>}  $index
     * @param  array<string, int>  $links  azure_oid => glpi_user_id
     */
    public function matchGlpiId(array $u, array $index, array $links): ?int
    {
        $linked = $links[$u['id']] ?? null;
        if ($linked && isset($index['users'][$linked])) {
            return $linked;
        }

        return $index['byEmail'][$u['email']] ?? $index['byEmail'][$u['upn']] ?? null;
    }

    /**
     * Estado GLPI de cada usuario, para la tabla: new | exists | disabled.
     *
     * @param  array<int, array<string, mixed>>  $users
     * @return array<string, array{status:string, glpi_id:?int}>
     */
    public function statuses(array $users): array
    {
        $index = $this->glpi->userIndex();
        $links = $this->links();

        $out = [];
        foreach ($users as $u) {
            $id = $this->matchGlpiId($u, $index, $links);
            $g = $id ? $index['users'][$id] : null;
            $out[$u['id']] = [
                'status' => ! $g ? 'new' : (($g['is_active'] && ! $g['is_deleted']) ? 'exists' : 'disabled'),
                'glpi_id' => $id,
            ];
        }

        return $out;
    }

    /** @return array<string, int> azure_oid => glpi_user_id */
    protected function links(): array
    {
        return EntraUserLink::query()->pluck('glpi_user_id', 'azure_oid')->map(fn ($v) => (int) $v)->all();
    }

    /* ---------------------------------------------------------------
     |  Importación
     * --------------------------------------------------------------- */

    /**
     * Importa (o simula, con $dryRun) los usuarios de Entra indicados.
     *
     * @param  array<int, string>  $entraIds
     * @return array<int, array{id:string, name:string, email:string, action:string, glpi_id:?int, changes:array<int, array{field:string, from:?string, to:?string}>, message:?string}>
     */
    public function import(array $entraIds, bool $dryRun, bool $updateExisting): array
    {
        $byId = collect($this->directory()['users'])->keyBy('id');
        $index = $this->glpi->userIndex();
        $links = $this->links();
        $titles = $this->glpi->dropdownNames('UserTitle');
        $categories = $this->glpi->dropdownNames('UserCategory');

        $results = [];
        foreach (array_unique($entraIds) as $oid) {
            $u = $byId->get($oid);
            if (! $u) {
                $results[] = $this->result(['id' => $oid, 'name' => $oid, 'email' => ''], 'skipped', null, [], 'No está en el directorio de Entra (actualiza el directorio).');

                continue;
            }

            try {
                $results[] = $this->importOne($u, $index, $links, $titles, $categories, $dryRun, $updateExisting);
            } catch (\Throwable $e) {
                Log::warning('Importación Entra→GLPI: error', ['email' => $u['email'], 'error' => $e->getMessage()]);
                $results[] = $this->result($u, 'error', null, [], $e instanceof GlpiException ? $e->getMessage() : 'Error inesperado: '.mb_substr($e->getMessage(), 0, 200));
            }
        }

        return $results;
    }

    protected function importOne(array $u, array $index, array $links, array $titles, array $categories, bool $dryRun, bool $updateExisting): array
    {
        if (! $u['member']) {
            return $this->result($u, 'skipped', null, [], 'Es una cuenta invitada.');
        }
        if ($u['email'] === '' || ! str_contains($u['email'], '@')) {
            return $this->result($u, 'skipped', null, [], 'No tiene correo.');
        }

        $glpiId = $this->matchGlpiId($u, $index, $links);

        // ---- Alta ----
        if ($glpiId === null) {
            if (! $u['enabled']) {
                return $this->result($u, 'skipped', null, [], 'La cuenta está deshabilitada en Entra: no se crea.');
            }

            // Zona horaria por país (misma regla que el login, App\Services\Entra\Country).
            $desired = $this->desired($u) + array_filter(['timezone' => $u['timezone']]);
            $changes = $this->diff([], $desired, $titles, $categories);
            $damaged = $this->damagedNote($u);

            if ($dryRun) {
                return $this->result($u, 'created', null, $changes, trim('Se crearía. '.$damaged));
            }

            $id = $this->glpi->createImportedUser($u['email'], $this->resolveDropdowns($desired));
            $this->link($u, $id, 'created');

            return $this->result($u, 'created', $id, $changes, $damaged);
        }

        // ---- Existente ----
        $current = $index['users'][$glpiId];
        $wasDisabled = ! $current['is_active'] || $current['is_deleted'];

        $desired = $updateExisting ? $this->desired($u) : [];
        // Zona horaria: solo se completa si en GLPI está vacía (igual que en el
        // login, ensureUserTimezone); una zona ya puesta no se pisa.
        if ($updateExisting && $u['timezone'] !== '' && ($current['timezone'] ?? '') === '') {
            $desired['timezone'] = $u['timezone'];
        }
        // Entra deshabilitado → se desactiva en GLPI. Nunca se reactiva.
        if (! $u['enabled'] && $current['is_active']) {
            $desired['is_active'] = 0;
        }

        $changes = $this->diff($current, $desired, $titles, $categories);
        $note = trim(($wasDisabled ? 'Deshabilitado en GLPI: se respeta (no se reactiva). ' : '').($updateExisting ? $this->damagedNote($u) : '')) ?: null;

        if ($changes === []) {
            if (! $dryRun) {
                $this->link($u, $glpiId, 'unchanged');
            }

            return $this->result($u, 'unchanged', $glpiId, [], $note);
        }

        if ($dryRun) {
            return $this->result($u, 'updated', $glpiId, $changes, trim('Se actualizaría. '.$note));
        }

        $input = array_intersect_key($this->resolveDropdowns($desired), array_flip(array_column($changes, 'field')));
        $this->glpi->updateImportedUser($glpiId, $input);
        $this->link($u, $glpiId, 'updated');

        return $this->result($u, 'updated', $glpiId, $changes, $note);
    }

    /**
     * Valores que Entra quiere en GLPI (solo los que vienen con dato). Cargo y
     * departamento van como NOMBRE (_title/_category) hasta resolverse a id.
     */
    protected function desired(array $u): array
    {
        // Los valores dañados en Entra ("LABRA�A") no se escriben: ver damagedNote().
        return array_filter($this->entraValues($u), fn ($v) => $v !== '' && ! self::isDamaged($v));
    }

    /** @return array<string, string> campo => valor de Entra (cargo/depto por nombre) */
    protected function entraValues(array $u): array
    {
        return [
            'firstname' => $u['given'],
            'realname' => $u['surname'],
            'mobile' => $u['mobile'],
            'phone' => $u['phone'],
            'registration_number' => $u['employee_id'],
            '_title' => $u['job_title'],
            '_category' => $u['department'],
        ];
    }

    /** Aviso con los campos que Entra trae con caracteres dañados (o null). */
    protected function damagedNote(array $u): ?string
    {
        $labels = [];
        foreach ($this->entraValues($u) as $field => $v) {
            if (self::isDamaged($v)) {
                $labels[] = self::FIELD_LABELS[match ($field) {
                    '_title' => 'usertitles_id',
                    '_category' => 'usercategories_id',
                    default => $field,
                }];
            }
        }

        return $labels ? 'Entra trae caracteres dañados en '.implode(', ', $labels).' (no se copiaron; corrígelo en Entra).' : null;
    }

    /** Lista de cambios campo a campo (comparando nombres para cargo/categoría). */
    protected function diff(array $current, array $desired, array $titles, array $categories): array
    {
        $changes = [];
        foreach ($desired as $field => $to) {
            [$glpiField, $from] = match ($field) {
                '_title' => ['usertitles_id', $titles[$current['usertitles_id'] ?? 0] ?? ''],
                '_category' => ['usercategories_id', $categories[$current['usercategories_id'] ?? 0] ?? ''],
                'is_active' => ['is_active', ($current['is_active'] ?? true) ? 'Sí' : 'No'],
                default => [$field, (string) ($current[$field] ?? '')],
            };
            $toLabel = $field === 'is_active' ? ($to ? 'Sí' : 'No') : (string) $to;

            if (mb_strtolower(trim($from)) !== mb_strtolower(trim($toLabel))) {
                $changes[] = ['field' => $glpiField, 'label' => self::FIELD_LABELS[$glpiField] ?? $glpiField, 'from' => $from !== '' ? $from : null, 'to' => $toLabel];
            }
        }

        return $changes;
    }

    /** Convierte _title/_category a ids de GLPI (creándolos si no existen). */
    protected function resolveDropdowns(array $desired): array
    {
        if (isset($desired['_title'])) {
            $desired['usertitles_id'] = $this->glpi->dropdownId('UserTitle', $desired['_title']);
        }
        if (isset($desired['_category'])) {
            $desired['usercategories_id'] = $this->glpi->dropdownId('UserCategory', $desired['_category']);
        }
        unset($desired['_title'], $desired['_category']);

        return array_filter($desired, fn ($v) => $v !== null);
    }

    protected function link(array $u, int $glpiId, string $action): void
    {
        EntraUserLink::updateOrCreate(['azure_oid' => $u['id']], [
            'glpi_user_id' => $glpiId,
            'email' => $u['email'],
            'last_action' => $action,
            'last_synced_at' => now(),
        ]);
    }

    protected function result(array $u, string $action, ?int $glpiId, array $changes, ?string $message = null): array
    {
        return [
            'id' => $u['id'],
            'name' => $u['name'],
            'email' => $u['email'],
            'action' => $action,
            'glpi_id' => $glpiId,
            'changes' => $changes,
            'message' => $message,
        ];
    }
}

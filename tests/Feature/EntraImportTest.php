<?php

namespace Tests\Feature;

use App\Models\EntraUserLink;
use App\Models\User;
use App\Services\Entra\Country;
use App\Services\Entra\EntraUserImport;
use App\Services\Glpi\GlpiClient;
use App\Services\Glpi\GlpiConfig;
use App\Services\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EntraImportTest extends TestCase
{
    use RefreshDatabase;

    /** Usuarios en GLPI simulados (id => fila de /User). */
    private array $glpiUsers = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['ticket.admins' => ['admin@verfrut.cl']]);

        $settings = app(Settings::class);
        $settings->setMany([
            'glpi.base_url' => 'https://helpdesk.test',
            'glpi.driver' => 'legacy',
            'glpi.legacy.app_token' => 'app',
            'glpi.legacy.user_token' => 'usr',
            'entra.client_id' => 'cid',
            'entra.client_secret' => 'sec',
            'entra.tenant_id' => 'tenant',
        ]);
        $this->app->instance(GlpiClient::class, new GlpiClient(GlpiConfig::resolve($settings)));

        $this->glpiUsers = [
            // Ya existe y activo, con nombre distinto al de Entra.
            10 => ['id' => 10, 'name' => 'ana.perez@unifrutti.com', 'firstname' => 'Ana', 'realname' => 'Viejo', 'is_active' => 1, 'is_deleted' => 0, 'usertitles_id' => 0, 'usercategories_id' => 0],
            // Cuenta genérica deshabilitada a mano en GLPI.
            11 => ['id' => 11, 'name' => 'operaciones@unifrutti.com', 'firstname' => '', 'realname' => '', 'is_active' => 0, 'is_deleted' => 0, 'usertitles_id' => 0, 'usercategories_id' => 0],
        ];

        $graphUsers = [
            $this->entra('oid-new', 'Juan', 'Soto', 'juan.soto@unifrutti.com', ['jobTitle' => 'Analista', 'department' => 'Finance', 'country' => 'CL']),
            $this->entra('oid-ana', 'Ana', 'Pérez', 'ana.perez@unifrutti.com', ['country' => 'CHILE']),
            $this->entra('oid-gen', 'Operaciones', 'Planta', 'operaciones@unifrutti.com', ['country' => 'PE']),
            $this->entra('oid-off', 'Baja', 'Total', 'baja@unifrutti.com', ['accountEnabled' => false]),
            $this->entra('oid-guest', 'Invitado', 'Externo', 'x@gmail.com', ['userType' => 'Guest']),
        ];

        Http::fake(function (HttpRequest $req) use ($graphUsers) {
            $url = $req->url();
            $path = parse_url($url, PHP_URL_PATH);

            return match (true) {
                str_contains($url, 'login.microsoftonline.com') => Http::response(['access_token' => 'g']),
                str_contains($url, 'graph.microsoft.com') => Http::response(['value' => $graphUsers]),
                str_ends_with($path, '/initSession') => Http::response(['session_token' => 's']),
                $req->method() === 'GET' && str_ends_with($path, '/apirest.php/User') => Http::response(array_values($this->glpiUsers), 200, ['Content-Range' => '0-1/'.count($this->glpiUsers)]),
                $req->method() === 'GET' && str_ends_with($path, '/UserEmail') => Http::response([], 200, ['Content-Range' => '0-0/0']),
                $req->method() === 'GET' && (str_ends_with($path, '/UserTitle') || str_ends_with($path, '/UserCategory')) => Http::response([], 200, ['Content-Range' => '0-0/0']),
                $req->method() === 'POST' && str_ends_with($path, '/User') => Http::response(['id' => 99], 201),
                $req->method() === 'POST' && (str_ends_with($path, '/UserTitle') || str_ends_with($path, '/UserCategory')) => Http::response(['id' => 7], 201),
                $req->method() === 'GET' && str_contains($path, '/Profile_User') => Http::response([['id' => 1, 'profiles_id' => 1]]),
                default => Http::response([], 200),
            };
        });
    }

    private function entra(string $id, string $given, string $surname, string $mail, array $extra = []): array
    {
        return array_merge([
            'id' => $id,
            'userPrincipalName' => $mail,
            'mail' => $mail,
            'displayName' => "$given $surname",
            'givenName' => $given,
            'surname' => $surname,
            'accountEnabled' => true,
            'userType' => 'Member',
        ], $extra);
    }

    private function admin(): User
    {
        return User::firstWhere('email', 'admin@verfrut.cl') ?? User::factory()->create(['email' => 'admin@verfrut.cl']);
    }

    public function test_country_variants_are_unified(): void
    {
        foreach (['CL', 'CHILE', 'Chile', 'Unifrutti Chile'] as $v) {
            $this->assertSame('Chile', EntraUserImport::normalizeCountry($v));
        }
        foreach (['PE', 'Peru', 'Perú', "Per\u{FFFD}"] as $v) {
            $this->assertSame('Perú', EntraUserImport::normalizeCountry($v));
        }
        $this->assertSame('', EntraUserImport::normalizeCountry(null));
    }

    public function test_entra_values_are_cleaned(): void
    {
        $this->assertSame('Adolfo del Rosario', EntraUserImport::clean('ADOLFO  DEL ROSARIO', fixCase: true));
        $this->assertSame('Farming', EntraUserImport::clean('FARMING', fixCase: true));
        $this->assertSame('María José', EntraUserImport::clean('María José', fixCase: true)); // mezclado: se respeta
        $this->assertSame('IT', EntraUserImport::clean('IT', fixCase: true));                 // siglas cortas
        $this->assertSame('', EntraUserImport::clean('0'));
        $this->assertSame('', EntraUserImport::clean('(en blanco)'));
        $this->assertTrue(EntraUserImport::isDamaged("LABRA\u{FFFD}A"));
    }

    public function test_damaged_values_are_not_written(): void
    {
        $this->glpiUsers[10]['realname'] = 'Pérez'; // así el único cambio sería el apellido dañado
        $import = app(EntraUserImport::class);
        $u = $import->normalize($this->entra('oid-ana', 'Ana', "P\u{FFFD}rez", 'ana.perez@unifrutti.com'));
        cache()->put(EntraUserImport::CACHE_KEY, ['fetched_at' => now()->toIso8601String(), 'users' => [$u]]);

        $res = $import->import(['oid-ana'], dryRun: false, updateExisting: true)[0];

        $this->assertSame('unchanged', $res['action']);
        $this->assertStringContainsString('dañados en Apellidos', $res['message']);
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'PUT');
    }

    public function test_non_admin_cannot_open_the_module(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/usuarios-entra')->assertForbidden();
        $this->actingAs($user)->postJson('/admin/usuarios-entra/directorio')->assertForbidden();
        $this->actingAs($user)->postJson('/admin/usuarios-entra/importar', ['ids' => ['oid-new']])->assertForbidden();
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'graph.microsoft.com') || $r->method() === 'POST');
    }

    public function test_directory_lists_members_with_glpi_status_and_filters(): void
    {
        $res = $this->actingAs($this->admin())
            ->postJson('/admin/usuarios-entra/directorio', ['only_enabled' => true])
            ->assertOk()
            ->assertJson(['ok' => true, 'total' => 3]) // sin invitado ni deshabilitado
            ->json();

        $status = collect($res['rows'])->pluck('glpi_status', 'id');
        $this->assertSame('new', $status['oid-new']);
        $this->assertSame('exists', $status['oid-ana']);
        $this->assertSame('disabled', $status['oid-gen']);
        $this->assertContains('Chile', collect($res['facets']['countries'])->pluck('value'));

        $this->actingAs($this->admin())
            ->postJson('/admin/usuarios-entra/directorio', ['only_enabled' => true, 'country' => 'Chile', 'glpi_status' => 'new'])
            ->assertJson(['total' => 1, 'ids' => ['oid-new']]);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $res = $this->actingAs($this->admin())
            ->postJson('/admin/usuarios-entra/importar', ['ids' => ['oid-new', 'oid-ana'], 'dry_run' => true])
            ->assertOk()
            ->json('results');

        $this->assertSame(['created', 'updated'], array_column($res, 'action'));
        Http::assertNotSent(fn (HttpRequest $r) => in_array($r->method(), ['POST', 'PUT'], true) && str_contains($r->url(), 'apirest.php'));
        $this->assertSame(0, EntraUserLink::count());
    }

    public function test_import_creates_new_user_with_fields_and_link(): void
    {
        $res = $this->actingAs($this->admin())
            ->postJson('/admin/usuarios-entra/importar', ['ids' => ['oid-new']])
            ->assertOk()
            ->json('results.0');

        $this->assertSame('created', $res['action']);
        $this->assertSame(99, $res['glpi_id']);

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST'
            && str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/User')
            && $r['input']['name'] === 'juan.soto@unifrutti.com'
            && $r['input']['firstname'] === 'Juan'
            && $r['input']['realname'] === 'Soto'
            && $r['input']['usertitles_id'] === 7
            && $r['input']['usercategories_id'] === 7
            && $r['input']['timezone'] === 'America/Santiago');
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/UserEmail'));

        $this->assertDatabaseHas('entra_user_links', ['azure_oid' => 'oid-new', 'glpi_user_id' => 99, 'last_action' => 'created']);
    }

    public function test_existing_user_is_updated_only_on_changed_fields(): void
    {
        $res = $this->actingAs($this->admin())
            ->postJson('/admin/usuarios-entra/importar', ['ids' => ['oid-ana']])
            ->json('results.0');

        $this->assertSame('updated', $res['action']);
        // Apellido distinto + zona horaria vacía en GLPI → se completa (país CHILE).
        $this->assertSame(['realname', 'timezone'], array_column($res['changes'], 'field'));
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PUT'
            && str_ends_with($r->url(), '/User/10')
            && $r['input'] === ['id' => 10, 'realname' => 'Pérez', 'timezone' => 'America/Santiago']);
    }

    public function test_existing_timezone_in_glpi_is_not_overwritten(): void
    {
        $this->glpiUsers[10]['timezone'] = 'America/Lima';

        $res = $this->actingAs($this->admin())
            ->postJson('/admin/usuarios-entra/importar', ['ids' => ['oid-ana']])
            ->json('results.0');

        $this->assertNotContains('timezone', array_column($res['changes'], 'field'));
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'PUT' && isset($r['input']['timezone']));
    }

    public function test_country_maps_to_timezone_like_the_login(): void
    {
        $this->assertSame('America/Santiago', Country::timezone('Unifrutti Chile'));
        $this->assertSame('America/Santiago', Country::timezone('CL'));
        $this->assertSame('America/Lima', Country::timezone("Per\u{FFFD}"));
        $this->assertSame('Africa/Johannesburg', Country::timezone('South Africa'));
        $this->assertNull(Country::timezone('Narnia'));
        $this->assertNull(Country::timezone(null));
    }

    public function test_user_disabled_in_glpi_is_never_reactivated(): void
    {
        $res = $this->actingAs($this->admin())
            ->postJson('/admin/usuarios-entra/importar', ['ids' => ['oid-gen']])
            ->json('results.0');

        $this->assertNotContains('is_active', array_column($res['changes'], 'field'));
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'PUT' && isset($r['input']['is_active']));
    }

    public function test_disabled_or_guest_accounts_are_not_created(): void
    {
        $res = $this->actingAs($this->admin())
            ->postJson('/admin/usuarios-entra/importar', ['ids' => ['oid-off', 'oid-guest']])
            ->json('results');

        $this->assertSame(['skipped', 'skipped'], array_column($res, 'action'));
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/User'));
    }

    public function test_entra_disabled_deactivates_existing_glpi_user(): void
    {
        $this->glpiUsers[12] = ['id' => 12, 'name' => 'baja@unifrutti.com', 'firstname' => 'Baja', 'realname' => 'Total', 'is_active' => 1, 'is_deleted' => 0, 'usertitles_id' => 0, 'usercategories_id' => 0];

        $res = $this->actingAs($this->admin())
            ->postJson('/admin/usuarios-entra/importar', ['ids' => ['oid-off']])
            ->json('results.0');

        $this->assertSame('updated', $res['action']);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PUT'
            && str_ends_with($r->url(), '/User/12')
            && ($r['input']['is_active'] ?? null) === 0);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Glpi\GlpiClient;
use App\Services\Glpi\GlpiConfig;
use App\Services\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class IntegrationModuleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        config(['ticket.admins' => ['admin@verfrut.cl']]);

        return User::factory()->create(['email' => 'admin@verfrut.cl']);
    }

    public function test_non_admin_cannot_access_connection_module(): void
    {
        $user = User::factory()->create(['email' => 'user@verfrut.cl']);

        $this->actingAs($user)->get('/admin/conexion')->assertForbidden();
    }

    public function test_admin_sees_connection_page_with_prefilled_values(): void
    {
        // Los secretos se precargan a propósito (admin only) para verlos/copiarlos.
        app(Settings::class)->setMany(['glpi.oauth.client_secret' => 'secret-xyz']);

        $this->actingAs($this->admin())
            ->get('/admin/conexion')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Settings/Glpi')
                ->has('values')
                ->where('values.oauth_client_secret', 'secret-xyz')
            );
    }

    public function test_admin_can_save_settings_and_secrets_are_encrypted(): void
    {
        $this->actingAs($this->admin())
            ->put('/admin/conexion', [
                'base_url' => 'https://helpdesk.verfrut.cl',
                'driver' => 'oauth',
                'oauth_client_id' => 'client-123',
                'oauth_client_secret' => 'super-secret',
                'oauth_username' => 'svc_portal',
                'oauth_password' => 'p4ss',
                'oauth_scope' => 'api',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        // No secreto: en claro.
        $this->assertSame('client-123', app(Settings::class)->get('glpi.oauth.client_id'));

        // Secreto: cifrado en BD, pero recuperable descifrado.
        $row = Setting::where('key', 'glpi.oauth.client_secret')->first();
        $this->assertTrue($row->is_encrypted);
        $this->assertNotSame('super-secret', $row->value);
        $this->assertSame('super-secret', app(Settings::class)->get('glpi.oauth.client_secret'));
    }

    public function test_blank_secret_keeps_previous_value(): void
    {
        $settings = app(Settings::class);
        $settings->setMany(['glpi.oauth.password' => 'original']);

        $this->actingAs($this->admin())
            ->put('/admin/conexion', [
                'driver' => 'oauth',
                'oauth_username' => 'svc_portal',
                'oauth_password' => '', // en blanco => conservar
            ])
            ->assertRedirect();

        $this->assertSame('original', app(Settings::class)->get('glpi.oauth.password'));
    }

    public function test_saved_settings_make_client_configured(): void
    {
        $settings = app(Settings::class);
        $settings->setMany([
            'glpi.base_url' => 'https://helpdesk.verfrut.cl',
            'glpi.driver' => 'oauth',
            'glpi.oauth.client_id' => 'c',
            'glpi.oauth.client_secret' => 's',
            'glpi.oauth.username' => 'u',
            'glpi.oauth.password' => 'p',
        ]);

        $client = new GlpiClient(GlpiConfig::resolve($settings));

        $this->assertTrue($client->isConfigured());
    }

    public function test_test_endpoint_reports_not_configured_in_demo(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/admin/conexion/probar', ['driver' => ''])
            ->assertOk()
            ->assertJson(['ok' => false]);
    }
}

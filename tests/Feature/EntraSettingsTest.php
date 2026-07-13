<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EntraSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        config(['ticket.admins' => ['admin@verfrut.cl']]);

        return User::factory()->create(['email' => 'admin@verfrut.cl']);
    }

    public function test_non_admin_cannot_access_entra_settings(): void
    {
        $user = User::factory()->create(['email' => 'user@verfrut.cl']);

        $this->actingAs($user)->get('/admin/acceso')->assertForbidden();
    }

    public function test_admin_sees_entra_page_with_prefilled_values(): void
    {
        // El secreto se precarga a propósito (admin only) para poder verlo/copiarlo.
        app(Settings::class)->setMany(['entra.client_secret' => 'secret-abc']);

        $this->actingAs($this->admin())
            ->get('/admin/acceso')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Settings/Entra')
                ->has('values')
                ->where('values.client_secret', 'secret-abc')
            );
    }

    public function test_admin_can_save_entra_and_secret_is_encrypted(): void
    {
        $this->actingAs($this->admin())
            ->put('/admin/acceso', [
                'client_id' => 'app-123',
                'tenant_id' => 'tenant-xyz',
                'client_secret' => 'secret-abc',
                'redirect_uri' => 'https://ticket.test/auth/entra/callback',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('app-123', app(Settings::class)->get('entra.client_id'));

        $row = Setting::where('key', 'entra.client_secret')->first();
        $this->assertTrue($row->is_encrypted);
        $this->assertNotSame('secret-abc', $row->value);
        $this->assertSame('secret-abc', app(Settings::class)->get('entra.client_secret'));
    }

    public function test_blank_secret_keeps_previous_value(): void
    {
        app(Settings::class)->setMany(['entra.client_secret' => 'original']);

        $this->actingAs($this->admin())
            ->put('/admin/acceso', ['client_id' => 'app-123', 'client_secret' => ''])
            ->assertRedirect();

        $this->assertSame('original', app(Settings::class)->get('entra.client_secret'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        config(['ticket.admins' => ['admin@verfrut.cl']]);

        return User::factory()->create(['email' => 'admin@verfrut.cl']);
    }

    public function test_non_admin_cannot_access_branding(): void
    {
        $user = User::factory()->create(['email' => 'user@verfrut.cl']);

        $this->actingAs($user)->get('/admin/marca')->assertForbidden();
    }

    public function test_admin_sees_branding_page(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/marca')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Settings/Branding')
                ->has('values')
            );
    }

    public function test_admin_can_upload_navbar_logo(): void
    {
        $this->actingAs($this->admin())
            ->put('/admin/marca', [
                'navbar_logo' => UploadedFile::fake()->image('logo.png', 200, 60),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $url = app(Settings::class)->get('brand.navbar_logo');
        $this->assertNotNull($url);
        $this->assertStringStartsWith('/branding/', $url);
        $this->assertTrue(File::exists(public_path(ltrim($url, '/'))));

        // limpieza del archivo de prueba
        File::delete(public_path(ltrim($url, '/')));
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Glpi\GlpiClient;
use App\Services\Glpi\GlpiConfig;
use App\Services\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class DisabledUserTest extends TestCase
{
    use RefreshDatabase;

    /** Cliente GLPI real (driver oauth) cuyo /Administration/User devuelve $user. */
    private function glpiReturning(?array $user, int $status = 200): void
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

        Http::fake([
            '*/token' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            '*/Administration/User*' => Http::response($user === null ? [] : [$user], $status),
            '*' => Http::response([], 200),
        ]);

        $this->app->instance(GlpiClient::class, new GlpiClient(GlpiConfig::resolve($settings)));
    }

    public function test_inactive_glpi_user_is_reported_disabled(): void
    {
        $this->glpiReturning(['id' => 378, 'is_active' => false, 'is_deleted' => false]);

        $this->assertTrue(app(GlpiClient::class)->isUserDisabled('baja@unifrutti.com'));
    }

    public function test_active_or_missing_user_is_not_disabled(): void
    {
        $this->glpiReturning(['id' => 9, 'is_active' => true, 'is_deleted' => false]);
        $this->assertFalse(app(GlpiClient::class)->isUserDisabled('activo@unifrutti.com'));

        $this->glpiReturning(null);
        $this->assertFalse(app(GlpiClient::class)->isUserDisabled('nuevo@unifrutti.com'));
    }

    public function test_glpi_failure_does_not_lock_users_out(): void
    {
        $this->glpiReturning(['error' => 'boom'], 500);

        $this->assertFalse(app(GlpiClient::class)->isUserDisabled('alguien@unifrutti.com'));
    }

    public function test_logged_in_user_disabled_later_is_logged_out(): void
    {
        $this->glpiReturning(['id' => 378, 'is_active' => false, 'is_deleted' => false]);
        $user = User::factory()->create(['email' => 'baja@unifrutti.com']);

        $this->actingAs($user)
            ->get('/inicio')
            ->assertRedirect(route('login'))
            ->assertSessionHas('disabledUser', 'baja@unifrutti.com');

        $this->assertGuest();
    }

    public function test_disabled_user_cannot_log_in_with_entra(): void
    {
        $this->glpiReturning(['id' => 378, 'is_active' => false, 'is_deleted' => false]);

        $entraUser = (new SocialiteUser)->map([
            'id' => 'oid-1', 'name' => 'Usuario Baja', 'email' => 'baja@unifrutti.com',
        ]);
        $provider = Mockery::mock();
        $provider->shouldReceive('user')->andReturn($entraUser);
        Socialite::shouldReceive('driver')->with('microsoft')->andReturn($provider);

        $this->get('/auth/entra/callback')
            ->assertRedirect(route('login'))
            ->assertSessionHas('disabledUser', 'baja@unifrutti.com');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'baja@unifrutti.com']);
    }

    public function test_entra_redirect_asks_for_account_only_when_requested(): void
    {
        config(['services.microsoft.client_id' => 'cid', 'services.microsoft.client_secret' => 'sec']);

        $normal = $this->get('/auth/entra/redirect')->assertRedirect()->headers->get('Location');
        $this->assertStringContainsString('login.microsoftonline.com', $normal);
        $this->assertStringNotContainsString('prompt=', $normal);

        $other = $this->get('/auth/entra/redirect?otra=1')->assertRedirect()->headers->get('Location');
        $this->assertStringContainsString('prompt=select_account', $other);
    }

    public function test_login_page_shows_disabled_notice(): void
    {
        $this->withSession(['disabledUser' => 'baja@unifrutti.com'])
            ->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Login')
                ->where('flash.disabledUser', 'baja@unifrutti.com'));
    }
}

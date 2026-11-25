<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Services\Auth\EntraConfig;
use App\Services\Entra\Country;
use App\Services\Glpi\GlpiClient;
use App\Services\Settings\Settings;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class EntraController extends Controller
{
    public function __construct(private Settings $settings)
    {
    }

    /** Aplica la config de Entra guardada en BD (si existe) sobre services.microsoft. */
    private function applyConfig(): array
    {
        $cfg = EntraConfig::resolve($this->settings);
        config(['services.microsoft' => $cfg]);

        return $cfg;
    }

    /** Puerta A — redirige al login de Microsoft/Entra (OIDC). */
    public function redirect(Request $request): RedirectResponse
    {
        if (blank($this->applyConfig()['client_id'])) {
            return redirect()->route('login')
                ->with('error', 'El inicio de sesión con Entra ID aún no está configurado.');
        }

        $driver = Socialite::driver('microsoft')
            ->scopes(['openid', 'profile', 'email', 'User.Read']);

        // ?otra=1 (botón tras el aviso de usuario deshabilitado): fuerza el
        // selector de cuentas de Entra (+ "Usar otra cuenta") para no reentrar
        // con la cuenta genérica abierta en el navegador. El login normal no lo
        // fuerza: con una sola cuenta entra directo (con varias, Entra ya pregunta).
        if ($request->boolean('otra')) {
            $driver->with(['prompt' => 'select_account']);
        }

        return $driver->redirect();
    }

    /** Puerta A — callback de Entra: crea/encuentra al usuario y abre sesión. */
    public function callback(): RedirectResponse
    {
        $this->applyConfig();

        try {
            $entraUser = Socialite::driver('microsoft')->user();
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('login')
                ->with('error', 'No se pudo completar el inicio de sesión con Entra ID.');
        }

        $email = $entraUser->getEmail();
        if (blank($email)) {
            return redirect()->route('login')
                ->with('error', 'Entra no entregó un correo válido para este usuario.');
        }

        // Usuario dado de baja en GLPI (inactivo): no entra al portal.
        if (app(GlpiClient::class)->isUserDisabled($email, fresh: true)) {
            return redirect()->route('login')->with('disabledUser', $email);
        }

        // Zona horaria derivada del país que entrega Entra (campo country). Misma
        // regla que la importación de usuarios a GLPI (App\Services\Entra\Country).
        $raw = (array) ($entraUser->user ?? []);
        $timezone = Country::timezone($raw['country'] ?? null);

        $attributes = [
            'name' => $entraUser->getName() ?: $email,
            'azure_oid' => $entraUser->getId(),
        ];
        // Solo la seteamos si la pudimos determinar: así no borramos una zona
        // ya guardada si en algún login Entra no devuelve el país.
        if ($timezone !== null) {
            $attributes['timezone'] = $timezone;
        }

        $user = User::updateOrCreate(['email' => $email], $attributes);

        // Provisiona al usuario EN GLPI al iniciar sesión: lo da de alta si aún
        // no existe (para que exista desde el login, sin esperar al primer ticket)
        // y completa su zona horaria si la tiene vacía. Nunca bloquea el login.
        app(GlpiClient::class)->ensureUserProvisioned($user->email, $user->name, $user->timezone);

        Auth::login($user, remember: true);

        return redirect()->intended(route('dashboard'));
    }

    /** Acceso de desarrollo: sólo en local y si se habilita explícitamente. */
    public function devLogin(Request $request): RedirectResponse
    {
        abort_unless(app()->environment('local') && env('ALLOW_DEV_LOGIN'), 404);

        $email = $request->string('email')->value() ?: 'dev@verfrut.cl';

        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => 'Usuario de prueba', 'azure_oid' => 'dev-'.md5($email)],
        );

        Auth::login($user);

        return redirect()->route('dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}

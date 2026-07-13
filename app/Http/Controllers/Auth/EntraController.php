<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Services\Auth\EntraConfig;
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
    public function redirect(): RedirectResponse
    {
        if (blank($this->applyConfig()['client_id'])) {
            return redirect()->route('login')
                ->with('error', 'El inicio de sesión con Entra ID aún no está configurado.');
        }

        return Socialite::driver('microsoft')
            ->scopes(['openid', 'profile', 'email', 'User.Read'])
            ->redirect();
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

        // Zona horaria derivada del país que entrega Entra (campo country).
        $raw = (array) ($entraUser->user ?? []);
        $timezone = $this->timezoneFromCountry($raw['country'] ?? null);

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

        Auth::login($user, remember: true);

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Deriva la zona horaria IANA a partir del campo `country` de Entra (puede
     * venir "Chile"/"CL" o "Peru"/"Perú"/"PE"). Devuelve null si viene vacío o
     * el país no está en el mapa: en ese caso no se define/toca la zona.
     */
    private function timezoneFromCountry(?string $country): ?string
    {
        // Normaliza: mayúsculas, sin espacios ni acentos ("Perú" -> "PERU").
        $key = mb_strtoupper(trim((string) $country));
        $key = strtr($key, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U']);

        if ($key === '') {
            return null;
        }

        $map = [
            'CL' => 'America/Santiago',
            'CHILE' => 'America/Santiago',
            'PE' => 'America/Lima',
            'PERU' => 'America/Lima',
        ];

        return $map[$key] ?? null;
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

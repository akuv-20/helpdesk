<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class EntraController extends Controller
{
    /** Puerta A — redirige al login de Microsoft/Entra (OIDC). */
    public function redirect(): RedirectResponse
    {
        if (blank(config('services.microsoft.client_id'))) {
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

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $entraUser->getName() ?: $email,
                'azure_oid' => $entraUser->getId(),
            ],
        );

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

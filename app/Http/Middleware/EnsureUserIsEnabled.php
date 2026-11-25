<?php

namespace App\Http\Middleware;

use App\Services\Glpi\GlpiClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cierra la sesión de un usuario que fue deshabilitado en GLPI después de
 * iniciar sesión (el login usa "recordarme", así que sin esto seguiría dentro).
 * El chequeo está cacheado 10 min por usuario en GlpiClient::isUserDisabled().
 */
class EnsureUserIsEnabled
{
    public function __construct(private GlpiClient $glpi)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $this->glpi->isUserDisabled($user->email)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('disabledUser', $user->email);
        }

        return $next($request);
    }
}

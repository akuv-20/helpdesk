<?php

namespace App\Http\Middleware;

use App\Services\Settings\Settings;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'appName' => config('app.name'),
            'auth' => [
                'user' => $request->user() ? [
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'isAdmin' => $request->user()->isAdmin(),
                ] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                // Número del ticket recién creado → modal de confirmación.
                'createdTicket' => fn () => $request->session()->get('createdTicket'),
            ],
            'allowDevLogin' => app()->environment('local') && (bool) env('ALLOW_DEV_LOGIN'),
            'branding' => $this->branding(),
            // Contador para el badge de "Aprobaciones" en el navbar (cacheado 60s).
            'pendingApprovalsCount' => fn () => $this->pendingApprovalsCount($request),
        ];
    }

    /** Aprobaciones pendientes del usuario (0 si no hay sesión o falla GLPI). */
    private function pendingApprovalsCount(Request $request): int
    {
        $user = $request->user();
        if (! $user) {
            return 0;
        }

        try {
            return app(\App\Services\Glpi\GlpiClient::class)
                ->cachedPendingApprovalsCount($user->email, $user->id);
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Imágenes de marca (logos y fondo) para el front. */
    private function branding(): array
    {
        try {
            $settings = app(Settings::class);

            return [
                'navbar_logo' => $settings->get('brand.navbar_logo'),
                'login_logo' => $settings->get('brand.login_logo'),
                'login_background' => $settings->get('brand.login_background'),
            ];
        } catch (\Throwable) {
            return [];
        }
    }
}

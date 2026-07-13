<?php

use App\Http\Controllers\Admin\BrandingController;
use App\Http\Controllers\Admin\EntraExplorerController;
use App\Http\Controllers\Admin\EntraSettingsController;
use App\Http\Controllers\Admin\IntegrationController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\Auth\EntraController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
| Puerta A — autenticación del usuario (Entra ID / OIDC)
*/
Route::middleware('guest')->group(function () {
    Route::get('/', fn () => Inertia::render('Login'))->name('login');

    Route::get('/auth/entra/redirect', [EntraController::class, 'redirect'])->name('entra.redirect');
    Route::get('/auth/entra/callback', [EntraController::class, 'callback'])->name('entra.callback');

    // Solo desarrollo (gated en el controlador).
    Route::post('/auth/dev-login', [EntraController::class, 'devLogin'])->name('dev.login');
});

Route::post('/logout', [EntraController::class, 'logout'])->name('logout');

/*
| Portal del usuario (requiere sesión)
*/
Route::middleware('auth')->group(function () {
    Route::get('/inicio', DashboardController::class)->name('dashboard');
    Route::get('/aprobaciones', ApprovalController::class)->name('approvals');

    Route::get('/tickets/nuevo', [TicketController::class, 'create'])->name('tickets.create');
    Route::get('/tickets/categorias', [TicketController::class, 'categories'])->name('tickets.categories');
    Route::post('/tickets', [TicketController::class, 'store'])->name('tickets.store');
    Route::get('/tickets/{id}', [TicketController::class, 'show'])->whereNumber('id')->name('tickets.show');
    Route::post('/tickets/{id}/responder', [TicketController::class, 'reply'])->whereNumber('id')->name('tickets.reply');
    Route::post('/tickets/{id}/solucion', [TicketController::class, 'solution'])->whereNumber('id')->name('tickets.solution');
    Route::post('/tickets/{id}/validacion', [TicketController::class, 'validation'])->whereNumber('id')->name('tickets.validation');
    // Callback OAuth para aprobar/rechazar validaciones como el propio usuario.
    Route::get('/tickets/validacion/callback', [TicketController::class, 'validationCallback'])->name('tickets.validation.callback');
    Route::get('/tickets/{id}/adjuntos/{docId}', [TicketController::class, 'download'])->whereNumber('id')->whereNumber('docId')->name('tickets.download');
});

/*
| Administración — mantenedores de ambos OAuth (solo admins)
*/
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    // Mantenedor de marca (logos, favicon, fondo del login)
    Route::get('/marca', [BrandingController::class, 'edit'])->name('branding.edit');
    Route::put('/marca', [BrandingController::class, 'update'])->name('branding.update');

    // Puerta A — mantenedor del login con Entra ID (OAuth/OIDC)
    Route::get('/acceso', [EntraSettingsController::class, 'edit'])->name('entra.edit');
    Route::put('/acceso', [EntraSettingsController::class, 'update'])->name('entra.update');
    Route::post('/acceso/probar', [EntraSettingsController::class, 'test'])->name('entra.test');

    // Explorador de datos de Entra/Graph por usuario (diagnóstico de campos)
    Route::get('/explorador-entra', [EntraExplorerController::class, 'show'])->name('entra.explorer');
    Route::post('/explorador-entra', [EntraExplorerController::class, 'lookup'])->name('entra.explorer.lookup');

    // Puerta C — cliente OAuth de aprobaciones (authorization_code por-usuario)
    Route::get('/aprobaciones-oauth', [\App\Http\Controllers\Admin\ApprovalOauthController::class, 'edit'])->name('approval-oauth.edit');
    Route::put('/aprobaciones-oauth', [\App\Http\Controllers\Admin\ApprovalOauthController::class, 'update'])->name('approval-oauth.update');

    // Puerta B — módulo de conexión con GLPI (OAuth / legacy)
    Route::get('/conexion', [IntegrationController::class, 'edit'])->name('connection.edit');
    Route::put('/conexion', [IntegrationController::class, 'update'])->name('connection.update');
    Route::post('/conexion/probar', [IntegrationController::class, 'test'])->name('connection.test');
});

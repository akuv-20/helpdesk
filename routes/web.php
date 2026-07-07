<?php

use App\Http\Controllers\Admin\FormDefinitionController;
use App\Http\Controllers\Admin\IntegrationController;
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

    Route::get('/tickets/nuevo', [TicketController::class, 'create'])->name('tickets.create');
    Route::get('/tickets/form-schema', [TicketController::class, 'formSchema'])->name('tickets.form-schema');
    Route::post('/tickets', [TicketController::class, 'store'])->name('tickets.store');
});

/*
| Administración — builder de formularios (solo admins)
*/
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/formularios', [FormDefinitionController::class, 'index'])->name('forms.index');
    Route::get('/formularios/nuevo', [FormDefinitionController::class, 'create'])->name('forms.create');
    Route::post('/formularios', [FormDefinitionController::class, 'store'])->name('forms.store');
    Route::get('/formularios/{formDefinition}', [FormDefinitionController::class, 'edit'])->name('forms.edit');
    Route::put('/formularios/{formDefinition}', [FormDefinitionController::class, 'update'])->name('forms.update');
    Route::delete('/formularios/{formDefinition}', [FormDefinitionController::class, 'destroy'])->name('forms.destroy');

    // Módulo de conexión con GLPI (OAuth / legacy)
    Route::get('/conexion', [IntegrationController::class, 'edit'])->name('connection.edit');
    Route::put('/conexion', [IntegrationController::class, 'update'])->name('connection.update');
    Route::post('/conexion/probar', [IntegrationController::class, 'test'])->name('connection.test');
});

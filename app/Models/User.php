<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'azure_oid', 'timezone'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            // Tokens OAuth del usuario contra GLPI: cifrados en BD.
            'glpi_access_token' => 'encrypted',
            'glpi_refresh_token' => 'encrypted',
            'glpi_token_expires_at' => 'datetime',
        ];
    }

    /** ¿Hay un access token de GLPI aún vigente (con margen de 1 min)? */
    public function hasValidGlpiToken(): bool
    {
        return filled($this->glpi_access_token)
            && $this->glpi_token_expires_at
            && $this->glpi_token_expires_at->isAfter(now()->addMinute());
    }

    /**
     * Guarda el juego de tokens OAuth de GLPI. Conserva el refresh anterior si
     * la respuesta no trae uno nuevo.
     *
     * @param  array{access:string, refresh:?string, expires_at:\Illuminate\Support\Carbon}  $tokens
     */
    public function storeGlpiTokens(array $tokens): void
    {
        $this->forceFill([
            'glpi_access_token' => $tokens['access'],
            'glpi_refresh_token' => $tokens['refresh'] ?: $this->glpi_refresh_token,
            'glpi_token_expires_at' => $tokens['expires_at'],
        ])->save();
    }

    /** ¿Tiene acceso al área de administración (builder de formularios)? */
    public function isAdmin(): bool
    {
        if (app()->environment('local') && env('ALLOW_DEV_LOGIN') && str_starts_with((string) $this->azure_oid, 'dev-')) {
            return true;
        }

        return in_array($this->email, config('ticket.admins', []), true);
    }
}

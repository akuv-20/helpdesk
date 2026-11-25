<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Vínculo usuario de Entra ↔ usuario de GLPI (ver migración entra_user_links). */
class EntraUserLink extends Model
{
    protected $fillable = ['azure_oid', 'glpi_user_id', 'email', 'last_action', 'last_synced_at'];

    protected function casts(): array
    {
        return [
            'glpi_user_id' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }
}

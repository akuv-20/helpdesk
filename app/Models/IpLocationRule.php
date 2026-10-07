<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Regla segmento de red (CIDR) → ubicación de GLPI. Ver IpLocationResolver
 * para el match y TicketController::store para su uso al crear el ticket.
 */
class IpLocationRule extends Model
{
    protected $fillable = [
        'cidr',
        'locations_id',
        'location_name',
        'label',
        'enabled',
    ];

    protected $casts = [
        'locations_id' => 'integer',
        'enabled' => 'boolean',
    ];
}

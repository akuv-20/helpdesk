<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reglas que vinculan un segmento de red (CIDR) con una ubicación de GLPI.
 * Al crear un ticket, el portal mira la IP del solicitante y, si cae en un
 * segmento, le asigna la ubicación correspondiente (locations_id) en GLPI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ip_location_rules', function (Blueprint $table) {
            $table->id();
            $table->string('cidr');                 // segmento normalizado, p.ej. 192.168.32.0/24
            $table->unsignedInteger('locations_id'); // id de la ubicación en GLPI
            $table->string('location_name')->nullable(); // completename cacheado (display)
            $table->string('label')->nullable();     // nota libre, p.ej. "Recepción fruta"
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique('cidr');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_location_rules');
    }
};

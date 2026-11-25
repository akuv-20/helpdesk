<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo usuario de Entra (object id) ↔ usuario de GLPI, escrito por la
 * importación desde Entra. Permite reconocer a la persona aunque cambie su
 * correo/UPN (el login en GLPI es el email), sin duplicarla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entra_user_links', function (Blueprint $table) {
            $table->id();
            $table->string('azure_oid')->unique();         // id del usuario en Entra
            $table->unsignedInteger('glpi_user_id')->index(); // id del usuario en GLPI
            $table->string('email');                      // correo con el que se importó
            $table->string('last_action')->nullable();    // created | updated | unchanged
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entra_user_links');
    }
};

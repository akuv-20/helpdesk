<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Token OAuth del PROPIO usuario contra GLPI (flujo authorization_code),
            // para aprobar/rechazar validaciones sin volver a pedir consentimiento
            // en cada acción. Cifrados en el modelo. Nullable: se llenan al 1er uso.
            $table->text('glpi_access_token')->nullable();
            $table->text('glpi_refresh_token')->nullable();
            $table->timestamp('glpi_token_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['glpi_access_token', 'glpi_refresh_token', 'glpi_token_expires_at']);
        });
    }
};

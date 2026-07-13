<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Zona horaria IANA derivada del país de Entra (country/usageLocation)
            // al iniciar sesión. Nullable: si no se puede determinar, queda vacía.
            $table->string('timezone')->nullable()->after('azure_oid');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};

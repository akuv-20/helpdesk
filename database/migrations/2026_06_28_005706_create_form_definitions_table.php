<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('form_definitions', function (Blueprint $table) {
            $table->id();
            // Rama: combinación tipo (incident|request) + categoría ITIL de GLPI.
            $table->string('type'); // incident | request
            $table->unsignedBigInteger('itil_category_id');
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            // Definición de campos (nuestro esquema JSON) editable por el builder.
            $table->json('fields');
            $table->timestamps();

            $table->unique(['type', 'itil_category_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('form_definitions');
    }
};

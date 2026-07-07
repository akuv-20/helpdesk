<?php

namespace Database\Seeders;

use App\Models\FormDefinition;
use Illuminate\Database\Seeder;

class FormDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        // Rama semilla (Fase 1): Incidente + categoría "Soporte" (id 1 en modo demo).
        // Muestra el motor: campos en orden + una rama condicional (showIf).
        FormDefinition::updateOrCreate(
            ['type' => 'incident', 'itil_category_id' => 1],
            [
                'name' => 'Incidente · Soporte',
                'is_active' => true,
                'fields' => [
                    [
                        'key' => 'equipo',
                        'label' => '¿Qué equipo presenta el problema?',
                        'input' => 'select',
                        'required' => true,
                        'options' => [
                            ['value' => 'pc', 'label' => 'PC / Notebook'],
                            ['value' => 'impresora', 'label' => 'Impresora'],
                            ['value' => 'correo', 'label' => 'Correo / Office'],
                            ['value' => 'otro', 'label' => 'Otro'],
                        ],
                    ],
                    [
                        'key' => 'modelo_impresora',
                        'label' => 'Modelo de la impresora',
                        'input' => 'text',
                        'required' => true,
                        'showIf' => ['field' => 'equipo', 'equals' => 'impresora'],
                    ],
                    [
                        'key' => 'ubicacion',
                        'label' => 'Ubicación / oficina',
                        'input' => 'text',
                        'required' => false,
                        'placeholder' => 'Ej: Planta Rancagua, piso 2',
                    ],
                    [
                        'key' => 'detalle',
                        'label' => 'Describe el problema',
                        'input' => 'textarea',
                        'required' => true,
                        'placeholder' => 'Cuéntanos qué ocurre, desde cuándo y qué has intentado…',
                    ],
                ],
            ],
        );
    }
}

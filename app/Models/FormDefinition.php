<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormDefinition extends Model
{
    protected $fillable = ['type', 'itil_category_id', 'name', 'is_active', 'fields'];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** Busca la definición activa para una rama (tipo + categoría ITIL). */
    public static function forBranch(string $type, int $categoryId): ?self
    {
        return static::query()
            ->where('is_active', true)
            ->where('type', $type)
            ->where('itil_category_id', $categoryId)
            ->first();
    }

    /**
     * ¿El campo es visible dado el conjunto de respuestas?
     * Regla showIf: { "field": "clave", "equals": valor } (o "in": [..]).
     */
    public static function fieldIsVisible(array $field, array $answers): bool
    {
        $rule = $field['showIf'] ?? null;
        if (! $rule) {
            return true;
        }

        $current = $answers[$rule['field']] ?? null;

        if (array_key_exists('equals', $rule)) {
            return $current == $rule['equals'];
        }

        if (array_key_exists('in', $rule)) {
            return in_array($current, (array) $rule['in'], false);
        }

        return true;
    }
}

<?php

namespace App\Services\Tickets;

use App\Models\FormDefinition;

/**
 * Convierte las respuestas del wizard (campos dinámicos) en el contenido
 * final del ticket de GLPI: un bloque ordenado y legible para el técnico.
 */
class TicketComposer
{
    /**
     * @param  array<string, mixed>  $answers
     * @return array{title:string, content:string, category_id:int}
     */
    public function compose(FormDefinition $definition, string $subject, int $categoryId, array $answers): array
    {
        $lines = [];

        foreach ($definition->fields as $field) {
            if (! FormDefinition::fieldIsVisible($field, $answers)) {
                continue;
            }

            $value = $answers[$field['key']] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            $lines[] = $field['label'].': '.$this->labelForValue($field, $value);
        }

        return [
            'title' => $subject,
            'content' => implode("\n", $lines),
            'category_id' => $categoryId,
        ];
    }

    /** Traduce el value de un select a su etiqueta legible. */
    protected function labelForValue(array $field, mixed $value): string
    {
        if (($field['input'] ?? null) === 'select' && ! empty($field['options'])) {
            foreach ($field['options'] as $opt) {
                if (($opt['value'] ?? null) == $value) {
                    return (string) ($opt['label'] ?? $value);
                }
            }
        }

        return (string) $value;
    }
}

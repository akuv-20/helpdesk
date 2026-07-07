<?php

namespace App\Http\Controllers;

use App\Models\FormDefinition;
use App\Services\Glpi\GlpiClient;
use App\Services\Glpi\GlpiException;
use App\Services\Tickets\TicketComposer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class TicketController extends Controller
{
    public function create(GlpiClient $glpi): Response
    {
        return Inertia::render('Tickets/Create', [
            'glpiConfigured' => $glpi->isConfigured(),
            'types' => [
                ['value' => 'incident', 'label' => 'Incidente', 'hint' => 'Algo que dejó de funcionar.'],
                ['value' => 'request', 'label' => 'Solicitud', 'hint' => 'Necesitas algo nuevo o un acceso.'],
            ],
            'categories' => $glpi->categories(),
        ]);
    }

    /** Devuelve el esquema de campos de una rama (tipo + categoría). */
    public function formSchema(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:incident,request'],
            'itil_category_id' => ['required', 'integer'],
        ]);

        $definition = FormDefinition::forBranch($data['type'], (int) $data['itil_category_id']);

        return response()->json([
            'fields' => $definition?->fields ?? [],
            'configured' => $definition !== null,
        ]);
    }

    public function store(Request $request, GlpiClient $glpi, TicketComposer $composer): RedirectResponse
    {
        $base = $request->validate([
            'type' => ['required', 'in:incident,request'],
            'itil_category_id' => ['required', 'integer'],
            'subject' => ['required', 'string', 'min:5', 'max:255'],
            'urgency' => ['nullable', 'integer', 'between:1,5'],
            'answers' => ['array'],
        ]);

        $definition = FormDefinition::forBranch($base['type'], (int) $base['itil_category_id']);
        if (! $definition) {
            return back()->with('error', 'Esa combinación de tipo y categoría aún no tiene formulario configurado.')->withInput();
        }

        $answers = $request->input('answers', []);

        // Validación dinámica: exige los campos requeridos que estén visibles.
        $this->validateAnswers($definition, $answers)->validate();

        $composed = $composer->compose($definition, $base['subject'], (int) $base['itil_category_id'], $answers);

        try {
            $glpi->createTicket([
                'title' => $composed['title'],
                'content' => $composed['content'],
                'urgency' => $base['urgency'] ?? null,
                'category_id' => $composed['category_id'],
            ], $request->user()->email);
        } catch (GlpiException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('dashboard')
            ->with('success', 'Tu solicitud fue creada. Te avisaremos cuando haya novedades.');
    }

    /** Construye un validador para los campos dinámicos visibles y requeridos. */
    protected function validateAnswers(FormDefinition $definition, array $answers): \Illuminate\Validation\Validator
    {
        $rules = [];
        $attributes = [];

        foreach ($definition->fields as $field) {
            if (! FormDefinition::fieldIsVisible($field, $answers)) {
                continue;
            }
            if (! empty($field['required'])) {
                $rules['answers.'.$field['key']] = ['required'];
                $attributes['answers.'.$field['key']] = $field['label'];
            }
        }

        return Validator::make(['answers' => $answers], $rules, [], $attributes);
    }
}

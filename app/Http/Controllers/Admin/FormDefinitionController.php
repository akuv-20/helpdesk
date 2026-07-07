<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\FormDefinitionRequest;
use App\Models\FormDefinition;
use App\Services\Glpi\GlpiClient;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class FormDefinitionController extends Controller
{
    private array $types = [
        ['value' => 'incident', 'label' => 'Incidente'],
        ['value' => 'request', 'label' => 'Solicitud'],
    ];

    public function index(GlpiClient $glpi): Response
    {
        $categories = collect($glpi->categories());

        $definitions = FormDefinition::orderBy('type')->get()->map(fn ($d) => [
            'id' => $d->id,
            'type' => $d->type,
            'itil_category_id' => $d->itil_category_id,
            'category_name' => $categories->firstWhere('id', $d->itil_category_id)['name'] ?? ('#'.$d->itil_category_id),
            'name' => $d->name,
            'is_active' => $d->is_active,
            'field_count' => count($d->fields),
        ]);

        return Inertia::render('Admin/Forms/Index', [
            'definitions' => $definitions,
            'types' => $this->types,
            'categories' => $categories->values(),
        ]);
    }

    public function create(GlpiClient $glpi): Response
    {
        return $this->editor(new FormDefinition(['fields' => []]), $glpi);
    }

    public function edit(FormDefinition $formDefinition, GlpiClient $glpi): Response
    {
        return $this->editor($formDefinition, $glpi);
    }

    public function store(FormDefinitionRequest $request): RedirectResponse
    {
        FormDefinition::create($this->payload($request));

        return redirect()->route('admin.forms.index')->with('success', 'Formulario creado.');
    }

    public function update(FormDefinitionRequest $request, FormDefinition $formDefinition): RedirectResponse
    {
        $formDefinition->update($this->payload($request));

        return redirect()->route('admin.forms.index')->with('success', 'Formulario actualizado.');
    }

    public function destroy(FormDefinition $formDefinition): RedirectResponse
    {
        $formDefinition->delete();

        return redirect()->route('admin.forms.index')->with('success', 'Formulario eliminado.');
    }

    private function editor(FormDefinition $definition, GlpiClient $glpi): Response
    {
        return Inertia::render('Admin/Forms/Edit', [
            'definition' => [
                'id' => $definition->id,
                'type' => $definition->type,
                'itil_category_id' => $definition->itil_category_id,
                'name' => $definition->name,
                'is_active' => $definition->is_active ?? true,
                'fields' => $definition->fields ?? [],
            ],
            'types' => $this->types,
            'categories' => $glpi->categories(),
            'inputs' => config('helpdesk.field_inputs'),
        ]);
    }

    private function payload(FormDefinitionRequest $request): array
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active');
        // Normaliza: quita options en campos no-select y showIf vacío.
        $data['fields'] = collect($data['fields'])->map(function ($f) {
            if (($f['input'] ?? null) !== 'select') {
                unset($f['options']);
            }
            if (empty($f['showIf']['field'] ?? null)) {
                unset($f['showIf']);
            }
            $f['required'] = (bool) ($f['required'] ?? false);

            return $f;
        })->all();

        return $data;
    }
}

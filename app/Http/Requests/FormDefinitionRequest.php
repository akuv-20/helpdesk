<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FormDefinitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->isAdmin();
    }

    public function rules(): array
    {
        $inputs = config('helpdesk.field_inputs');
        $current = $this->route('formDefinition'); // modelo en update, null en store

        return [
            'type' => ['required', Rule::in(['incident', 'request'])],
            'itil_category_id' => [
                'required', 'integer',
                Rule::unique('form_definitions')
                    ->where(fn ($q) => $q->where('type', $this->input('type')))
                    ->ignore($current?->id),
            ],
            'name' => ['nullable', 'string', 'max:120'],
            'is_active' => ['boolean'],

            'fields' => ['present', 'array'],
            'fields.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]*$/', 'distinct'],
            'fields.*.label' => ['required', 'string', 'max:200'],
            'fields.*.input' => ['required', Rule::in($inputs)],
            'fields.*.required' => ['boolean'],
            'fields.*.placeholder' => ['nullable', 'string', 'max:200'],

            'fields.*.options' => ['array'],
            'fields.*.options.*.value' => ['required', 'string', 'max:120'],
            'fields.*.options.*.label' => ['required', 'string', 'max:200'],

            'fields.*.showIf' => ['nullable', 'array'],
            'fields.*.showIf.field' => ['required_with:fields.*.showIf', 'string'],
            'fields.*.showIf.equals' => ['nullable'],
            'fields.*.showIf.in' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'fields.*.key.regex' => 'La clave debe empezar con letra y usar solo minúsculas, números o guion bajo.',
            'itil_category_id.unique' => 'Ya existe un formulario para esa combinación de tipo y categoría.',
        ];
    }
}

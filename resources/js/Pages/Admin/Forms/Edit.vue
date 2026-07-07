<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import draggable from 'vuedraggable';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DynamicForm from '../../../Components/DynamicForm.vue';

const props = defineProps({
    definition: { type: Object, required: true },
    types: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    inputs: { type: Array, default: () => [] },
});

const isNew = computed(() => !props.definition.id);

const form = useForm({
    type: props.definition.type,
    itil_category_id: props.definition.itil_category_id,
    name: props.definition.name,
    is_active: props.definition.is_active ?? true,
    fields: JSON.parse(JSON.stringify(props.definition.fields ?? [])),
});

const expanded = ref(null); // índice del campo abierto en edición
const previewAnswers = ref({});

let keyCounter = form.fields.length;
function addField() {
    keyCounter += 1;
    form.fields.push({
        key: `campo_${keyCounter}`,
        label: 'Nuevo campo',
        input: 'text',
        required: false,
        placeholder: '',
        options: [],
        showIf: { field: '', equals: '' },
    });
    expanded.value = form.fields.length - 1;
}

function removeField(i) {
    form.fields.splice(i, 1);
    expanded.value = null;
}

function addOption(field) {
    field.options = field.options ?? [];
    field.options.push({ value: '', label: '' });
}

// Campos previos disponibles como condición (no se puede depender de sí mismo).
function priorFields(i) {
    return form.fields.slice(0, i).filter((f) => f.key);
}

function submit() {
    const url = isNew.value ? '/admin/formularios' : `/admin/formularios/${props.definition.id}`;
    const method = isNew.value ? 'post' : 'put';
    form[method](url);
}

const errorList = computed(() => Object.values(form.errors));
</script>

<template>
    <Head :title="isNew ? 'Nuevo formulario' : 'Editar formulario'" />

    <AppLayout>
        <div class="mb-6">
            <Link href="/admin/formularios" class="text-sm text-slate-500 hover:underline">← Formularios</Link>
            <h1 class="mt-2 text-xl font-semibold text-slate-900">{{ isNew ? 'Nuevo formulario' : 'Editar formulario' }}</h1>
            <p class="text-sm text-slate-500">Arrastra para ordenar. La vista previa se actualiza en vivo.</p>
        </div>

        <div v-if="errorList.length" class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <p v-for="(e, i) in errorList" :key="i">• {{ e }}</p>
        </div>

        <!-- Rama: tipo + categoría + nombre -->
        <div class="mb-6 grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-3">
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Tipo</label>
                <select v-model="form.type" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option :value="null" disabled>Selecciona…</option>
                    <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Categoría ITIL</label>
                <select v-model="form.itil_category_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option :value="null" disabled>Selecciona…</option>
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Nombre (interno)</label>
                <input v-model="form.name" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Ej: Incidente · Soporte" />
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <!-- Constructor -->
            <div>
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-slate-700">Campos</h2>
                    <button type="button" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50" @click="addField">
                        + Agregar campo
                    </button>
                </div>

                <draggable v-model="form.fields" item-key="key" handle=".drag" class="space-y-2">
                    <template #item="{ element: field, index }">
                        <div class="rounded-xl border border-slate-200 bg-white">
                            <div class="flex items-center gap-2 px-3 py-2">
                                <span class="drag cursor-grab text-slate-400 select-none">⠿</span>
                                <button type="button" class="min-w-0 flex-1 text-left" @click="expanded = expanded === index ? null : index">
                                    <span class="truncate font-medium text-slate-800">{{ field.label || field.key }}</span>
                                    <span class="ml-2 text-xs text-slate-400">{{ field.input }}{{ field.required ? ' · obligatorio' : '' }}</span>
                                </button>
                                <button type="button" class="text-xs text-red-600 hover:underline" @click="removeField(index)">Quitar</button>
                            </div>

                            <!-- Editor del campo -->
                            <div v-if="expanded === index" class="space-y-3 border-t border-slate-100 px-3 py-3">
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-slate-600">Clave</label>
                                        <input v-model="field.key" class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm font-mono" />
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-slate-600">Tipo de campo</label>
                                        <select v-model="field.input" class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm">
                                            <option v-for="inp in inputs" :key="inp" :value="inp">{{ inp }}</option>
                                        </select>
                                    </div>
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-slate-600">Etiqueta</label>
                                    <input v-model="field.label" class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm" />
                                </div>
                                <div class="flex items-center gap-4">
                                    <label class="flex items-center gap-2 text-sm text-slate-700">
                                        <input v-model="field.required" type="checkbox" /> Obligatorio
                                    </label>
                                    <input v-model="field.placeholder" placeholder="Texto de ayuda (placeholder)" class="flex-1 rounded-md border border-slate-300 px-2 py-1.5 text-sm" />
                                </div>

                                <!-- Opciones (solo select) -->
                                <div v-if="field.input === 'select'" class="rounded-lg bg-slate-50 p-3">
                                    <p class="mb-2 text-xs font-medium text-slate-600">Opciones</p>
                                    <div v-for="(opt, oi) in field.options" :key="oi" class="mb-2 flex items-center gap-2">
                                        <input v-model="opt.value" placeholder="valor" class="w-1/3 rounded-md border border-slate-300 px-2 py-1 text-sm font-mono" />
                                        <input v-model="opt.label" placeholder="etiqueta visible" class="flex-1 rounded-md border border-slate-300 px-2 py-1 text-sm" />
                                        <button type="button" class="text-xs text-red-600" @click="field.options.splice(oi, 1)">✕</button>
                                    </div>
                                    <button type="button" class="text-xs font-medium text-blue-600 hover:underline" @click="addOption(field)">+ Opción</button>
                                </div>

                                <!-- Condición showIf -->
                                <div class="rounded-lg bg-slate-50 p-3">
                                    <p class="mb-2 text-xs font-medium text-slate-600">Mostrar solo si…</p>
                                    <div class="flex items-center gap-2">
                                        <select v-model="field.showIf.field" class="rounded-md border border-slate-300 px-2 py-1 text-sm">
                                            <option value="">(siempre visible)</option>
                                            <option v-for="pf in priorFields(index)" :key="pf.key" :value="pf.key">{{ pf.label || pf.key }}</option>
                                        </select>
                                        <span class="text-sm text-slate-500">es igual a</span>
                                        <input v-model="field.showIf.equals" placeholder="valor" class="flex-1 rounded-md border border-slate-300 px-2 py-1 text-sm font-mono" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </draggable>

                <p v-if="!form.fields.length" class="rounded-xl border border-dashed border-slate-300 px-4 py-8 text-center text-sm text-slate-400">
                    Aún no hay campos. Agrega el primero.
                </p>
            </div>

            <!-- Vista previa en vivo -->
            <div>
                <h2 class="mb-3 text-sm font-semibold text-slate-700">Vista previa</h2>
                <div class="rounded-xl border border-slate-200 bg-white p-5">
                    <DynamicForm v-model="previewAnswers" :fields="form.fields" />
                    <p v-if="!form.fields.length" class="text-sm text-slate-400">Los campos aparecerán aquí.</p>
                </div>
            </div>
        </div>

        <div class="mt-6 flex items-center justify-between">
            <label class="flex items-center gap-2 text-sm text-slate-700">
                <input v-model="form.is_active" type="checkbox" /> Formulario activo
            </label>
            <button
                type="button"
                :disabled="form.processing"
                class="rounded-lg bg-blue-600 px-5 py-2 text-sm font-medium text-white transition hover:bg-blue-700 disabled:opacity-60"
                @click="submit"
            >
                {{ form.processing ? 'Guardando…' : 'Guardar formulario' }}
            </button>
        </div>
    </AppLayout>
</template>

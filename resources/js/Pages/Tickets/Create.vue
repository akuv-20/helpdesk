<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import DynamicForm from '../../Components/DynamicForm.vue';

const props = defineProps({
    types: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    glpiConfigured: { type: Boolean, default: false },
});

const step = ref(1);
const schemaLoading = ref(false);
const schemaConfigured = ref(true);
const fields = ref([]);

const form = useForm({
    type: null,
    itil_category_id: null,
    subject: '',
    urgency: 3,
    answers: {},
});

const urgencies = [
    { value: 5, label: 'Muy alta' },
    { value: 4, label: 'Alta' },
    { value: 3, label: 'Media' },
    { value: 2, label: 'Baja' },
    { value: 1, label: 'Muy baja' },
];

function pickType(value) {
    form.type = value;
    step.value = 2;
}

async function pickCategory(id) {
    form.itil_category_id = id;
    schemaLoading.value = true;
    step.value = 3;
    try {
        const { data } = await window.axios.get('/tickets/form-schema', {
            params: { type: form.type, itil_category_id: id },
        });
        fields.value = data.fields;
        schemaConfigured.value = data.configured;
        form.answers = {};
    } finally {
        schemaLoading.value = false;
    }
}

function back() {
    if (step.value > 1) step.value -= 1;
}

function submit() {
    form.transform((data) => ({ ...data, answers: data.answers })).post('/tickets');
}

const typeLabel = (v) => props.types.find((t) => t.value === v)?.label ?? '';
const categoryLabel = (id) => props.categories.find((c) => c.id === id)?.name ?? '';
</script>

<template>
    <Head title="Nueva solicitud" />

    <AppLayout>
        <div class="mb-6">
            <Link href="/inicio" class="text-sm text-slate-500 hover:underline">← Volver</Link>
            <h1 class="mt-2 text-xl font-semibold text-slate-900">Nueva solicitud</h1>
            <p class="text-sm text-slate-500">Te guiamos en unos pocos pasos.</p>
        </div>

        <!-- Indicador de pasos -->
        <ol class="mb-6 flex items-center gap-2 text-xs font-medium">
            <li v-for="(label, i) in ['Tipo', 'Categoría', 'Detalles']" :key="i" class="flex items-center gap-2">
                <span
                    class="grid h-6 w-6 place-items-center rounded-full"
                    :class="step >= i + 1 ? 'bg-blue-600 text-white' : 'bg-slate-200 text-slate-500'"
                >{{ i + 1 }}</span>
                <span :class="step >= i + 1 ? 'text-slate-900' : 'text-slate-400'">{{ label }}</span>
                <span v-if="i < 2" class="mx-1 h-px w-6 bg-slate-200"></span>
            </li>
        </ol>

        <!-- Paso 1: tipo -->
        <div v-if="step === 1" class="grid gap-3 sm:grid-cols-2">
            <button
                v-for="t in types"
                :key="t.value"
                type="button"
                class="rounded-xl border border-slate-200 bg-white p-5 text-left transition hover:border-blue-400 hover:shadow-sm"
                @click="pickType(t.value)"
            >
                <p class="font-medium text-slate-900">{{ t.label }}</p>
                <p class="mt-1 text-sm text-slate-500">{{ t.hint }}</p>
            </button>
        </div>

        <!-- Paso 2: categoría ITIL -->
        <div v-else-if="step === 2">
            <div class="grid gap-3 sm:grid-cols-2">
                <button
                    v-for="c in categories"
                    :key="c.id"
                    type="button"
                    class="rounded-xl border border-slate-200 bg-white p-5 text-left font-medium text-slate-900 transition hover:border-blue-400 hover:shadow-sm"
                    @click="pickCategory(c.id)"
                >
                    {{ c.name }}
                </button>
            </div>
            <button type="button" class="mt-4 text-sm text-slate-500 hover:underline" @click="back">← Cambiar tipo</button>
        </div>

        <!-- Paso 3: detalles dinámicos -->
        <form v-else class="space-y-5 rounded-xl border border-slate-200 bg-white p-6" @submit.prevent="submit">
            <div class="flex flex-wrap gap-2 text-xs">
                <span class="rounded-full bg-slate-100 px-3 py-1 text-slate-600">{{ typeLabel(form.type) }}</span>
                <span class="rounded-full bg-slate-100 px-3 py-1 text-slate-600">{{ categoryLabel(form.itil_category_id) }}</span>
            </div>

            <div v-if="schemaLoading" class="py-6 text-center text-sm text-slate-400">Cargando formulario…</div>

            <div
                v-else-if="!schemaConfigured"
                class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
            >
                Esta combinación todavía no tiene formulario configurado. (En la Fase 1 solo está cargada la rama
                <strong>Incidente · Soporte</strong> como ejemplo.)
            </div>

            <template v-else>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Asunto <span class="text-red-500">*</span></label>
                    <input
                        v-model="form.subject"
                        type="text"
                        placeholder="Resumen breve de tu solicitud"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-100 focus:outline-none"
                    />
                    <p v-if="form.errors.subject" class="mt-1 text-xs text-red-600">{{ form.errors.subject }}</p>
                </div>

                <DynamicForm v-model="form.answers" :fields="fields" :errors="form.errors" />

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Urgencia</label>
                    <select
                        v-model="form.urgency"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-100 focus:outline-none"
                    >
                        <option v-for="u in urgencies" :key="u.value" :value="u.value">{{ u.label }}</option>
                    </select>
                </div>
            </template>

            <div class="flex items-center justify-between pt-2">
                <button type="button" class="text-sm text-slate-500 hover:underline" @click="back">← Cambiar categoría</button>
                <button
                    v-if="schemaConfigured && !schemaLoading"
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-lg bg-blue-600 px-5 py-2 text-sm font-medium text-white transition hover:bg-blue-700 disabled:opacity-60"
                >
                    {{ form.processing ? 'Enviando…' : 'Enviar solicitud' }}
                </button>
            </div>
        </form>
    </AppLayout>
</template>

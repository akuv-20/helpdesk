<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    suggestedSelect: { type: String, default: '' },
});

const upn = ref('');
const select = ref(props.suggestedSelect);
const loading = ref(false);
const result = ref(null); // { ok, status, requestedSelect, data } | { ok:false, message }
const error = ref(null);

const inputClass =
    'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-100 focus:outline-none';

// JSON formateado para mostrar.
const pretty = computed(() => (result.value?.data ? JSON.stringify(result.value.data, null, 2) : ''));

// Lista de campos "con valor" para ojear rápido cuáles sirven.
const fields = computed(() => {
    const d = result.value?.data;
    if (!d || typeof d !== 'object' || Array.isArray(d)) return [];
    return Object.entries(d)
        .filter(([k]) => !k.startsWith('@'))
        .map(([k, v]) => ({
            key: k,
            value: v === null ? null : Array.isArray(v) ? v.join(', ') : String(v),
            empty: v === null || v === '' || (Array.isArray(v) && v.length === 0),
        }));
});

async function lookup() {
    if (!upn.value.trim()) return;
    loading.value = true;
    error.value = null;
    result.value = null;
    try {
        const { data } = await window.axios.post('/admin/explorador-entra', {
            upn: upn.value.trim(),
            select: select.value,
        });
        result.value = data;
        if (data.ok === false && data.message) error.value = data.message;
    } catch (e) {
        error.value = e.response?.data?.message ?? 'No se pudo ejecutar la consulta.';
    } finally {
        loading.value = false;
    }
}

function useSuggested() {
    select.value = props.suggestedSelect;
}

function clearSelect() {
    select.value = '';
}

async function copyJson() {
    try {
        await navigator.clipboard.writeText(pretty.value);
    } catch (_) {
        // sin portapapeles disponible: ignorar
    }
}
</script>

<template>
    <Head title="Explorar Entra" />

    <AppLayout>
        <div class="mb-6">
            <Link href="/inicio" class="text-sm text-slate-500 hover:underline">← Volver</Link>
            <h1 class="mt-2 text-xl font-semibold text-slate-900">Explorar datos de Entra ID</h1>
            <p class="text-sm text-slate-500">
                Consulta Microsoft Graph por un usuario y muestra el objeto crudo, para ver qué campos existen y decidir
                cuáles agregar a la config de Entra. Usa el permiso de aplicación
                <code class="rounded bg-slate-100 px-1">User.Read.All</code>.
            </p>
        </div>

        <form class="space-y-4 rounded-xl border border-slate-200 bg-white p-5" @submit.prevent="lookup">
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Correo o UPN del usuario</label>
                <input v-model="upn" type="text" placeholder="persona@verfrut.cl" :class="inputClass" autocomplete="off" />
            </div>

            <div>
                <div class="mb-1 flex items-center justify-between">
                    <label class="block text-sm font-medium text-slate-700">Campos ($select)</label>
                    <div class="flex gap-2 text-xs">
                        <button type="button" class="text-blue-600 hover:underline" @click="useSuggested">Sugeridos</button>
                        <button type="button" class="text-slate-500 hover:underline" @click="clearSelect">Vaciar (por defecto)</button>
                    </div>
                </div>
                <textarea v-model="select" rows="3" :class="inputClass" spellcheck="false"
                    placeholder="displayName,country,usageLocation… (en blanco = propiedades por defecto de Graph)"></textarea>
                <p class="mt-1 text-xs text-slate-500">
                    Separa por comas. Si Graph rechaza un campo inexistente, verás el error abajo (también informativo).
                </p>
            </div>

            <div class="flex justify-end">
                <button type="submit" :disabled="loading || !upn.trim()"
                    class="rounded-lg bg-blue-600 px-5 py-2 text-sm font-medium text-white transition hover:bg-blue-700 disabled:opacity-60">
                    {{ loading ? 'Consultando…' : 'Consultar' }}
                </button>
            </div>
        </form>

        <!-- Error / estado -->
        <div v-if="error" class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            {{ error }}
        </div>

        <div v-if="result && !error" class="mt-6 space-y-6">
            <div class="flex items-center gap-3 text-sm">
                <span class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                    :class="result.ok ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'">
                    HTTP {{ result.status }}
                </span>
                <span v-if="result.ok" class="text-slate-500">{{ fields.length }} campos devueltos</span>
            </div>

            <!-- Tabla campo → valor (solo si vino un usuario) -->
            <div v-if="fields.length" class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-2 font-medium">Campo</th>
                            <th class="px-4 py-2 font-medium">Valor</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="f in fields" :key="f.key" :class="f.empty ? 'text-slate-400' : 'text-slate-800'">
                            <td class="px-4 py-2 font-mono text-xs">{{ f.key }}</td>
                            <td class="px-4 py-2">
                                <span v-if="f.empty" class="italic">— vacío —</span>
                                <span v-else>{{ f.value }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- JSON crudo -->
            <div class="rounded-xl border border-slate-200 bg-slate-900">
                <div class="flex items-center justify-between border-b border-slate-700 px-4 py-2">
                    <span class="text-xs font-medium text-slate-300">Respuesta cruda (JSON)</span>
                    <button type="button" class="text-xs text-slate-400 hover:text-white" @click="copyJson">Copiar</button>
                </div>
                <pre class="max-h-[32rem] overflow-auto px-4 py-3 text-xs leading-relaxed text-slate-100"><code>{{ pretty }}</code></pre>
            </div>
        </div>
    </AppLayout>
</template>

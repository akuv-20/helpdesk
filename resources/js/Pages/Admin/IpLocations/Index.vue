<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    rules: { type: Array, default: () => [] },
    locations: { type: Array, default: () => [] },
    glpiConfigured: { type: Boolean, default: false },
});

const inputClass =
    'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-100 focus:outline-none';

const form = useForm({ cidr: '', locations_id: '', label: '', enabled: true });
const editingId = ref(null); // null = creando; id = editando esa regla

// Combobox de ubicación: input con filtro en vez de un <select> largo.
const locationQuery = ref('');
const locationOpen = ref(false);
const filteredLocations = computed(() => {
    const q = locationQuery.value.trim().toLowerCase();
    const list = q ? props.locations.filter((l) => l.name.toLowerCase().includes(q)) : props.locations;
    return list.slice(0, 50); // acota la lista visible aunque haya cientos
});
function onLocationInput() {
    locationOpen.value = true;
    form.locations_id = ''; // al editar el texto se deselecciona hasta elegir de la lista
}
function pickLocation(l) {
    form.locations_id = l.id;
    locationQuery.value = l.name;
    locationOpen.value = false;
}
function closeLocationSoon() {
    setTimeout(() => { locationOpen.value = false; }, 120);
}

function resetForm() {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    locationQuery.value = '';
}

function submit() {
    const opts = { preserveScroll: true, onSuccess: () => resetForm() };
    if (editingId.value) {
        form.put(`/admin/ubicaciones-ip/${editingId.value}`, opts);
    } else {
        form.post('/admin/ubicaciones-ip', opts);
    }
}

function startEdit(rule) {
    editingId.value = rule.id;
    form.cidr = rule.cidr;
    form.locations_id = rule.locations_id;
    form.label = rule.label ?? '';
    form.enabled = rule.enabled;
    form.clearErrors();
    locationQuery.value = rule.location_name ?? '';
    // Lleva la vista al formulario (está arriba).
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function toggle(rule) {
    router.put(`/admin/ubicaciones-ip/${rule.id}`, {
        cidr: rule.cidr,
        locations_id: rule.locations_id,
        label: rule.label,
        enabled: !rule.enabled,
    }, { preserveScroll: true });
}

function remove(rule) {
    if (confirm(`¿Eliminar la regla ${rule.cidr}?`)) {
        router.delete(`/admin/ubicaciones-ip/${rule.id}`, { preserveScroll: true });
    }
}

// Probar IP (AJAX, sin recargar).
const testIp = ref('');
const testResult = ref(null);
const testing = ref(false);
async function probar() {
    if (!testIp.value.trim()) return;
    testing.value = true;
    testResult.value = null;
    try {
        const { data } = await window.axios.post('/admin/ubicaciones-ip/probar', { ip: testIp.value.trim() });
        testResult.value = data;
    } finally {
        testing.value = false;
    }
}
</script>

<template>
    <Head title="Ubicaciones por IP" />

    <AppLayout>
        <div class="mb-6">
            <Link href="/inicio" class="text-sm text-slate-500 hover:underline">← Volver</Link>
            <h1 class="mt-2 text-xl font-semibold text-slate-900">Ubicaciones por IP</h1>
            <p class="text-sm text-slate-500">
                Vincula un segmento de red con una ubicación de GLPI. Al crear un ticket, el portal detecta
                la IP del solicitante y le asigna la ubicación que corresponda.
            </p>
        </div>

        <div
            v-if="!glpiConfigured"
            class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
        >
            La conexión con GLPI no está configurada, así que no hay ubicaciones para elegir.
        </div>

        <!-- Alta / edición de regla -->
        <form
            class="mb-6 rounded-xl border bg-white p-5"
            :class="editingId ? 'border-blue-300 ring-1 ring-blue-100' : 'border-slate-200'"
            @submit.prevent="submit"
        >
            <h2 class="mb-3 text-sm font-semibold text-slate-700">{{ editingId ? 'Editar regla' : 'Nueva regla' }}</h2>
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Segmento (CIDR)</label>
                    <input v-model="form.cidr" type="text" placeholder="192.168.32.0/24  ·  o 192.168.32" :class="inputClass" />
                    <p class="mt-1 text-xs text-slate-400">Puedes escribir <code>192.168.32</code> (asume /24), <code>192.168.0.0/16</code>, etc.</p>
                    <p v-if="form.errors.cidr" class="mt-1 text-xs text-red-600">{{ form.errors.cidr }}</p>
                </div>
                <div class="relative">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Ubicación de GLPI</label>
                    <input
                        v-model="locationQuery"
                        type="text"
                        placeholder="Buscar ubicación…"
                        autocomplete="off"
                        :class="inputClass"
                        @focus="locationOpen = true"
                        @input="onLocationInput"
                        @blur="closeLocationSoon"
                    />
                    <ul
                        v-if="locationOpen && filteredLocations.length"
                        class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg"
                    >
                        <li
                            v-for="l in filteredLocations"
                            :key="l.id"
                            class="cursor-pointer px-3 py-1.5 text-sm text-slate-700 hover:bg-blue-50"
                            :class="{ 'bg-blue-50 font-medium': l.id === form.locations_id }"
                            @mousedown.prevent="pickLocation(l)"
                        >
                            {{ l.name }}
                        </li>
                    </ul>
                    <p v-if="locationOpen && locationQuery && !filteredLocations.length" class="mt-1 text-xs text-slate-400">
                        Sin coincidencias.
                    </p>
                    <p v-if="form.errors.locations_id" class="mt-1 text-xs text-red-600">{{ form.errors.locations_id }}</p>
                </div>
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-600">Nota <span class="font-normal text-slate-400">(opcional)</span></label>
                    <input v-model="form.label" type="text" placeholder="Ej. Recepción fruta — Planta Oro Verde" :class="inputClass" />
                </div>
            </div>
            <div class="mt-4 flex justify-end gap-2">
                <button
                    v-if="editingId"
                    type="button"
                    class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50"
                    @click="resetForm"
                >
                    Cancelar
                </button>
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-lg bg-blue-600 px-5 py-2 text-sm font-medium text-white transition hover:bg-blue-700 disabled:opacity-60"
                >
                    {{ editingId ? 'Guardar cambios' : 'Agregar regla' }}
                </button>
            </div>
        </form>

        <!-- Probar IP -->
        <div class="mb-6 rounded-xl border border-slate-200 bg-white p-5">
            <h2 class="mb-3 text-sm font-semibold text-slate-700">Probar una IP</h2>
            <div class="flex flex-wrap items-start gap-2">
                <input v-model="testIp" type="text" placeholder="192.168.32.45" class="min-w-0 flex-1" :class="inputClass" @keyup.enter="probar" />
                <button
                    type="button"
                    :disabled="testing"
                    class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 disabled:opacity-60"
                    @click="probar"
                >
                    Probar
                </button>
            </div>
            <p v-if="testResult" class="mt-3 text-sm">
                <template v-if="testResult.matched">
                    <span class="text-slate-500">{{ testResult.ip }} →</span>
                    <span class="font-medium text-green-700">{{ testResult.location }}</span>
                </template>
                <span v-else class="text-slate-500">{{ testResult.ip }} → sin ubicación (ninguna regla coincide)</span>
            </p>
        </div>

        <!-- Reglas existentes -->
        <div v-if="rules.length" class="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs text-slate-500">
                    <tr>
                        <th class="px-4 py-2 font-medium">Segmento</th>
                        <th class="px-4 py-2 font-medium">Ubicación</th>
                        <th class="px-4 py-2 font-medium">Nota</th>
                        <th class="px-4 py-2 font-medium">Estado</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr
                        v-for="rule in rules"
                        :key="rule.id"
                        :class="[rule.enabled ? '' : 'opacity-50', editingId === rule.id ? 'bg-blue-50' : '']"
                    >
                        <td class="px-4 py-2 font-mono text-slate-800">{{ rule.cidr }}</td>
                        <td class="px-4 py-2 text-slate-700">{{ rule.location_name ?? ('#' + rule.locations_id) }}</td>
                        <td class="px-4 py-2 text-slate-500">{{ rule.label || '—' }}</td>
                        <td class="px-4 py-2">
                            <button
                                type="button"
                                class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="rule.enabled ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-500'"
                                @click="toggle(rule)"
                            >
                                {{ rule.enabled ? 'Activa' : 'Inactiva' }}
                            </button>
                        </td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            <button type="button" class="text-xs text-blue-600 hover:underline" @click="startEdit(rule)">Editar</button>
                            <button type="button" class="ml-3 text-xs text-red-500 hover:underline" @click="remove(rule)">Eliminar</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div v-else class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-10 text-center text-sm text-slate-500">
            Todavía no hay reglas. Agrega la primera arriba.
        </div>
    </AppLayout>
</template>

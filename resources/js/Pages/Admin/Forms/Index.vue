<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';

defineProps({
    definitions: { type: Array, default: () => [] },
});

const typeLabel = (t) => (t === 'incident' ? 'Incidente' : 'Solicitud');

function remove(def) {
    if (confirm(`¿Eliminar el formulario "${def.name || def.category_name}"?`)) {
        router.delete(`/admin/formularios/${def.id}`);
    }
}
</script>

<template>
    <Head title="Formularios" />

    <AppLayout>
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold text-slate-900">Formularios</h1>
                <p class="text-sm text-slate-500">Configura los campos por tipo y categoría, sin tocar código.</p>
            </div>
            <Link
                href="/admin/formularios/nuevo"
                class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-blue-700"
            >
                Nuevo formulario
            </Link>
        </div>

        <div v-if="definitions.length" class="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">Tipo</th>
                        <th class="px-4 py-3 font-medium">Categoría</th>
                        <th class="px-4 py-3 font-medium">Campos</th>
                        <th class="px-4 py-3 font-medium">Estado</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="def in definitions" :key="def.id">
                        <td class="px-4 py-3">{{ typeLabel(def.type) }}</td>
                        <td class="px-4 py-3 font-medium text-slate-900">{{ def.category_name }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ def.field_count }}</td>
                        <td class="px-4 py-3">
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="def.is_active ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-500'"
                            >{{ def.is_active ? 'Activo' : 'Inactivo' }}</span>
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <Link :href="`/admin/formularios/${def.id}`" class="text-sm font-medium text-blue-600 hover:underline">Editar</Link>
                            <button type="button" class="ml-3 text-sm text-red-600 hover:underline" @click="remove(def)">Eliminar</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-else class="grid place-items-center rounded-xl border border-dashed border-slate-300 bg-white px-4 py-16 text-center">
            <p class="text-slate-500">Todavía no hay formularios configurados.</p>
            <Link href="/admin/formularios/nuevo" class="mt-2 text-sm font-medium text-blue-600 hover:underline">Crear el primero</Link>
        </div>
    </AppLayout>
</template>

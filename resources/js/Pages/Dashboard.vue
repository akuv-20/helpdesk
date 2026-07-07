<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';

defineProps({
    tickets: { type: Array, default: () => [] },
    glpiConfigured: { type: Boolean, default: false },
});

const statusLabels = {
    1: 'Nuevo',
    2: 'En curso (asignado)',
    3: 'En curso (planificado)',
    4: 'En espera',
    5: 'Resuelto',
    6: 'Cerrado',
};

function statusLabel(status) {
    return statusLabels[status] ?? 'En proceso';
}
</script>

<template>
    <Head title="Mis solicitudes" />

    <AppLayout>
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold text-slate-900">Mis solicitudes</h1>
                <p class="text-sm text-slate-500">Aquí ves el estado de todo lo que has reportado.</p>
            </div>
            <Link
                href="/tickets/nuevo"
                class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-blue-700"
            >
                Nueva solicitud
            </Link>
        </div>

        <div
            v-if="!glpiConfigured"
            class="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
        >
            La conexión con GLPI todavía no está configurada, por lo que no se muestran solicitudes reales.
        </div>

        <div v-if="tickets.length" class="divide-y divide-slate-200 overflow-hidden rounded-xl border border-slate-200 bg-white">
            <div v-for="ticket in tickets" :key="ticket.id" class="flex items-center justify-between gap-4 px-4 py-3">
                <div class="min-w-0">
                    <p class="truncate font-medium text-slate-900">{{ ticket.title }}</p>
                    <p class="text-xs text-slate-400">#{{ ticket.id }} · {{ ticket.opened_at }}</p>
                </div>
                <span class="shrink-0 rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600">
                    {{ statusLabel(ticket.status) }}
                </span>
            </div>
        </div>

        <div v-else class="grid place-items-center rounded-xl border border-dashed border-slate-300 bg-white px-4 py-16 text-center">
            <p class="text-slate-500">Aún no tienes solicitudes.</p>
            <Link href="/tickets/nuevo" class="mt-2 text-sm font-medium text-blue-600 hover:underline">
                Crear la primera
            </Link>
        </div>
    </AppLayout>
</template>

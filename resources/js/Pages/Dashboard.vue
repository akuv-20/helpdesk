<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '../Layouts/AppLayout.vue';

const props = defineProps({
    tickets: { type: Array, default: () => [] },
    pagination: { type: Object, default: () => ({ total: 0, page: 1, per_page: 20, last_page: 1 }) },
    filters: { type: Object, default: () => ({ q: '', status: 'all' }) },
    pendingApprovals: { type: Array, default: () => [] },
    glpiConfigured: { type: Boolean, default: false },
});

// Modal de confirmación: aparece con el número del ticket recién creado.
const inertiaPage = usePage();
const createdTicket = ref(inertiaPage.props.flash?.createdTicket ?? null);
watch(() => inertiaPage.props.flash?.createdTicket, (v) => { if (v) createdTicket.value = v; });

const statusLabels = {
    1: 'Nuevo',
    2: 'En curso (asignado)',
    3: 'En curso (planificado)',
    4: 'En espera',
    5: 'Resuelto',
    6: 'Cerrado',
};
const statusColors = {
    1: 'bg-green-100 text-green-700',
    2: 'bg-amber-100 text-amber-700',
    3: 'bg-amber-100 text-amber-700',
    4: 'bg-slate-100 text-slate-600',
    5: 'bg-blue-100 text-blue-700',
    6: 'bg-slate-200 text-slate-700',
};
const statusLabel = (s) => statusLabels[s] ?? 'En proceso';
const statusColor = (s) => statusColors[s] ?? 'bg-slate-100 text-slate-600';

// Controles (inicializados desde el servidor). Búsqueda/filtro/paginación
// se resuelven server-side vía Inertia.
const query = ref(props.filters.q ?? '');
const statusFilter = ref(props.filters.status ?? 'all');

const hasFilters = computed(() => !!(query.value || (statusFilter.value && statusFilter.value !== 'all')));
const showControls = computed(() => props.tickets.length > 0 || hasFilters.value);
const canPrev = computed(() => props.pagination.page > 1);
const canNext = computed(() => props.pagination.page < props.pagination.last_page);

function reload(page = 1) {
    router.get('/inicio', {
        q: query.value || undefined,
        status: statusFilter.value !== 'all' ? statusFilter.value : undefined,
        page: page > 1 ? page : undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

// Búsqueda con debounce; el filtro de estado recarga al instante.
let searchTimer = null;
watch(query, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => reload(1), 350);
});
watch(statusFilter, () => reload(1));
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

        <!-- Aprobaciones pendientes: validaciones que un técnico pidió responder -->
        <div v-if="pendingApprovals.length" class="mb-6">
            <div class="mb-2 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-indigo-800">Aprobaciones pendientes</h2>
                <Link href="/aprobaciones" class="text-xs font-medium text-indigo-600 hover:underline">Ver todas →</Link>
            </div>
            <div class="divide-y divide-indigo-100 overflow-hidden rounded-xl border border-indigo-200 bg-indigo-50">
                <Link
                    v-for="ap in pendingApprovals"
                    :key="ap.id"
                    :href="`/tickets/${ap.id}`"
                    class="flex items-center justify-between gap-4 px-4 py-3 transition hover:bg-indigo-100"
                >
                    <div class="min-w-0">
                        <p class="truncate font-medium text-slate-900">{{ ap.title }}</p>
                        <p class="text-xs text-slate-500">
                            #{{ ap.id }}
                            <span v-if="ap.requested_by">· solicitada por {{ ap.requested_by }}</span>
                            <span v-if="ap.requested_at">· {{ ap.requested_at }}</span>
                        </p>
                    </div>
                    <span class="shrink-0 rounded-full bg-indigo-600 px-3 py-1 text-xs font-medium text-white">Responder</span>
                </Link>
            </div>
        </div>

        <template v-if="showControls">
            <!-- Buscador + filtro por estado (server-side) -->
            <div class="mb-4 flex flex-wrap gap-2">
                <input
                    v-model="query"
                    type="search"
                    placeholder="Buscar por nombre o descripción…"
                    class="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-100 focus:outline-none"
                />
                <select
                    v-model="statusFilter"
                    class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700 focus:border-blue-500 focus:ring-2 focus:ring-blue-100 focus:outline-none"
                >
                    <option value="all">Todos los estados</option>
                    <option value="1">Nuevos</option>
                    <option value="curso">En curso</option>
                    <option value="4">En espera</option>
                    <option value="5">Resueltos</option>
                    <option value="6">Cerrados</option>
                </select>
            </div>

            <div v-if="tickets.length" class="divide-y divide-slate-200 overflow-hidden rounded-xl border border-slate-200 bg-white">
                <Link
                    v-for="ticket in tickets"
                    :key="ticket.id"
                    :href="`/tickets/${ticket.id}`"
                    class="flex items-center justify-between gap-4 px-4 py-3 transition hover:bg-slate-100"
                >
                    <div class="min-w-0">
                        <p class="truncate font-medium text-slate-900">{{ ticket.title }}</p>
                        <p class="text-xs text-slate-400">
                            #{{ ticket.id }} · Actualizado {{ ticket.updated_at ?? ticket.opened_at }}
                        </p>
                    </div>
                    <span class="shrink-0 rounded-full px-3 py-1 text-xs font-medium" :class="statusColor(ticket.status)">
                        {{ statusLabel(ticket.status) }}
                    </span>
                </Link>
            </div>

            <div v-else class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-12 text-center text-sm text-slate-500">
                Sin resultados con esos filtros.
            </div>

            <!-- Paginación server-side -->
            <div v-if="pagination.last_page > 1" class="mt-4 flex items-center justify-between text-sm">
                <button
                    type="button"
                    :disabled="!canPrev"
                    class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-slate-600 transition hover:bg-slate-100 disabled:opacity-40"
                    @click="reload(pagination.page - 1)"
                >
                    ← Anterior
                </button>
                <span class="text-slate-500">Página {{ pagination.page }} de {{ pagination.last_page }} · {{ pagination.total }} solicitudes</span>
                <button
                    type="button"
                    :disabled="!canNext"
                    class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-slate-600 transition hover:bg-slate-100 disabled:opacity-40"
                    @click="reload(pagination.page + 1)"
                >
                    Siguiente →
                </button>
            </div>
        </template>

        <div v-else class="grid place-items-center rounded-xl border border-dashed border-slate-300 bg-white px-4 py-16 text-center">
            <p class="text-slate-500">Aún no tienes solicitudes.</p>
            <Link href="/tickets/nuevo" class="mt-2 text-sm font-medium text-blue-600 hover:underline">
                Crear la primera
            </Link>
        </div>

        <!-- Modal de confirmación con el número de ticket -->
        <Teleport to="body">
            <Transition name="modal">
                <div
                    v-if="createdTicket"
                    class="fixed inset-0 z-50 grid place-items-center bg-slate-900/50 p-4"
                    @click.self="createdTicket = null"
                >
                    <div class="w-full max-w-md rounded-2xl bg-white p-6 text-center shadow-xl">
                        <div class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-green-100 text-3xl text-green-600">
                            ✓
                        </div>
                        <h2 class="mt-4 text-lg font-semibold text-slate-900">¡Solicitud creada!</h2>
                        <p class="mt-1 text-sm text-slate-500">Tu solicitud quedó registrada con el número:</p>

                        <div class="my-4 rounded-xl border border-slate-200 bg-slate-50 py-4">
                            <span class="text-3xl font-bold tracking-tight text-blue-600">#{{ createdTicket }}</span>
                        </div>

                        <p class="text-sm text-slate-600">
                            <strong>Toma nota de este número.</strong> Te servirá para hacer seguimiento a tu solicitud.
                        </p>

                        <div class="mt-6 flex gap-3">
                            <Link
                                :href="`/tickets/${createdTicket}`"
                                class="flex-1 rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
                            >
                                Ver solicitud
                            </Link>
                            <button
                                type="button"
                                class="flex-1 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-blue-700"
                                @click="createdTicket = null"
                            >
                                Entendido
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </AppLayout>
</template>

<style scoped>
.modal-enter-active,
.modal-leave-active {
    transition: opacity 0.25s ease;
}
.modal-enter-from,
.modal-leave-to {
    opacity: 0;
}
</style>

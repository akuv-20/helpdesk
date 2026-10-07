<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '../Layouts/AppLayout.vue';

const props = defineProps({
    tickets: { type: Array, default: () => [] },
    pagination: { type: Object, default: () => ({ total: 0, page: 1, per_page: 20, last_page: 1 }) },
    filters: { type: Object, default: () => ({ q: '', status: '1,2,3,4,5' }) },
    statusCounts: { type: Object, default: () => ({}) },
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
// Color de la franja izquierda por estado (mismo criterio que el pill).
const statusStripes = {
    1: 'border-l-green-500',
    2: 'border-l-amber-500',
    3: 'border-l-amber-500',
    4: 'border-l-slate-400',
    5: 'border-l-blue-500',
    6: 'border-l-slate-400',
};
const statusLabel = (s) => statusLabels[s] ?? 'En proceso';
const statusColor = (s) => statusColors[s] ?? 'bg-slate-100 text-slate-600';
const statusStripe = (s) => statusStripes[s] ?? 'border-l-slate-400';

// Filtro de estado como chips multi-selección. Cada chip agrupa uno o más
// estados GLPI; "En curso" junta asignado (2) y planificado (3).
const STATUS_BUCKETS = [
    { key: '1', label: 'Nuevos', ids: [1], dot: 'bg-green-500' },
    { key: 'curso', label: 'En curso', ids: [2, 3], dot: 'bg-amber-500' },
    { key: '4', label: 'En espera', ids: [4], dot: 'bg-slate-400' },
    { key: '5', label: 'Resueltos', ids: [5], dot: 'bg-blue-500' },
    { key: '6', label: 'Cerrados', ids: [6], dot: 'bg-slate-500' },
];
const ALL_IDS = [1, 2, 3, 4, 5, 6];

function parseStatus(s) {
    if (!s || s === 'all') return [...ALL_IDS];
    const out = new Set();
    String(s).split(',').forEach((p) => {
        p = p.trim();
        if (p === 'curso') { out.add(2); out.add(3); }
        else { const n = Number(p); if (n >= 1 && n <= 6) out.add(n); }
    });
    return [...out];
}

// Controles (inicializados desde el servidor). Búsqueda/filtro/paginación
// se resuelven server-side vía Inertia.
const query = ref(props.filters.q ?? '');
const selected = ref(new Set(parseStatus(props.filters.status)));

const counts = computed(() => props.statusCounts ?? {});
const totalCount = computed(() => ALL_IDS.reduce((s, id) => s + (counts.value[id] ?? 0), 0));
const bucketCount = (b) => b.ids.reduce((s, id) => s + (counts.value[id] ?? 0), 0);
const isActive = (b) => b.ids.every((id) => selected.value.has(id));
const isAll = computed(() => ALL_IDS.every((id) => selected.value.has(id)));

function statusParam() {
    if (isAll.value) return 'all';
    return ALL_IDS.filter((id) => selected.value.has(id)).join(',');
}

function toggleBucket(b) {
    const next = new Set(selected.value);
    if (b.ids.every((id) => next.has(id))) {
        // Apagar, salvo que dejara el filtro vacío (siempre ≥1 estado).
        if ([...next].filter((id) => !b.ids.includes(id)).length === 0) return;
        b.ids.forEach((id) => next.delete(id));
    } else {
        b.ids.forEach((id) => next.add(id));
    }
    selected.value = next;
    reload(1);
}

function selectAll() {
    selected.value = new Set(ALL_IDS);
    reload(1);
}

const hasAnyTicket = computed(() => totalCount.value > 0);
const showControls = computed(() => hasAnyTicket.value);
const canPrev = computed(() => props.pagination.page > 1);
const canNext = computed(() => props.pagination.page < props.pagination.last_page);

function reload(page = 1) {
    router.get('/inicio', {
        q: query.value || undefined,
        status: statusParam(), // siempre explícito (el default no es "all")
        page: page > 1 ? page : undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

// Búsqueda con debounce; los chips recargan al instante (en toggleBucket).
let searchTimer = null;
watch(query, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => reload(1), 350);
});
</script>

<template>
    <Head title="Mis tickets" />

    <AppLayout>
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold text-slate-900">Mis tickets</h1>
                <p class="text-sm text-slate-500">Aquí ves el estado de todo lo que has reportado.</p>
            </div>
            <Link
                href="/tickets/nuevo"
                class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-blue-700"
            >
                Nuevo ticket
            </Link>
        </div>

        <div
            v-if="!glpiConfigured"
            class="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
        >
            La conexión con GLPI todavía no está configurada, por lo que no se muestran tickets reales.
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
            <!-- Buscador (server-side) -->
            <div class="mb-3">
                <input
                    v-model="query"
                    type="search"
                    placeholder="Buscar por nombre o descripción…"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-100 focus:outline-none"
                />
            </div>

            <!-- Filtro de estado: chips multi-selección con contador -->
            <div class="mb-4 flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-medium transition"
                    :class="isAll ? 'bg-slate-900 text-white' : 'border border-slate-200 bg-white text-slate-600 hover:bg-slate-50'"
                    @click="selectAll"
                >
                    Todos
                    <span :class="isAll ? 'text-slate-300' : 'text-slate-400'">{{ totalCount }}</span>
                </button>
                <button
                    v-for="b in STATUS_BUCKETS"
                    :key="b.key"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-medium transition"
                    :class="isActive(b)
                        ? 'border-slate-300 bg-white text-slate-800 shadow-sm'
                        : 'border-slate-200 bg-slate-50 text-slate-400 hover:bg-slate-100'"
                    @click="toggleBucket(b)"
                >
                    <span class="h-2 w-2 rounded-full" :class="isActive(b) ? b.dot : 'bg-slate-300'"></span>
                    {{ b.label }}
                    <span :class="isActive(b) ? 'text-slate-500' : 'text-slate-400'">{{ bucketCount(b) }}</span>
                </button>
            </div>

            <div v-if="tickets.length" class="divide-y divide-slate-200 overflow-hidden rounded-xl border border-slate-200 bg-white">
                <Link
                    v-for="ticket in tickets"
                    :key="ticket.id"
                    :href="`/tickets/${ticket.id}`"
                    class="flex items-center gap-4 border-l-4 px-4 py-3 transition hover:bg-slate-100"
                    :class="statusStripe(ticket.status)"
                >
                    <!-- Rail de número: el dato que más se usa para dar seguimiento -->
                    <div class="flex min-w-[64px] flex-col items-center justify-center rounded-lg bg-blue-50 px-2.5 py-1.5">
                        <span class="text-[10px] font-semibold tracking-wider text-blue-500">TICKET</span>
                        <span class="text-xl font-bold leading-none text-blue-900 tabular-nums">{{ ticket.id }}</span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium text-slate-900">{{ ticket.title }}</p>
                        <p class="text-xs text-slate-400">Actualizado {{ ticket.updated_at ?? ticket.opened_at }}</p>
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
                <span class="text-slate-500">Página {{ pagination.page }} de {{ pagination.last_page }} · {{ pagination.total }} tickets</span>
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
            <p class="text-slate-500">Aún no tienes tickets.</p>
            <Link href="/tickets/nuevo" class="mt-2 text-sm font-medium text-blue-600 hover:underline">
                Crear el primero
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
                        <h2 class="mt-4 text-lg font-semibold text-slate-900">¡Ticket creado!</h2>
                        <p class="mt-1 text-sm text-slate-500">Tu ticket quedó registrado con el número:</p>

                        <div class="my-4 rounded-xl border border-slate-200 bg-slate-50 py-4">
                            <span class="text-3xl font-bold tracking-tight text-blue-600">#{{ createdTicket }}</span>
                        </div>

                        <p class="text-sm text-slate-600">
                            <strong>Toma nota de este número.</strong> Te servirá para hacer seguimiento a tu ticket.
                        </p>

                        <div class="mt-6 flex gap-3">
                            <Link
                                :href="`/tickets/${createdTicket}`"
                                class="flex-1 rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
                            >
                                Ver ticket
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

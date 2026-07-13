<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

defineProps({
    pending: { type: Array, default: () => [] },
    responded: { type: Array, default: () => [] },
    glpiConfigured: { type: Boolean, default: false },
});
</script>

<template>
    <Head title="Aprobaciones" />

    <AppLayout>
        <div class="mb-6">
            <h1 class="text-xl font-semibold text-slate-900">Aprobaciones</h1>
            <p class="text-sm text-slate-500">Validaciones que te pidieron autorizar.</p>
        </div>

        <div
            v-if="!glpiConfigured"
            class="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
        >
            La conexión con GLPI todavía no está configurada.
        </div>

        <!-- Pendientes -->
        <section class="mb-8">
            <h2 class="mb-2 text-sm font-semibold text-indigo-800">Pendientes de responder</h2>

            <div v-if="pending.length" class="divide-y divide-indigo-100 overflow-hidden rounded-xl border border-indigo-200 bg-indigo-50">
                <Link
                    v-for="ap in pending"
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

            <p v-else class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-6 text-center text-sm text-slate-500">
                No tienes aprobaciones pendientes.
            </p>
        </section>

        <!-- Historial -->
        <section>
            <h2 class="mb-2 text-sm font-semibold text-slate-700">Historial</h2>

            <div v-if="responded.length" class="divide-y divide-slate-100 overflow-hidden rounded-xl border border-slate-200 bg-white">
                <Link
                    v-for="(ap, i) in responded"
                    :key="`${ap.id}-${i}`"
                    :href="`/tickets/${ap.id}`"
                    class="flex items-center justify-between gap-4 px-4 py-3 transition hover:bg-slate-50"
                >
                    <div class="min-w-0">
                        <p class="truncate font-medium text-slate-900">{{ ap.title }}</p>
                        <p class="text-xs text-slate-500">
                            #{{ ap.id }}
                            <span v-if="ap.requested_by">· solicitada por {{ ap.requested_by }}</span>
                            <span v-if="ap.responded_at">· {{ ap.responded_at }}</span>
                        </p>
                    </div>
                    <span
                        class="shrink-0 rounded-full px-3 py-1 text-xs font-medium"
                        :class="ap.outcome === 'approved' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'"
                    >
                        {{ ap.outcome === 'approved' ? 'Aprobada' : 'Rechazada' }}
                    </span>
                </Link>
            </div>

            <p v-else class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-6 text-center text-sm text-slate-500">
                Todavía no has respondido aprobaciones.
            </p>
        </section>
    </AppLayout>
</template>

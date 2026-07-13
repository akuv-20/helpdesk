<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import RichInput from '../../Components/RichInput.vue';

const props = defineProps({
    ticket: { type: Object, required: true },
});

const statusLabels = {
    1: 'Nuevo',
    2: 'En curso (asignado)',
    3: 'En curso (planificado)',
    4: 'En espera',
    5: 'Resuelto',
    6: 'Cerrado',
};

const statusDot = {
    1: 'bg-green-500',
    2: 'bg-amber-500',
    3: 'bg-amber-500',
    4: 'bg-slate-400',
    5: 'bg-blue-500',
    6: 'bg-slate-400',
};

// Responder: solo el solicitante y en tickets abiertos (no Resuelto 5 ni Cerrado 6).
const canReply = computed(() => props.ticket.is_requester !== false && ![5, 6].includes(props.ticket.status));
const statusLabel = computed(() => statusLabels[props.ticket.status] ?? 'En proceso');
const dotColor = computed(() => statusDot[props.ticket.status] ?? 'bg-slate-400');
const typeLabel = computed(() => (props.ticket.type === 1 ? 'Incidente' : 'Solicitud'));
const assignedTo = computed(() => {
    const parts = [...(props.ticket.technicians ?? []), ...(props.ticket.groups ?? [])];
    return parts.length ? parts.join(', ') : 'Sin asignar aún';
});

function initials(name) {
    if (!name) return '?';
    const p = name.trim().split(/\s+/);
    return ((p[0]?.[0] ?? '') + (p[1]?.[0] ?? '')).toUpperCase() || '?';
}

const form = useForm({ content: '', inline_images: [], attachments: [] });
const replyEditor = ref(null);

function onReply({ content, images }) {
    form.content = content;
    form.inline_images = images;
}
function onFileChange(e) {
    form.attachments = [...form.attachments, ...Array.from(e.target.files)];
    e.target.value = '';
}
function removeFile(i) {
    form.attachments = form.attachments.filter((_, idx) => idx !== i);
}
function sendReply() {
    form.post(`/tickets/${props.ticket.id}/responder`, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            replyEditor.value?.reset();
        },
    });
}

// Aprobar / rechazar la solución propuesta (comentario integrado, como GLPI).
const solutionForm = useForm({ action: '', comment: '' });

function respondSolution(action) {
    solutionForm.action = action;
    solutionForm.post(`/tickets/${props.ticket.id}/solucion`, {
        preserveScroll: true,
        onSuccess: () => solutionForm.reset(),
    });
}

// Aprobar / rechazar una VALIDACIÓN (aprobación pedida por un técnico en GLPI).
// Es distinta de la solución: aquí autorizas o no el requerimiento.
const validationForm = useForm({ action: '', comment: '' });

function respondValidation(action) {
    validationForm.action = action;
    validationForm.post(`/tickets/${props.ticket.id}/validacion`, {
        preserveScroll: true,
        onSuccess: () => validationForm.reset(),
    });
}
</script>

<template>
    <Head :title="`Solicitud #${ticket.id}`" />

    <AppLayout>
        <div class="mb-4">
            <Link href="/inicio" class="text-sm text-slate-500 hover:underline">← Mis solicitudes</Link>
        </div>

        <!-- Encabezado -->
        <div class="mb-5 flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-5 py-3">
            <span class="h-3 w-3 rounded-full" :class="dotColor"></span>
            <h1 class="truncate text-lg font-semibold text-slate-900">{{ ticket.title }}</h1>
            <span class="text-sm text-slate-400">#{{ ticket.id }}</span>
        </div>

        <div class="grid gap-5 lg:grid-cols-3">
            <!-- Columna principal: conversación -->
            <div class="space-y-4 lg:col-span-2">
                <!-- Validación pendiente: un técnico pidió TU aprobación (distinta de la solución) -->
                <div v-if="ticket.pending_validation" class="rounded-xl border border-indigo-300 bg-indigo-50 p-5">
                    <p class="text-sm font-medium text-indigo-800">Se solicita tu aprobación</p>
                    <p class="mt-1 text-sm text-indigo-700">
                        El equipo pidió que autorices este requerimiento.
                        <span v-if="ticket.pending_validation.requested_by">Solicitada por {{ ticket.pending_validation.requested_by }}.</span>
                    </p>
                    <p v-if="ticket.pending_validation.comment" class="mt-2 rounded-lg bg-white/70 px-3 py-2 text-sm text-slate-700">
                        “{{ ticket.pending_validation.comment }}”
                    </p>

                    <label class="mt-4 mb-1 block text-xs font-medium text-slate-600">
                        Comentario <span class="font-normal text-slate-400">(opcional al aprobar · obligatorio al rechazar)</span>
                    </label>
                    <textarea
                        v-model="validationForm.comment"
                        rows="3"
                        placeholder="Escribe un comentario…"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-100 focus:outline-none"
                    ></textarea>
                    <p v-if="validationForm.errors.comment" class="mt-1 text-xs text-red-600">{{ validationForm.errors.comment }}</p>

                    <div class="mt-3 flex flex-wrap gap-2">
                        <button
                            type="button"
                            :disabled="validationForm.processing"
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-700 disabled:opacity-60"
                            @click="respondValidation('approve')"
                        >
                            ✓ Aprobar
                        </button>
                        <button
                            type="button"
                            :disabled="validationForm.processing"
                            class="rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-medium text-red-600 transition hover:bg-red-50 disabled:opacity-60"
                            @click="respondValidation('reject')"
                        >
                            ✕ Rechazar
                        </button>
                    </div>
                </div>

                <!-- Aprobar / rechazar solución (ticket Resuelto) — comentario integrado -->
                <div v-if="ticket.can_respond_solution && ticket.is_requester !== false" class="rounded-xl border border-green-300 bg-green-50 p-5">
                    <p class="text-sm font-medium text-green-800">El equipo propuso una solución.</p>
                    <p class="mt-1 text-sm text-green-700">¿Se resolvió tu problema? Revísala en el hilo de abajo.</p>

                    <label class="mt-4 mb-1 block text-xs font-medium text-slate-600">
                        Comentario <span class="font-normal text-slate-400">(opcional al aprobar · obligatorio al rechazar)</span>
                    </label>
                    <textarea
                        v-model="solutionForm.comment"
                        rows="3"
                        placeholder="Escribe un comentario…"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-100 focus:outline-none"
                    ></textarea>
                    <p v-if="solutionForm.errors.comment" class="mt-1 text-xs text-red-600">{{ solutionForm.errors.comment }}</p>

                    <div class="mt-3 flex flex-wrap gap-2">
                        <button
                            type="button"
                            :disabled="solutionForm.processing"
                            class="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-green-700 disabled:opacity-60"
                            @click="respondSolution('approve')"
                        >
                            ✓ Aprobar y cerrar
                        </button>
                        <button
                            type="button"
                            :disabled="solutionForm.processing"
                            class="rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-medium text-red-600 transition hover:bg-red-50 disabled:opacity-60"
                            @click="respondSolution('reject')"
                        >
                            ✕ Rechazar y reabrir
                        </button>
                    </div>
                </div>

                <!-- Aviso: ticket cerrado (sin acciones) -->
                <div
                    v-if="ticket.status === 6"
                    class="rounded-xl border border-slate-200 bg-white px-4 py-3 text-center text-sm text-slate-500"
                >
                    Este ticket está cerrado. Si necesitas algo más, crea una nueva solicitud.
                </div>

                <!-- Responder (solo tickets abiertos) -->
                <form v-if="canReply" class="rounded-xl border border-slate-200 bg-white p-4" @submit.prevent="sendReply">
                    <RichInput
                        ref="replyEditor"
                        :rows="3"
                        placeholder="Escribe una respuesta… puedes pegar capturas (Ctrl+V)."
                        @change="onReply"
                    />
                    <p v-if="form.errors.content" class="mt-1 text-xs text-red-600">{{ form.errors.content }}</p>

                    <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                        <input
                            type="file"
                            multiple
                            accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv,.zip"
                            class="text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-4 file:py-2 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200"
                            @change="onFileChange"
                        />
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="rounded-lg bg-blue-600 px-5 py-2 text-sm font-medium text-white transition hover:bg-blue-700 disabled:opacity-60"
                        >
                            {{ form.processing ? 'Enviando…' : 'Enviar respuesta' }}
                        </button>
                    </div>

                    <ul v-if="form.attachments.length" class="mt-2 space-y-1">
                        <li
                            v-for="(f, i) in form.attachments"
                            :key="i"
                            class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-1.5 text-sm text-slate-700"
                        >
                            <span class="truncate">{{ f.name }}</span>
                            <button type="button" class="ml-3 shrink-0 text-xs text-red-500 hover:underline" @click="removeFile(i)">quitar</button>
                        </li>
                    </ul>
                    <p v-if="form.errors.attachments" class="mt-1 text-xs text-red-600">{{ form.errors.attachments }}</p>
                </form>

                <!-- Timeline (más nuevo arriba, descripción original al final) -->
                <div
                    v-for="(entry, i) in ticket.timeline"
                    :key="i"
                    class="flex gap-3"
                >
                    <div class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-slate-200 text-xs font-semibold text-slate-600">
                        {{ initials(entry.author) }}
                    </div>
                    <div
                        class="min-w-0 flex-1 rounded-xl border p-4"
                        :class="[
                            entry.kind === 'description' || entry.kind === 'solution' || entry.kind === 'validation_approved' ? 'border-green-200 bg-green-50'
                            : entry.kind === 'rejection' || entry.kind === 'validation_rejected' ? 'border-red-200 bg-red-50'
                            : entry.kind === 'validation_request' ? 'border-indigo-200 bg-indigo-50'
                            : 'border-slate-200 bg-white',
                        ]"
                    >
                        <div class="mb-1 flex items-center justify-between gap-2 text-xs text-slate-500">
                            <span class="truncate">
                                {{ entry.author || 'Soporte' }}
                                <span v-if="entry.kind === 'solution'" class="ml-1 font-medium text-green-700">· Solución</span>
                                <span v-else-if="entry.kind === 'rejection'" class="ml-1 font-medium text-red-600">· Rechazo</span>
                                <span v-else-if="entry.kind === 'description'" class="ml-1 text-slate-400">· Solicitud inicial</span>
                                <span v-else-if="entry.kind === 'validation_request'" class="ml-1 font-medium text-indigo-700">· Solicitud de aprobación</span>
                                <span v-else-if="entry.kind === 'validation_approved'" class="ml-1 font-medium text-green-700">· Aprobación concedida</span>
                                <span v-else-if="entry.kind === 'validation_rejected'" class="ml-1 font-medium text-red-600">· Aprobación rechazada</span>
                            </span>
                            <span class="shrink-0">{{ entry.date }}</span>
                        </div>

                        <a
                            v-if="entry.kind === 'document'"
                            :href="`/tickets/${ticket.id}/adjuntos/${entry.doc_id}`"
                            class="inline-flex items-center gap-2 text-sm text-blue-600 hover:underline"
                        >
                            <span class="text-slate-400">📎</span>{{ entry.file }}
                        </a>
                        <!-- Contenido HTML saneado en el backend (imágenes inline vía proxy) -->
                        <div v-else class="prose-ticket text-sm text-slate-700" v-html="entry.content || '—'"></div>
                    </div>
                </div>
            </div>

            <!-- Panel lateral: Caso + Actores -->
            <aside class="space-y-4">
                <div class="rounded-xl border border-slate-200 bg-white p-5">
                    <h2 class="mb-3 text-sm font-semibold text-slate-700">Caso</h2>
                    <dl class="space-y-3 text-sm">
                        <div>
                            <dt class="text-xs text-slate-400">Fecha de apertura</dt>
                            <dd class="text-slate-700">{{ ticket.opened_at ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-400">Tipo</dt>
                            <dd class="text-slate-700">{{ typeLabel }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-400">Categoría</dt>
                            <dd class="text-slate-700">{{ ticket.category ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-400">Estado</dt>
                            <dd class="flex items-center gap-2 text-slate-700">
                                <span class="h-2 w-2 rounded-full" :class="dotColor"></span>{{ statusLabel }}
                            </dd>
                        </div>
                    </dl>
                </div>

                <div class="rounded-xl border border-slate-200 bg-white p-5">
                    <h2 class="mb-3 text-sm font-semibold text-slate-700">Actores</h2>
                    <dl class="space-y-3 text-sm">
                        <div>
                            <dt class="text-xs text-slate-400">Solicitante</dt>
                            <dd class="text-slate-700">{{ ticket.requester ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-400">Asignado a</dt>
                            <dd class="text-slate-700">{{ assignedTo }}</dd>
                        </div>
                    </dl>
                </div>
            </aside>
        </div>
    </AppLayout>
</template>

<style scoped>
.prose-ticket :deep(img) {
    max-width: 100%;
    height: auto;
    border-radius: 8px;
    margin: 6px 0;
}
.prose-ticket :deep(p) {
    margin: 0 0 6px;
}
.prose-ticket :deep(a) {
    color: #2563eb;
    text-decoration: underline;
}
</style>

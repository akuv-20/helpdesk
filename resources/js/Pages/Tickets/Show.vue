<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import RichInput from '../../Components/RichInput.vue';
import EntityChip from '../../Components/EntityChip.vue';

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
const technicians = computed(() => props.ticket.technicians ?? []);
const groups = computed(() => props.ticket.groups ?? []);
const hasAssignees = computed(() => technicians.value.length > 0 || groups.value.length > 0);

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

// Previsualización de adjuntos: imágenes, PDF y Word (.docx) se ven en un modal
// (con opción de descargar); el resto de tipos se descargan directo. El navegador
// solo trae visor nativo para imagen/PDF; el .docx se renderiza en el cliente con
// docx-preview (carga diferida), leyendo el archivo desde nuestro propio endpoint
// autenticado (sin mandar el documento a ningún servicio externo).
const preview = ref(null); // { docId, file, kind }
const docxContainer = ref(null);
const docxState = ref('idle'); // idle | loading | ready | error

const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];

function fileKind(name) {
    const ext = (name ?? '').split('.').pop()?.toLowerCase() ?? '';
    if (IMAGE_EXT.includes(ext)) return 'image';
    if (ext === 'pdf') return 'pdf';
    if (ext === 'docx') return 'docx';
    return 'other';
}
function isPreviewable(name) {
    return fileKind(name) !== 'other';
}
function downloadHref(docId) {
    return `/tickets/${props.ticket.id}/adjuntos/${docId}`;
}
function viewHref(docId) {
    return `/tickets/${props.ticket.id}/adjuntos/${docId}?view=1`;
}
async function openPreview(entry) {
    preview.value = { docId: entry.doc_id, file: entry.file, kind: fileKind(entry.file) };
    if (preview.value.kind === 'docx') {
        await nextTick();
        renderDocx(preview.value.docId);
    }
}
function closePreview() {
    preview.value = null;
    docxState.value = 'idle';
}

// Renderiza un .docx dentro del modal. docx-preview y su parser se cargan solo
// aquí (import dinámico → chunk aparte), así no pesan en el bundle principal.
async function renderDocx(docId) {
    docxState.value = 'loading';
    try {
        const [{ renderAsync }, resp] = await Promise.all([
            import('docx-preview'),
            fetch(viewHref(docId), { credentials: 'same-origin' }),
        ]);
        if (!resp.ok) throw new Error('descarga fallida');
        const blob = await resp.blob();
        if (docxContainer.value) docxContainer.value.innerHTML = '';
        await renderAsync(blob, docxContainer.value, undefined, { inWrapper: true });
        docxState.value = 'ready';
    } catch (e) {
        docxState.value = 'error';
    }
}

function onKeydown(e) {
    if (e.key === 'Escape' && preview.value) closePreview();
}
onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));

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
                            <span class="flex min-w-0 items-center gap-1.5">
                                <EntityChip :label="entry.author || 'Soporte'" variant="user" />
                                <span v-if="entry.kind === 'solution'" class="font-medium text-green-700">· Solución</span>
                                <span v-else-if="entry.kind === 'rejection'" class="font-medium text-red-600">· Rechazo</span>
                                <span v-else-if="entry.kind === 'description'" class="text-slate-400">· Solicitud inicial</span>
                                <span v-else-if="entry.kind === 'validation_request'" class="font-medium text-indigo-700">· Solicitud de aprobación</span>
                                <span v-else-if="entry.kind === 'validation_approved'" class="font-medium text-green-700">· Aprobación concedida</span>
                                <span v-else-if="entry.kind === 'validation_rejected'" class="font-medium text-red-600">· Aprobación rechazada</span>
                            </span>
                            <span class="shrink-0">{{ entry.date }}</span>
                        </div>

                        <template v-if="entry.kind === 'document'">
                            <!-- Imágenes y PDF: abren en un modal con vista previa. -->
                            <button
                                v-if="isPreviewable(entry.file)"
                                type="button"
                                class="inline-flex items-center gap-2 text-sm text-blue-600 hover:underline"
                                @click="openPreview(entry)"
                            >
                                <span class="text-slate-400">📎</span>{{ entry.file }}
                            </button>
                            <!-- Otros tipos: descarga directa. -->
                            <a
                                v-else
                                :href="downloadHref(entry.doc_id)"
                                class="inline-flex items-center gap-2 text-sm text-blue-600 hover:underline"
                            >
                                <span class="text-slate-400">📎</span>{{ entry.file }}
                            </a>
                        </template>
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
                            <dt class="mb-1 text-xs text-slate-400">Tipo</dt>
                            <dd>
                                <EntityChip :label="typeLabel" :variant="ticket.type === 1 ? 'incident' : 'request'" />
                            </dd>
                        </div>
                        <div>
                            <dt class="mb-1 text-xs text-slate-400">Categoría</dt>
                            <dd>
                                <EntityChip v-if="ticket.category" :label="ticket.category" variant="category" />
                                <span v-else class="text-slate-400">—</span>
                            </dd>
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
                            <dt class="mb-1 text-xs text-slate-400">Solicitante</dt>
                            <dd>
                                <EntityChip v-if="ticket.requester" :label="ticket.requester" variant="user" />
                                <span v-else class="text-slate-400">—</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="mb-1 text-xs text-slate-400">Asignado a</dt>
                            <dd v-if="hasAssignees" class="flex flex-wrap gap-1.5">
                                <EntityChip v-for="t in technicians" :key="'t-' + t" :label="t" variant="technician" />
                                <EntityChip v-for="g in groups" :key="'g-' + g" :label="g" variant="group" />
                            </dd>
                            <dd v-else class="text-slate-400">Sin asignar aún</dd>
                        </div>
                    </dl>
                </div>
            </aside>
        </div>

        <!-- Modal de previsualización de adjunto (imagen o PDF) -->
        <Teleport to="body">
            <div
                v-if="preview"
                class="fixed inset-0 z-50 flex flex-col bg-black/70 p-4 sm:p-8"
                @click.self="closePreview"
            >
                <!-- Barra superior: nombre + descargar + cerrar -->
                <div class="mx-auto flex w-full max-w-5xl items-center justify-between gap-3 pb-3 text-white">
                    <span class="truncate text-sm font-medium">{{ preview.file }}</span>
                    <div class="flex shrink-0 items-center gap-2">
                        <a
                            :href="downloadHref(preview.docId)"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-white/15 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-white/25"
                        >
                            ↓ Descargar
                        </a>
                        <button
                            type="button"
                            aria-label="Cerrar"
                            class="grid h-8 w-8 place-items-center rounded-lg bg-white/15 text-lg text-white transition hover:bg-white/25"
                            @click="closePreview"
                        >
                            ✕
                        </button>
                    </div>
                </div>

                <!-- Contenido -->
                <div class="mx-auto flex w-full max-w-5xl flex-1 overflow-hidden rounded-xl bg-white">
                    <img
                        v-if="preview.kind === 'image'"
                        :src="viewHref(preview.docId)"
                        :alt="preview.file"
                        class="m-auto max-h-full max-w-full object-contain"
                    />
                    <iframe
                        v-else-if="preview.kind === 'pdf'"
                        :src="viewHref(preview.docId)"
                        :title="preview.file"
                        class="h-full w-full"
                    ></iframe>
                    <!-- Word (.docx): render en el cliente con docx-preview -->
                    <div v-else-if="preview.kind === 'docx'" class="relative h-full w-full overflow-auto bg-slate-100">
                        <div v-if="docxState === 'loading'" class="grid h-full place-items-center text-sm text-slate-500">
                            Cargando documento…
                        </div>
                        <div v-else-if="docxState === 'error'" class="grid h-full place-items-center px-6 text-center text-sm text-slate-500">
                            <div>
                                <p>No se pudo previsualizar este documento.</p>
                                <a :href="downloadHref(preview.docId)" class="mt-2 inline-block font-medium text-blue-600 hover:underline">
                                    Descargar archivo
                                </a>
                            </div>
                        </div>
                        <div v-show="docxState === 'ready'" ref="docxContainer" class="mx-auto py-4"></div>
                    </div>
                </div>
            </div>
        </Teleport>
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

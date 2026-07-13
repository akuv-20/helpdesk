<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import RichInput from '../../Components/RichInput.vue';

const props = defineProps({
    types: { type: Array, default: () => [] },
    glpiConfigured: { type: Boolean, default: false },
});

// phase: 'type' → 'navigate' (baja por el árbol) → 'details'
const phase = ref('type');
const loadingCats = ref(false);
const tree = ref([]); // raíces del árbol filtrado por tipo (las Áreas)
const path = ref([]); // nodos elegidos hasta ahora (breadcrumb)

const form = useForm({
    type: null,
    itil_category_id: null,
    subject: '',
    description: '',
    inline_images: [],
    attachments: [],
});

function onDescription({ content, images }) {
    form.description = content;
    form.inline_images = images;
}

function onFileChange(e) {
    form.attachments = [...form.attachments, ...Array.from(e.target.files)];
    e.target.value = ''; // permite volver a elegir el mismo archivo
}

function removeFile(i) {
    form.attachments = form.attachments.filter((_, idx) => idx !== i);
}

const steps = ['Tipo', 'Categoría', 'Detalles'];
const stepNumber = computed(() => ({ type: 1, navigate: 2, details: 3 }[phase.value]));

// Opciones del nivel actual: hijos del último nodo elegido, o las raíces.
const currentOptions = computed(() =>
    path.value.length ? path.value[path.value.length - 1].children : tree.value,
);

// Migas: tipo + nombres de los nodos ya elegidos.
const crumbs = computed(() => [typeLabel(form.type), ...path.value.map((n) => n.name)]);

async function pickType(value) {
    form.type = value;
    form.itil_category_id = null;
    path.value = [];
    loadingCats.value = true;
    phase.value = 'navigate';
    try {
        const { data } = await window.axios.get('/tickets/categorias', {
            params: { type: value },
        });
        tree.value = data.areas;
    } finally {
        loadingCats.value = false;
    }
}

function pickNode(node) {
    if (node.children && node.children.length) {
        // Nodo intermedio: bajamos un nivel más.
        path.value = [...path.value, node];
    } else {
        // Hoja: es la categoría real que se envía a GLPI.
        form.itil_category_id = node.id;
        path.value = [...path.value, node];
        phase.value = 'details';
    }
}

function back() {
    if (phase.value === 'details') {
        form.itil_category_id = null;
        path.value = path.value.slice(0, -1);
        phase.value = 'navigate';
    } else if (phase.value === 'navigate') {
        if (path.value.length) {
            path.value = path.value.slice(0, -1);
        } else {
            phase.value = 'type';
        }
    }
}

function submit() {
    form.post('/tickets');
}

const typeLabel = (v) => props.types.find((t) => t.value === v)?.label ?? '';
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
            <li v-for="(label, i) in steps" :key="i" class="flex items-center gap-2">
                <span
                    class="grid h-6 w-6 place-items-center rounded-full"
                    :class="stepNumber >= i + 1 ? 'bg-blue-600 text-white' : 'bg-slate-200 text-slate-500'"
                >{{ i + 1 }}</span>
                <span :class="stepNumber >= i + 1 ? 'text-slate-900' : 'text-slate-400'">{{ label }}</span>
                <span v-if="i < steps.length - 1" class="mx-1 h-px w-6 bg-slate-200"></span>
            </li>
        </ol>

        <!-- Aviso de modo demo: las categorías no vienen de GLPI todavía. -->
        <div
            v-if="!glpiConfigured"
            class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
        >
            <strong>Modo demo:</strong> no hay conexión con GLPI configurada, así que estas categorías son un
            <strong>ejemplo interno</strong> (no las de tu mantenedor). Configúrala en
            <Link href="/admin/conexion" class="font-medium underline">Conexión</Link>
            para usar las categorías reales.
        </div>

        <!-- Migas: tipo + camino recorrido -->
        <div v-if="phase !== 'type'" class="mb-4 flex flex-wrap items-center gap-1.5 text-xs">
            <template v-for="(crumb, i) in crumbs" :key="i">
                <span v-if="i > 0" class="text-slate-300">›</span>
                <span class="rounded-full bg-slate-100 px-3 py-1 text-slate-600">{{ crumb }}</span>
            </template>
        </div>

        <!-- Paso 1: tipo de ticket -->
        <div v-if="phase === 'type'" class="grid gap-3 sm:grid-cols-2">
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

        <!-- Paso 2: navegación por el árbol de categorías -->
        <div v-else-if="phase === 'navigate'">
            <div v-if="loadingCats" class="py-6 text-center text-sm text-slate-400">Cargando categorías…</div>

            <div
                v-else-if="currentOptions.length === 0"
                class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
            >
                Todavía no hay categorías configuradas en GLPI para esta rama.
            </div>

            <div v-else class="grid gap-3 sm:grid-cols-2">
                <button
                    v-for="node in currentOptions"
                    :key="node.name"
                    type="button"
                    class="flex items-center justify-between rounded-xl border border-slate-200 bg-white p-5 text-left font-medium text-slate-900 transition hover:border-blue-400 hover:shadow-sm"
                    @click="pickNode(node)"
                >
                    {{ node.name }}
                    <!-- Flecha si el nodo tiene sub-niveles (navegación). -->
                    <span v-if="node.children && node.children.length" class="text-slate-300">›</span>
                </button>
            </div>

            <button type="button" class="mt-4 text-sm text-slate-500 hover:underline" @click="back">
                ← {{ path.length ? 'Atrás' : 'Cambiar tipo' }}
            </button>
        </div>

        <!-- Paso 3: asunto + descripción -->
        <form v-else class="space-y-5 rounded-xl border border-slate-200 bg-white p-6" @submit.prevent="submit">
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

            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Descripción <span class="text-red-500">*</span></label>
                <RichInput
                    :rows="5"
                    placeholder="Cuéntanos con detalle qué necesitas o qué está fallando. Puedes pegar capturas de pantalla."
                    @change="onDescription"
                />
                <p class="mt-1 text-xs text-slate-400">Tip: pega una captura (Ctrl+V) y se verá aquí mismo.</p>
                <p v-if="form.errors.description" class="mt-1 text-xs text-red-600">{{ form.errors.description }}</p>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Adjuntos <span class="font-normal text-slate-400">(opcional)</span></label>
                <input
                    type="file"
                    multiple
                    accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv,.zip"
                    class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-4 file:py-2 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200"
                    @change="onFileChange"
                />
                <p class="mt-1 text-xs text-slate-400">Hasta 5 archivos, 10 MB c/u.</p>

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
            </div>

            <div class="flex items-center justify-between pt-2">
                <button type="button" class="text-sm text-slate-500 hover:underline" @click="back">← Cambiar categoría</button>
                <button
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

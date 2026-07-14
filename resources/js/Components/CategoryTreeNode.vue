<script setup>
import { router } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';

const props = defineProps({
    node: { type: Object, required: true },
    depth: { type: Number, default: 0 },
    // Texto del buscador (minúsculas). Vacío = sin filtro.
    query: { type: String, default: '' },
    // Estado inicial de despliegue (cambia con "Expandir/Colapsar todo" vía :key).
    defaultOpen: { type: Boolean, default: true },
    // ¿Se pueden agregar subcategorías? (GLPI configurado).
    canAdd: { type: Boolean, default: false },
    // Ruta de nombres desde la raíz hasta el PADRE de este nodo (sin el nivel
    // Incidente/Solicitud, que el árbol pliega). El backend la usa para resolver
    // el id real del padre al crear.
    parentPath: { type: Array, default: () => [] },
    // Rama activa: 'incident' | 'request'.
    branch: { type: String, default: 'incident' },
});

// Ruta completa hasta este nodo (incluyéndolo): la que se envía al agregar un hijo.
const selfPath = computed(() => [...props.parentPath, props.node.name]);

const hasChildren = computed(() => (props.node.children?.length ?? 0) > 0);

// Un nodo hoja (sin hijos) es una categoría REAL de GLPI: la que se envía al
// crear un ticket. Los nodos con hijos son solo navegación.
const isLeaf = computed(() => !hasChildren.value);

const open = ref(props.defaultOpen);

// ¿Este nodo o algún descendiente coincide con el buscador?
function subtreeMatches(node, q) {
    if (!q) return true;
    if ((node.name ?? '').toLowerCase().includes(q)) return true;
    return (node.children ?? []).some((c) => subtreeMatches(c, q));
}

const visible = computed(() => subtreeMatches(props.node, props.query));
const selfMatch = computed(
    () => props.query && (props.node.name ?? '').toLowerCase().includes(props.query),
);

// Hijos que sobreviven al filtro.
const visibleChildren = computed(() =>
    (props.node.children ?? []).filter((c) => subtreeMatches(c, props.query)),
);

// Cuántas categorías reales (hojas) cuelgan de este nodo.
function countLeaves(node) {
    if (!(node.children?.length)) return 1;
    return node.children.reduce((sum, c) => sum + countLeaves(c), 0);
}
const leafCount = computed(() => (isLeaf.value ? 0 : countLeaves(props.node)));

// Con búsqueda activa, forzamos abierto para que se vean las coincidencias.
watch(
    () => props.query,
    (q) => { if (q) open.value = true; },
);

function toggle() {
    if (hasChildren.value) open.value = !open.value;
}

/* ---- Alta de subcategoría (el "+") ------------------------------------- */

// Se puede agregar bajo cualquier nodo (el padre se resuelve por ruta en el
// backend; no depende del id del nodo, que en las carpetas llega nulo).
const canAddHere = computed(() => props.canAdd);

const adding = ref(false);
const newName = ref('');
const saving = ref(false);
const inputEl = ref(null);

async function startAdd() {
    adding.value = true;
    open.value = true; // que se vea dónde caerá la nueva subcategoría
    await nextTick();
    inputEl.value?.focus();
}

function cancelAdd() {
    adding.value = false;
    newName.value = '';
}

function submitAdd() {
    const name = newName.value.trim();
    if (!name || saving.value) return;
    saving.value = true;

    router.post(
        '/admin/categorias',
        { path: selfPath.value, branch: props.branch, name },
        {
            preserveScroll: true,
            preserveState: true, // conserva pestaña/despliegue del árbol
            onSuccess: () => { cancelAdd(); },
            onFinish: () => { saving.value = false; },
        },
    );
}
</script>

<template>
    <li v-if="visible">
        <div
            class="group flex items-center gap-2 rounded-md py-1 pr-2 transition hover:bg-slate-50"
            :style="{ paddingLeft: depth * 20 + 'px' }"
        >
            <!-- Chevron (solo nodos con hijos) -->
            <button
                v-if="hasChildren"
                type="button"
                class="grid h-5 w-5 shrink-0 place-items-center rounded text-slate-400 hover:bg-slate-200 hover:text-slate-600"
                @click="toggle"
            >
                <svg
                    class="h-3.5 w-3.5 transition-transform"
                    :class="open ? 'rotate-90' : ''"
                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                    stroke-linecap="round" stroke-linejoin="round"
                >
                    <path d="m9 18 6-6-6-6" />
                </svg>
            </button>
            <span v-else class="h-5 w-5 shrink-0"></span>

            <!-- Icono: carpeta (navegación) o etiqueta (categoría real / hoja) -->
            <svg
                v-if="hasChildren"
                class="h-4 w-4 shrink-0 text-blue-500"
                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                stroke-linecap="round" stroke-linejoin="round"
            >
                <path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z" />
            </svg>
            <svg
                v-else
                class="h-4 w-4 shrink-0 text-emerald-500"
                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                stroke-linecap="round" stroke-linejoin="round"
            >
                <path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z" />
                <circle cx="7.5" cy="7.5" r=".5" fill="currentColor" />
            </svg>

            <!-- Nombre -->
            <span
                class="text-sm"
                :class="[
                    isLeaf ? 'text-slate-700' : 'font-medium text-slate-900',
                    selfMatch ? 'rounded bg-yellow-100 px-1' : '',
                ]"
            >{{ node.name }}</span>

            <!-- Badge: id de GLPI (hojas) o nº de categorías (nodos) -->
            <span
                v-if="isLeaf"
                class="ml-1 rounded bg-emerald-50 px-1.5 py-0.5 font-mono text-[11px] text-emerald-700"
                title="ID de la categoría en GLPI"
            >#{{ node.id }}</span>
            <span
                v-else
                class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500"
                :title="leafCount + ' categorías'"
            >{{ leafCount }}</span>

            <!-- Botón "+" para agregar una subcategoría bajo este nodo -->
            <button
                v-if="canAddHere && !adding"
                type="button"
                class="ml-1 grid h-5 w-5 place-items-center rounded text-slate-400 transition hover:bg-blue-50 hover:text-blue-600"
                title="Agregar subcategoría aquí"
                @click="startAdd"
            >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 5v14M5 12h14" />
                </svg>
            </button>
        </div>

        <!-- Formulario inline para la nueva subcategoría (como un hijo) -->
        <ul v-if="adding">
            <li>
                <form
                    class="flex items-center gap-2 py-1 pr-2"
                    :style="{ paddingLeft: (depth + 1) * 20 + 'px' }"
                    @submit.prevent="submitAdd"
                >
                    <span class="h-5 w-5 shrink-0"></span>
                    <input
                        ref="inputEl"
                        v-model="newName"
                        type="text"
                        placeholder="Nombre de la subcategoría"
                        maxlength="255"
                        class="min-w-0 flex-1 rounded-md border border-slate-300 px-2 py-1 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-100 focus:outline-none"
                        @keydown.esc="cancelAdd"
                    />
                    <button
                        type="submit"
                        :disabled="saving || !newName.trim()"
                        class="rounded-md bg-blue-600 px-3 py-1 text-sm font-medium text-white transition hover:bg-blue-700 disabled:opacity-50"
                    >
                        {{ saving ? 'Guardando…' : 'Agregar' }}
                    </button>
                    <button type="button" class="rounded-md px-2 py-1 text-sm text-slate-500 hover:text-slate-700" @click="cancelAdd">
                        Cancelar
                    </button>
                </form>
            </li>
        </ul>

        <!-- Hijos (recursión) -->
        <ul v-if="hasChildren && open">
            <CategoryTreeNode
                v-for="(child, i) in visibleChildren"
                :key="child.id ?? child.name + i"
                :node="child"
                :depth="depth + 1"
                :query="query"
                :default-open="defaultOpen"
                :can-add="canAdd"
                :parent-path="selfPath"
                :branch="branch"
            />
        </ul>
    </li>
</template>

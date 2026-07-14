<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import CategoryTreeNode from '../../../Components/CategoryTreeNode.vue';

const props = defineProps({
    glpiConfigured: { type: Boolean, default: false },
    trees: {
        type: Object,
        default: () => ({ incident: [], request: [] }),
    },
});

const tab = ref('incident'); // 'incident' | 'request'
const search = ref('');
const query = computed(() => search.value.trim().toLowerCase());

// Clave del árbol: al cambiar (expandir/colapsar todo) remonta los nodos y
// reinicia su estado de despliegue a `defaultOpen`.
const treeKey = ref(0);
const defaultOpen = ref(true);

function expandAll() {
    defaultOpen.value = true;
    treeKey.value++;
}
function collapseAll() {
    defaultOpen.value = false;
    treeKey.value++;
}

const activeTree = computed(() => props.trees[tab.value] ?? []);

// Estadísticas por rama.
function countLeaves(nodes) {
    return (nodes ?? []).reduce(
        (sum, n) => sum + (n.children?.length ? countLeaves(n.children) : 1),
        0,
    );
}
const stats = computed(() => ({
    incident: { areas: props.trees.incident?.length ?? 0, leaves: countLeaves(props.trees.incident) },
    request: { areas: props.trees.request?.length ?? 0, leaves: countLeaves(props.trees.request) },
}));

const tabs = computed(() => [
    { key: 'incident', label: 'Incidente', ...stats.value.incident },
    { key: 'request', label: 'Solicitud', ...stats.value.request },
]);
</script>

<template>
    <Head title="Categorías" />

    <AppLayout>
        <div class="mb-6">
            <Link href="/inicio" class="text-sm text-slate-500 hover:underline">← Volver</Link>
            <h1 class="mt-2 text-xl font-semibold text-slate-900">Árbol de categorías</h1>
            <p class="text-sm text-slate-500">
                Las categorías ITIL de GLPI tal como las ve el usuario al crear un ticket, pero desplegadas por
                completo para revisarlas de un vistazo. Los nodos con
                <span class="font-medium text-blue-600">carpeta</span> son solo navegación; las
                <span class="font-medium text-emerald-600">etiquetas</span> (hojas) son las categorías reales que
                se envían a GLPI, con su <span class="font-mono">#id</span>.
            </p>
            <p v-if="glpiConfigured" class="mt-1 text-sm text-slate-500">
                Usa el <span class="font-semibold text-blue-600">+</span> junto a cualquier nodo para agregar una
                subcategoría en esa rama (en cualquier nivel).
                <span class="text-amber-700">Se crea en el GLPI compartido</span> (la ven también los técnicos).
            </p>
        </div>

        <div
            v-if="!glpiConfigured"
            class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
        >
            GLPI no está configurado (<Link href="/admin/conexion" class="underline">/admin/conexion</Link>). Se
            muestra el árbol de <strong>ejemplo</strong> (modo demo), no las categorías reales.
        </div>

        <!-- Pestañas por tipo -->
        <div class="mb-4 flex gap-2">
            <button
                v-for="t in tabs"
                :key="t.key"
                type="button"
                class="flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-medium transition"
                :class="tab === t.key
                    ? 'border-blue-600 bg-blue-600 text-white'
                    : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'"
                @click="tab = t.key"
            >
                {{ t.label }}
                <span
                    class="rounded-full px-2 py-0.5 text-xs"
                    :class="tab === t.key ? 'bg-white/20' : 'bg-slate-100 text-slate-500'"
                >{{ t.leaves }}</span>
            </button>
        </div>

        <!-- Barra de herramientas -->
        <div class="mb-3 flex flex-wrap items-center gap-2">
            <div class="relative flex-1 min-w-[200px]">
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"
                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8" /><path d="m21 21-4.3-4.3" />
                </svg>
                <input
                    v-model="search"
                    type="text"
                    placeholder="Buscar categoría…"
                    class="w-full rounded-lg border border-slate-300 py-2 pl-9 pr-3 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-100 focus:outline-none"
                />
            </div>
            <button type="button" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-600 hover:border-slate-300" @click="expandAll">
                Expandir todo
            </button>
            <button type="button" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-600 hover:border-slate-300" @click="collapseAll">
                Colapsar todo
            </button>
        </div>

        <!-- Árbol -->
        <div class="rounded-xl border border-slate-200 bg-white p-3">
            <p class="mb-2 px-1 text-xs text-slate-400">
                {{ stats[tab].areas }} áreas · {{ stats[tab].leaves }} categorías
            </p>

            <ul v-if="activeTree.length" :key="treeKey">
                <CategoryTreeNode
                    v-for="(node, i) in activeTree"
                    :key="node.id ?? node.name + i"
                    :node="node"
                    :depth="0"
                    :query="query"
                    :default-open="defaultOpen"
                    :can-add="glpiConfigured"
                    :parent-path="[]"
                    :branch="tab"
                />
            </ul>
            <p v-else class="px-1 py-6 text-center text-sm text-slate-400">
                No hay categorías para esta rama.
            </p>
        </div>
    </AppLayout>
</template>

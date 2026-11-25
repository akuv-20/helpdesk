<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    graphConfigured: { type: Boolean, default: false },
    glpiWritable: { type: Boolean, default: false },
    batchSize: { type: Number, default: 20 },
});

const inputClass =
    'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-100 focus:outline-none';

const ready = computed(() => props.graphConfigured && props.glpiWritable);

// ---------------- Filtros + directorio ----------------
const filters = reactive({
    q: '',
    country: '',
    domain: '',
    department: '',
    glpi_status: '',
    only_enabled: true,
    only_named: true,
    only_mail: true,
});
const page = ref(1);
const updateExisting = ref(true);

const data = ref(null); // respuesta de /directorio
const loading = ref(false);
const loadError = ref(null);

async function load({ refresh = false } = {}) {
    if (!ready.value) return;
    loading.value = true;
    loadError.value = null;
    try {
        const { data: res } = await window.axios.post('/admin/usuarios-entra/directorio', {
            ...filters,
            page: page.value,
            refresh,
        });
        if (res.ok) {
            data.value = res;
        } else {
            loadError.value = res.message;
        }
    } catch (e) {
        loadError.value = e.response?.data?.message ?? 'No se pudo cargar el directorio.';
    } finally {
        loading.value = false;
    }
}

let debounce = null;
watch(filters, () => {
    page.value = 1;
    clearTimeout(debounce);
    debounce = setTimeout(() => load(), 350);
});
watch(page, () => load());
onMounted(() => load());

const statusLabel = { new: 'No está en GLPI', exists: 'En GLPI', disabled: 'Deshabilitado en GLPI' };
const statusClass = {
    new: 'bg-blue-100 text-blue-700',
    exists: 'bg-green-100 text-green-700',
    disabled: 'bg-slate-200 text-slate-600',
};

// ---------------- Selección ----------------
const selected = ref(new Set());
const pageIds = computed(() => (data.value?.rows ?? []).map((r) => r.id));
const allPageSelected = computed(() => pageIds.value.length > 0 && pageIds.value.every((id) => selected.value.has(id)));

function toggle(id) {
    const s = new Set(selected.value);
    s.has(id) ? s.delete(id) : s.add(id);
    selected.value = s;
}
function togglePage() {
    const s = new Set(selected.value);
    if (allPageSelected.value) pageIds.value.forEach((id) => s.delete(id));
    else pageIds.value.forEach((id) => s.add(id));
    selected.value = s;
}
function clearSelection() {
    selected.value = new Set();
}

// ---------------- Importación por lotes ----------------
const running = ref(false);
const progress = reactive({ done: 0, total: 0, dryRun: false });
const results = ref([]);
const expanded = ref(new Set());

const actionLabel = {
    created: 'Creado',
    updated: 'Actualizado',
    unchanged: 'Sin cambios',
    skipped: 'Omitido',
    error: 'Error',
};
const actionClass = {
    created: 'bg-blue-100 text-blue-700',
    updated: 'bg-amber-100 text-amber-800',
    unchanged: 'bg-slate-100 text-slate-600',
    skipped: 'bg-slate-100 text-slate-500',
    error: 'bg-red-100 text-red-700',
};
const resultCounts = computed(() =>
    results.value.reduce((acc, r) => ({ ...acc, [r.action]: (acc[r.action] ?? 0) + 1 }), {}),
);

async function run(ids, dryRun) {
    if (!ids.length || running.value) return;
    if (!dryRun && !confirm(`Se importarán ${ids.length} usuario(s) al GLPI (lo ven los técnicos). ¿Continuar?`)) return;

    running.value = true;
    results.value = [];
    expanded.value = new Set();
    Object.assign(progress, { done: 0, total: ids.length, dryRun });

    try {
        for (let i = 0; i < ids.length; i += props.batchSize) {
            const batch = ids.slice(i, i + props.batchSize);
            try {
                const { data: res } = await window.axios.post('/admin/usuarios-entra/importar', {
                    ids: batch,
                    dry_run: dryRun,
                    update_existing: updateExisting.value,
                });
                results.value.push(...(res.results ?? []));
            } catch (e) {
                const message = e.response?.data?.message ?? 'Falló el lote.';
                results.value.push(...batch.map((id) => ({ id, name: id, email: '', action: 'error', changes: [], message })));
            }
            progress.done = Math.min(ids.length, i + batch.length);
        }
    } finally {
        running.value = false;
        if (!dryRun) {
            clearSelection();
            load(); // refresca los estados GLPI de la tabla
        }
    }
}

function toggleExpanded(id) {
    const s = new Set(expanded.value);
    s.has(id) ? s.delete(id) : s.add(id);
    expanded.value = s;
}
</script>

<template>
    <Head title="Usuarios de Entra" />

    <AppLayout>
        <div class="mb-6">
            <Link href="/inicio" class="text-sm text-slate-500 hover:underline">← Volver</Link>
            <h1 class="mt-2 text-xl font-semibold text-slate-900">Importar usuarios de Entra a GLPI</h1>
            <p class="text-sm text-slate-500">
                Busca en el directorio de Entra ID, filtra y crea o actualiza los usuarios en GLPI, de a uno o en lote.
                Siempre puedes <strong>simular</strong> primero para ver qué cambiaría.
            </p>
        </div>

        <div v-if="!graphConfigured" class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            Falta configurar Entra ID (Client ID, Secret y Tenant) en <Link href="/admin/acceso" class="underline">Acceso</Link>.
            La app necesita el permiso de aplicación <code>User.Read.All</code>.
        </div>
        <div v-if="!glpiWritable" class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            Faltan los tokens legacy (App-Token y User-Token) de GLPI en <Link href="/admin/conexion" class="underline">GLPI</Link>:
            la importación escribe por el API legacy.
        </div>

        <template v-if="ready">
            <!-- Filtros -->
            <div class="mb-4 rounded-xl border border-slate-200 bg-white p-5">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <div class="sm:col-span-2 lg:col-span-3">
                        <input v-model="filters.q" type="search" placeholder="Buscar por nombre, correo o cargo…" :class="inputClass" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">País</label>
                        <select v-model="filters.country" :class="inputClass">
                            <option value="">Todos</option>
                            <option v-for="o in data?.facets.countries ?? []" :key="o.value" :value="o.value">{{ o.value }} ({{ o.count }})</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Dominio del correo</label>
                        <select v-model="filters.domain" :class="inputClass">
                            <option value="">Todos</option>
                            <option v-for="o in data?.facets.domains ?? []" :key="o.value" :value="o.value">{{ o.value }} ({{ o.count }})</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Departamento</label>
                        <select v-model="filters.department" :class="inputClass">
                            <option value="">Todos</option>
                            <option v-for="o in data?.facets.departments ?? []" :key="o.value" :value="o.value">{{ o.value }} ({{ o.count }})</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Estado en GLPI</label>
                        <select v-model="filters.glpi_status" :class="inputClass">
                            <option value="">Todos</option>
                            <option value="new">No está en GLPI</option>
                            <option value="exists">Ya está en GLPI</option>
                            <option value="disabled">Deshabilitado en GLPI</option>
                        </select>
                    </div>
                    <div class="flex flex-col justify-end gap-1.5 text-sm text-slate-700 sm:col-span-2">
                        <label class="flex items-center gap-2"><input v-model="filters.only_enabled" type="checkbox" class="rounded" /> Solo cuentas habilitadas en Entra</label>
                        <label class="flex items-center gap-2"><input v-model="filters.only_named" type="checkbox" class="rounded" /> Solo con nombre y apellido <span class="text-xs text-slate-400">(descarta muchas genéricas)</span></label>
                        <label class="flex items-center gap-2"><input v-model="filters.only_mail" type="checkbox" class="rounded" /> Solo con buzón de correo</label>
                    </div>
                </div>
                <div class="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-3 text-xs text-slate-500">
                    <span v-if="data">
                        Directorio: {{ data.directory_total.toLocaleString('es-CL') }} usuarios · actualizado {{ data.fetched_at }}
                    </span>
                    <span v-else-if="loading">Descargando el directorio de Entra (puede tardar unos segundos)…</span>
                    <button
                        type="button"
                        :disabled="loading"
                        class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 font-medium text-slate-600 transition hover:bg-slate-50 disabled:opacity-60"
                        @click="load({ refresh: true })"
                    >
                        Actualizar directorio
                    </button>
                </div>
            </div>

            <div v-if="loadError" class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ loadError }}</div>

            <!-- Acciones -->
            <div v-if="data" class="mb-3 flex flex-wrap items-center gap-2">
                <span class="text-sm text-slate-700">
                    <strong>{{ data.total.toLocaleString('es-CL') }}</strong> coinciden
                    <span class="text-slate-400">
                        · {{ data.counts.new ?? 0 }} nuevos · {{ data.counts.exists ?? 0 }} en GLPI · {{ data.counts.disabled ?? 0 }} deshabilitados en GLPI
                    </span>
                </span>
                <span class="flex-1"></span>
                <label class="flex items-center gap-2 text-xs text-slate-600">
                    <input v-model="updateExisting" type="checkbox" class="rounded" /> Actualizar datos de los que ya existen
                </label>
            </div>
            <div v-if="data" class="mb-3 flex flex-wrap items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5">
                <span class="text-sm text-slate-600">{{ selected.size }} seleccionado(s)</span>
                <button v-if="selected.size" type="button" class="text-xs text-slate-500 hover:underline" @click="clearSelection">Limpiar</button>
                <span class="flex-1"></span>
                <button
                    type="button"
                    :disabled="running || !selected.size"
                    class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50 disabled:opacity-50"
                    @click="run([...selected], true)"
                >
                    Simular seleccionados
                </button>
                <button
                    type="button"
                    :disabled="running || !selected.size"
                    class="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-blue-700 disabled:opacity-50"
                    @click="run([...selected], false)"
                >
                    Importar seleccionados
                </button>
                <span class="mx-1 h-5 w-px bg-slate-300"></span>
                <button
                    type="button"
                    :disabled="running || !data.total"
                    class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50 disabled:opacity-50"
                    @click="run(data.ids, true)"
                >
                    Simular todos ({{ data.total }})
                </button>
                <button
                    type="button"
                    :disabled="running || !data.total"
                    class="rounded-lg bg-blue-900 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-blue-800 disabled:opacity-50"
                    @click="run(data.ids, false)"
                >
                    Importar todos ({{ data.total }})
                </button>
            </div>

            <!-- Progreso + resultados -->
            <div v-if="running || results.length" class="mb-4 rounded-xl border border-slate-200 bg-white p-5">
                <div class="mb-2 flex items-center justify-between text-sm">
                    <span class="font-semibold text-slate-700">{{ progress.dryRun ? 'Simulación' : 'Importación' }}</span>
                    <span class="text-slate-500">{{ progress.done }} / {{ progress.total }}</span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-slate-100">
                    <div class="h-full bg-blue-600 transition-all" :style="{ width: (progress.total ? (progress.done / progress.total) * 100 : 0) + '%' }"></div>
                </div>
                <p v-if="progress.dryRun && !running" class="mt-2 text-xs text-slate-500">Simulación: no se escribió nada en GLPI.</p>

                <div v-if="results.length" class="mt-3 flex flex-wrap gap-2">
                    <span v-for="(n, a) in resultCounts" :key="a" class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="actionClass[a]">
                        {{ actionLabel[a] }}{{ progress.dryRun && (a === 'created' || a === 'updated') ? ' (simulado)' : '' }}: {{ n }}
                    </span>
                </div>

                <ul v-if="results.length" class="mt-3 max-h-96 divide-y divide-slate-100 overflow-auto rounded-lg border border-slate-100 text-sm">
                    <li v-for="r in results" :key="r.id" class="px-3 py-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="actionClass[r.action]">{{ actionLabel[r.action] }}</span>
                            <span class="font-medium text-slate-800">{{ r.name }}</span>
                            <span class="text-xs text-slate-400">{{ r.email }}</span>
                            <span v-if="r.glpi_id" class="text-xs text-slate-400">· GLPI #{{ r.glpi_id }}</span>
                            <button v-if="r.changes.length" type="button" class="ml-auto text-xs text-blue-600 hover:underline" @click="toggleExpanded(r.id)">
                                {{ expanded.has(r.id) ? 'Ocultar' : `${r.changes.length} cambio(s)` }}
                            </button>
                        </div>
                        <p v-if="r.message" class="mt-0.5 text-xs text-slate-500">{{ r.message }}</p>
                        <table v-if="expanded.has(r.id)" class="mt-2 w-full text-xs">
                            <tr v-for="c in r.changes" :key="c.field">
                                <td class="w-48 py-0.5 pr-2 text-slate-500">{{ c.label }}</td>
                                <td class="py-0.5 text-slate-400 line-through">{{ c.from ?? '—' }}</td>
                                <td class="py-0.5 text-slate-800">→ {{ c.to }}</td>
                            </tr>
                        </table>
                    </li>
                </ul>
            </div>

            <!-- Tabla -->
            <div v-if="data && data.rows.length" class="overflow-x-auto rounded-xl border border-slate-200 bg-white" :class="{ 'opacity-60': loading }">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs text-slate-500">
                        <tr>
                            <th class="w-8 px-3 py-2"><input type="checkbox" class="rounded" :checked="allPageSelected" @change="togglePage" /></th>
                            <th class="px-3 py-2 font-medium">Usuario</th>
                            <th class="px-3 py-2 font-medium">Cargo / Departamento</th>
                            <th class="px-3 py-2 font-medium">País</th>
                            <th class="px-3 py-2 font-medium">GLPI</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="r in data.rows" :key="r.id" :class="selected.has(r.id) ? 'bg-blue-50' : ''">
                            <td class="px-3 py-2"><input type="checkbox" class="rounded" :checked="selected.has(r.id)" @change="toggle(r.id)" /></td>
                            <td class="px-3 py-2">
                                <div class="font-medium text-slate-800">
                                    {{ r.name }}
                                    <span v-if="!r.enabled" class="ml-1 rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-medium text-red-700">Deshabilitada en Entra</span>
                                </div>
                                <div class="text-xs text-slate-500 break-all">{{ r.email }}</div>
                                <div v-if="r.upn" class="text-[11px] text-slate-400 break-all">UPN: {{ r.upn }}</div>
                            </td>
                            <td class="px-3 py-2 text-xs text-slate-600">
                                <div>{{ r.job_title || '—' }}</div>
                                <div class="text-slate-400">{{ r.department || '—' }}</div>
                            </td>
                            <td class="px-3 py-2 text-xs text-slate-600">{{ r.country || '—' }}</td>
                            <td class="px-3 py-2">
                                <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass[r.glpi_status]">
                                    {{ statusLabel[r.glpi_status] }}
                                </span>
                                <div v-if="r.glpi_id" class="mt-0.5 text-[11px] text-slate-400">#{{ r.glpi_id }}</div>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-right">
                                <button type="button" :disabled="running" class="text-xs text-slate-500 hover:underline disabled:opacity-50" @click="run([r.id], true)">Simular</button>
                                <button type="button" :disabled="running" class="ml-3 text-xs font-medium text-blue-600 hover:underline disabled:opacity-50" @click="run([r.id], false)">
                                    {{ r.glpi_status === 'new' ? 'Importar' : 'Actualizar' }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else-if="data && !loading" class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-10 text-center text-sm text-slate-500">
                Ningún usuario coincide con los filtros.
            </div>

            <!-- Paginación -->
            <div v-if="data && data.last_page > 1" class="mt-3 flex items-center justify-center gap-3 text-sm">
                <button type="button" :disabled="page <= 1 || loading" class="rounded-lg border border-slate-300 bg-white px-3 py-1 disabled:opacity-40" @click="page--">‹ Anterior</button>
                <span class="text-slate-500">Página {{ data.page }} de {{ data.last_page }}</span>
                <button type="button" :disabled="page >= data.last_page || loading" class="rounded-lg border border-slate-300 bg-white px-3 py-1 disabled:opacity-40" @click="page++">Siguiente ›</button>
            </div>
        </template>
    </AppLayout>
</template>

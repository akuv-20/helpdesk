<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import CopyableField from '../../../Components/CopyableField.vue';

const props = defineProps({
    values: { type: Object, default: () => ({}) },
});

const form = useForm({
    base_url: props.values.base_url ?? '',
    driver: props.values.driver ?? '',
    oauth_client_id: props.values.oauth_client_id ?? '',
    oauth_client_secret: props.values.oauth_client_secret ?? '',
    oauth_username: props.values.oauth_username ?? '',
    oauth_password: props.values.oauth_password ?? '',
    oauth_scope: props.values.oauth_scope ?? 'api',
    legacy_app_token: props.values.legacy_app_token ?? '',
    legacy_user_token: props.values.legacy_user_token ?? '',
});

const testing = ref(false);
const testResult = ref(null);

const isOauth = computed(() => form.driver === 'oauth');
const isLegacy = computed(() => form.driver === 'legacy');

function save() {
    form.put('/admin/conexion', { preserveScroll: true });
}

async function testConnection() {
    testing.value = true;
    testResult.value = null;
    try {
        const { data } = await window.axios.post('/admin/conexion/probar', form.data());
        testResult.value = data;
    } catch (e) {
        testResult.value = { ok: false, message: e.response?.data?.message ?? 'No se pudo ejecutar la prueba.' };
    } finally {
        testing.value = false;
    }
}

const inputClass =
    'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-100 focus:outline-none';
</script>

<template>
    <Head title="Conexión con GLPI" />

    <AppLayout>
        <div class="mb-6">
            <Link href="/inicio" class="text-sm text-slate-500 hover:underline">← Volver</Link>
            <h1 class="mt-2 text-xl font-semibold text-slate-900">Conexión con GLPI</h1>
            <p class="text-sm text-slate-500">Configura cómo el portal se autentica contra la API de GLPI. Los secretos se guardan cifrados.</p>
        </div>

        <form class="space-y-6" @submit.prevent="save">
            <!-- General -->
            <div class="space-y-4 rounded-xl border border-slate-200 bg-white p-5">
                <CopyableField v-model="form.base_url" type="url" label="URL base de GLPI" placeholder="https://helpdesk.verfrut.cl" :error="form.errors.base_url" />
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Método de autenticación</label>
                    <select v-model="form.driver" :class="inputClass">
                        <option value="">— Sin conexión (modo demo) —</option>
                        <option value="oauth">OAuth2 (API v2, recomendado)</option>
                        <option value="legacy">Legacy (apirest.php)</option>
                    </select>
                </div>
            </div>

            <!-- OAuth -->
            <div v-if="isOauth" class="space-y-4 rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="text-sm font-semibold text-slate-700">OAuth2 · password grant (usuario de servicio)</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <CopyableField v-model="form.oauth_client_id" label="Client ID" />
                    <CopyableField v-model="form.oauth_client_secret" label="Client Secret" secret autocomplete="new-password" />
                    <CopyableField v-model="form.oauth_username" label="Usuario de servicio" />
                    <CopyableField v-model="form.oauth_password" label="Contraseña del usuario" secret autocomplete="new-password" />
                    <CopyableField v-model="form.oauth_scope" label="Scope" placeholder="api" />
                </div>
            </div>

            <!-- Legacy -->
            <div v-if="isLegacy" class="space-y-4 rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="text-sm font-semibold text-slate-700">Legacy · apirest.php</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <CopyableField v-model="form.legacy_app_token" label="App-Token" secret autocomplete="new-password" />
                    <CopyableField v-model="form.legacy_user_token" label="User-Token (cuenta de servicio)" secret autocomplete="new-password" />
                </div>
            </div>

            <!-- Resultado de prueba -->
            <div
                v-if="testResult"
                class="rounded-lg border px-4 py-3 text-sm"
                :class="testResult.ok ? 'border-green-200 bg-green-50 text-green-800' : 'border-red-200 bg-red-50 text-red-800'"
            >
                {{ testResult.ok ? '✓ ' : '✕ ' }}{{ testResult.message }}
            </div>

            <div class="flex items-center justify-between">
                <button
                    type="button"
                    :disabled="testing || !form.driver"
                    class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 disabled:opacity-50"
                    @click="testConnection"
                >
                    {{ testing ? 'Probando…' : 'Probar conexión' }}
                </button>
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-lg bg-blue-600 px-5 py-2 text-sm font-medium text-white transition hover:bg-blue-700 disabled:opacity-60"
                >
                    {{ form.processing ? 'Guardando…' : 'Guardar' }}
                </button>
            </div>
        </form>
    </AppLayout>
</template>

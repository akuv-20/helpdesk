<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import CopyableField from '../../../Components/CopyableField.vue';

const props = defineProps({
    values: { type: Object, default: () => ({}) },
});

const form = useForm({
    client_id: props.values.client_id ?? '',
    tenant_id: props.values.tenant_id ?? '',
    client_secret: props.values.client_secret ?? '',
    redirect_uri: props.values.redirect_uri ?? '',
});

const testing = ref(false);
const testResult = ref(null);

function save() {
    form.put('/admin/acceso', { preserveScroll: true });
}

async function testConnection() {
    testing.value = true;
    testResult.value = null;
    try {
        const { data } = await window.axios.post('/admin/acceso/probar', form.data());
        testResult.value = data;
    } catch (e) {
        testResult.value = { ok: false, message: e.response?.data?.message ?? 'No se pudo ejecutar la prueba.' };
    } finally {
        testing.value = false;
    }
}
</script>

<template>
    <Head title="Acceso · Entra ID" />

    <AppLayout>
        <div class="mb-6">
            <Link href="/inicio" class="text-sm text-slate-500 hover:underline">← Volver</Link>
            <h1 class="mt-2 text-xl font-semibold text-slate-900">Acceso con Entra ID</h1>
            <p class="text-sm text-slate-500">Credenciales del login de las personas (Puerta A). Se guardan cifradas; la BD manda sobre el .env.</p>
        </div>

        <form class="space-y-6" @submit.prevent="save">
            <div class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-2">
                <CopyableField v-model="form.client_id" label="Client ID (Application ID)" />
                <CopyableField v-model="form.tenant_id" label="Tenant ID (Directory ID)" placeholder="GUID del directorio o 'common'" />
                <div class="sm:col-span-2">
                    <CopyableField v-model="form.client_secret" label="Client Secret (Value)" secret autocomplete="new-password" />
                </div>
            </div>

            <!-- Redirect URI a registrar en Entra -->
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <CopyableField
                    v-model="form.redirect_uri"
                    type="url"
                    label="Redirect URI"
                    placeholder="https://ticket.test/auth/entra/callback"
                    hint="Esta URI debe estar registrada idéntica en Entra (plataforma Web)."
                    :error="form.errors.redirect_uri"
                />
            </div>

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
                    :disabled="testing"
                    class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 disabled:opacity-50"
                    @click="testConnection"
                >
                    {{ testing ? 'Probando…' : 'Probar tenant' }}
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

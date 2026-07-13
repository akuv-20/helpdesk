<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import CopyableField from '../../../Components/CopyableField.vue';

const props = defineProps({
    values: { type: Object, default: () => ({}) },
    redirectUri: { type: String, default: '' },
});

const form = useForm({
    client_id: props.values.client_id ?? '',
    client_secret: props.values.client_secret ?? '',
});

function save() {
    form.put('/admin/aprobaciones-oauth', { preserveScroll: true });
}
</script>

<template>
    <Head title="Aprobaciones · OAuth" />

    <AppLayout>
        <div class="mb-6">
            <Link href="/inicio" class="text-sm text-slate-500 hover:underline">← Volver</Link>
            <h1 class="mt-2 text-xl font-semibold text-slate-900">Cliente OAuth de aprobaciones</h1>
            <p class="text-sm text-slate-500">
                Cliente <strong>authorization_code</strong> de GLPI para que cada usuario apruebe/rechace sus
                validaciones actuando como él mismo. Es distinto del cliente de la cuenta de servicio (en
                <Link href="/admin/conexion" class="font-medium underline">Conexión</Link>). Se guarda cifrado; la BD manda sobre el .env.
            </p>
        </div>

        <!-- Redirect URI a registrar en GLPI -->
        <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-5">
            <CopyableField
                :model-value="redirectUri"
                label="Redirect URI para el cliente OAuth en GLPI"
                hint="Regístrala idéntica (sin barra final) en el cliente OAuth de GLPI."
                readonly
            />
        </div>

        <form class="space-y-4 rounded-xl border border-slate-200 bg-white p-5" @submit.prevent="save">
            <CopyableField v-model="form.client_id" label="Client ID" />
            <CopyableField v-model="form.client_secret" label="Client Secret" secret autocomplete="new-password" :error="form.errors.client_secret" />

            <div class="flex justify-end">
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

<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    values: { type: Object, default: () => ({}) },
});

const fields = [
    { key: 'navbar_logo', label: 'Logo de la barra de navegación', hint: 'PNG/SVG, se muestra a ~32px de alto.' },
    { key: 'login_logo', label: 'Logo del login', hint: 'Se muestra centrado en la pantalla de ingreso.' },
    { key: 'login_background', label: 'Fondo del login', hint: 'Imagen de fondo de la pantalla de ingreso.' },
    { key: 'favicon', label: 'Favicon (pestaña del navegador)', hint: 'PNG o ICO, idealmente 32×32 o 48×48.' },
];

const form = useForm({
    navbar_logo: null,
    login_logo: null,
    login_background: null,
    favicon: null,
    remove: [],
});

function onFile(key, e) {
    form[key] = e.target.files[0] ?? null;
    // Si eligen archivo nuevo, quitan la marca de "eliminar".
    form.remove = form.remove.filter((k) => k !== key);
}

function toggleRemove(key, checked) {
    if (checked) {
        form.remove = [...new Set([...form.remove, key])];
        form[key] = null;
    } else {
        form.remove = form.remove.filter((k) => k !== key);
    }
}

function save() {
    form.put('/admin/marca', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset('navbar_logo', 'login_logo', 'login_background', 'favicon', 'remove');
        },
    });
}
</script>

<template>
    <Head title="Marca" />

    <AppLayout>
        <div class="mb-6">
            <Link href="/inicio" class="text-sm text-slate-500 hover:underline">← Volver</Link>
            <h1 class="mt-2 text-xl font-semibold text-slate-900">Marca del portal</h1>
            <p class="text-sm text-slate-500">Logos, favicon y fondo del login. Las imágenes se guardan en el servidor.</p>
        </div>

        <form class="space-y-4" @submit.prevent="save">
            <div
                v-for="f in fields"
                :key="f.key"
                class="rounded-xl border border-slate-200 bg-white p-5"
            >
                <div class="flex items-start gap-4">
                    <!-- Vista previa actual -->
                    <div class="grid h-16 w-16 shrink-0 place-items-center overflow-hidden rounded-lg border border-slate-200 bg-slate-50">
                        <img v-if="values[f.key]" :src="values[f.key]" alt="" class="max-h-full max-w-full object-contain" />
                        <span v-else class="text-xs text-slate-400">—</span>
                    </div>

                    <div class="min-w-0 flex-1">
                        <label class="block text-sm font-medium text-slate-700">{{ f.label }}</label>
                        <p class="mb-2 text-xs text-slate-400">{{ f.hint }}</p>

                        <input
                            type="file"
                            accept="image/png,image/jpeg,image/svg+xml,image/x-icon,.ico"
                            class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-4 file:py-2 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200"
                            @change="onFile(f.key, $event)"
                        />
                        <p v-if="form.errors[f.key]" class="mt-1 text-xs text-red-600">{{ form.errors[f.key] }}</p>

                        <label v-if="values[f.key]" class="mt-2 flex items-center gap-2 text-xs text-slate-500">
                            <input type="checkbox" @change="toggleRemove(f.key, $event.target.checked)" />
                            Quitar (volver al predeterminado)
                        </label>
                    </div>
                </div>
            </div>

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

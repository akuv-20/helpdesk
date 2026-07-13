<script setup>
import { Head, usePage, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const flashError = computed(() => page.props.flash?.error);
const branding = computed(() => page.props.branding ?? {});
const appName = computed(() => page.props.appName ?? 'HelpDesk Unifrutti');

// Solo aparece si el backend habilitó el acceso de desarrollo.
const allowDevLogin = computed(() => page.props.allowDevLogin);

const bgStyle = computed(() =>
    branding.value.login_background
        ? {
              backgroundImage: `url(${branding.value.login_background})`,
              backgroundSize: 'cover',
              backgroundPosition: 'center',
          }
        : {},
);

function devLogin() {
    router.post('/auth/dev-login');
}
</script>

<template>
    <Head title="Ingresar" />

    <div class="grid min-h-screen place-items-center bg-gradient-to-br from-blue-700 to-blue-900 px-4" :style="bgStyle">
        <div class="w-full max-w-sm rounded-2xl bg-white p-8 shadow-lg">
            <div class="mb-8 text-center">
                <img
                    v-if="branding.login_logo"
                    :src="branding.login_logo"
                    alt="Logo"
                    class="mx-auto mb-4 max-h-20 w-auto object-contain"
                />
                <div
                    v-else
                    class="mx-auto mb-4 grid h-14 w-14 place-items-center rounded-2xl bg-blue-600 text-2xl text-white"
                >
                    ●
                </div>
                <h1 class="text-2xl font-semibold text-slate-900">{{ appName }}</h1>
                <p class="mt-1 text-sm text-slate-500">Ingresa para crear y seguir tus solicitudes.</p>
            </div>

            <div
                v-if="flashError"
                class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"
            >
                {{ flashError }}
            </div>

            <a
                href="/auth/entra/redirect"
                class="flex w-full items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-3 font-medium text-white shadow-sm transition hover:bg-blue-700"
            >
                Ingresar con tu cuenta corporativa
            </a>

            <button
                v-if="allowDevLogin"
                type="button"
                class="mt-3 w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm text-slate-600 transition hover:bg-slate-50"
                @click="devLogin"
            >
                Entrar sin Entra ID (temporal)
            </button>
            <p v-if="allowDevLogin" class="mt-2 text-center text-xs text-slate-400">
                Acceso de desarrollo mientras se configura Entra. Deshabilítalo con ALLOW_DEV_LOGIN=false.
            </p>
        </div>
    </div>
</template>

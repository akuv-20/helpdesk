<script setup>
import { Link, usePage, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth?.user);
const branding = computed(() => page.props.branding ?? {});
const appName = computed(() => page.props.appName ?? 'HelpDesk Unifrutti');

// Iniciales para el avatar (primeras letras de nombre y apellido).
const initials = computed(() => {
    const p = (user.value?.name ?? '').trim().split(/\s+/);
    return ((p[0]?.[0] ?? '') + (p[1]?.[0] ?? '')).toUpperCase() || '?';
});

// Banner de alerta: parpadea brevemente al aparecer y desaparece a los 4s.
const banner = ref(null); // { type, message, blink }
let hideTimer = null;
let blinkTimer = null;

function showBanner(type, message) {
    if (!message) return;
    banner.value = { type, message, blink: true };
    clearTimeout(blinkTimer);
    clearTimeout(hideTimer);
    blinkTimer = setTimeout(() => { if (banner.value) banner.value.blink = false; }, 900);
    hideTimer = setTimeout(() => { banner.value = null; }, 4000);
}

watch(() => page.props.flash?.success, (v) => showBanner('success', v), { immediate: true });
watch(() => page.props.flash?.error, (v) => showBanner('error', v), { immediate: true });

onBeforeUnmount(() => {
    clearTimeout(blinkTimer);
    clearTimeout(hideTimer);
});

function logout() {
    router.post('/logout');
}
</script>

<template>
    <div class="min-h-full">
        <header class="rounded-b-3xl bg-[linear-gradient(265deg,#2463AF_0%,#0B3456_100%)] shadow-lg">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-3">
                <div class="flex items-center gap-3">
                    <Link href="/inicio" class="flex items-center gap-2 font-semibold text-white">
                        <img v-if="branding.navbar_logo" :src="branding.navbar_logo" alt="Logo" class="h-9 w-auto max-w-[180px] object-contain" />
                        <template v-else>
                            <span class="grid h-8 w-8 place-items-center rounded-lg bg-white/15 text-white">●</span>
                            {{ appName }}
                        </template>
                    </Link>
                    <!-- Botón Home (casita + texto), a la derecha del logo -->
                    <Link href="/inicio" class="flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium text-white/90 transition hover:bg-white/10">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 10.5 12 3l9 7.5" /><path d="M5 9.5V21h14V9.5" /><path d="M9.5 21v-5h5v5" />
                        </svg>
                        Home
                    </Link>
                </div>

                <div v-if="user" class="flex items-center gap-3 text-sm">
                    <Link href="/aprobaciones" class="flex items-center gap-1.5 rounded-md px-3 py-1.5 text-white/90 transition hover:bg-white/10">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3.85 8.62a4 4 0 0 1 4.78-4.77 4 4 0 0 1 6.74 0 4 4 0 0 1 4.78 4.78 4 4 0 0 1 0 6.74 4 4 0 0 1-4.77 4.78 4 4 0 0 1-6.75 0 4 4 0 0 1-4.78-4.77 4 4 0 0 1 0-6.76Z" />
                            <path d="m9 12 2 2 4-4" />
                        </svg>
                        Aprobaciones
                    </Link>
                    <template v-if="user.isAdmin">
                        <Link href="/admin/marca" class="rounded-md px-3 py-1.5 text-white/90 transition hover:bg-white/10">
                            Marca
                        </Link>
                        <Link href="/admin/acceso" class="rounded-md px-3 py-1.5 text-white/90 transition hover:bg-white/10">
                            Acceso
                        </Link>
                        <Link href="/admin/explorador-entra" class="rounded-md px-3 py-1.5 text-white/90 transition hover:bg-white/10">
                            Explorar Entra
                        </Link>
                        <Link href="/admin/conexion" class="rounded-md px-3 py-1.5 text-white/90 transition hover:bg-white/10">
                            GLPI
                        </Link>
                        <Link href="/admin/aprobaciones-oauth" class="rounded-md px-3 py-1.5 text-white/90 transition hover:bg-white/10">
                            OAuth aprob.
                        </Link>
                    </template>
                    <div class="hidden items-center gap-2 border-l border-white/20 pl-3 sm:flex">
                        <div class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-white/20 text-xs font-semibold text-white">
                            {{ initials }}
                        </div>
                        <div class="leading-tight">
                            <p class="text-sm font-medium text-white">{{ user.name }}</p>
                            <p class="text-xs text-white/60">{{ user.email }}</p>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="rounded-md px-3 py-1.5 text-white/90 transition hover:bg-white/10"
                        @click="logout"
                    >
                        Salir
                    </button>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-7xl px-4 py-8">
            <Transition name="flash">
                <div
                    v-if="banner"
                    class="mb-6 rounded-lg border px-4 py-3 text-sm"
                    :class="[
                        banner.type === 'success'
                            ? 'border-green-200 bg-green-50 text-green-800'
                            : 'border-red-200 bg-red-50 text-red-800',
                        banner.blink ? 'flash-blink' : '',
                    ]"
                >
                    {{ banner.message }}
                </div>
            </Transition>

            <slot />
        </main>
    </div>
</template>

<style scoped>
/* Parpadeo suave al aparecer */
@keyframes flash-blink {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.4; }
}
.flash-blink {
    animation: flash-blink 0.45s ease-in-out 2;
}

/* Fade de entrada/salida del banner */
.flash-enter-active,
.flash-leave-active {
    transition: opacity 0.4s ease, transform 0.4s ease;
}
.flash-enter-from,
.flash-leave-to {
    opacity: 0;
    transform: translateY(-4px);
}
</style>

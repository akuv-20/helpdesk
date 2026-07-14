<script setup>
import { Link, usePage, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth?.user);
const branding = computed(() => page.props.branding ?? {});
const appName = computed(() => page.props.appName ?? 'HelpDesk Unifrutti');

// Aprobaciones pendientes → badge tipo notificación en el navbar.
const approvalsCount = computed(() => page.props.pendingApprovalsCount ?? 0);

// Iniciales para el avatar (primeras letras de nombre y apellido).
const initials = computed(() => {
    const p = (user.value?.name ?? '').trim().split(/\s+/);
    return ((p[0]?.[0] ?? '') + (p[1]?.[0] ?? '')).toUpperCase() || '?';
});

// Menú desplegable de administración (agrupa todos los mantenedores admin
// para no saturar la barra de navegación).
const adminOpen = ref(false);
const adminLinks = [
    { href: '/admin/marca', label: 'Marca' },
    { href: '/admin/acceso', label: 'Acceso' },
    { href: '/admin/explorador-entra', label: 'Explorar Entra' },
    { href: '/admin/categorias', label: 'Categorías' },
    { href: '/admin/conexion', label: 'GLPI' },
    { href: '/admin/aprobaciones-oauth', label: 'OAuth aprob.' },
];

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

// Recarga la página actual (vuelve a pedir los props al servidor; en el árbol
// de categorías eso refresca la caché de GLPI).
const reloading = ref(false);
function reloadPage() {
    reloading.value = true;
    router.reload({ onFinish: () => { reloading.value = false; } });
}

function logout() {
    router.post('/logout');
}
</script>

<template>
    <div class="min-h-full">
        <header class="rounded-b-3xl border-t-4 border-brand-red bg-[linear-gradient(265deg,#2463AF_0%,#0B3456_100%)] shadow-lg">
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
                    <button
                        type="button"
                        title="Recargar"
                        aria-label="Recargar"
                        class="grid h-9 w-9 place-items-center rounded-md text-white/90 transition hover:bg-white/10"
                        @click="reloadPage"
                    >
                        <svg class="h-4 w-4" :class="reloading ? 'animate-spin' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 12a9 9 0 0 1 15-6.7L21 8" /><path d="M21 3v5h-5" />
                            <path d="M21 12a9 9 0 0 1-15 6.7L3 16" /><path d="M3 21v-5h5" />
                        </svg>
                    </button>
                    <Link href="/aprobaciones" class="flex items-center gap-1.5 rounded-md px-3 py-1.5 text-white/90 transition hover:bg-white/10">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3.85 8.62a4 4 0 0 1 4.78-4.77 4 4 0 0 1 6.74 0 4 4 0 0 1 4.78 4.78 4 4 0 0 1 0 6.74 4 4 0 0 1-4.77 4.78 4 4 0 0 1-6.75 0 4 4 0 0 1-4.78-4.77 4 4 0 0 1 0-6.76Z" />
                            <path d="m9 12 2 2 4-4" />
                        </svg>
                        Aprobaciones
                        <span
                            v-if="approvalsCount > 0"
                            class="grid h-5 min-w-5 place-items-center rounded-full bg-brand-red px-1.5 text-xs font-semibold text-white"
                            :title="approvalsCount + ' aprobaciones pendientes'"
                        >{{ approvalsCount > 99 ? '99+' : approvalsCount }}</span>
                    </Link>
                    <div v-if="user.isAdmin" class="relative">
                        <button
                            type="button"
                            class="flex items-center gap-1.5 rounded-md px-3 py-1.5 text-white/90 transition hover:bg-white/10"
                            @click="adminOpen = !adminOpen"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2Z" />
                                <circle cx="12" cy="12" r="3" />
                            </svg>
                            Administración
                            <svg class="h-3.5 w-3.5 transition-transform" :class="adminOpen ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                        </button>

                        <!-- Backdrop para cerrar al hacer clic fuera -->
                        <div v-if="adminOpen" class="fixed inset-0 z-10" @click="adminOpen = false"></div>

                        <!-- Panel -->
                        <div v-if="adminOpen" class="absolute right-0 z-20 mt-2 w-52 overflow-hidden rounded-xl border border-slate-200 bg-white py-1 text-slate-700 shadow-xl">
                            <Link
                                v-for="l in adminLinks"
                                :key="l.href"
                                :href="l.href"
                                class="block px-4 py-2 text-sm transition hover:bg-slate-50 hover:text-blue-600"
                                @click="adminOpen = false"
                            >
                                {{ l.label }}
                            </Link>
                        </div>
                    </div>
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

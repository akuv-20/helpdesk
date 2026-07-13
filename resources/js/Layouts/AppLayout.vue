<script setup>
import { Link, usePage, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth?.user);
const branding = computed(() => page.props.branding ?? {});

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
        <header class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-3">
                <Link href="/inicio" class="flex items-center gap-2 font-semibold text-slate-900">
                    <img v-if="branding.navbar_logo" :src="branding.navbar_logo" alt="Logo" class="h-8 w-auto max-w-[180px] object-contain" />
                    <template v-else>
                        <span class="grid h-8 w-8 place-items-center rounded-lg bg-blue-600 text-white">●</span>
                        Mesa de Ayuda
                    </template>
                </Link>

                <div v-if="user" class="flex items-center gap-3 text-sm">
                    <Link href="/aprobaciones" class="rounded-md px-3 py-1.5 text-slate-600 transition hover:bg-slate-100">
                        Aprobaciones
                    </Link>
                    <template v-if="user.isAdmin">
                        <Link href="/admin/marca" class="rounded-md px-3 py-1.5 text-slate-600 transition hover:bg-slate-100">
                            Marca
                        </Link>
                        <Link href="/admin/acceso" class="rounded-md px-3 py-1.5 text-slate-600 transition hover:bg-slate-100">
                            Acceso
                        </Link>
                        <Link href="/admin/explorador-entra" class="rounded-md px-3 py-1.5 text-slate-600 transition hover:bg-slate-100">
                            Explorar Entra
                        </Link>
                        <Link href="/admin/conexion" class="rounded-md px-3 py-1.5 text-slate-600 transition hover:bg-slate-100">
                            GLPI
                        </Link>
                        <Link href="/admin/aprobaciones-oauth" class="rounded-md px-3 py-1.5 text-slate-600 transition hover:bg-slate-100">
                            OAuth aprob.
                        </Link>
                    </template>
                    <span class="hidden text-slate-500 sm:inline">{{ user.name }}</span>
                    <button
                        type="button"
                        class="rounded-md px-3 py-1.5 text-slate-600 transition hover:bg-slate-100"
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

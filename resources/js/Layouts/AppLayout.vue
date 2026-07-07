<script setup>
import { Link, usePage, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth?.user);
const flashSuccess = computed(() => page.props.flash?.success);
const flashError = computed(() => page.props.flash?.error);

function logout() {
    router.post('/logout');
}
</script>

<template>
    <div class="min-h-full">
        <header class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-4xl items-center justify-between px-4 py-3">
                <Link href="/inicio" class="flex items-center gap-2 font-semibold text-slate-900">
                    <span class="grid h-8 w-8 place-items-center rounded-lg bg-blue-600 text-white">●</span>
                    Mesa de Ayuda
                </Link>

                <div v-if="user" class="flex items-center gap-3 text-sm">
                    <template v-if="user.isAdmin">
                        <Link href="/admin/formularios" class="rounded-md px-3 py-1.5 text-slate-600 transition hover:bg-slate-100">
                            Formularios
                        </Link>
                        <Link href="/admin/conexion" class="rounded-md px-3 py-1.5 text-slate-600 transition hover:bg-slate-100">
                            Conexión
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

        <main class="mx-auto max-w-4xl px-4 py-8">
            <div
                v-if="flashSuccess"
                class="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"
            >
                {{ flashSuccess }}
            </div>
            <div
                v-if="flashError"
                class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"
            >
                {{ flashError }}
            </div>

            <slot />
        </main>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    label: { type: String, default: '' },
    secret: { type: Boolean, default: false }, // muestra ojo para revelar/ocultar
    type: { type: String, default: 'text' },
    placeholder: { type: String, default: '' },
    hint: { type: String, default: '' },
    error: { type: String, default: '' },
    autocomplete: { type: String, default: 'off' },
    readonly: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const revealed = ref(false);
const copied = ref(false);

const inputType = computed(() => (props.secret && !revealed.value ? 'password' : props.type));

async function copy() {
    try {
        await navigator.clipboard.writeText(props.modelValue ?? '');
        copied.value = true;
        setTimeout(() => (copied.value = false), 1400);
    } catch (_) {
        // sin portapapeles: ignorar
    }
}
</script>

<template>
    <div>
        <label v-if="label" class="mb-1 block text-sm font-medium text-slate-700">{{ label }}</label>
        <div class="flex items-center gap-1.5">
            <input
                :type="inputType"
                :value="modelValue"
                :placeholder="placeholder"
                :autocomplete="autocomplete"
                :readonly="readonly"
                class="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-100 focus:outline-none"
                :class="readonly ? 'bg-slate-50 text-slate-600' : ''"
                @input="emit('update:modelValue', $event.target.value)"
            />

            <!-- Ojo: revelar / ocultar (solo secretos) -->
            <button
                v-if="secret"
                type="button"
                :title="revealed ? 'Ocultar' : 'Mostrar'"
                class="shrink-0 rounded-lg border border-slate-300 p-2 text-slate-500 transition hover:bg-slate-50"
                @click="revealed = !revealed"
            >
                <svg v-if="!revealed" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z" /><circle cx="12" cy="12" r="3" />
                </svg>
                <svg v-else class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 3l18 18M10.6 10.6a3 3 0 0 0 4.2 4.2M9.9 5.2A9.5 9.5 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.2 4M6.6 6.6A17 17 0 0 0 2 12s3.5 7 10 7a9.5 9.5 0 0 0 3.1-.5" />
                </svg>
            </button>

            <!-- Copiar al portapapeles -->
            <button
                type="button"
                :title="copied ? 'Copiado' : 'Copiar'"
                class="shrink-0 rounded-lg border border-slate-300 p-2 transition hover:bg-slate-50"
                :class="copied ? 'text-green-600' : 'text-slate-500'"
                @click="copy"
            >
                <svg v-if="copied" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M20 6 9 17l-5-5" />
                </svg>
                <svg v-else class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="9" y="9" width="11" height="11" rx="2" /><path d="M5 15V5a2 2 0 0 1 2-2h10" />
                </svg>
            </button>
        </div>
        <p v-if="hint" class="mt-1 text-xs text-slate-500">{{ hint }}</p>
        <p v-if="error" class="mt-1 text-xs text-red-600">{{ error }}</p>
    </div>
</template>

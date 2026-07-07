<script setup>
import { computed } from 'vue';

const props = defineProps({
    fields: { type: Array, default: () => [] },
    modelValue: { type: Object, default: () => ({}) },
    errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['update:modelValue']);

// Motor de visibilidad: misma regla showIf que el backend.
function isVisible(field) {
    const rule = field.showIf;
    if (!rule) return true;
    const current = props.modelValue[rule.field];
    if ('equals' in rule) return current === rule.equals;
    if ('in' in rule) return (rule.in ?? []).includes(current);
    return true;
}

const visibleFields = computed(() => props.fields.filter(isVisible));

function update(key, value) {
    emit('update:modelValue', { ...props.modelValue, [key]: value });
}

function errorFor(key) {
    return props.errors[`answers.${key}`];
}

const inputClass =
    'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-100 focus:outline-none';
</script>

<template>
    <div class="space-y-5">
        <div v-for="field in visibleFields" :key="field.key">
            <label class="mb-1 block text-sm font-medium text-slate-700">
                {{ field.label }}
                <span v-if="field.required" class="text-red-500">*</span>
            </label>

            <select
                v-if="field.input === 'select'"
                :value="modelValue[field.key] ?? ''"
                :class="inputClass"
                @change="update(field.key, $event.target.value)"
            >
                <option value="" disabled>Selecciona una opción…</option>
                <option v-for="opt in field.options" :key="opt.value" :value="opt.value">
                    {{ opt.label }}
                </option>
            </select>

            <textarea
                v-else-if="field.input === 'textarea'"
                :value="modelValue[field.key] ?? ''"
                rows="5"
                :placeholder="field.placeholder"
                :class="inputClass"
                @input="update(field.key, $event.target.value)"
            ></textarea>

            <input
                v-else
                :type="field.input === 'number' ? 'number' : field.input === 'date' ? 'date' : 'text'"
                :value="modelValue[field.key] ?? ''"
                :placeholder="field.placeholder"
                :class="inputClass"
                @input="update(field.key, $event.target.value)"
            />

            <p v-if="errorFor(field.key)" class="mt-1 text-xs text-red-600">{{ errorFor(field.key) }}</p>
        </div>
    </div>
</template>

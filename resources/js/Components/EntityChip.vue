<script setup>
import { computed } from 'vue';

/**
 * Chip estilo GLPI: pastilla clara con un ícono coloreado según el tipo de
 * entidad (usuario, grupo, categoría, tipo de ticket) + el texto. Replica el
 * formato de los "actores" del formulario de ticket de GLPI 11.
 */
const props = defineProps({
    label: { type: String, default: '' },
    // user | technician | group | category | incident | request
    variant: { type: String, default: 'user' },
});

// Ícono (paths estilo Tabler, trazo) por variante.
const ICONS = {
    user: '<circle cx="12" cy="7" r="4"/><path d="M6 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"/>',
    technician: '<circle cx="12" cy="7" r="4"/><path d="M6 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"/>',
    group: '<circle cx="9" cy="7" r="4"/><path d="M3 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/><path d="M21 21v-2a4 4 0 0 0-3-3.85"/>',
    category: '<path d="M7.5 7.5h.01"/><path d="M3 6v5.172a2 2 0 0 0 .586 1.414l7.71 7.71a2.41 2.41 0 0 0 3.408 0l4.592-4.592a2.41 2.41 0 0 0 0-3.408l-7.71-7.71A2 2 0 0 0 11.172 3H6a3 3 0 0 0-3 3z"/>',
    incident: '<path d="M12 9v4"/><path d="M10.363 3.591 2.257 17.125a2 2 0 0 0 1.72 3.006h16.046a2 2 0 0 0 1.72-3.006L13.637 3.591a2 2 0 0 0-3.274 0z"/><path d="M12 16h.01"/>',
    request: '<circle cx="12" cy="12" r="9"/><path d="M9 12h6"/><path d="M12 9v6"/>',
};

// Color del ícono por variante (paleta tipo GLPI: cada itemtype su tono).
const COLORS = {
    user: 'text-sky-600',
    technician: 'text-teal-600',
    group: 'text-violet-600',
    category: 'text-amber-600',
    incident: 'text-orange-600',
    request: 'text-blue-600',
};

const iconPath = computed(() => ICONS[props.variant] ?? ICONS.user);
const iconColor = computed(() => COLORS[props.variant] ?? COLORS.user);
</script>

<template>
    <span
        class="inline-flex max-w-full items-center gap-1.5 rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-medium text-slate-700"
    >
        <svg
            class="h-3.5 w-3.5 shrink-0"
            :class="iconColor"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            v-html="iconPath"
        />
        <span class="truncate">{{ label }}</span>
    </span>
</template>

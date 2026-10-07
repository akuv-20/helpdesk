// Color estable por persona para los avatares del timeline: deriva un tono del
// nombre, así cada persona mantiene siempre el mismo color (como en GLPI) y se
// distinguen de un vistazo cuando hay varios actores en el hilo.
//
// Los strings de clases van completos a propósito para que Tailwind los detecte.

const PALETTE = [
    'bg-rose-100 text-rose-700',
    'bg-orange-100 text-orange-700',
    'bg-amber-100 text-amber-700',
    'bg-emerald-100 text-emerald-700',
    'bg-teal-100 text-teal-700',
    'bg-sky-100 text-sky-700',
    'bg-blue-100 text-blue-700',
    'bg-indigo-100 text-indigo-700',
    'bg-violet-100 text-violet-700',
    'bg-fuchsia-100 text-fuchsia-700',
];

const NEUTRAL = 'bg-slate-200 text-slate-600';

/**
 * Devuelve las clases Tailwind (fondo + texto) para el avatar de una persona.
 * @param {string|null|undefined} name
 * @returns {string}
 */
export function avatarColor(name) {
    const key = (name ?? '').trim().toLowerCase();
    if (!key) return NEUTRAL;

    let hash = 0;
    for (let i = 0; i < key.length; i++) {
        hash = (hash * 31 + key.charCodeAt(i)) >>> 0;
    }

    return PALETTE[hash % PALETTE.length];
}

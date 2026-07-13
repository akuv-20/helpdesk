<script setup>
import { onBeforeUnmount, ref } from 'vue';

const props = defineProps({
    placeholder: { type: String, default: 'Escribe aquí…' },
    rows: { type: Number, default: 4 },
});

// Emite { content, images } cada vez que cambia. content trae marcadores
// #INLINE_N# donde van las imágenes; images es el arreglo de File en ese orden.
const emit = defineEmits(['change']);

const editor = ref(null);
let images = []; // { uid, file, url }
let counter = 0;

function emitChange() {
    if (!editor.value) return;
    const clone = editor.value.cloneNode(true);
    const imgs = [...clone.querySelectorAll('img[data-uid]')];
    const files = [];

    imgs.forEach((img, i) => {
        const uid = img.getAttribute('data-uid');
        const rec = images.find((r) => r.uid === uid);
        if (rec) {
            files.push(rec.file);
            img.setAttribute('src', `#INLINE_${i}#`);
        }
        img.removeAttribute('data-uid');
        img.removeAttribute('style');
    });

    emit('change', { content: clone.innerHTML, images: files });
}

function insertHtml(html) {
    editor.value.focus();
    document.execCommand('insertHTML', false, html);
}

function onPaste(e) {
    const items = e.clipboardData?.items ?? [];
    for (const it of items) {
        if (it.type.startsWith('image/')) {
            e.preventDefault();
            const file = it.getAsFile();
            if (!file) continue;
            const uid = 'u' + counter++;
            const url = URL.createObjectURL(file);
            images.push({ uid, file, url });
            insertHtml(`<img data-uid="${uid}" src="${url}" style="max-width:100%;border-radius:8px;margin:4px 0;" /><br>`);
            emitChange();
            return;
        }
    }
    // Texto: pegamos en plano para no arrastrar formato ajeno.
    e.preventDefault();
    insertHtml(document.createTextNode(e.clipboardData.getData('text/plain')).textContent.replace(/[<>&]/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c])));
}

function reset() {
    if (editor.value) editor.value.innerHTML = '';
    images.forEach((r) => URL.revokeObjectURL(r.url));
    images = [];
    emitChange();
}

onBeforeUnmount(() => images.forEach((r) => URL.revokeObjectURL(r.url)));

defineExpose({ reset });
</script>

<template>
    <div
        ref="editor"
        contenteditable="true"
        :data-placeholder="placeholder"
        :style="{ minHeight: rows * 1.5 + 'rem' }"
        class="rich-input w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700 focus:border-blue-500 focus:ring-2 focus:ring-blue-100 focus:outline-none"
        @input="emitChange"
        @paste="onPaste"
    ></div>
</template>

<style scoped>
.rich-input:empty::before {
    content: attr(data-placeholder);
    color: #94a3b8;
}
.rich-input {
    overflow-y: auto;
    max-height: 24rem;
}
.rich-input :deep(img) {
    max-width: 100%;
    border-radius: 8px;
}
</style>

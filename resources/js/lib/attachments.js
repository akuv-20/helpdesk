// Config compartida de adjuntos (creación y seguimientos): tipos aceptados,
// límite de tamaño y validación por archivo para el feedback visual (ticks).
// Mantener alineado con la validación del backend (TicketController).

export const MAX_FILE_MB = 10;
export const MAX_FILE_BYTES = MAX_FILE_MB * 1024 * 1024;

// Extensiones aceptadas (incluye correos .eml y .msg).
export const ACCEPTED_EXTS = [
    'jpg', 'jpeg', 'png', 'gif', 'pdf',
    'doc', 'docx', 'xls', 'xlsx',
    'txt', 'csv', 'zip', 'eml', 'msg',
];

// Valor para el atributo accept del <input type="file">.
export const ACCEPT_ATTR = ACCEPTED_EXTS.map((e) => '.' + e).join(',');

// Texto amigable para mostrar qué se acepta.
export const ACCEPTED_LABEL = 'Imágenes, PDF, Word, Excel, TXT, CSV, ZIP y correos (.eml, .msg)';

/** Extensión en minúsculas de un nombre de archivo (sin el punto). */
export function fileExt(name) {
    const m = /\.([^.]+)$/.exec(name ?? '');
    return m ? m[1].toLowerCase() : '';
}

/** Tamaño legible (B / KB / MB). */
export function fileSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(0) + ' KB';
    return (bytes / 1024 / 1024).toFixed(1) + ' MB';
}

/**
 * Valida un File: formato aceptado y tamaño dentro del límite.
 * @returns {{ok: boolean, reason: string}}
 */
export function validateFile(file) {
    const ext = fileExt(file?.name);
    if (!ACCEPTED_EXTS.includes(ext)) {
        return { ok: false, reason: 'Formato no permitido' };
    }
    if ((file?.size ?? 0) > MAX_FILE_BYTES) {
        return { ok: false, reason: `Supera ${MAX_FILE_MB} MB` };
    }
    return { ok: true, reason: '' };
}

// Comprime imágenes en el navegador ANTES de subirlas: una foto de teléfono de
// varios MB queda en cientos de KB. Así evitamos el 413 del servidor y no
// sobrecargamos GLPI con imágenes enormes.
//
// Solo toca imágenes rasterizadas (jpg/png/webp). Deja intactos los gif (pueden
// ser animados), pdf y documentos. Si el resultado no es más chico que el
// original, o el navegador no soporta la API, devuelve el archivo original.

const MAX_DIMENSION = 2000; // px del lado mayor
const QUALITY = 0.82; // calidad JPEG (0–1)
const COMPRESSIBLE = ['image/jpeg', 'image/png', 'image/webp'];

/**
 * Comprime un File de imagen. Devuelve un File nuevo (JPEG) o el original.
 * @param {File} file
 * @returns {Promise<File>}
 */
export async function compressImage(file) {
    if (!file || !COMPRESSIBLE.includes(file.type)) return file;
    if (typeof createImageBitmap !== 'function') return file;

    let bitmap;
    try {
        // imageOrientation corrige la rotación EXIF de las fotos de teléfono.
        bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
    } catch {
        return file; // navegador viejo o imagen corrupta: subimos el original
    }

    const scale = Math.min(1, MAX_DIMENSION / Math.max(bitmap.width, bitmap.height));
    const w = Math.max(1, Math.round(bitmap.width * scale));
    const h = Math.max(1, Math.round(bitmap.height * scale));

    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');
    // Fondo blanco por si el PNG trae transparencia (el JPEG no la soporta).
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, w, h);
    ctx.drawImage(bitmap, 0, 0, w, h);
    bitmap.close?.();

    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', QUALITY));
    // Si no mejoró (imagen ya muy liviana), dejamos el original.
    if (!blob || blob.size >= file.size) return file;

    const name = file.name.replace(/\.[^.]+$/, '') + '.jpg';
    return new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() });
}

/**
 * Comprime una lista de archivos (imágenes se comprimen, el resto pasa igual).
 * @param {FileList|File[]} files
 * @returns {Promise<File[]>}
 */
export async function compressImages(files) {
    return Promise.all(Array.from(files).map((f) => compressImage(f)));
}

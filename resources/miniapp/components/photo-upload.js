import { h } from '../core/dom.js';

const MAX_SIDE = 1600;
const QUALITY = 0.8;

/** Kompres gambar: sisi terpanjang maks 1600 px, JPEG 0,8. Mengembalikan File. */
export async function compressImage(file) {
  if (!file.type.startsWith('image/')) throw new Error('Berkas harus berupa gambar.');
  let bmp;
  try {
    bmp = await createImageBitmap(file, { imageOrientation: 'from-image' });
  } catch {
    bmp = await new Promise((resolve, reject) => {
      const img = new Image();
      img.onload = () => resolve(img);
      img.onerror = () => reject(new Error('Gambar tidak dapat dibaca.'));
      img.src = URL.createObjectURL(file);
    });
  }
  const w0 = bmp.width, h0 = bmp.height;
  const scale = Math.min(1, MAX_SIDE / Math.max(w0, h0));
  const canvas = document.createElement('canvas');
  canvas.width = Math.round(w0 * scale);
  canvas.height = Math.round(h0 * scale);
  const ctx = canvas.getContext('2d');
  ctx.fillStyle = '#fff';
  ctx.fillRect(0, 0, canvas.width, canvas.height);
  ctx.drawImage(bmp, 0, 0, canvas.width, canvas.height);
  bmp.close?.();
  const blob = await new Promise((res) => canvas.toBlob(res, 'image/jpeg', QUALITY));
  if (!blob) throw new Error('Gagal memampatkan gambar.');
  return new File([blob], `foto-${Date.now()}.jpg`, { type: 'image/jpeg' });
}

/**
 * createPhotoUpload({name, existingUrl, onChange}) -> {el, getFile, setError}
 * Kamera belakang lewat capture=environment; hasil dipratinjau.
 */
export function createPhotoUpload({ name, existingUrl = null, onChange = () => {} }) {
  let file = null;
  const input = h('input', { type: 'file', accept: 'image/*', capture: 'environment', id: `f-${name}`, class: 'sr-only' });
  const img = h('img', { class: 'photo-preview', alt: 'Pratinjau foto', hidden: !existingUrl });
  if (existingUrl) img.src = existingUrl;
  const status = h('p', { class: 'muted small' });
  const btn = h('label', { class: 'btn', for: `f-${name}` }, existingUrl ? 'Ganti foto' : 'Ambil foto');
  const el = h('div', { class: 'photo-upload' }, img, h('div', { class: 'photo-actions' }, btn, input), status);

  input.addEventListener('change', async () => {
    const f = input.files?.[0];
    if (!f) return;
    status.textContent = 'Memampatkan...';
    try {
      file = await compressImage(f);
      img.src = URL.createObjectURL(file);
      img.hidden = false;
      btn.textContent = 'Ganti foto';
      status.textContent = `${Math.round(file.size / 1024)} KB`;
      onChange(file);
    } catch (e) {
      status.textContent = e.message;
    }
    input.value = '';
  });

  return {
    el,
    getFile: () => file,
    hasValue: () => !!file || !!existingUrl,
    setError: (msg) => { status.textContent = msg || ''; status.classList.toggle('field-error', !!msg); },
  };
}

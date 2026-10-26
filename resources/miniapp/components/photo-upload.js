// Komponen unggah foto aman: kamera/galeri, kompres canvas (maks 1600 px, JPEG 0,8), pratinjau, hapus,
// batas jumlah, dan tampilan gambar privat lewat fetch + Authorization (blob -> objectURL).
// Bagian murni (tanpa DOM) diekspor agar dapat diuji dengan node --test.
import { h } from '../core/dom.js';
import { getToken, newKey } from '../core/api.js';
import { terapkanHeaderSppg, getSppgAktif } from '../core/sppg-aktif.js';

export const MAX_SIDE = 1600;
export const QUALITY = 0.8;
export const MAX_BYTES = 5 * 1024 * 1024; // batas server (kontrak POST /lampiran)
export const MAX_RAW_BYTES = 30 * 1024 * 1024; // batas berkas mentah dari kamera sebelum dikompres

/** Urutan percobaan kompres: bila hasil > 5 MB, turunkan kualitas lalu ukuran. */
export const ATTEMPTS = [
  { maxSide: MAX_SIDE, quality: QUALITY },
  { maxSide: MAX_SIDE, quality: 0.65 },
  { maxSide: 1280, quality: 0.6 },
  { maxSide: 1024, quality: 0.5 },
];

/** Ukuran target canvas: sisi terpanjang <= maxSide, rasio tetap, tidak pernah diperbesar. */
export function fitSize(w, hgt, maxSide = MAX_SIDE) {
  if (!(w > 0) || !(hgt > 0)) throw new Error('Ukuran gambar tidak valid.');
  const scale = Math.min(1, maxSide / Math.max(w, hgt));
  return { width: Math.max(1, Math.round(w * scale)), height: Math.max(1, Math.round(hgt * scale)), scale };
}

/** Pemeriksaan awal berkas pilihan pengguna -> '' bila lolos. */
export function validasiBerkasAwal(file) {
  if (!file) return 'Tidak ada berkas dipilih.';
  if (file.type && !file.type.startsWith('image/')) return 'Berkas harus berupa gambar (JPEG, PNG, atau WebP).';
  if (!(file.size > 0)) return 'Berkas kosong (0 byte).';
  if (file.size > MAX_RAW_BYTES) return 'Berkas terlalu besar untuk diproses (lebih dari 30 MB).';
  return '';
}

/** Pemeriksaan hasil kompres terhadap batas server. */
export const validasiHasil = (size) => (size > MAX_BYTES ? 'Foto masih lebih dari 5 MB setelah dikompres. Ambil ulang dengan resolusi lebih kecil.' : '');

export function formatUkuran(bytes) {
  const b = Number(bytes) || 0;
  if (b >= 1024 * 1024) return `${(b / 1024 / 1024).toFixed(1).replace('.', ',')} MB`;
  return `${Math.max(1, Math.round(b / 1024))} KB`;
}

/**
 * Jalur API lampiran yang aman dibuka dengan token. Hanya path relatif /api/v1/lampiran/{id};
 * URL absolut/eksternal ditolak (null) agar token tidak bocor ke domain lain.
 * Menerima string url atau objek BerkasRingkas {lampiran_id,url} / Lampiran {id,url}.
 */
export function lampiranPath(berkas) {
  if (!berkas) return null;
  const url = typeof berkas === 'string' ? berkas : berkas.url ?? (berkas.lampiran_id ?? berkas.id ? `/api/v1/lampiran/${berkas.lampiran_id ?? berkas.id}` : null);
  return typeof url === 'string' && /^\/api\/v1\/lampiran\/\d+$/.test(url) ? url : null;
}

// ---- Kompres canvas ----
async function decode(file) {
  try {
    return await createImageBitmap(file, { imageOrientation: 'from-image' });
  } catch {
    const src = URL.createObjectURL(file);
    try {
      return await new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => resolve(img);
        img.onerror = () => reject(new Error('Gambar tidak dapat dibaca. Gunakan foto JPEG, PNG, atau WebP.'));
        img.src = src;
      });
    } finally { setTimeout(() => URL.revokeObjectURL(src), 10000); }
  }
}

/** Kompres: kembalikan {file, width, height, original}. EXIF terbuang karena digambar ulang di canvas. */
export async function compressImageDetail(file) {
  const bad = validasiBerkasAwal(file);
  if (bad) throw new Error(bad);
  const bmp = await decode(file);
  const w0 = bmp.naturalWidth || bmp.width;
  const h0 = bmp.naturalHeight || bmp.height;
  try {
    let last = null;
    for (const a of ATTEMPTS) {
      const { width, height } = fitSize(w0, h0, a.maxSide);
      const canvas = document.createElement('canvas');
      canvas.width = width;
      canvas.height = height;
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, width, height);
      ctx.drawImage(bmp, 0, 0, width, height);
      const blob = await new Promise((res) => canvas.toBlob(res, 'image/jpeg', a.quality));
      if (!blob) throw new Error('Gagal memampatkan gambar.');
      last = { blob, width, height };
      if (blob.size <= MAX_BYTES) break;
      canvas.width = canvas.height = 0; // lepaskan memori sebelum percobaan berikutnya
    }
    const msg = validasiHasil(last.blob.size);
    if (msg) throw new Error(msg);
    return { file: new File([last.blob], `foto-${Date.now()}.jpg`, { type: 'image/jpeg' }), width: last.width, height: last.height, original: file.size };
  } finally { bmp.close?.(); }
}

/** Kompres gambar: sisi terpanjang maks 1600 px, JPEG 0,8. Mengembalikan File (kompatibel dengan versi lama). */
export async function compressImage(file) {
  return (await compressImageDetail(file)).file;
}

// ---- URL objek: dilepas saat berpindah layar agar memori tidak bocor ----
const tracked = new Set();
let hookInstalled = false;
function trackUrl(url) {
  tracked.add(url);
  if (!hookInstalled && typeof window !== 'undefined') {
    hookInstalled = true;
    window.addEventListener('hashchange', () => { tracked.forEach((u) => URL.revokeObjectURL(u)); tracked.clear(); });
  }
  return url;
}
const release = (url) => { if (url && tracked.delete(url)) URL.revokeObjectURL(url); };

/** Ambil gambar privat: fetch + Authorization -> Blob. Galat memuat pesan Indonesia. */
export async function fetchLampiranBlob(path, { fetchFn = (...a) => fetch(...a), token = getToken(), sppgId = getSppgAktif() } = {}) {
  if (!lampiranPath(path)) throw new Error('Alamat berkas tidak valid.');
  let res;
  try {
    res = await fetchFn(path, { headers: terapkanHeaderSppg({ Accept: 'image/*', ...(token ? { Authorization: `Bearer ${token}` } : {}) }, sppgId) });
  } catch { throw new Error('Tidak ada koneksi internet.'); }
  if (!res.ok) {
    // 403 lampiran_disembunyikan (Investor) bukan galat SPPG aktif: pilihan SPPG tidak dihapus.
    if (res.status === 403) {
      let alasan = null;
      try { alasan = (await res.json())?.alasan; } catch { /* bukan JSON */ }
      if (alasan === 'lampiran_disembunyikan') throw new Error('Foto tidak ditampilkan untuk peran Anda.');
    }
    throw new Error(res.status === 404 ? 'Berkas tidak ditemukan.' : res.status === 403 ? 'Anda tidak punya hak akses ke berkas ini.' : `Berkas gagal dimuat (${res.status}).`);
  }
  const blob = await res.blob();
  if (blob.type && !blob.type.startsWith('image/')) throw new Error('Berkas bukan gambar.');
  return blob;
}

/** Gambar privat sebagai elemen: <figure> berisi status memuat -> <img>. Tautan berkas = berkas atau url. */
export function createAuthImage(berkas, { alt = 'Foto', caption = null } = {}) {
  const path = lampiranPath(berkas);
  const fig = h('figure', { class: 'photo-figure' });
  const state = h('p', { class: 'muted small', role: 'status' }, 'Memuat foto...');
  fig.append(state);
  if (caption) fig.append(h('figcaption', { class: 'muted small' }, caption));
  async function load() {
    state.textContent = 'Memuat foto...';
    state.hidden = false;
    try {
      const blob = await fetchLampiranBlob(path);
      const url = trackUrl(URL.createObjectURL(blob));
      const img = h('img', { class: 'photo-preview', alt, src: url });
      fig.insertBefore(img, fig.firstChild);
      state.hidden = true;
    } catch (e) {
      state.replaceChildren(`${e.message} `, /hak akses/.test(e.message) ? null : h('button', { class: 'link', type: 'button', onclick: load }, 'Coba lagi'));
    }
  }
  if (path) load(); else state.textContent = 'Foto tidak tersedia.';
  return fig;
}

/**
 * createPhotoUpload({name, existing, existingUrl, max, onChange, onPick, retain, label}) ->
 *   {el, getFile, getFiles, getKey, hasValue, setError, remove, destroy}
 * - existing / existingUrl: berkas tersimpan (BerkasRingkas atau url /api/v1/lampiran/{id}), dipratinjau lewat token.
 * - max: batas jumlah foto (default 1 = pilihan baru menggantikan). Bila penuh, tombol ambil dinonaktifkan.
 * - Setiap pilihan foto memiliki `key` (uuid v4) tetap selama foto itu dipilih, dipakai sebagai Idempotency-Key
 *   saat unggah/ulang kirim.
 * - retain=false: foto tidak disimpan di komponen, hanya diteruskan ke onPick({file,key,previewUrl,width,height}).
 */
export function createPhotoUpload({ name, existing = null, existingUrl = null, max = 1, onChange = () => {}, onPick = null, retain = true, label = 'foto', camera = true } = {}) {
  let items = []; // {id,key,file,url,width,height,original}
  let busy = false;
  const existingRef = existing ?? existingUrl;
  const hasExisting = !!lampiranPath(existingRef);

  const list = h('div', { class: 'photo-list' });
  const old = hasExisting ? h('div', { class: 'photo-old' }, createAuthImage(existingRef, { alt: `${label} tersimpan`, caption: 'Foto tersimpan' })) : null;
  const progress = h('progress', { class: 'photo-progress', hidden: true, 'aria-label': 'Memampatkan foto' });
  const status = h('p', { class: 'muted small', role: 'status', 'aria-live': 'polite' });
  const cam = h('input', { type: 'file', accept: 'image/*', capture: 'environment', id: `f-${name}`, class: 'sr-only', multiple: max > 1 });
  const gal = h('input', { type: 'file', accept: 'image/*', id: `f-${name}-galeri`, class: 'sr-only', multiple: max > 1 });
  const camBtn = h('label', { class: 'btn', for: `f-${name}` }, 'Ambil foto');
  const galBtn = h('label', { class: 'btn', for: `f-${name}-galeri` }, 'Pilih dari galeri');
  const actions = h('div', { class: 'photo-actions' }, camera ? camBtn : null, galBtn, cam, gal);
  const el = h('div', { class: 'photo-upload' }, old, list, actions, progress, status);

  const full = () => retain && max > 1 && items.length >= max;
  function paint() {
    list.replaceChildren(...items.map((it) => h('figure', { class: 'photo-item' },
      h('img', { class: 'photo-preview', src: it.url, alt: `Pratinjau ${label}` }),
      h('figcaption', { class: 'photo-cap' },
        h('span', { class: 'muted small' }, `${formatUkuran(it.file.size)} · ${it.width}×${it.height}`),
        h('button', { class: 'btn btn-danger btn-sm', type: 'button', onclick: () => remove(it.id), 'aria-label': `Hapus ${label}` }, 'Hapus')))));
    const has = items.length > 0 || hasExisting;
    camBtn.textContent = max === 1 && has ? 'Ambil ulang' : 'Ambil foto';
    galBtn.textContent = max === 1 && has ? 'Ganti dari galeri' : 'Pilih dari galeri';
    const f = full();
    for (const b of [camBtn, galBtn]) b.classList.toggle('disabled', f);
    cam.disabled = gal.disabled = f || busy;
    if (f) status.textContent = `Batas ${max} foto tercapai. Hapus salah satu untuk menambah.`;
  }

  function remove(id) {
    const it = items.find((x) => x.id === id);
    if (!it) return;
    release(it.url);
    items = items.filter((x) => x.id !== id);
    status.textContent = '';
    paint();
    onChange(getFiles());
  }

  async function handle(fileList) {
    const picked = [...(fileList || [])];
    if (!picked.length) return;
    busy = true;
    paint();
    progress.hidden = false;
    status.classList.remove('field-error');
    try {
      for (const f of picked) {
        if (retain && max === 1 && items.length) { release(items[0].url); items = []; }
        else if (full()) { status.textContent = `Batas ${max} foto tercapai; sisanya diabaikan.`; break; }
        status.textContent = 'Memampatkan foto...';
        const r = await compressImageDetail(f);
        const rec = { id: newKey(), key: newKey(), file: r.file, url: trackUrl(URL.createObjectURL(r.file)), width: r.width, height: r.height, original: r.original };
        if (retain) items.push(rec);
        status.textContent = `Siap: ${formatUkuran(r.file.size)} (asli ${formatUkuran(r.original)})`;
        if (onPick) onPick(rec);
        if (retain) { paint(); onChange(getFiles()); }
      }
    } catch (e) {
      status.textContent = e.message || 'Foto gagal diproses.';
      status.classList.add('field-error');
    } finally {
      busy = false;
      progress.hidden = true;
      paint();
    }
  }
  for (const inp of [cam, gal]) inp.addEventListener('change', () => { const fl = [...inp.files]; inp.value = ''; handle(fl); });

  const getFiles = () => items.map((i) => i.file);
  function destroy() { items.forEach((i) => release(i.url)); items = []; }
  paint();

  return {
    el,
    getFile: () => items[items.length - 1]?.file ?? null,
    getFiles,
    getKey: () => items[items.length - 1]?.key ?? null,
    hasValue: () => items.length > 0 || hasExisting,
    setError: (msg) => { status.textContent = msg || ''; status.classList.toggle('field-error', !!msg); },
    remove,
    destroy,
  };
}

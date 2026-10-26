// Lampiran tambahan untuk record yang SUDAH tersimpan: POST /api/v1/lampiran (multipart) + Idempotency-Key.
// Dipakai di detailExtras modul (bukti distribusi, keuangan, nanti keluhan/mutu/log produksi).
// Foto yang menjadi bagian payload formulir (foto/nota/bukti) cukup memakai kolom {type:'photo'} di form-view.
import { h } from '../core/dom.js';
import { request, newKey, uploadMultipart } from '../core/api.js';
import { enqueue } from '../core/offline-queue.js';
import { firstError, PESAN_413, PESAN_429, PESAN_409 } from '../core/errors.js';
import { createPhotoUpload, createAuthImage, formatUkuran } from './photo-upload.js';
import { createUploadState, bodyUnggahTertunda, validasiBerkasUnggah, MAKS_LAMPIRAN } from '../core/lampiran-uploader.js';

export const MODUL_LAMPIRAN = ['bukti-distribusi', 'keuangan-pencairan', 'keuangan-pengeluaran', 'keluhan', 'mutu', 'log-produksi'];
export const STATUS_UNGGAH = { mengunggah: 'Mengunggah...', terunggah: 'Terunggah', menunggu: 'Menunggu jaringan: dikirim otomatis saat online', gagal: 'Gagal' };

/** Pesan galat unggah (413/422/429/409/403/404/offline) berbahasa Indonesia. Murni. */
export function pesanUnggah(e) {
  const s = e?.status;
  if (s === 0) return 'Tidak ada koneksi internet.';
  if (s === 413) return PESAN_413;
  if (s === 429) return PESAN_429;
  if (s === 409) return PESAN_409;
  if (s === 422) return firstError(e.errors) || 'Berkas ditolak. Gunakan foto JPEG, PNG, atau WebP maksimal 5 MB.';
  if (s === 403) return 'Anda tidak punya hak mengunggah lampiran pada data ini.';
  if (s === 404) return 'Data induk tidak ditemukan atau sudah dihapus.';
  if (s >= 500) return 'Terjadi gangguan pada server. Coba lagi nanti.';
  return e?.message || 'Unggahan gagal.';
}

/**
 * Kirim dengan antrean offline. Kunci idempotensi `key` dibuat pemanggil SEKALI per pilihan foto dan dipakai ulang
 * pada setiap percobaan, sehingga server tidak membuat data ganda.
 * -> {status:'terunggah', data} | {status:'menunggu'}; galat lain dilempar (ApiError).
 * deps ({request, enqueue}) dapat diganti pada pengujian.
 */
export async function kirimDenganAntrean({ method = 'POST', path, body, key, label = 'Lampiran' }, deps = {}) {
  const send = deps.request || request;
  const queue = deps.enqueue || enqueue;
  try {
    const data = await send(method, path, { body, key, silent: true });
    return { status: 'terunggah', data };
  } catch (e) {
    if (e?.status === 0) {
      const ok = await queue({ id: key, method, path, body, label, createdAt: Date.now() });
      if (ok !== false) return { status: 'menunggu', data: null };
    }
    throw e;
  }
}

/** Unggah satu foto ke record: field kontrak LampiranInput {modul, lampiranable_id, kategori, berkas}. */
export function uploadLampiran({ modul, recordId, file, kategori = 'foto', key = newKey(), label = 'Lampiran', onProgress = null }, deps) {
  if (!MODUL_LAMPIRAN.includes(modul)) return Promise.reject(Object.assign(new Error(`Modul lampiran tidak dikenal: ${modul}`), { status: 422 }));
  const salah = validasiBerkasUnggah(file);
  if (salah) return Promise.reject(Object.assign(new Error(salah), { status: 422, errors: { berkas: [salah] } }));
  // Dengan onProgress, kirim lewat XHR (progres unggah); tanpa jaringan tetap masuk antrean offline (status 0).
  const lewatProgres = onProgress ? { request: (m, p, o) => uploadMultipart(p, o.body, { key: o.key, onProgress }) } : {};
  return kirimDenganAntrean({ path: '/lampiran', body: { modul, lampiranable_id: recordId, kategori, berkas: file }, key, label }, { ...lewatProgres, ...deps });
}

/**
 * Panel lampiran untuk detail record.
 * createLampiranPanel({modul, recordId, kategori, lampiran:[Lampiran], max=5, canAdd, title}) -> Node
 * Setiap foto baru langsung diunggah; status per baris (mengunggah / terunggah / menunggu jaringan / gagal + Coba lagi).
 */
export function createLampiranPanel({ modul, recordId, kategori = 'foto', lampiran = [], max = 5, canAdd = false, title = 'Lampiran', disembunyikan = false }) {
  if (disembunyikan) return h('section', { class: 'panel stack-tight', 'aria-label': title }, h('h2', { class: 'panel-title' }, title), h('p', { class: 'muted small' }, 'Foto tidak ditampilkan untuk peran Anda.'));
  const rows = [];
  const box = h('div', { class: 'stack-tight' });
  const old = (lampiran || []).filter((l) => l?.url).map((l) => createAuthImage(l, { alt: `Lampiran ${l.kategori || ''}`.trim(), caption: `${l.kategori || 'lampiran'} · ${formatUkuran(l.ukuran)}` }));
  const total = () => (lampiran?.length || 0) + rows.filter((r) => r.status !== 'gagal').length;

  const wrap = h('section', { class: 'panel stack-tight', 'aria-label': title }, h('h2', { class: 'panel-title' }, title), old.length ? h('div', { class: 'photo-grid' }, old) : h('p', { class: 'muted small' }, 'Belum ada lampiran.'), box);
  if (!canAdd) return wrap;

  const limitInfo = h('p', { class: 'muted small' });
  const picker = createPhotoUpload({
    name: `lampiran-${modul}-${recordId}`, max: 1, retain: false, label: 'lampiran',
    onPick: (rec) => {
      if (total() >= max) { picker.setError(`Batas ${max} lampiran tercapai.`); return; }
      add(rec);
    },
  });
  wrap.append(picker.el, limitInfo);

  function add(rec) {
    const row = { key: rec.key, file: rec.file, status: 'mengunggah', msg: '', progress: 0 };
    rows.push(row);
    row.el = h('div', { class: 'upload-row' });
    box.append(row.el);
    paint(row, rec.url);
    send(row, rec.url);
    limitInfo.textContent = `${total()} dari ${max} lampiran.`;
  }
  function paint(row, url) {
    row.el.replaceChildren(
      h('img', { class: 'thumb', src: url, alt: 'Foto yang diunggah' }),
      h('div', { class: 'upload-info' },
        h('span', { class: `badge ${row.status === 'terunggah' ? 'badge-ok' : row.status === 'gagal' ? 'badge-danger' : row.status === 'menunggu' ? 'badge-warn' : 'badge-info'}` }, STATUS_UNGGAH[row.status]),
        row.status === 'mengunggah' ? h('progress', { class: 'upload-progress', max: 100, value: row.progress || 0, 'aria-label': `Mengunggah ${row.progress || 0}%` }) : null,
        row.msg ? h('span', { class: 'field-error', role: 'alert' }, row.msg) : null,
        h('span', { class: 'muted small' }, formatUkuran(row.file.size))),
      row.status === 'gagal' ? h('button', { class: 'btn', type: 'button', onclick: () => send(row, url) }, 'Coba lagi') : null,
      row.status === 'gagal' ? h('button', { class: 'btn btn-danger', type: 'button', onclick: () => { row.el.remove(); rows.splice(rows.indexOf(row), 1); limitInfo.textContent = `${total()} dari ${max} lampiran.`; } }, 'Hapus') : null);
  }
  async function send(row, url) {
    row.status = 'mengunggah'; row.msg = ''; paint(row, url);
    try {
      const r = await uploadLampiran({ modul, recordId, file: row.file, kategori, key: row.key, onProgress: (p) => { row.progress = p; paint(row, url); } });
      row.status = r.status;
    } catch (e) {
      row.status = 'gagal';
      row.msg = pesanUnggah(e);
    }
    paint(row, url);
  }
  return wrap;
}

/**
 * Kolom form `lampiran_ids` untuk modul yang menerima unggahan tertunda (keluhan, mutu, log-produksi):
 * tiap foto diunggah segera ke POST /lampiran (progres + galat per foto), Simpan memblokir bila ada foto
 * yang belum selesai/gagal, dan `get()` mengembalikan id terunggah untuk payload `lampiran_ids`.
 * lampiranField({modul, label, kategori='foto', existing:[Lampiran], max, sppgId:()=>id|null, help})
 * Dipakai di skema modul sebagai elemen `fields` (type 'custom'); instance dibuat per render form.
 */
export function lampiranField({ name = 'lampiran_ids', modul, label = 'Foto', kategori = 'foto', existing = [], max = MAKS_LAMPIRAN, sppgId = () => null, help = null, deps = {} }) {
  let state = null;

  return {
    name, label, type: 'custom', help,
    build() {
      state = createUploadState({
        modul, kategori, max, tersimpan: existing.length, sppgId,
        upload: ({ onProgress, ...a }) => (deps.upload || ((x) => uploadMultipart('/lampiran', bodyUnggahTertunda(x), { key: x.key, onProgress })))({ ...a, onProgress }),
        newKey,
      });
      const lama = existing.filter((l) => l?.url).map((l) => createAuthImage(l, { alt: `${label} tersimpan`, caption: `${l.kategori || 'foto'} · ${formatUkuran(l.ukuran)}` }));
      const box = h('div', { class: 'stack-tight' });
      const info = h('p', { class: 'muted small', role: 'status', 'aria-live': 'polite' });
      const picker = createPhotoUpload({
        name: `lampiran-${modul}`, max, retain: false, label: label.toLowerCase(),
        onPick: (rec) => {
          const r = state.add({ file: rec.file, key: rec.key, url: rec.url });
          picker.setError(r.ok ? '' : r.error);
          if (r.ok) paint();
        },
      });
      const el = h('div', { class: 'stack-tight' }, lama.length ? h('div', { class: 'photo-grid' }, lama) : null, box, picker.el, info);

      function paint() {
        box.replaceChildren(...state.items.map((it) => h('div', { class: 'upload-row', dataset: { status: it.status } },
          h('img', { class: 'thumb', src: it.url, alt: `Pratinjau ${label.toLowerCase()}` }),
          h('div', { class: 'upload-info' },
            h('span', { class: `badge ${it.status === 'terunggah' ? 'badge-ok' : it.status === 'gagal' ? 'badge-danger' : 'badge-info'}` }, STATUS_UNGGAH[it.status]),
            it.status === 'mengunggah' ? h('progress', { class: 'upload-progress', max: 100, value: it.progress || 0, 'aria-label': `Mengunggah ${it.progress || 0}%` }) : null,
            it.error ? h('span', { class: 'field-error', role: 'alert' }, it.error) : null,
            h('span', { class: 'muted small' }, formatUkuran(it.file.size))),
          h('div', { class: 'stack-tight' },
            it.status === 'gagal' ? h('button', { class: 'btn', type: 'button', onclick: () => state.retry(it.key) }, 'Coba lagi') : null,
            it.status !== 'mengunggah' ? h('button', { class: 'btn btn-danger', type: 'button', onclick: () => { state.remove(it.key); } }, 'Hapus') : null))));
        info.textContent = `${existing.length + state.items.filter((i) => i.status !== 'gagal').length} dari ${max} foto.`;
      }
      state.subscribe(paint);
      paint();

      return {
        el,
        get: () => state.ids(),
        set: () => {},
        hasValue: () => state.ids().length > 0,
        setError: (msg) => picker.setError(msg || ''),
        focusEl: null,
      };
    },
    // Dijalankan form-view saat Simpan: foto belum selesai/gagal memblokir (pesan di bawah kolom).
    validate: () => (state ? state.blokirSimpan() : ''),
  };
}

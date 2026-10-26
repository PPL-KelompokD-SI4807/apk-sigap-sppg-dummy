// Feedback dan Keluhan (fitur 15, PBI 15). Mockup: Gambar 17 (form) dan Gambar 10 (keluhan kritis).
// Foto keluhan: unggah ke POST /lampiran lalu kirim lampiran_ids saat simpan (components/lampiran-field.js).
import { defineCrudModule } from '../../components/crud-module.js';
import { lampiranField, createLampiranPanel } from '../../components/lampiran-field.js';
import { rowCard } from '../../components/row-card.js';
import { h } from '../../core/dom.js';
import { api } from '../../core/api.js';
import { getUser } from '../../core/auth.js';
import { lampiranDisembunyikan } from '../../core/lampiran-uploader.js';
import { resolve } from '../../core/router.js';
import { tanggalJamWIB } from '../../core/waktu.js';
import {
  STATUS, PRIORITAS, kategoriLabel, statusLabel, prioritasLabel, prioritasBadge, statusBadge,
  isKritis, prioritasOtomatis, transisiValid, keluhanFields, keluhanPayload, rowPermsKeluhan,
} from './schema.js';

const unwrap = (r) => (r && r.data !== undefined && !Array.isArray(r) ? r.data : r);

// Filter daftar (mutable; dibaca list-view tiap muat; berubah -> resolve() merender ulang layar).
const listQuery = { status: '', prioritas: '', from: '', to: '' };

// Tombol "Batch tidak diketahui" (kolom kustom, hanya tampil untuk kategori kritis).
const batchField = {
  name: 'batch_tidak_diketahui', label: 'Batch', type: 'custom',
  showIf: (cur) => isKritis(cur.kategori),
  build: ({ value, onChange }) => {
    let on = value === true;
    const btn = h('button', { type: 'button', class: 'btn btn-block', 'aria-pressed': 'false' });
    const paint = () => { btn.setAttribute('aria-pressed', on ? 'true' : 'false'); btn.classList.toggle('btn-primary', on); btn.textContent = on ? 'Batch tidak diketahui (dipilih)' : 'Batch tidak diketahui'; };
    btn.addEventListener('click', () => { on = !on; paint(); onChange?.(); btn.dispatchEvent(new Event('change', { bubbles: true })); });
    paint();
    return { el: btn, get: () => on, set: (v) => { on = v === true; paint(); }, hasValue: () => on, focusEl: btn };
  },
};

// Foto hanya pada form isi keluhan (baru, atau ubah oleh Admin); form tindak lanjut Kepala SPPG/Ahli Gizi tidak punya foto.
const fotoField = ({ user, record }) => lampiranField({
  modul: 'keluhan', label: 'Foto keluhan', existing: record?.lampiran || [],
  // Admin wajib menyertakan sppg_id pada unggahan tertunda: ikut kolom SPPG di form (baru) atau SPPG record (ubah).
  sppgId: () => (user?.role === 'admin' ? document.getElementById('f-sppg_id')?.value || record?.sppg_id || null : null),
  help: 'Opsional, maks 5 foto (JPEG/PNG/WebP, maks 5 MB). Terunggah langsung; Simpan menunggu semua foto selesai.',
});

function fields({ mode, user, record }) {
  const f = keluhanFields({ mode, user, record });
  if (mode === 'detail') return f;
  const i = f.findIndex((x) => x.name === 'deskripsi');
  const denganFoto = i < 0 ? f : [...f.slice(0, i + 1), fotoField({ user, record }), ...f.slice(i + 1)];
  if (mode !== 'create') return denganFoto;
  const j = denganFoto.findIndex((x) => x.name === 'jadwal_produksi_id');
  return [...denganFoto.slice(0, j + 1), batchField, ...denganFoto.slice(j + 1)];
}

// ---- Atas form: peringatan prioritas (baru) / ringkasan keluhan (tindak lanjut) ----
function formTop({ mode, record, form, collect }) {
  if (mode === 'update' && record) {
    return h('div', { class: 'panel stack-tight' },
      h('div', { class: 'card-title' }, h('strong', {}, kategoriLabel(record.kategori)), h('span', { class: `badge ${prioritasBadge(record.prioritas)}` }, prioritasLabel(record.prioritas))),
      h('p', { class: 'small' }, record.deskripsi));
  }
  const box = h('div', { 'aria-live': 'polite' });
  function show() {
    const k = collect().kategori;
    box.replaceChildren();
    if (!k) return;
    const p = prioritasOtomatis(k);
    if (p === 'kritis') box.append(h('div', { class: 'alert-danger' }, 'Prioritas otomatis: ', h('strong', {}, 'KRITIS'), '. Kepala SPPG dan Ahli Gizi akan langsung menerima notifikasi. Kaitkan dengan batch (jadwal produksi) atau tekan "Batch tidak diketahui".'));
    else box.append(h('div', { class: 'notice' }, `Prioritas otomatis: ${prioritasLabel(p)}.`));
  }
  form.addEventListener('change', show);
  setTimeout(show, 0);
  return box;
}

// ---- Atas daftar: ringkasan per status (juga filter), prioritas, tanggal ----
function listTop() {
  const wrap = h('div', { class: 'stack' });
  const chips = h('div', { class: 'seg', role: 'group', 'aria-label': 'Filter status' });
  const kritis = h('div', { 'aria-live': 'polite' });
  const setF = (k, v) => { listQuery[k] = v; resolve(); };
  const mk = (value, label, n) => h('button', {
    type: 'button', class: `seg-btn${listQuery.status === value ? ' active' : ''}`, 'aria-pressed': listQuery.status === value ? 'true' : 'false', onclick: () => setF('status', value),
  }, n == null ? label : `${label} (${n})`);
  chips.append(mk('', 'Semua'), ...STATUS.map((s) => mk(s.value, s.label)));

  const prio = h('select', { id: 'f-prioritas', 'aria-label': 'Prioritas' }, h('option', { value: '' }, 'Semua prioritas'), PRIORITAS.map((p) => h('option', { value: p.value }, p.label)));
  prio.value = listQuery.prioritas;
  prio.addEventListener('change', () => setF('prioritas', prio.value));
  const dari = h('input', { type: 'date', id: 'f-from', value: listQuery.from, 'aria-label': 'Dari tanggal' });
  const sampai = h('input', { type: 'date', id: 'f-to', value: listQuery.to, 'aria-label': 'Sampai tanggal' });
  dari.addEventListener('change', () => setF('from', dari.value));
  sampai.addEventListener('change', () => setF('to', sampai.value));
  wrap.append(kritis, chips, h('div', { class: 'filter-row' }, prio, dari, sampai));

  // Ringkasan jumlah per status mengikuti filter tanggal.
  api.get('/keluhan/ringkasan', { from: listQuery.from, to: listQuery.to }, { silent: true }).then((res) => {
    const r = unwrap(res);
    chips.replaceChildren(mk('', 'Semua', r.total), ...STATUS.map((s) => mk(s.value, s.label, r.per_status?.[s.value] ?? 0)));
    if (r.kritis_terbuka > 0) kritis.append(h('div', { class: 'alert-danger' }, `${r.kritis_terbuka} keluhan kritis masih terbuka.`));
  }).catch(() => { /* ringkasan opsional */ });
  return wrap;
}

export default defineCrudModule({
  slug: 'keluhan',
  title: 'Keluhan',
  icon: '📣',
  description: 'Feedback dan keluhan penerima manfaat',
  endpoint: '/keluhan',
  createLabel: '+ Catat',
  columns: [{ key: 'kategori', primary: true, format: (r) => r.kategori_label || kategoriLabel(r.kategori) }],
  fields,
  mapPayload: (v, { mode }) => keluhanPayload(v, { mode, user: getUser() }),
  itemTitle: (r) => `Keluhan ${r.kategori_label || kategoriLabel(r.kategori)}`,
  searchPlaceholder: 'Cari deskripsi keluhan...',
  listQuery,
  listTop,
  formTop,
  rowPerms: (rec, p) => rowPermsKeluhan(getUser(), rec, p),
  renderCard: (r, href) => rowCard({
    href,
    title: r.kategori_label || kategoriLabel(r.kategori),
    sub: [r.titik_layanan?.nama, tanggalJamWIB(r.created_at), String(r.deskripsi ?? '').slice(0, 70)].filter(Boolean).join(' · '),
    badges: [{ text: prioritasLabel(r.prioritas), cls: prioritasBadge(r.prioritas) }, { text: r.status_label || statusLabel(r.status), cls: statusBadge(r.status) }],
  }),
  detailExtras: (rec, p) => {
    const nodes = [];
    if (rec.prioritas === 'kritis') nodes.push(h('div', { class: 'alert-danger' }, 'Keluhan KRITIS',
      rec.dieskalasi_pada ? ` · dieskalasi ${tanggalJamWIB(rec.dieskalasi_pada)}` : '', rec.jadwal_produksi_id ? '' : ' · batch tidak diketahui'));
    if (p.U && transisiValid(rec.status).length && getUser()?.role !== 'admin') {
      nodes.push(h('a', { class: 'btn btn-primary btn-block', href: `#/keluhan/${rec.id}/ubah` }, rec.status === 'baru' ? 'Proses keluhan' : 'Selesaikan / tolak dengan catatan'));
    }
    if (lampiranDisembunyikan(getUser(), rec) || (rec.lampiran || []).length || p.U) nodes.push(createLampiranPanel({ disembunyikan: lampiranDisembunyikan(getUser(), rec), modul: 'keluhan', recordId: rec.id, kategori: 'foto', lampiran: rec.lampiran || [], canAdd: !!p.U, title: 'Foto keluhan' }));
    return nodes;
  },
});

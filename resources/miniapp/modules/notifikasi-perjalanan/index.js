// Notifikasi Perjalanan Mobil ke Tujuan (fitur 2, PBI 2). Mockup: Gambar 21.
import { defineCrudModule } from '../../components/crud-module.js';
import { rowCard } from '../../components/row-card.js';
import { h } from '../../core/dom.js';
import { api, request, newKey } from '../../core/api.js';
import { getUser } from '../../core/auth.js';
import { userPerms } from '../../core/permissions.js';
import { route, resolve } from '../../core/router.js';
import { showConfirm, toast, setMainButton } from '../../core/ui.js';
import { errorMessage } from '../../core/errors.js';
import { todayWIB, jamWIB } from '../../core/waktu.js';
import {
  STATUS_PERJALANAN, statusLabel, statusBadge, transisiValid, aksiLabel, unitPerjalananBoleh, bolehUbahStatus,
  validasiAlasan, statusPayload, PENANDA_PIC, picDialihkan, kirimLabel, kirimBadge, ringkasEstimasi,
  perjalananFields, perjalananPayload,
} from './schema.js';

const SLUG = 'notifikasi-perjalanan';
const unwrap = (r) => (r && r.data !== undefined && !Array.isArray(r) ? r.data : r);
const me = () => (unitPerjalananBoleh(getUser()) ? userPerms(getUser(), SLUG) : { C: false, R: false, U: false, D: false });

// Filter daftar (mutable; dibaca list-view tiap muat). Default: hari ini WIB.
const listQuery = { tanggal: todayWIB(), status: '' };

// ---- Pratinjau estimasi di atas form ----
function formTop({ form, collect }) {
  const box = h('div', { class: 'stack-tight', 'aria-live': 'polite' });
  let tick = 0;
  async function refresh() {
    const my = ++tick;
    const v = collect();
    if (!v.jadwal_distribusi_id) return box.replaceChildren();
    box.replaceChildren(h('p', { class: 'muted small' }, 'Menghitung estimasi tiba...'));
    try {
      const d = unwrap(await api.post('/notifikasi-perjalanan/pratinjau', perjalananPayload(v), { silent: true }));
      if (my !== tick) return;
      const r = ringkasEstimasi(d);
      box.replaceChildren(h('div', { class: 'panel stack-tight' },
        h('strong', {}, `Estimasi tiba ${r.estimasi}`),
        h('span', { class: 'muted small' }, `${r.durasi} · ${r.sumber} · berangkat ${jamWIB(d.waktu_berangkat)}`),
        r.dialihkan ? h('div', { class: 'notice' }, PENANDA_PIC) : null,
        d.pesan ? h('div', { class: 'preview-msg' }, h('span', { class: 'muted small' }, 'Pratinjau pesan'), h('p', {}, d.pesan)) : null));
    } catch (e) {
      if (my !== tick) return;
      if (e?.status === 401) throw e;
      box.replaceChildren(h('div', { class: 'alert-danger' }, errorMessage(e, 'Pratinjau tidak tersedia.')));
    }
  }
  form.addEventListener('change', refresh);
  form.addEventListener('input', (ev) => { if (ev.target?.name === 'estimasi_menit') refresh(); });
  setTimeout(refresh, 400); // jadwal terisi dari ?jadwal= / draf setelah opsi dimuat
  return box;
}

// ---- Tombol transisi (dipakai detail dan layar terakhir) ----
function aksiPerjalanan(rec) {
  if (!bolehUbahStatus(getUser(), rec, me())) return null;
  const box = h('div', { class: 'stack-tight' }, h('h2', { class: 'panel-title' }, 'Ubah status perjalanan'));
  const row = h('div', { class: 'row-actions' });
  const batalBox = h('div', { class: 'field', hidden: true });
  const alasan = h('textarea', { id: 'alasan-batal', rows: 3, maxlength: 500, 'aria-describedby': 'alasan-err', placeholder: 'Mis. mobil mogok, jalan banjir' });
  const err = h('p', { class: 'field-error', id: 'alasan-err', role: 'alert' });
  const kirimBatal = h('button', { class: 'btn btn-danger btn-block', type: 'button' }, 'Konfirmasi pembatalan');
  batalBox.append(h('label', { class: 'label', for: 'alasan-batal' }, 'Alasan pembatalan ', h('span', { class: 'req' }, '*')), alasan, err, kirimBatal);

  async function kirim(to, btn) {
    const msg = validasiAlasan(to, alasan.value);
    if (msg) { err.textContent = msg; alasan.focus(); return; }
    err.textContent = '';
    const gagal = to === 'dibatalkan';
    if (!(await showConfirm({ title: aksiLabel(to), message: gagal ? 'Perjalanan akan dibatalkan dan tidak dapat dilanjutkan.' : `Ubah status menjadi "${statusLabel(to)}"? PIC dapat menerima notifikasi.`, okText: 'Ya, ubah', destructive: gagal }))) return;
    btn.disabled = true;
    try {
      await request('POST', `/notifikasi-perjalanan/${rec.id}/status`, { body: statusPayload(to, alasan.value), key: newKey(), silent: true });
      toast(`Status menjadi ${statusLabel(to)}.`, 'success');
      resolve();
    } catch (e) {
      if (e?.status === 401) throw e;
      btn.disabled = false;
      if (e?.status === 422 && e.errors?.alasan) err.textContent = e.errors.alasan[0] ?? e.errors.alasan;
      else toast(errorMessage(e, 'Status gagal diubah.'), 'error', 5000);
    }
  }
  for (const to of transisiValid(rec.status)) {
    const batal = to === 'dibatalkan';
    const btn = h('button', { class: `btn ${batal ? 'btn-danger' : 'btn-primary'}`, type: 'button' }, aksiLabel(to));
    btn.addEventListener('click', () => {
      if (batal) { batalBox.hidden = false; alasan.focus(); return; }
      kirim(to, btn);
    });
    row.append(btn);
  }
  kirimBatal.addEventListener('click', () => kirim('dibatalkan', kirimBatal));
  box.append(row, batalBox);
  return h('div', { class: 'panel' }, box);
}

function banner(rec) {
  return [
    picDialihkan(rec) ? h('div', { class: 'notice' }, PENANDA_PIC) : null,
    rec.terlambat ? h('div', { class: 'alert-danger' }, 'Perjalanan terlambat dari estimasi tiba.') : null,
    rec.status === 'dibatalkan' && rec.alasan_batal ? h('div', { class: 'alert-danger' }, `Dibatalkan: ${rec.alasan_batal}`) : null,
  ].filter(Boolean);
}

function ringkasKartu(rec) {
  const r = ringkasEstimasi(rec);
  return h('div', { class: 'panel stack-tight' },
    h('div', { class: 'card-title' }, h('strong', {}, rec.titik_layanan?.nama ?? `Perjalanan #${rec.id}`), h('span', { class: `badge ${statusBadge(rec.status)}` }, rec.status_label || statusLabel(rec.status))),
    h('div', { class: 'big-num' }, rec.status === 'tiba' ? `Tiba ${jamWIB(rec.waktu_tiba)}` : `Estimasi tiba ${r.estimasi}`),
    h('div', { class: 'muted small' }, `Berangkat ${jamWIB(rec.waktu_berangkat)} · ${r.durasi} · ${r.sumber}`),
    h('div', {}, h('span', { class: `badge ${kirimBadge(rec.status_kirim)}` }, kirimLabel(rec.status_kirim))),
    rec.pesan ? h('p', { class: 'small' }, rec.pesan) : null);
}

// ---- Layar status terakhir ----
async function terakhirScreen(ctx) {
  const q = {};
  if (ctx.query.get('jadwal')) q.jadwal_distribusi_id = ctx.query.get('jadwal');
  if (ctx.query.get('titik')) q.titik_layanan_id = ctx.query.get('titik');
  const rec = unwrap(await api.get('/notifikasi-perjalanan/terakhir', q));
  if (ctx.isStale()) return;
  ctx.setTitle('Perjalanan Terakhir');
  if (!rec) {
    ctx.el.append(h('div', { class: 'empty' }, h('p', { class: 'muted' }, 'Belum ada perjalanan.')));
  } else {
    ctx.el.append(ringkasKartu(rec), ...banner(rec));
    const aksi = aksiPerjalanan(rec);
    if (aksi) ctx.el.append(aksi);
    ctx.el.append(h('a', { class: 'btn btn-block', href: `#/${SLUG}/${rec.id}` }, 'Lihat detail'));
  }
  if (me().C) setMainButton({ text: 'Berangkat & Kirim Notifikasi', onClick: () => { location.hash = `#/${SLUG}/baru`; } });
}

function listTop() {
  const tanggal = h('input', { type: 'date', id: 'f-tanggal', value: listQuery.tanggal, max: todayWIB(), 'aria-label': 'Tanggal berangkat' });
  tanggal.addEventListener('change', () => { listQuery.tanggal = tanggal.value || todayWIB(); resolve(); });
  const seg = h('div', { class: 'seg', role: 'group', 'aria-label': 'Status perjalanan' },
    [{ value: '', label: 'Semua' }, ...STATUS_PERJALANAN].map((o) => h('button', {
      type: 'button', class: `seg-btn${listQuery.status === o.value ? ' active' : ''}`, 'aria-pressed': listQuery.status === o.value ? 'true' : 'false',
      onclick: () => { listQuery.status = o.value; resolve(); },
    }, o.label)));
  return h('div', { class: 'stack' },
    h('div', { class: 'panel stack-tight' }, h('label', { class: 'label', for: 'f-tanggal' }, 'Tanggal berangkat'), tanggal,
      h('a', { class: 'btn btn-block', href: `#/${SLUG}/terakhir` }, 'Status perjalanan terakhir')),
    seg);
}

const base = defineCrudModule({
  slug: SLUG,
  title: 'Notifikasi Perjalanan',
  icon: '🚐',
  description: 'Mobil berangkat, estimasi tiba, notifikasi PIC',
  endpoint: '/notifikasi-perjalanan',
  createLabel: '+ Berangkat',
  columns: [{ key: 'titik_layanan', primary: true, format: (r) => r.titik_layanan?.nama ?? `#${r.id}` }],
  fields: ({ mode }) => perjalananFields({ mode }),
  mapPayload: (v) => perjalananPayload(v),
  itemTitle: (r) => `Perjalanan ${r.titik_layanan?.nama ?? `#${r.id}`}`,
  extraGuard: unitPerjalananBoleh,
  searchable: false,
  queueOffline: false,
  listQuery,
  listTop,
  prefill: (q) => (q.get('jadwal') ? { jadwal_distribusi_id: q.get('jadwal') } : {}),
  formTop,
  beforeSave: () => showConfirm({ title: 'Berangkat & kirim notifikasi', message: 'Perjalanan dimulai dan pesan dikirim ke PIC titik layanan. Lanjutkan?', okText: 'Berangkat' }),
  renderCard: (r, href) => rowCard({
    href,
    title: r.titik_layanan?.nama ?? `Perjalanan #${r.id}`,
    sub: [`berangkat ${jamWIB(r.waktu_berangkat)}`, r.status === 'tiba' ? `tiba ${jamWIB(r.waktu_tiba)}` : `estimasi ${jamWIB(r.estimasi_tiba)}`, picDialihkan(r) ? 'dialihkan ke Kepala' : null].filter(Boolean).join(' · '),
    badges: [{ text: r.status_label || statusLabel(r.status), cls: statusBadge(r.status) }, r.terlambat ? { text: 'Terlambat', cls: 'badge-danger' } : null],
  }),
  detailExtras: (rec) => [...banner(rec), ringkasKartu(rec), aksiPerjalanan(rec)].filter(Boolean),
});

export default {
  ...base,
  register() {
    route(`/${SLUG}/terakhir`, terakhirScreen, { title: 'Perjalanan Terakhir', guard: () => me().R });
    base.register();
  },
};

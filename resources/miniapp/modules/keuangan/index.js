// Keuangan (fitur 14, PBI 14). Mockup: Gambar 16 (ringkasan, pencairan, pengeluaran) dan Gambar 12 (form pengeluaran).
// Tiga layar: #/keuangan (Ringkasan), #/keuangan/pencairan, #/keuangan/pengeluaran. Hak 'keuangan': akuntan CRUD, admin/kepala baca.
import { defineCrudModule } from '../../components/crud-module.js';
import { rowCard } from '../../components/row-card.js';
import { navTabs } from '../../components/nav-tabs.js';
import { createAuthImage } from '../../components/photo-upload.js';
import { createLampiranPanel } from '../../components/lampiran-field.js';
import { h, formatValue } from '../../core/dom.js';
import { api, fetchList, request, newKey } from '../../core/api.js';
import { getUser } from '../../core/auth.js';
import { userPerms } from '../../core/permissions.js';
import { route, resolve, navigate } from '../../core/router.js';
import { setMainButton, toast, showConfirm } from '../../core/ui.js';
import { renderChart } from '../../core/charts.js';
import { errorMessage, ringkasGalatAksi } from '../../core/errors.js';
import { rupiahField, periodeField } from './fields.js';
import {
  KATEGORI, kategoriLabel, formatRupiah, labelPeriode, periodeSekarang, periodeDari, statusAnggaran, clampPersen, teksPersen, melebihiSisa,
  hitungPersen, seriKategori, AMBANG_PERINGATAN,
  pencairanFields, pengeluaranFields, pencairanDetailFields, pengeluaranDetailFields, pencairanPayload, pengeluaranPayload,
} from './schema.js';
import {
  statusPersetujuanBadge, statusPersetujuanLabel, statusPengeluaran, STATUS_PERSETUJUAN, KEPUTUSAN_PENGELUARAN, tombolKeputusanPengeluaranTampil,
  validasiCatatanKeputusan, payloadKeputusanPengeluaran, rowPermsPengeluaran, dariPembelian, pesanLampiranTerkunci, teksMenunggu, MAKS_CATATAN, MIN_CATATAN,
} from './persetujuan.js';

const SLUG = 'keuangan';
const unwrap = (r) => (r && r.data !== undefined && !Array.isArray(r) ? r.data : r);
const me = () => userPerms(getUser(), SLUG);
const PALET = ['#2481cc', '#1f9d6e', '#d9831f', '#8e55d6', '#d0504d', '#5a7a99'];
const custom = { periode: periodeField(), nominal: rupiahField };

export function keuanganTabs(active) {
  return navTabs([
    { key: 'ringkasan', href: '#/keuangan', label: 'Ringkasan' },
    { key: 'pencairan', href: '#/keuangan/pencairan', label: 'Pencairan' },
    { key: 'pengeluaran', href: '#/keuangan/pengeluaran', label: 'Pengeluaran' },
  ], active, 'Keuangan');
}

/** Filter periode bulan (input month) untuk daftar. */
function periodeInput(listQuery) {
  const el = h('input', { type: 'month', id: 'f-periode-filter', value: listQuery.periode, 'aria-label': 'Periode (bulan)' });
  el.addEventListener('change', () => { listQuery.periode = el.value; resolve(); });
  return el;
}

// ---- Ringkasan sisa anggaran (cache ringan) untuk peringatan di form pengeluaran ----
const ringkasanCache = new Map();
async function ringkasanPeriode(periode) {
  if (!ringkasanCache.has(periode)) ringkasanCache.set(periode, api.get('/keuangan/ringkasan', { periode }, { silent: true }).then(unwrap).catch(() => null));
  return ringkasanCache.get(periode);
}

// ================= Pencairan =================
const pencairanQuery = { periode: '' };
let pencairanInit = false;
const pencairanModule = defineCrudModule({
  slug: 'keuangan/pencairan',
  permissionSlug: SLUG,
  title: 'Pencairan Dana',
  icon: '🏦',
  description: 'Pencairan dana anggaran',
  endpoint: '/keuangan/pencairan',
  createLabel: '+ Pencairan',
  columns: [{ key: 'sumber_dana', primary: true }],
  fields: ({ mode, record }) => (mode === 'detail' ? pencairanDetailFields : pencairanFields({ custom })),
  mapPayload: (v) => pencairanPayload(v),
  itemTitle: (r) => `Pencairan ${r.sumber_dana ?? `#${r.id}`}`,
  searchPlaceholder: 'Cari sumber dana atau referensi...',
  listQuery: pencairanQuery,
  listTop: () => {
    if (!pencairanInit) { pencairanQuery.periode = periodeSekarang(); pencairanInit = true; }
    return h('div', { class: 'stack' }, keuanganTabs('pencairan'), periodeInput(pencairanQuery), pencairanQuery.periode ? null : h('p', { class: 'muted small' }, 'Menampilkan semua periode.'));
  },
  renderCard: (r, href) => rowCard({
    href,
    title: r.sumber_dana,
    sub: [labelPeriode(r.periode), formatValue(r.tanggal_cair, 'date'), r.no_referensi].filter(Boolean).join(' · '),
    aside: formatRupiah(r.nominal),
    badge: r.bukti ? { text: 'Ada bukti', cls: 'badge-ok' } : null,
  }),
  detailExtras: (rec, p) => {
    const utama = rec.bukti?.lampiran_id;
    const lain = (rec.lampiran || []).filter((l) => l.id !== utama);
    return [
      h('div', { class: 'panel stack-tight' }, h('h2', { class: 'panel-title' }, 'Bukti transfer'),
        rec.bukti ? createAuthImage(rec.bukti, { alt: 'Bukti transfer' }) : h('p', { class: 'muted small' }, 'Tidak ada bukti.')),
      lain.length || p.U ? createLampiranPanel({ modul: 'keuangan-pencairan', recordId: rec.id, kategori: 'bukti', lampiran: lain, canAdd: !!p.U, title: 'Lampiran lain' }) : null,
    ];
  },
});
pencairanModule.hidden = true;

// ================= Pengeluaran =================
const pengeluaranQuery = { periode: '', kategori: '', melebihi_anggaran: '', status_persetujuan: '' };
let pengeluaranInit = false;

function listTopPengeluaran() {
  if (!pengeluaranInit) { pengeluaranQuery.periode = periodeSekarang(); pengeluaranInit = true; }
  const kat = h('select', { id: 'f-kategori', 'aria-label': 'Kategori' }, h('option', { value: '' }, 'Semua kategori'), KATEGORI.map((k) => h('option', { value: k.value }, k.label)));
  kat.value = pengeluaranQuery.kategori;
  kat.addEventListener('change', () => { pengeluaranQuery.kategori = kat.value; resolve(); });
  const on = pengeluaranQuery.melebihi_anggaran === '1';
  const chip = h('button', { type: 'button', class: `seg-btn${on ? ' active' : ''}`, 'aria-pressed': on ? 'true' : 'false', onclick: () => { pengeluaranQuery.melebihi_anggaran = on ? '' : '1'; resolve(); } }, on ? 'Hanya yang melebihi anggaran (aktif)' : 'Hanya yang melebihi anggaran');
  const stat = h('select', { id: 'f-status-persetujuan', 'aria-label': 'Status persetujuan' }, h('option', { value: '' }, 'Semua status persetujuan'), STATUS_PERSETUJUAN.map((k) => h('option', { value: k.value }, k.label)));
  stat.value = pengeluaranQuery.status_persetujuan;
  stat.addEventListener('change', () => { pengeluaranQuery.status_persetujuan = stat.value; resolve(); });
  return h('div', { class: 'stack' }, keuanganTabs('pengeluaran'), h('div', { class: 'filter-row' }, periodeInput(pengeluaranQuery), kat), h('div', { class: 'filter-row' }, stat), h('div', { class: 'seg' }, chip),
    pengeluaranQuery.periode ? null : h('p', { class: 'muted small' }, 'Menampilkan semua periode.'));
}

// Peringatan sisa anggaran saat mengisi form pengeluaran baru.
function formTopPengeluaran({ mode, record, form, collect }) {
  if (mode !== 'create') {
    if (!record) return null;
    return h('div', { class: 'stack-tight' },
      statusPengeluaran(record) === 'ditolak' ? h('div', { class: 'alert-danger', role: 'alert' }, `Ditolak investor: ${terakhirCatatan(record) ?? '-'}`) : null,
      h('div', { class: 'notice' }, 'Menyimpan perubahan mengajukan ulang pengeluaran ini ke investor. Pengajuan yang masih menunggu akan dibatalkan.'));
  }
  const box = h('div', { 'aria-live': 'polite', class: 'stack-tight' }, h('div', { class: 'notice' }, 'Pengeluaran baru diajukan ke investor dan baru dihitung ke anggaran setelah disetujui.'));
  let seq = 0;
  async function show() {
    const my = ++seq;
    const v = collect();
    const periode = periodeDari(v.tanggal);
    if (!/^\d{4}-\d{2}$/.test(periode)) return box.replaceChildren();
    const r = await ringkasanPeriode(periode);
    if (my !== seq) return;
    box.replaceChildren(h('div', { class: 'notice' }, 'Pengeluaran baru diajukan ke investor dan baru dihitung ke anggaran setelah disetujui.'));
    if (!r) return;
    const st = statusAnggaran(r);
    box.append(h('div', { class: st.tone === 'danger' ? 'alert-danger' : st.tone === 'warn' ? 'notice' : 'panel' },
      `Anggaran ${labelPeriode(periode)}: sisa ${formatRupiah(r.sisa)} (${st.label}${r.persen != null ? `, ${teksPersen(r.persen)} terpakai` : ''}).`));
    if (melebihiSisa(v.nominal, r.sisa)) {
      box.append(h('div', { class: 'alert-danger', role: 'alert' }, 'Nominal melebihi sisa anggaran periode. Pengeluaran tetap dicatat dan ditandai "Melebihi anggaran".'));
    }
  }
  form.addEventListener('input', show);
  form.addEventListener('change', show);
  setTimeout(show, 0);
  return box;
}


/** Badge kartu: status persetujuan (selain disetujui) tampil utama; "Melebihi anggaran" hanya bermakna setelah disetujui. */
function badgesPengeluaran(r) {
  const st = statusPengeluaran(r);
  const out = [];
  if (st !== 'disetujui') out.push({ text: r.status_persetujuan_label || statusPersetujuanLabel(st), cls: statusPersetujuanBadge(st) });
  if (r.melebihi_anggaran && st === 'disetujui') out.push({ text: 'Melebihi anggaran', cls: 'badge-danger' });
  return { badge: out[0] ?? null, badges: out.slice(1) };
}

const terakhirCatatan = (rec) => [...(rec.riwayat_persetujuan ?? [])].reverse().find((x) => x.status === 'ditolak')?.catatan_keputusan ?? null;

function riwayatPanel(rec) {
  const box = h('div', { class: 'panel stack-tight' }, h('h2', { class: 'panel-title' }, 'Riwayat persetujuan'));
  const rr = rec.riwayat_persetujuan ?? [];
  if (!rr.length) { box.append(h('p', { class: 'muted small' }, 'Belum ada riwayat persetujuan.')); return box; }
  for (const x of [...rr].reverse()) {
    box.append(h('div', { class: 'panel stack-tight' },
      h('div', { class: 'card-title' }, h('strong', {}, `Pengajuan #${x.nomor} · versi ${x.pengeluaran_versi}`), h('span', { class: `badge ${statusPersetujuanBadge(x.status)}`.trim() }, x.status_label || statusPersetujuanLabel(x.status))),
      h('div', { class: 'card-row' }, h('span', { class: 'muted' }, `${kategoriLabel(x.kategori)} · ${formatValue(x.tanggal, 'date')}`), h('strong', {}, formatRupiah(x.nominal))),
      h('p', { class: 'muted small' }, `Diajukan ${x.diajukan_oleh ? `oleh ${x.diajukan_oleh} ` : ''}${formatValue(x.diajukan_pada, 'datetime')}`),
      x.diputuskan_pada ? h('p', { class: 'muted small' }, `Diputuskan ${x.diputuskan_oleh ? `oleh ${x.diputuskan_oleh} ` : ''}${formatValue(x.diputuskan_pada, 'datetime')}`) : null,
      x.catatan_keputusan ? h('p', { class: 'small' }, h('strong', {}, 'Catatan: '), x.catatan_keputusan) : null));
  }
  return box;
}

/** Panel keputusan investor: catatan (wajib bila tolak) + Setujui/Tolak. 409 -> pesan jelas lalu muat ulang. */
function keputusanPanel(rec) {
  const catatan = h('textarea', { class: 'input', id: 'catatan-keputusan', rows: '3', maxlength: String(MAKS_CATATAN), placeholder: `Wajib diisi bila menolak (min. ${MIN_CATATAN} karakter)` });
  const err = h('p', { class: 'field-error', role: 'alert' });
  const tombol = [];
  async function kirim(keputusan, label) {
    const salah = validasiCatatanKeputusan(keputusan, catatan.value);
    err.textContent = salah;
    if (salah) { catatan.focus(); return; }
    const pesan = keputusan === 'disetujui'
      ? `Setujui pengeluaran ${kategoriLabel(rec.kategori)} senilai ${formatRupiah(rec.nominal)}? Nominal akan dihitung ke anggaran. Keputusan tidak dapat diubah.`
      : `Tolak pengeluaran ${kategoriLabel(rec.kategori)} senilai ${formatRupiah(rec.nominal)}? Akuntan dapat memperbaiki dan mengajukan ulang.`;
    if (!(await showConfirm({ title: label, message: pesan, okText: 'Ya, kirim', destructive: keputusan === 'ditolak' }))) return;
    tombol.forEach((b) => { b.disabled = true; });
    try {
      await request('POST', `/keuangan/pengeluaran/${rec.id}/keputusan`, { body: payloadKeputusanPengeluaran({ keputusan, catatan: catatan.value, versi: rec.versi }), key: newKey(), silent: true });
      toast(keputusan === 'disetujui' ? 'Pengeluaran disetujui.' : 'Pengeluaran ditolak.', 'success');
      resolve();
    } catch (e) {
      if (e?.status === 401) throw e;
      const g = ringkasGalatAksi(e, 'Keputusan gagal dikirim.');
      toast(g.pesan, 'error', 6000);
      err.textContent = g.fields.catatan || g.fields.keputusan || g.fields.nominal || '';
      if (g.muatUlang) { resolve(); return; }
      tombol.forEach((b) => { b.disabled = false; });
    }
  }
  const row = h('div', { class: 'row-actions' });
  for (const k of KEPUTUSAN_PENGELUARAN) {
    const b = h('button', { class: `btn ${k.value === 'disetujui' ? 'btn-primary' : 'btn-danger'}`, type: 'button', onclick: () => kirim(k.value, k.label) }, k.label);
    tombol.push(b);
    row.append(b);
  }
  return h('div', { class: 'panel stack-tight' }, h('h2', { class: 'panel-title' }, 'Keputusan Anda'),
    h('div', { class: 'card-row' }, h('span', { class: 'muted' }, `${kategoriLabel(rec.kategori)} · ${formatValue(rec.tanggal, 'date')}`), h('strong', {}, formatRupiah(rec.nominal))),
    h('p', { class: 'muted small' }, 'Periksa nota di bawah sebelum memutuskan.'),
    h('label', { class: 'label', for: 'catatan-keputusan' }, 'Catatan'), catatan, err, row);
}

function persetujuanPanels(rec) {
  const st = statusPengeluaran(rec);
  const nodes = [];
  if (st === 'diajukan') nodes.push(h('div', { class: 'notice' }, 'Menunggu persetujuan investor. Belum dihitung ke anggaran.'));
  if (st === 'ditolak') nodes.push(h('div', { class: 'alert-danger', role: 'alert' }, h('strong', {}, 'Ditolak investor. '), terakhirCatatan(rec) ?? '-'));
  if (tombolKeputusanPengeluaranTampil(getUser(), rec)) nodes.push(keputusanPanel(rec));
  nodes.push(riwayatPanel(rec));
  return nodes;
}

const pengeluaranModule = defineCrudModule({
  slug: 'keuangan/pengeluaran',
  permissionSlug: SLUG,
  title: 'Pengeluaran',
  icon: '🧾',
  description: 'Pengeluaran operasional dan nota',
  endpoint: '/keuangan/pengeluaran',
  createLabel: '+ Pengeluaran',
  columns: [{ key: 'uraian', primary: true }],
  fields: ({ mode, record }) => (mode === 'detail' ? pengeluaranDetailFields : pengeluaranFields({ custom, record })),
  mapPayload: (v) => pengeluaranPayload(v),
  sertakanVersi: true,
  rowPerms: rowPermsPengeluaran,
  itemTitle: (r) => r.uraian ?? `Pengeluaran #${r.id}`,
  searchPlaceholder: 'Cari uraian pengeluaran...',
  listQuery: pengeluaranQuery,
  listTop: listTopPengeluaran,
  formTop: formTopPengeluaran,
  onSaved: ({ res }) => {
    ringkasanCache.clear();
    const d = res?.data && unwrap(res.data);
    if (d?.status_persetujuan === 'diajukan') toast('Pengeluaran diajukan ke investor untuk disetujui.', 'info', 5000);
    else if (d?.melebihi_anggaran) toast('Pengeluaran melebihi sisa anggaran periode. Tetap dicatat dan ditandai.', 'error', 5000);
  },
  renderCard: (r, href) => rowCard({
    href,
    title: r.uraian,
    sub: [formatValue(r.tanggal, 'date'), r.kategori_label || kategoriLabel(r.kategori), r.nota ? 'nota ✓' : 'tanpa nota'].join(' · '),
    aside: formatRupiah(r.nominal),
    ...badgesPengeluaran(r),
  }),
  detailExtras: (rec, p) => {
    const nodes = [];
    nodes.push(...persetujuanPanels(rec));
    if (dariPembelian(rec)) nodes.push(h('div', { class: 'notice' }, 'Pengeluaran ini berasal dari pembelian bahan terverifikasi sehingga tidak dapat diubah atau dihapus.'));
    if (rec.melebihi_anggaran) nodes.push(h('div', { class: 'alert-danger', role: 'alert' }, 'Pengeluaran ini melebihi sisa anggaran periode saat dicatat.'));
    nodes.push(h('div', { class: 'panel stack-tight' }, h('h2', { class: 'panel-title' }, 'Foto nota'),
      rec.nota ? createAuthImage(rec.nota, { alt: 'Foto nota' }) : h('p', { class: 'muted small' }, 'Tidak ada nota.')));
    const lain = (rec.lampiran || []).filter((l) => l.id !== rec.nota?.lampiran_id);
    // Server menolak lampiran baru pada pengeluaran disetujui (409) atau hasil pembelian (403).
    const terkunci = pesanLampiranTerkunci(rec);
    if (terkunci) {
      // Panel unggah disembunyikan; lampiran yang sudah ada tetap bisa dilihat tanpa tombol tambah.
      if (lain.length) nodes.push(createLampiranPanel({ modul: 'keuangan-pengeluaran', recordId: rec.id, kategori: 'nota', lampiran: lain, canAdd: false, title: 'Lampiran lain' }));
      const info = h('p', { class: 'muted small', role: 'note' });
      info.textContent = terkunci;
      nodes.push(info);
    } else if (lain.length || p.U) {
      nodes.push(createLampiranPanel({ modul: 'keuangan-pengeluaran', recordId: rec.id, kategori: 'nota', lampiran: lain, canAdd: !!p.U, title: 'Lampiran lain' }));
    }
    return nodes;
  },
});
pengeluaranModule.hidden = true;

// ================= Ringkasan =================
function barAnggaran(r) {
  const st = statusAnggaran(r);
  const persen = r.persen ?? hitungPersen(r.anggaran, r.terpakai);
  const lebar = clampPersen(persen);
  const bar = h('div', { class: `progress progress-lg kpi-${st.tone}`, role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': String(Math.round(lebar)), 'aria-valuetext': `${teksPersen(persen)} terpakai, ${st.label}`, 'aria-label': 'Serapan anggaran' },
    h('span', { style: `width:${lebar}%` }), h('i', { class: 'tick', style: `left:${AMBANG_PERINGATAN}%`, 'aria-hidden': 'true', title: 'Ambang peringatan 80%' }));
  return h('div', { class: 'stack-tight' }, bar,
    h('p', { class: 'small' }, `${teksPersen(persen)} terpakai · peringatan otomatis pada 80% dan 100%`),
    h('p', { class: `status-line status-${st.tone}`, role: 'status' }, h('span', { class: `badge ${st.badge}`.trim() }, st.label), ' ', st.teks));
}

function ringkasanPanel(r) {
  const sisaNeg = Number(r.sisa) < 0;
  const row = (label, value, cls = '') => h('div', { class: 'tbl-row' }, h('span', { class: 'muted' }, label), h('strong', { class: `num ${cls}`.trim() }, value));
  return h('div', { class: 'panel stack-tight' },
    row('Total pencairan', formatRupiah(r.anggaran)),
    row('Total pengeluaran (disetujui)', formatRupiah(r.terpakai)),
    row('Sisa anggaran', `${formatRupiah(r.sisa)}${sisaNeg ? ' (minus)' : ''}`, sisaNeg ? 'neg' : 'pos'),
    barAnggaran(r),
    teksMenunggu(r) ? h('div', { class: 'notice', role: 'status' }, h('strong', {}, 'Menunggu persetujuan: '), teksMenunggu(r), ' ',
      h('a', { class: 'link', href: '#/keuangan/pengeluaran', onclick: () => { pengeluaranQuery.status_persetujuan = 'diajukan'; pengeluaranQuery.periode = r.periode || pengeluaranQuery.periode; pengeluaranInit = true; } }, 'Lihat daftar')) : null,
    Number(r.jumlah_melebihi_anggaran) > 0 ? h('div', { class: 'alert-danger', role: 'alert' }, `${r.jumlah_melebihi_anggaran} pengeluaran ditandai melebihi anggaran.`) : null);
}

function kategoriPanel(r) {
  const seri = seriKategori(r);
  const panel = h('div', { class: 'panel stack-tight' }, h('h2', { class: 'panel-title' }, 'Pengeluaran per kategori'));
  if (!seri.total) { panel.append(h('p', { class: 'muted small' }, 'Belum ada pengeluaran pada periode ini.')); return { el: panel, destroy() {} }; }
  const canvas = h('canvas', { role: 'img', 'aria-label': `Pengeluaran per kategori: ${seri.items.map((x) => `${x.label} ${teksPersen(x.persen)}`).join(', ')}` });
  const legend = h('ul', { class: 'legend legend-1' }, seri.items.map((x, i) => h('li', {}, h('span', { class: 'dot', style: `background:${PALET[i % PALET.length]}`, 'aria-hidden': 'true' }), `${x.label} ${teksPersen(x.persen)} · `, h('strong', {}, formatRupiah(x.value)))));
  panel.append(h('div', { class: 'chart-box chart-box-sm' }, canvas), legend);
  let chart = null;
  let dead = false;
  renderChart(canvas, { type: 'doughnut', data: { labels: seri.items.map((x) => x.label), datasets: [{ data: seri.items.map((x) => x.value), backgroundColor: seri.items.map((_, i) => PALET[i % PALET.length]), borderWidth: 0 }] }, options: { plugins: { legend: { display: false } }, cutout: '62%' } })
    .then((c) => { if (dead) c.destroy(); else chart = c; }).catch(() => { /* legenda teks tetap tersedia */ });
  return { el: panel, destroy: () => { dead = true; chart?.destroy(); } };
}

async function ringkasanScreen(ctx) {
  const user = getUser();
  const admin = user?.role === 'admin';
  const periode = ctx.query.get('periode') || periodeSekarang();
  const sppgId = ctx.query.get('sppg_id') || '';
  ctx.setTitle(`Keuangan · ${labelPeriode(periode)}`);
  const goto = (p, s) => navigate(`/keuangan?periode=${encodeURIComponent(p)}${s ? `&sppg_id=${encodeURIComponent(s)}` : ''}`, { replace: true });

  const pick = h('input', { type: 'month', id: 'f-periode', value: periode, 'aria-label': 'Periode (bulan)' });
  pick.addEventListener('change', () => pick.value && goto(pick.value, sppgId));
  const head = h('div', { class: 'stack-tight panel' }, h('label', { class: 'label', for: 'f-periode' }, 'Periode'), pick);
  if (admin) {
    const sel = h('select', { id: 'f-sppg', 'aria-label': 'SPPG' }, h('option', { value: '' }, '- Pilih SPPG -'));
    fetchList('/sppg', { perPage: 100 }).then(({ items }) => { items.forEach((s) => sel.append(h('option', { value: s.id }, s.nama))); sel.value = sppgId; }).catch(() => {});
    sel.addEventListener('change', () => goto(periode, sel.value));
    head.append(h('label', { class: 'label', for: 'f-sppg' }, 'SPPG'), sel);
  }
  ctx.el.append(keuanganTabs('ringkasan'), head);
  if (admin && !sppgId) { ctx.el.append(h('div', { class: 'notice' }, 'Pilih SPPG untuk melihat ringkasan anggarannya.')); return; }

  const r = unwrap(await api.get('/keuangan/ringkasan', { periode, sppg_id: sppgId }, { silent: true }).catch((e) => { if (e?.status === 401) throw e; ctx.el.append(h('div', { class: 'alert-danger', role: 'alert' }, errorMessage(e, 'Ringkasan gagal dimuat.'))); return null; }));
  if (ctx.isStale() || !r) return;
  const kat = kategoriPanel(r);
  ctx.el.append(ringkasanPanel(r), kat.el);

  // Transaksi terbaru (pengeluaran)
  const terbaru = h('div', { class: 'cards' });
  ctx.el.append(h('h2', { class: 'section-title' }, 'Transaksi terbaru'), terbaru);
  try {
    const { items } = await fetchList('/keuangan/pengeluaran', { perPage: 5, extra: { periode, sppg_id: sppgId } });
    if (ctx.isStale()) return kat.destroy;
    if (!items.length) terbaru.append(h('p', { class: 'muted center pad' }, 'Belum ada pengeluaran.'));
    items.forEach((x) => terbaru.append(rowCard({
      href: `#/keuangan/pengeluaran/${x.id}`, title: x.uraian,
      sub: [formatValue(x.tanggal, 'date'), x.kategori_label || kategoriLabel(x.kategori), x.nota ? 'nota ✓' : null].filter(Boolean).join(' · '),
      aside: formatRupiah(x.nominal), ...badgesPengeluaran(x),
    })));
    ctx.el.append(h('a', { class: 'btn btn-block', href: '#/keuangan/pengeluaran' }, 'Lihat semua pengeluaran'));
  } catch (e) {
    if (e?.status === 401) throw e;
    terbaru.append(h('p', { class: 'muted center pad' }, errorMessage(e, 'Transaksi gagal dimuat.')));
  }
  if (me().C) {
    ctx.el.append(h('a', { class: 'btn btn-block', href: '#/keuangan/pencairan/baru' }, '+ Pencairan dana'));
    setMainButton({ text: '+ Tambah Pengeluaran', onClick: () => navigate('/keuangan/pengeluaran/baru') });
  }
  return kat.destroy;
}

const ringkasanModule = {
  slug: SLUG,
  title: 'Keuangan',
  icon: '💰',
  description: 'Anggaran, pencairan dan pengeluaran',
  permissionSlug: SLUG,
  register() {
    route('/keuangan', ringkasanScreen, { title: 'Keuangan', guard: () => me().R });
  },
};

export default [pencairanModule, pengeluaranModule, ringkasanModule];

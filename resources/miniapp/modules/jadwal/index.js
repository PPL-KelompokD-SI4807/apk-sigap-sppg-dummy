// Jadwal Produksi dan Distribusi (fitur 9, PBI 9). Mockup: Gambar 26.
// Dua modul berbagi slug izin 'jadwal': produksi di #/jadwal, distribusi di #/jadwal/distribusi.
import { defineCrudModule } from '../../components/crud-module.js';
import { rowCard } from '../../components/row-card.js';
import { navTabs } from '../../components/nav-tabs.js';
import { h, formatValue } from '../../core/dom.js';
import { api, request, newKey } from '../../core/api.js';
import { getUser } from '../../core/auth.js';
import { userPerms } from '../../core/permissions.js';
import { route, resolve } from '../../core/router.js';
import { showConfirm, toast } from '../../core/ui.js';
import { errorMessage } from '../../core/errors.js';
import {
  STATUS_PRODUKSI, STATUS_DISTRIBUSI, statusProduksiLabel, statusDistribusiLabel, statusBadge, transisiValid, aksiLabel,
  unitBoleh, rowPermsJadwal, hitungKapasitas, sisaPorsiDistribusi,
  produksiFields, produksiDetailFields, produksiPayload, distribusiFields, distribusiDetailFields, distribusiPayload,
  statusKebutuhan, ringkasKebutuhan, catatanTidakDapat,
} from './schema.js';

const unwrap = (r) => (r && r.data !== undefined && !Array.isArray(r) ? r.data : r);
const SLUG = 'jadwal';
const me = () => userPerms(getUser(), SLUG);
const jam = (v) => (v ? String(v).slice(0, 5) : null);
const porsi = (n) => `${Number(n ?? 0).toLocaleString('id-ID')} porsi`;

export function jadwalTabs(active) {
  const u = getUser();
  const items = [];
  if (unitBoleh(u, 'produksi')) items.push({ key: 'produksi', href: '#/jadwal', label: 'Produksi' });
  if (unitBoleh(u, 'distribusi')) items.push({ key: 'distribusi', href: '#/jadwal/distribusi', label: 'Distribusi' });
  return items.length > 1 ? navTabs(items, active, 'Jadwal') : null;
}

/** Tombol aksi transisi status: hanya transisi valid; berangkat ditahan bila mutu belum Layak. */
function aksiStatus(kind, rec, base, p) {
  const next = transisiValid(kind, rec.status);
  if (!p.U || !next.length) return null;
  const box = h('div', { class: 'stack-tight' }, h('h2', { class: 'panel-title' }, 'Ubah status'));
  const row = h('div', { class: 'row-actions' });
  for (const to of next) {
    const gagal = to === 'dibatalkan';
    const ditahan = kind === 'distribusi' && to === 'berangkat' && rec.layak_distribusi === false;
    const btn = h('button', { class: `btn ${gagal ? 'btn-danger' : 'btn-primary'}`, type: 'button', disabled: ditahan }, aksiLabel(kind, to));
    btn.addEventListener('click', async () => {
      const nama = kind === 'produksi' ? statusProduksiLabel(to) : statusDistribusiLabel(to);
      if (!(await showConfirm({ title: aksiLabel(kind, to), message: `Ubah status jadwal menjadi "${nama}"?`, okText: 'Ya, ubah', destructive: gagal }))) return;
      btn.disabled = true;
      try {
        await request('PATCH', `${base}/${rec.id}`, { body: { status: to }, key: newKey(), silent: true });
        toast(`Status menjadi ${nama}.`, 'success');
        resolve();
      } catch (e) {
        if (e?.status === 401) throw e;
        toast(errorMessage(e, 'Status gagal diubah.'), 'error', 5000);
        btn.disabled = false;
      }
    });
    row.append(btn);
  }
  box.append(row);
  if (kind === 'distribusi' && next.includes('berangkat') && rec.layak_distribusi === false) {
    box.append(h('div', { class: 'alert-danger' }, 'Keberangkatan ditahan: pemeriksaan mutu terbaru belum berstatus Layak.'));
  }
  return h('div', { class: 'panel' }, box);
}

// ---- Layar kebutuhan bahan ----
async function kebutuhanScreen(ctx) {
  const d = unwrap(await api.get(`/jadwal/${ctx.params.id}/kebutuhan-bahan`));
  if (ctx.isStale()) return;
  ctx.setTitle('Kebutuhan Bahan');
  const r = ringkasKebutuhan(d);
  ctx.el.append(h('div', { class: 'panel' }, h('strong', {}, d.menu?.nama_menu ?? 'Menu'),
    h('div', { class: 'muted' }, `${formatValue(d.tanggal_produksi, 'date')} · target ${porsi(d.target_porsi)}`)));
  if (r.kurang) ctx.el.append(h('div', { class: 'alert-danger' }, `${r.kurang} bahan kurang dari stok. Staf Gudang perlu menambah stok sebelum produksi.`));
  else if (r.tidakDapat) ctx.el.append(h('div', { class: 'notice' }, 'Bahan yang dapat dibandingkan sudah cukup, tetapi sebagian bahan tidak dapat dibandingkan (lihat catatan).'));
  else ctx.el.append(h('div', { class: 'alert-ok' }, 'Seluruh bahan cukup.'));
  const list = h('div', { class: 'cards' });
  for (const it of d.bahan ?? []) {
    const s = statusKebutuhan(it);
    list.append(h('div', { class: 'panel' },
      h('div', { class: 'card-title' }, h('span', {}, it.nama),
        h('span', { class: `badge ${{ kurang: 'badge-danger', cukup: 'badge-ok', tidak_dapat: 'badge-warn' }[s]}` }, { kurang: 'Kurang', cukup: 'Cukup', tidak_dapat: 'Tidak dapat dibandingkan' }[s])),
      s === 'tidak_dapat'
        ? h('p', { class: 'muted small' }, catatanTidakDapat(it))
        : h('div', { class: 'stack-tight' },
          h('div', { class: 'card-row' }, h('span', { class: 'muted' }, 'Kebutuhan'), h('span', {}, `${Number(it.kebutuhan).toLocaleString('id-ID', { maximumFractionDigits: 3 })} ${it.satuan}`)),
          h('div', { class: 'card-row' }, h('span', { class: 'muted' }, 'Stok'), h('span', {}, `${Number(it.stok).toLocaleString('id-ID', { maximumFractionDigits: 3 })} ${it.satuan}`)),
          s === 'kurang' ? h('div', { class: 'card-row bad' }, h('strong', {}, 'Kurang'), h('strong', {}, `${Number(it.kekurangan).toLocaleString('id-ID', { maximumFractionDigits: 3 })} ${it.satuan}`)) : null)));
  }
  if (!(d.bahan ?? []).length) list.append(h('p', { class: 'muted center pad' }, 'Menu belum memiliki komposisi bahan.'));
  ctx.el.append(list);
}

// ---- Info di atas form produksi: sisa kapasitas harian ----
function produksiFormTop({ record, form, collect }) {
  const box = h('div', { class: 'stack-tight', 'aria-live': 'polite' });
  let kapasitas = null;
  let tick = 0;
  const sppgId = getUser()?.sppg_id;
  const kap = sppgId ? api.get(`/sppg/${sppgId}`, null, { silent: true }).then((r) => { kapasitas = unwrap(r)?.kapasitas_porsi_harian ?? null; }).catch(() => {}) : Promise.resolve();
  async function refresh() {
    const my = ++tick;
    const v = collect();
    await kap;
    if (!v.tanggal_produksi) return;
    let jadwal = [];
    try { jadwal = (await api.get('/jadwal', { tanggal: v.tanggal_produksi, per_page: 100 }, { silent: true }))?.data ?? []; } catch { /* abaikan */ }
    if (my !== tick) return;
    const k = hitungKapasitas({ kapasitas, jadwal, excludeId: record?.id ?? null, target: v.target_porsi });
    box.replaceChildren();
    if (k.kapasitas == null) return;
    box.append(h('div', { class: k.melebihi ? 'alert-danger' : 'notice' },
      `Kapasitas harian ${porsi(k.kapasitas)} · sudah terjadwal ${porsi(k.terjadwal)} · sisa ${porsi(k.sisa)}.`,
      k.melebihi ? ` Target ${porsi(v.target_porsi)} melebihi sisa kapasitas; server akan menolak.` : ''));
  }
  form.addEventListener('change', refresh);
  form.addEventListener('input', (e) => { if (e.target?.name === 'target_porsi') refresh(); });
  refresh();
  return box;
}

function distribusiFormTop({ record, form, collect, values }) {
  const box = h('div', { class: 'stack-tight', 'aria-live': 'polite' });
  let tick = 0;
  async function refresh() {
    const my = ++tick;
    const id = record?.jadwal_produksi_id ?? collect().jadwal_produksi_id;
    if (!id) return box.replaceChildren();
    let j = null;
    try { j = unwrap(await api.get(`/jadwal/${id}`, null, { silent: true })); } catch { /* abaikan */ }
    if (my !== tick || !j) return;
    const jumlah = collect().jumlah_porsi;
    const sisa = sisaPorsiDistribusi(j, record?.jumlah_porsi ?? 0);
    const lebih = jumlah != null && Number(jumlah) > sisa;
    box.replaceChildren(
      h('div', { class: lebih ? 'alert-danger' : 'notice' }, `Target produksi ${porsi(j.target_porsi)} · sisa dapat dialokasikan ${porsi(sisa)}.`, lebih ? ' Jumlah porsi melebihi sisa; server akan menolak.' : ''),
      j.layak_distribusi ? null : h('div', { class: 'notice' }, 'Pemeriksaan mutu belum Layak: keberangkatan akan ditahan sampai hasil Layak.'));
  }
  form.addEventListener('change', refresh);
  form.addEventListener('input', (e) => { if (e.target?.name === 'jumlah_porsi') refresh(); });
  refresh();
  return box;
}

// ---- Modul distribusi (didaftarkan lebih dulu agar /jadwal/distribusi tidak ditangkap /jadwal/:id) ----
const distribusiModule = defineCrudModule({
  slug: 'jadwal/distribusi',
  permissionSlug: SLUG,
  title: 'Jadwal Distribusi',
  icon: '🚚',
  description: 'Pengantaran ke titik layanan',
  endpoint: '/jadwal/distribusi',
  columns: [{ key: 'titik_layanan', primary: true, format: (r) => r.titik_layanan?.nama ?? `#${r.id}` }],
  fields: ({ mode, record }) => (mode === 'detail' ? distribusiDetailFields : distribusiFields({ mode, record })),
  mapPayload: (v, { mode }) => { const o = distribusiPayload(v); if (mode === 'update') delete o.jadwal_produksi_id; return o; },
  itemTitle: (r) => `Distribusi ${r.titik_layanan?.nama ?? `#${r.id}`}`,
  searchPlaceholder: 'Cari titik layanan...',
  filters: { param: 'status', label: 'Status distribusi', options: [{ value: '', label: 'Semua' }, ...STATUS_DISTRIBUSI] },
  listRedirect: (u) => (unitBoleh(u, 'distribusi') ? null : '/jadwal'),
  listTop: () => jadwalTabs('distribusi'),
  prefill: (q) => (q.get('jadwal') ? { jadwal_produksi_id: q.get('jadwal') } : {}),
  formTop: distribusiFormTop,
  renderCard: (r, href) => rowCard({
    href,
    title: r.titik_layanan?.nama ?? `Distribusi #${r.id}`,
    sub: [formatValue(r.tanggal_distribusi, 'date'), jam(r.jam_berangkat) ? `berangkat ${jam(r.jam_berangkat)}` : null, r.armada?.plat_nomor, r.pengemudi?.nama].filter(Boolean).join(' · '),
    aside: Number(r.jumlah_porsi ?? 0).toLocaleString('id-ID'),
    asideSub: 'porsi',
    badge: { text: r.status_label || statusDistribusiLabel(r.status), cls: statusBadge(r.status) },
  }),
  rowPerms: (rec, p) => rowPermsJadwal('distribusi', getUser(), rec, p),
  detailExtras: (rec, p) => {
    const nodes = [];
    if (rec.status === 'siap' && rec.layak_distribusi === false) nodes.push(h('div', { class: 'alert-danger' }, 'Distribusi ditahan: pemeriksaan mutu belum Layak.'));
    nodes.push(aksiStatus('distribusi', rec, '/jadwal/distribusi', p));
    return nodes.filter(Boolean);
  },
});
distribusiModule.hidden = true;

// ---- Modul produksi ----
const produksiBase = defineCrudModule({
  slug: SLUG,
  title: 'Jadwal',
  icon: '📅',
  description: 'Produksi dan distribusi harian',
  endpoint: '/jadwal',
  columns: [{ key: 'menu', primary: true, format: (r) => r.menu?.nama_menu ?? `#${r.id}` }],
  fields: ({ mode, record }) => (mode === 'detail' ? produksiDetailFields : produksiFields({ mode, record })),
  mapPayload: produksiPayload,
  itemTitle: (r) => `Produksi ${r.menu?.nama_menu ?? `#${r.id}`}`,
  searchPlaceholder: 'Cari menu atau catatan...',
  filters: { param: 'status', label: 'Status produksi', options: [{ value: '', label: 'Semua' }, ...STATUS_PRODUKSI] },
  listRedirect: (u) => (unitBoleh(u, 'produksi') ? null : '/jadwal/distribusi'),
  listTop: () => jadwalTabs('produksi'),
  formTop: produksiFormTop,
  onSaved: ({ mode, res }) => {
    const kurang = res?.data?.kekurangan_bahan;
    if (mode === 'create' && kurang?.length) toast(`Stok ${kurang.length} bahan kurang untuk target ini. Staf Gudang diberi tahu.`, 'info', 6000);
  },
  renderCard: (r, href) => rowCard({
    href,
    title: r.menu?.nama_menu ?? `Jadwal #${r.id}`,
    sub: [formatValue(r.tanggal_produksi, 'date'), jam(r.jam_mulai_masak) ? `masak ${jam(r.jam_mulai_masak)}` : null, r.status_mutu === 'tidak_layak' ? 'mutu tidak layak' : null].filter(Boolean).join(' · '),
    aside: Number(r.target_porsi ?? 0).toLocaleString('id-ID'),
    asideSub: 'porsi',
    badge: { text: r.status_label || statusProduksiLabel(r.status), cls: statusBadge(r.status) },
  }),
  rowPerms: (rec, p) => rowPermsJadwal('produksi', getUser(), rec, p),
  detailExtras: (rec, p) => {
    const nodes = [];
    if (rec.status_mutu === 'tidak_layak') nodes.push(h('div', { class: 'alert-danger' }, 'Hasil mutu terbaru TIDAK LAYAK: distribusi ditahan sampai pemeriksaan ulang Layak.'));
    nodes.push(aksiStatus('produksi', rec, '/jadwal', p));
    const aktif = rec.status !== 'dibatalkan';
    const links = h('div', { class: 'row-actions' });
    links.append(h('a', { class: 'btn', href: `#/jadwal/${rec.id}/kebutuhan` }, 'Kebutuhan bahan'));
    if (aktif && userPerms(getUser(), 'jadwal').C && unitBoleh(getUser(), 'distribusi')) links.append(h('a', { class: 'btn', href: `#/jadwal/distribusi/baru?jadwal=${rec.id}` }, '+ Distribusi'));
    if (aktif && userPerms(getUser(), 'log-produksi').C) links.append(h('a', { class: 'btn', href: `#/log-produksi/baru?jadwal=${rec.id}` }, 'Catat log produksi'));
    if (aktif && userPerms(getUser(), 'mutu').C) links.append(h('a', { class: 'btn', href: `#/mutu/baru?jadwal=${rec.id}` }, 'Periksa mutu'));
    nodes.push(links);
    return nodes.filter(Boolean);
  },
});

const produksiModule = {
  ...produksiBase,
  register() {
    route('/jadwal/:id/kebutuhan', kebutuhanScreen, { title: 'Kebutuhan Bahan', guard: () => me().R });
    produksiBase.register();
  },
};

export default [distribusiModule, produksiModule];

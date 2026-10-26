// Menu dan Gizi (fitur 8, PBI 8): menu + komposisi + ringkasan gizi, tab Standar gizi. Mockup: Gambar 25.
import { defineCrudModule } from '../../components/crud-module.js';
import { rowCard } from '../../components/row-card.js';
import { navTabs } from '../../components/nav-tabs.js';
import { h, clear, formatValue } from '../../core/dom.js';
import { api } from '../../core/api.js';
import { toast } from '../../core/ui.js';
import { GIZI, KELOMPOK, kelompokLabel, menuFields, menuDetailFields, mapPayload, hitungPeringatanGizi, standarFields, standarDetailFields, standarPayload, rentang } from './schema.js';
import { komposisiField } from './komposisi.js';
import { statusBadges, persetujuanPanel, rincianBiayaScreen } from './persetujuan.js';
import { route } from '../../core/router.js';
import { userPerms } from '../../core/permissions.js';
import { getUser } from '../../core/auth.js';

const unwrap = (r) => (r && r.data !== undefined && !Array.isArray(r) ? r.data : r);

export const menuTabs = (active) => navTabs([
  { key: 'menu', href: '#/menu-gizi', label: 'Menu' },
  { key: 'standar', href: '#/menu-gizi/standar-gizi', label: 'Standar gizi' },
], active, 'Menu dan Gizi');

let standarCache = null;
const loadStandar = async () => {
  if (standarCache) return standarCache;
  try { standarCache = (await api.get('/menu-gizi/standar-gizi', { per_page: 100 }, { silent: true }))?.data ?? []; } catch { standarCache = []; }
  return standarCache;
};

const peringatanList = (items) => h('div', { class: 'stack-tight' }, items.map((p) => h('div', { class: 'notice' }, `⚠ ${p.pesan}`)));

/** Panel ringkasan gizi dari GET /menu-gizi/{id}/ringkasan-gizi. */
async function ringkasanPanel(rec) {
  const box = h('div', { class: 'panel' }, h('h2', { class: 'panel-title' }, 'Ringkasan gizi per porsi'));
  try {
    const r = unwrap(await api.get(`/menu-gizi/${rec.id}/ringkasan-gizi`, null, { silent: true }));
    const tbl = h('div', { class: 'tbl' });
    for (const g of GIZI) {
      const bad = (r.peringatan || []).find((p) => p.kolom === g.kolom);
      tbl.append(h('div', { class: `tbl-row${bad ? ' bad' : ''}` },
        h('span', {}, g.label, h('div', { class: 'muted small' }, r.standar ? `Standar ${rentang(r.standar, g)}` : 'Standar belum diatur')),
        h('span', { class: 'num' }, `${Number(r.per_porsi?.[g.kolom] ?? 0).toLocaleString('id-ID')} ${g.unit}`),
        bad ? h('span', { class: 'badge badge-warn' }, bad.status === 'kurang' ? 'Di bawah standar' : 'Di atas standar') : null));
    }
    box.append(tbl);
    if (!r.standar_tersedia) box.append(h('p', { class: 'muted small' }, 'Standar gizi kelompok ini belum dikonfigurasi, jadi perbandingan belum dapat dilakukan.'));
    else if (r.sesuai_standar) box.append(h('div', { class: 'alert-ok' }, 'Seluruh nilai gizi sesuai standar.'));
    if (r.peringatan?.length) box.append(peringatanList(r.peringatan));
    box.append(h('p', { class: 'muted small' }, `Total takaran bahan ${Number(r.total_gram_per_porsi ?? 0).toLocaleString('id-ID')} g per porsi.`));
  } catch { box.append(h('p', { class: 'muted' }, 'Ringkasan gizi gagal dimuat.')); }
  return box;
}

const komposisiPanel = (rec) => h('div', { class: 'panel' }, h('h2', { class: 'panel-title' }, 'Komposisi bahan'),
  (rec.bahan || []).length
    ? h('div', { class: 'tbl' }, rec.bahan.map((b) => h('div', { class: 'tbl-row' }, h('span', {}, b.nama), h('span', { class: 'num' }, `${Number(b.gram_per_porsi).toLocaleString('id-ID')} g`))))
    : h('p', { class: 'muted' }, 'Belum ada komposisi bahan.'));

// Pratinjau peringatan di atas form: dihitung dari standar kelompok terpilih; tidak memblokir simpan.
function formTop({ form, collect }) {
  const box = h('div', { class: 'stack-tight', 'aria-live': 'polite' });
  let tick = 0;
  async function refresh() {
    const my = ++tick;
    const v = collect();
    const standar = (await loadStandar()).find((s) => s.kelompok_sasaran === v.kelompok_sasaran);
    if (my !== tick) return;
    clear(box);
    if (!v.kelompok_sasaran) return;
    if (!standar) return box.append(h('p', { class: 'muted small' }, 'Standar gizi kelompok ini belum diatur, perbandingan dilewati.'));
    const w = hitungPeringatanGizi(v, standar);
    if (w.length) box.append(h('p', { class: 'small' }, 'Peringatan gizi (tidak menghalangi penyimpanan):'), peringatanList(w));
    else box.append(h('div', { class: 'alert-ok' }, 'Nilai gizi sesuai standar.'));
  }
  form.addEventListener('input', refresh);
  form.addEventListener('change', refresh);
  refresh();
  return box;
}

const standarModule = defineCrudModule({
  slug: 'menu-gizi/standar-gizi',
  permissionSlug: 'menu-gizi',
  title: 'Standar Gizi',
  icon: '📏',
  description: 'Rentang gizi per kelompok sasaran',
  endpoint: '/menu-gizi/standar-gizi',
  columns: [{ key: 'kelompok_sasaran', primary: true, format: (r) => kelompokLabel(r.kelompok_sasaran) }],
  fields: ({ mode }) => (mode === 'detail' ? standarDetailFields : standarFields()),
  mapPayload: standarPayload,
  itemTitle: (r) => `Standar ${kelompokLabel(r.kelompok_sasaran)}`,
  listTop: () => menuTabs('standar'),
  createLabel: '+ Standar',
  onSaved: () => { standarCache = null; },
  renderCard: (r, href) => rowCard({
    href,
    title: kelompokLabel(r.kelompok_sasaran),
    sub: GIZI.slice(0, 3).map((g) => `${g.label} ${rentang(r, g)}`).join(' · '),
  }),
});
// Slug tak muncul di menu beranda; layar dibuka lewat tab pada daftar menu.
standarModule.hidden = true;

const menuModule = defineCrudModule({
  slug: 'menu-gizi',
  title: 'Menu dan Gizi',
  icon: '🍱',
  description: 'Menu, komposisi bahan, dan standar gizi',
  endpoint: '/menu-gizi',
  columns: [
    { key: 'nama_menu', primary: true },
    { key: 'kelompok_sasaran', badge: true, format: (r) => kelompokLabel(r.kelompok_sasaran) },
  ],
  fields: ({ mode }) => (mode === 'detail' ? menuDetailFields : [...menuFields(), komposisiField]),
  mapPayload,
  searchPlaceholder: 'Cari nama menu atau siklus...',
  filters: { param: 'kelompok_sasaran', label: 'Kelompok sasaran', options: [{ value: '', label: 'Semua' }, ...KELOMPOK] },
  listTop: () => menuTabs('menu'),
  renderCard: (r, href) => rowCard({
    href,
    title: r.nama_menu,
    sub: [kelompokLabel(r.kelompok_sasaran), r.siklus, r.tanggal_sajian ? formatValue(r.tanggal_sajian, 'date') : null, `${r.jumlah_bahan ?? 0} bahan`].filter(Boolean).join(' · '),
    aside: Number(r.energi_kkal ?? 0).toLocaleString('id-ID'),
    asideSub: 'kkal',
    badges: statusBadges(r),
  }),
  formTop,
  onSaved: ({ res }) => {
    const w = unwrap(res.data)?.peringatan_gizi;
    if (w?.length) toast(`Peringatan gizi: ${w.map((p) => p.pesan).join(' ')}`, 'info', 6000);
  },
  detailExtras: async (rec) => [persetujuanPanel(rec), komposisiPanel(rec), await ringkasanPanel(rec)],
});

const menuDenganRincian = {
  ...menuModule,
  register() {
    route('/menu-gizi/:id/rincian-biaya', rincianBiayaScreen, { title: 'Rincian Biaya Menu', guard: () => userPerms(getUser(), 'menu-gizi').R });
    menuModule.register();
  },
};

// Standar harus terdaftar sebelum /menu-gizi/:id.
export default [standarModule, menuDenganRincian];

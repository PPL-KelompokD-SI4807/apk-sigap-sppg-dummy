// Dashboard Mini App (R-07). Rute: #/dashboard. Deep link: ?r=/dashboard.
// GET /api/v1/dashboard?rentang=7|30&sppg_id=<id>; kartu dirender dari data.widgets sesuai peran.
import { h, formatValue } from '../../core/dom.js';
import { api } from '../../core/api.js';
import { getUser } from '../../core/auth.js';
import { route } from '../../core/router.js';
import { getSppgAktif, setSppgAktif } from '../../core/sppg-aktif.js';
import { SLUG, RENTANG, RENTANG_BAWAAN, bangunQuery, widgetTampil, pilihanSppg, labelMode, pesanGalat, rentangValid } from './logika.js';
import { renderKartu, buatPengelolaGrafik } from './view.js';

const unwrap = (r) => (r && r.data !== undefined && !Array.isArray(r) ? r.data : r);

/**
 * Tanpa sppg_id, investor/admin ingin mode gabungan; header X-SPPG-Id dari pilihan SPPG aktif akan
 * mempersempitnya. request() menyusun header secara sinkron, jadi pilihan dilepas sesaat lalu dipulihkan.
 */
function ambilDashboard(query, role) {
  const lepas = query.sppg_id == null && (role === 'investor' || role === 'admin');
  const simpan = getSppgAktif();
  if (lepas && simpan != null) setSppgAktif(null);
  try {
    return api.get('/dashboard', query, { silent: true });
  } finally {
    if (lepas && simpan != null) setSppgAktif(simpan);
  }
}

function dashboardScreen(ctx) {
  const user = getUser();
  const mgr = buatPengelolaGrafik();
  let rentang = rentangValid(ctx.query.get('rentang') ?? RENTANG_BAWAAN);
  let sppgId = null;
  let nomor = 0;
  let pilihanTerakhir = [];

  const tombol = RENTANG.map((r) => h('button', {
    class: 'seg-btn', type: 'button', 'aria-pressed': 'false', onclick: () => { if (r !== rentang) { rentang = r; muat(); } },
  }, `${r} hari`));
  const segmen = h('div', { class: 'seg', role: 'group', 'aria-label': 'Rentang tren' }, tombol);
  const pilihWadah = h('div', { class: 'field', hidden: true });
  const meta = h('div', { class: 'stack-tight' });
  const isi = h('div', { class: 'stack' });
  ctx.el.append(h('div', { class: 'panel stack-tight' }, segmen, pilihWadah, meta), isi);

  const tandaiRentang = () => tombol.forEach((b, i) => { const aktif = RENTANG[i] === rentang; b.classList.toggle('active', aktif); b.setAttribute('aria-pressed', String(aktif)); });

  function gambarPemilih(pilihan) {
    if (user?.role !== 'investor' || pilihan.length < 1) { pilihWadah.hidden = true; return; }
    // Hanya digambar ulang bila daftar berubah, agar fokus pemilih tidak hilang.
    const kunci = JSON.stringify(pilihan.map((s) => [s.id, s.nama]));
    if (kunci === JSON.stringify(pilihanTerakhir.map((s) => [s.id, s.nama]))) return;
    pilihanTerakhir = pilihan;
    const sel = h('select', { id: 'dash-sppg', 'aria-label': 'Pilih SPPG', onchange: (e) => { sppgId = e.target.value ? Number(e.target.value) : null; muat(); } },
      h('option', { value: '' }, 'Semua SPPG (gabungan)'),
      pilihan.map((s) => h('option', { value: String(s.id), selected: s.id === sppgId }, s.nama ?? s.kode ?? `SPPG ${s.id}`)));
    pilihWadah.replaceChildren(h('label', { class: 'label', for: 'dash-sppg' }, 'SPPG'), sel);
    pilihWadah.hidden = false;
  }

  function gambarMeta(d) {
    const baris = [`Mode: ${labelMode(d.mode)}`, `Rentang tren: ${d.rentang_hari ?? rentang} hari`, `Diperbarui: ${formatValue(d.dibuat_pada, 'datetime')}`];
    const nama = (Array.isArray(d.sppg) ? d.sppg : []).map((s) => s.nama).filter(Boolean).join(', ');
    meta.replaceChildren(h('p', { class: 'muted small' }, baris.join(' · ')), nama ? h('p', { class: 'muted small' }, `SPPG: ${nama}`) : null);
  }

  async function muat() {
    const saya = ++nomor;
    mgr.hancurkan();
    tandaiRentang();
    isi.replaceChildren(h('p', { class: 'muted pad center', role: 'status' }, 'Memuat dashboard...'));
    try {
      const d = unwrap(await ambilDashboard(bangunQuery({ rentang, sppgId }), user?.role));
      if (saya !== nomor || ctx.isStale()) return;
      gambarPemilih(pilihanSppg(d));
      gambarMeta(d);
      const kartu = widgetTampil(d.widgets);
      if (!kartu.length) {
        isi.replaceChildren(h('div', { class: 'empty' }, h('h2', {}, 'Belum ada ringkasan'),
          h('p', { class: 'muted' }, 'Belum ada ringkasan untuk akun Anda. Hubungi Kepala SPPG agar Anda ditempatkan pada unit kerja.')));
        return;
      }
      isi.replaceChildren(...kartu.map(([k, w]) => renderKartu(k, w, mgr)));
    } catch (e) {
      if (saya !== nomor || ctx.isStale()) return;
      if (e?.status === 401) { isi.replaceChildren(); return; }
      isi.replaceChildren(h('div', { class: 'empty' }, h('h2', {}, 'Dashboard gagal dimuat'),
        h('p', { class: 'muted', role: 'alert' }, pesanGalat(e)),
        e?.status === 403 ? null : h('button', { class: 'btn', type: 'button', onclick: muat }, 'Coba lagi')));
    }
  }

  muat();
  return () => { nomor++; mgr.tutup(); };
}

export default {
  slug: SLUG,
  title: 'Dashboard',
  icon: '📊',
  description: 'Ringkasan operasional sesuai peran',
  hidden: true, // tidak ada slug izin R/W; tautan di beranda (home.js), rute untuk semua peran login penuh
  register() {
    route(`/${SLUG}`, dashboardScreen, { title: 'Dashboard', guard: () => !!getUser() });
  },
};

// Beranda WEB (R04-8 PR3): pengguna, peran, SPPG, dan menu modul. Menu = hasil menuUntuk() dari peta hak SERVER
// (GET /auth/me); klien tidak menyimpan matriks hak. Tanpa fitur Telegram-only (draf AI, ?r=, share t.me, undangan, antrean offline).
import { h, formatValue } from './core/dom.js';
import { ikon, ikonAtauEmoji } from './core/ikon-web.js';
import { roleLabel, petaValid, dashboardTersedia, agregatBoleh, multiSppg } from './core/permissions.js';
import { getSppgAktif } from './core/sppg-aktif.js'; // build web: versi memori (sppg-aktif-web.js)
import { api } from './core/transport.js'; // build web: cookie (transport-web.js)

export const PESAN_SEGERA = 'Segera tersedia di web';
export const PESAN_PETA_RUSAK = 'Hak akses akun Anda tidak dapat dimuat, jadi menu disembunyikan. Muat ulang halaman, atau hubungi Admin bila berulang.';

const kunciIkon = (href) => String(href).replace(/^#\//, '');
const kartu = (href, emoji, judul, deskripsi) => h('a', { class: 'menu-card', href },
  h('span', { class: 'menu-icon', 'aria-hidden': 'true' }, ikonAtauEmoji(kunciIkon(href), emoji, { ukuran: 20 })),
  h('span', { class: 'menu-title' }, judul),
  deskripsi ? h('span', { class: 'menu-desc' }, deskripsi) : null);

/** Kartu pintasan non-modul, ditentukan peta server (dashboard, agregat) + selalu: Sesi dan Browser. */
export function pintasanWeb(user) {
  const p = [];
  if (dashboardTersedia(user)) p.push({ href: '#/dashboard', ikon: '\u{1F4CA}', judul: 'Dashboard', deskripsi: 'Lihat dashboard sesuai peran' });
  if (agregatBoleh(user, 'penerima-manfaat')) p.push({ href: '#/penerima-manfaat-agregat', ikon: '\u{1F9D2}', judul: 'Ringkasan Penerima Manfaat', deskripsi: 'Jumlah per kelompok dan titik layanan' });
  return p;
}

const angka = (v) => (typeof v === 'number' && Number.isFinite(v) ? v : null);
const fmt = (n) => n.toLocaleString('id-ID');
const persenTeks = (n) => `${String(Math.round(n * 10) / 10).replace('.', ',')}%`;

/**
 * Satu angka utama untuk kartu hero, dari respons GET /dashboard (hanya data yang benar-benar ada; tidak pernah mengarang).
 * Porsi hari ini (Investor, Kepala SPPG) lebih dulu, lalu pengeluaran bulan berjalan (Akuntan). Selain itu null -> kartu sambutan.
 * -> {label, angka, satuan, persen|null, kiri, kanan} atau null.
 */
export function angkaUtama(data) {
  const w = data?.widgets;
  if (!w || typeof w !== 'object') return null;
  const por = w.porsi_hari_ini ?? w.porsi_target_vs_realisasi;
  const real = angka(por?.realisasi);
  if (real !== null) {
    const target = angka(por.target);
    const capaian = angka(por.capaian_persen) ?? (target ? (real / target) * 100 : null);
    return {
      label: 'Porsi hari ini', angka: fmt(real), satuan: 'porsi tersalurkan', persen: capaian === null ? null : Math.max(0, Math.min(100, capaian)),
      kiri: target ? `Target ${fmt(target)} porsi` : '', kanan: capaian === null ? '' : `Capaian ${persenTeks(capaian)}`,
    };
  }
  const keu = w.pencairan_vs_pengeluaran;
  const keluar = angka(keu?.pengeluaran);
  if (keluar !== null) {
    const cair = angka(keu.pencairan);
    const persen = angka(keu.persen);
    return {
      label: 'Pengeluaran bulan ini', angka: formatValue(keluar, 'rupiah'), satuan: '', persen: persen === null ? null : Math.max(0, Math.min(100, persen)),
      kiri: cair !== null ? `Pencairan ${formatValue(cair, 'rupiah')}` : '', kanan: persen === null ? '' : `Terpakai ${persenTeks(persen)}`,
    };
  }
  return null;
}

/**
 * Kartu hero gradien. Selalu tampil sebagai sambutan (nama, peran, SPPG); bila dasbor menyediakan angka utama untuk peran
 * ini, blok angka dibuka setelah data tiba. Tanpa data (atau gagal memuat) kartu tetap sambutan: tidak ada angka karangan.
 */
function buatHero(user, u) {
  const chip = h('span', { class: 'hero-chip' }, 'Selamat datang');
  const sasaran = h('span', { class: 'hero-target' }, u.sppg_name || '');
  const nama = h('h2', { class: 'hero-nama' }, u.name || '');
  const peran = h('p', { class: 'hero-sub' }, u.role_label || roleLabel(u.role) || '');
  const nilai = h('b', {});
  const satuan = h('span', {});
  const bar = h('i', {});
  const kiri = h('span', {});
  const kanan = h('span', {});
  const track = h('div', { class: 'track', hidden: true }, bar);
  const meta = h('div', { class: 'track-meta', hidden: true }, kiri, kanan);
  const data = h('div', { class: 'hero-data', hidden: true }, h('div', { class: 'hero-figure' }, nilai, satuan), track, meta);
  const tersedia = dashboardTersedia(user);
  const kaki = h('div', { class: 'hero-foot' },
    h('a', { class: 'btn-light', href: '#/dashboard' }, 'Buka dashboard', ikon('panah', { ukuran: 15, tebal: 2 })));
  const el = h('section', { class: 'hero-web', 'aria-label': 'Ringkasan akun' },
    h('div', { class: 'hero-top' }, chip, sasaran), h('div', { class: 'hero-sapa' }, nama, peran), data, tersedia ? kaki : null);

  function isi(info) {
    chip.textContent = info.label;
    nilai.textContent = info.angka;
    satuan.textContent = info.satuan;
    satuan.hidden = !info.satuan;
    kiri.textContent = info.kiri;
    kanan.textContent = info.kanan;
    track.hidden = info.persen === null;
    meta.hidden = !info.kiri && !info.kanan;
    if (info.persen !== null) bar.style.width = `${info.persen}%`; // CSSOM (CSP style-src 'self' tidak memblokir)
    data.hidden = false;
    el.classList.add('hero-ada-angka');
  }
  // Tidak menunggu: kartu sambutan tampil dulu. Galat/akses ditolak diabaikan (tetap sambutan).
  if (tersedia) {
    Promise.resolve().then(() => api.get('/dashboard', { rentang: 7 }, { silent: true }))
      .then((res) => { const info = angkaUtama(res?.data ?? res); if (info) isi(info); })
      .catch(() => {});
  }
  return el;
}

/** menu: keluaran menuUntuk(user, modul) / menuFor(user). */
export function renderBerandaWeb(el, { user, menu }) {
  const u = user ?? {};
  el.append(buatHero(user, u));
  // Investor multi-SPPG: SPPG aktif dan tautan ganti (pilihan hanya di memori; server tetap memvalidasi header).
  if (multiSppg(user)) {
    const id = getSppgAktif();
    const nama = h('span', {}, id ? `SPPG aktif: #${id}. ` : 'Belum memilih SPPG. ');
    el.append(h('div', { class: 'notice' }, nama, h('a', { class: 'link', href: '#/ganti-sppg' }, id ? 'Ganti SPPG' : 'Pilih SPPG')));
    // Nama SPPG aktif dari GET /sppg-saya (SppgPilihan: id, kode, nama); gagal memuat = tetap menampilkan nomor.
    if (id) {
      api.get('/sppg-saya', undefined, { silent: true }).then((res) => {
        const s = (res?.data ?? []).find((x) => x.id === id);
        if (s?.nama) nama.textContent = `SPPG aktif: ${s.nama}${s.kode ? ` (${s.kode})` : ''}. `;
      }).catch(() => {});
    }
  }
  const adaPeta = petaValid(user);
  if (!adaPeta) el.append(h('div', { class: 'alert-danger', role: 'alert' }, PESAN_PETA_RUSAK));
  el.append(h('h2', { class: 'section-title' }, 'Menu'));
  const grid = h('nav', { class: 'menu-grid', 'aria-label': 'Menu modul' });
  if (adaPeta && !menu.length) grid.append(h('p', { class: 'muted pad' }, 'Belum ada modul yang tersedia untuk akun Anda.'));
  if (adaPeta) for (const p of pintasanWeb(user)) grid.append(kartu(p.href, p.ikon, p.judul, p.deskripsi));
  for (const m of menu) grid.append(kartu(`#/${m.slug}`, m.icon, m.title, m.description));
  grid.append(kartu('#/sesi', '\u{1F4F1}', 'Sesi dan Browser', 'Sesi aktif, browser tepercaya, dan keluar'));
  el.append(grid);
  return grid;
}

/** Layar sementara untuk modul yang layar webnya belum ada. */
export function renderSegera(el, judul) {
  el.append(h('section', { class: 'panel stack' },
    h('h2', { class: 'panel-title' }, judul),
    h('p', { class: 'notice' }, PESAN_SEGERA),
    h('a', { class: 'btn btn-block', href: '#/' }, 'Kembali ke beranda')));
}

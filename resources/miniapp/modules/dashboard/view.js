// Penampil kartu Dashboard R-07. Semua teks lewat h()/textContent (tanpa innerHTML).
// Tabel RENDERER memetakan kunci widget -> fungsi render; kunci tak dikenal memakai fallback generik.
import { h, formatValue } from '../../core/dom.js';
import { renderChart } from '../../core/charts.js';
import {
  formatPersen, formatAngka, singkatRupiah, statusAnggaran, penandaAnggaran, GAYA_ORANYE, tampilSel, tampilTotalSamar,
  LEGENDA_PENYAMARAN, judulWidget, labelPeriode, periodeWidget, deretGrafik, labelSumbu, ringkasanDeret, humanisasi,
  pasanganSkalar, jumlahBuktiBelumAda,
} from './logika.js';

const rp = (v) => formatValue(v, 'rupiah');
const an = (v) => formatAngka(v);
const arr = (v) => (Array.isArray(v) ? v : []);
const WARNA = ['#2481cc', '#d9831f', '#1f9d6e', '#d0504d', '#8e55d6', '#5a7a99'];

// ---- Pengelola grafik: semua instance dihancurkan saat render ulang / pindah layar ----
export function buatPengelolaGrafik() {
  const aktif = [];
  let mati = false;
  return {
    tambah(canvas, config) {
      renderChart(canvas, config).then((c) => {
        if (mati || !canvas.isConnected) c.destroy(); else aktif.push(c);
      }).catch(() => { canvas.parentElement?.replaceChildren(h('p', { class: 'muted small' }, 'Grafik tidak dapat dimuat.')); });
    },
    hancurkan() { aktif.splice(0).forEach((c) => { try { c.destroy(); } catch { /* abaikan */ } }); },
    tutup() { mati = true; this.hancurkan(); },
  };
}

// ---- Blok bangunan ----
const kpi = (label, nilai, sub, tone) => h('div', { class: `kpi${tone ? ` kpi-${tone}` : ''}` },
  h('span', { class: 'kpi-label' }, label), h('strong', { class: 'kpi-value' }, nilai), sub ? h('span', { class: 'kpi-sub' }, sub) : null);
const grid = (...k) => h('div', { class: 'kpi-grid' }, k.filter(Boolean));
const baris = (label, nilai) => h('div', { class: 'tbl-row' }, h('span', {}, label), h('span', { class: 'num' }, nilai));
const tabel = (rows) => h('div', { class: 'tbl' }, rows.filter(Boolean));
const kosong = (teks) => h('p', { class: 'muted small' }, teks);
const badge = (teks, cls = 'badge', style) => h('span', { class: cls, style }, teks);
const bar = (persen) => h('div', { class: 'progress', role: 'img', 'aria-label': `Terpakai ${formatPersen(persen)}` },
  h('span', { style: `width:${Math.max(0, Math.min(100, Number(persen) || 0))}%` }));
const sub = (teks) => h('h3', { class: 'small muted', style: 'margin:10px 0 4px' }, teks);

function lencanaPenanda(pn) {
  const p = penandaAnggaran(pn);
  return p ? badge(p.label, p.cls, p.kunci === 'mencapai_100' ? GAYA_ORANYE : undefined) : null;
}
const toneDari = (pn) => { const p = penandaAnggaran(pn); return !p ? 'ok' : p.kunci === 'melampaui' ? 'danger' : 'warn'; };

function grafik(mgr, w, { nama, jenis = 'bar', judul, rupiah = false, tumpuk = false, nameLabel } = {}) {
  const d = deretGrafik(w, nama);
  if (!d) return kosong('Belum ada data grafik.');
  const canvas = h('canvas', { role: 'img', 'aria-label': ringkasanDeret(d, judul || 'Grafik') });
  const box = h('div', { class: 'chart-box' }, canvas);
  mgr.tambah(canvas, {
    type: jenis,
    data: {
      labels: d.labels.map(labelSumbu),
      datasets: Object.entries(d.series).map(([k, v], i) => ({
        label: nameLabel?.[k] ?? humanisasi(k), data: v, borderColor: WARNA[i % WARNA.length], backgroundColor: `${WARNA[i % WARNA.length]}${jenis === 'line' ? '33' : 'cc'}`, tension: 0.25, pointRadius: 2,
      })),
    },
    options: {
      plugins: { legend: { display: Object.keys(d.series).length > 1 } },
      scales: { y: { beginAtZero: true, stacked: tumpuk, ticks: { precision: 0, callback: rupiah ? (v) => singkatRupiah(v) : undefined } }, x: { stacked: tumpuk, ticks: { maxRotation: 45, autoSkip: true } } },
    },
  });
  const ringkas = h('p', { class: 'sr-only' }, ringkasanDeret(d, judul || 'Grafik'));
  return h('div', {}, box, ringkas);
}

function daftar(items, fn, teksKosong = 'Tidak ada data.') {
  const a = arr(items);
  return a.length ? h('div', { class: 'tbl' }, a.map(fn)) : kosong(teksKosong);
}

// ---- Renderer per kunci ----
const porsi = (w) => grid(
  kpi('Target', an(w.target)), kpi('Realisasi', an(w.realisasi), `Capaian ${formatPersen(w.capaian_persen)}`),
  kpi('Jadwal aktif', an(w.jadwal_aktif), `Dengan log ${an(w.jadwal_dengan_log)} · tanpa log ${an(w.jadwal_tanpa_log)}`),
  kpi('Didistribusikan', an(w.didistribusikan)), kpi('Diterima', an(w.diterima), `${formatPersen(w.diterima_persen_dari_diproduksi)} dari produksi`),
  kpi('Sisa makanan', `${an(w.sisa_makanan_kg)} kg`));

function anggaran(w) {
  const st = statusAnggaran(w.status);
  const pn = lencanaPenanda(w.penanda);
  const wadah = h('div', { class: 'stack-tight' },
    h('div', { class: 'row-actions', style: 'align-items:center' }, badge(st.label, st.cls), pn),
    grid(kpi('Anggaran', rp(w.anggaran)), kpi('Terpakai', rp(w.terpakai), formatPersen(w.persen), toneDari(w.penanda)), kpi('Sisa', rp(w.sisa))),
    bar(w.persen));
  if (w.rencana_anggaran !== undefined) {
    wadah.append(baris('Rencana anggaran (= total pencairan periode)', rp(w.rencana_anggaran)));
    if (w.keterangan_rencana) wadah.append(h('p', { class: 'muted small' }, w.keterangan_rencana));
  }
  if (w.menunggu_persetujuan) wadah.append(baris('Menunggu persetujuan', `${an(w.menunggu_persetujuan.jumlah)} (${rp(w.menunggu_persetujuan.nominal)})`));
  if (arr(w.per_sppg).length) {
    wadah.append(sub('Per SPPG'));
    for (const s of w.per_sppg) {
      const x = statusAnggaran(s.status);
      wadah.append(h('div', { class: 'tbl-row' }, h('span', {}, s.nama, h('div', { class: 'muted small' }, `${rp(s.terpakai)} dari ${rp(s.anggaran)} · ${formatPersen(s.persen)}`)),
        h('span', { class: 'rc-badges' }, badge(x.label, x.cls), lencanaPenanda(s.penanda))));
    }
  }
  return wadah;
}

const buktiNota = (w) => {
  const n = jumlahBuktiBelumAda(w);
  return h('div', { class: 'stack-tight' },
    grid(kpi('Pengeluaran dihitung', an(w.pengeluaran_dihitung)), kpi('Bukti belum ada', an(w.bukti_belum_ada?.jumlah), `${rp(w.bukti_belum_ada?.nominal)} · ${formatPersen(w.persen_bukti_belum_ada)}`, n > 0 ? 'warn' : 'ok')),
    n > 0 ? badge('Bukti belum ada', 'badge badge-warn') : null);
};

const stokKritis = (w) => grid(
  kpi('Di bawah minimum', an(w.di_bawah_minimum), `dari ${an(w.total_bahan)} bahan`, w.di_bawah_minimum > 0 ? 'warn' : 'ok'),
  kpi('Sudah kedaluwarsa', an(w.sudah_kedaluwarsa), null, w.sudah_kedaluwarsa > 0 ? 'danger' : 'ok'),
  kpi('Akan kedaluwarsa', an(w.akan_kedaluwarsa), `dalam ${an(w.hari_peringatan_kedaluwarsa)} hari`, w.akan_kedaluwarsa > 0 ? 'warn' : 'ok'));

const mutu = (w) => h('div', { class: 'stack-tight' },
  grid(kpi('Periksa hari ini', an(w.hari_ini?.pemeriksaan), `Layak ${an(w.hari_ini?.layak)} · tidak layak ${an(w.hari_ini?.tidak_layak)}`, w.hari_ini?.tidak_layak > 0 ? 'danger' : 'ok'),
    kpi('Batch belum diperiksa', an(w.hari_ini?.batch_belum_diperiksa)),
    kpi('Layak bulan ini', formatPersen(w.persen_layak_bulan), `${an(w.bulan_berjalan?.pemeriksaan)} pemeriksaan`)));

const pengajuan = (w) => h('div', { class: 'stack-tight' },
  grid(kpi('Total menunggu', an(w.total)), kpi('Pengeluaran', an(w.pengeluaran?.jumlah), w.pengeluaran?.nominal != null ? rp(w.pengeluaran.nominal) : null), kpi('Menu', an(w.menu?.jumlah))),
  arr(w.per_sppg).length ? tabel(w.per_sppg.map((s) => baris(s.nama, `Pengeluaran ${an(s.pengeluaran)} · menu ${an(s.menu)}`))) : null);

const perStatus = (list) => tabel(arr(list).map((s) => baris(s.label ?? humanisasi(s.status), an(s.jumlah))));

const R = {
  porsi_hari_ini: porsi,
  porsi_target_vs_realisasi: porsi,
  anggaran,
  pengajuan_menunggu: pengajuan,
  pengajuan_menunggu_investor: pengajuan,
  stok_kritis: stokKritis,
  mutu,
  hasil_mutu: mutu,
  bukti_nota: buktiNota,
  insiden_mutu: (w, m) => h('div', { class: 'stack-tight' },
    grid(kpi('Tidak layak hari ini', an(w.tidak_layak_hari_ini), null, w.tidak_layak_hari_ini > 0 ? 'danger' : 'ok'), kpi('Tidak layak bulan ini', an(w.tidak_layak_bulan))),
    grafik(m, w.tren, { jenis: 'line', judul: 'Tren insiden mutu' })),
  keluhan_kritis: (w, m) => h('div', { class: 'stack-tight' },
    grid(kpi('Kritis terbuka', an(w.kritis_terbuka), null, w.kritis_terbuka > 0 ? 'danger' : 'ok'), kpi('Kritis masuk dalam rentang', an(w.kritis_masuk_rentang)), kpi('Terbuka semua prioritas', an(w.terbuka_semua_prioritas))),
    grafik(m, w.tren, { jenis: 'line', judul: 'Tren keluhan kritis' })),
  keluhan_terbuka: (w, m) => h('div', { class: 'stack-tight' },
    grid(kpi('Terbuka', an(w.terbuka), `Baru ${an(w.baru)} · diproses ${an(w.diproses)}`), kpi('Kritis terbuka', an(w.kritis_terbuka), null, w.kritis_terbuka > 0 ? 'danger' : 'ok'), kpi('Masuk hari ini', an(w.masuk_hari_ini))),
    perStatus(w.per_prioritas),
    grafik(m, w.tren, { jenis: 'line', judul: 'Tren keluhan' })),
  tren: (w, m) => h('div', { class: 'stack-tight' },
    sub('Porsi'), grafik(m, w, { nama: ['porsi_target', 'porsi_realisasi', 'porsi_diterima'], jenis: 'line', judul: 'Tren porsi' }),
    sub('Pengeluaran'), grafik(m, w, { nama: ['pengeluaran'], jenis: 'line', rupiah: true, judul: 'Tren pengeluaran' }),
    sub('Keluhan dan mutu'), grafik(m, w, { nama: ['keluhan_masuk', 'mutu_tidak_layak'], jenis: 'line', judul: 'Tren keluhan dan mutu' })),
  jadwal_produksi_hari_ini: (w) => h('div', { class: 'stack-tight' },
    grid(kpi('Jadwal', an(w.total), `Aktif ${an(w.aktif)}`), kpi('Target porsi', an(w.target_porsi))), perStatus(w.per_status)),
  distribusi_per_titik: (w, m) => h('div', { class: 'stack-tight' },
    grafik(m, w, { judul: 'Distribusi per titik' }),
    daftar(w.items, (x) => baris(x.nama, `Diterima ${an(x.porsi_diterima)}/${an(x.porsi_rencana)} · selesai ${an(x.jadwal_selesai)}/${an(x.jumlah_jadwal)}${x.terlambat > 0 ? ` · terlambat ${an(x.terlambat)}` : ''}`), 'Belum ada distribusi hari ini.')),
  rekap_harian: (w) => tabel([
    baris('Jam kirim', w.jam_kirim ?? '-'), baris('Sudah disusun', formatValue(w.sudah_disusun)), baris('Status kirim', humanisasi(w.status_kirim)),
    baris('Dikirim pada', formatValue(w.dikirim_pada, 'datetime')), baris('Lewat jam kirim', formatValue(w.sudah_lewat_jam_kirim))]),
  menu_hari_ini: (w) => h('div', { class: 'stack-tight' }, perStatus(w.per_status),
    daftar(w.items, (x) => baris(`${x.nama_menu} (${humanisasi(x.kelompok_sasaran)})`, x.status_label ?? humanisasi(x.status_persetujuan)), 'Belum ada menu hari ini.')),
  gizi_vs_standar: (w) => h('div', { class: 'stack-tight' },
    grid(kpi('Sesuai', an(w.sesuai), null, 'ok'), kpi('Tidak sesuai', an(w.tidak_sesuai), null, w.tidak_sesuai > 0 ? 'danger' : 'ok'), kpi('Tanpa standar', an(w.tanpa_standar))),
    daftar(w.items, (x) => baris(x.nama_menu, x.standar_tersedia ? (x.sesuai ? 'Sesuai standar' : 'Tidak sesuai standar') : 'Tanpa standar'))),
  biaya_per_porsi: (w) => h('div', { class: 'stack-tight' },
    grid(kpi('Rata-rata biaya/porsi', rp(w.rata_rata)), kpi('Menu tanpa biaya', an(w.menu_tanpa_biaya))),
    daftar(w.items, (x) => baris(x.nama_menu, x.biaya_per_porsi == null ? 'belum diajukan' : rp(x.biaya_per_porsi)))),
  kebutuhan_khusus: (w) => {
    const t = tampilTotalSamar(w.total);
    return h('div', { class: 'stack-tight' },
      w.keterangan ? h('p', { class: 'muted small' }, w.keterangan) : null,
      baris('Total', t.teks),
      tabel(arr(w.per_kelompok_sasaran).map((s) => { const c = tampilSel(s); return baris(s.label ?? humanisasi(s.kelompok_sasaran), c.disamarkan ? `${c.teks} (disamarkan)` : c.teks); })),
      h('p', { class: 'muted small' }, LEGENDA_PENYAMARAN));
  },
  pencairan_vs_pengeluaran: (w) => {
    const st = statusAnggaran(w.status);
    return h('div', { class: 'stack-tight' }, badge(st.label, st.cls),
      grid(kpi('Pencairan', rp(w.pencairan), `${an(w.jumlah_pencairan)} transaksi`), kpi('Pengeluaran', rp(w.pengeluaran), `${an(w.jumlah_pengeluaran)} transaksi · ${formatPersen(w.persen)}`), kpi('Selisih', rp(w.selisih))),
      bar(w.persen));
  },
  serapan_per_kategori: (w, m) => h('div', { class: 'stack-tight' },
    grid(kpi('Anggaran', rp(w.anggaran)), kpi('Terpakai', rp(w.terpakai))),
    grafik(m, w, { rupiah: true, judul: 'Serapan per kategori' }),
    daftar(w.items, (x) => baris(x.label ?? humanisasi(x.kategori), `${rp(x.nominal)} · ${formatPersen(x.persen_dari_anggaran)} anggaran`))),
  pengeluaran_menunggu: (w) => grid(
    kpi('Pengeluaran menunggu', an(w.pengeluaran?.jumlah), rp(w.pengeluaran?.nominal)),
    kpi('Pembelian menunggu verifikasi', an(w.pembelian_menunggu_verifikasi?.jumlah), rp(w.pembelian_menunggu_verifikasi?.nominal))),
  selisih_tren: (w, m) => h('div', { class: 'stack-tight' },
    baris('Total selisih', rp(w.total_selisih)),
    grafik(m, w, { nama: ['pencairan', 'pengeluaran'], jenis: 'line', rupiah: true, judul: 'Pencairan dan pengeluaran' }),
    grafik(m, w, { nama: ['selisih_kumulatif'], jenis: 'line', rupiah: true, judul: 'Selisih kumulatif' })),
  stok_per_bahan: (w) => h('div', { class: 'stack-tight' },
    baris('Total bahan', an(w.total_bahan)),
    tabel(arr(w.per_kategori).filter((k) => k.jumlah > 0).map((k) => baris(k.label ?? humanisasi(k.kategori), an(k.jumlah)))),
    sub('Stok terendah'), daftar(w.terendah, itemStok)),
  stok_di_bawah_minimum: (w) => h('div', { class: 'stack-tight' }, baris('Jumlah bahan', an(w.jumlah)), daftar(w.items, itemStok, 'Tidak ada stok di bawah minimum.')),
  stok_mendekati_kedaluwarsa: (w) => h('div', { class: 'stack-tight' },
    grid(kpi('Sudah kedaluwarsa', an(w.sudah_kedaluwarsa), null, w.sudah_kedaluwarsa > 0 ? 'danger' : 'ok'), kpi('Akan kedaluwarsa', an(w.akan_kedaluwarsa), `dalam ${an(w.hari_peringatan)} hari`)),
    daftar(w.items, (x) => baris(x.nama, `${formatValue(x.kedaluwarsa_terdekat, 'date')}`), 'Tidak ada bahan mendekati kedaluwarsa.')),
  mutasi_hari_ini: (w) => h('div', { class: 'stack-tight' }, baris('Total mutasi', an(w.total)), tabel(arr(w.per_jenis).map((j) => baris(humanisasi(j.jenis), an(j.jumlah))))),
  pengiriman_pemasok: (w) => h('div', { class: 'stack-tight' },
    grid(kpi('Penerimaan hari ini', an(w.penerimaan_hari_ini), `${an(w.pemasok_hari_ini)} pemasok`), kpi('Menunggu verifikasi', an(w.pembelian?.menunggu_verifikasi)), kpi('Ditolak bulan ini', an(w.pembelian?.ditolak_bulan_ini))),
    tabel(arr(w.per_jenis_pemasok).filter((j) => j.penerimaan > 0).map((j) => baris(j.label ?? humanisasi(j.jenis), `${an(j.penerimaan)} penerimaan`))),
    w.terjadwal == null ? kosong('Belum ada jadwal pengiriman pemasok') : daftar(w.terjadwal, (x) => baris(x.pemasok ?? '-', formatValue(x.tanggal, 'date')))),
  menu_target_hari_ini: (w) => h('div', { class: 'stack-tight' },
    grid(kpi('Jadwal', an(w.jumlah_jadwal)), kpi('Total target porsi', an(w.total_target_porsi))),
    daftar(w.items, (x) => baris(`${x.menu} (${humanisasi(x.kelompok_sasaran)})`, `${an(x.target_porsi)} porsi · ${x.status_label ?? humanisasi(x.status)}`), 'Belum ada jadwal hari ini.')),
  status_log_produksi: (w) => h('div', { class: 'stack-tight' },
    grid(kpi('Sudah ada log', an(w.sudah_ada_log), null, 'ok'), kpi('Belum ada log', an(w.belum_ada_log), null, w.belum_ada_log > 0 ? 'warn' : 'ok')),
    daftar(w.items, (x) => baris(x.menu, x.ada_log ? `Realisasi ${an(x.realisasi_porsi)}/${an(x.target_porsi)}` : 'Belum ada log'))),
  checklist_mutu_belum_diisi: (w) => h('div', { class: 'stack-tight' }, baris('Belum diisi', an(w.jumlah)),
    daftar(w.items, (x) => baris(x.menu, `Mulai masak ${x.jam_mulai_masak ?? '-'}`), 'Semua checklist sudah diisi.')),
  rute_hari_ini: (w) => h('div', { class: 'stack-tight' }, baris('Jumlah titik', an(w.jumlah_titik)),
    daftar(w.items, (x) => baris(x.titik_layanan?.nama ?? '-', `${an(x.jumlah_porsi)} porsi · ${x.jam_rencana_tiba ?? '-'} · ${x.status_label ?? humanisasi(x.status)}`), 'Belum ada rute hari ini.')),
  status_kirim: (w) => h('div', { class: 'stack-tight' }, baris('Total pengiriman', an(w.total)), perStatus(w.per_status),
    w.notifikasi_perjalanan ? baris('Notifikasi perjalanan', `Terkirim ${an(w.notifikasi_perjalanan.terkirim)} · menunggu ${an(w.notifikasi_perjalanan.menunggu)} · gagal ${an(w.notifikasi_perjalanan.gagal)}`) : null),
  bukti_belum_diunggah: (w) => h('div', { class: 'stack-tight' }, baris('Belum diunggah', an(w.jumlah)),
    daftar(w.items, (x) => baris(x.titik_layanan ?? '-', `${x.jam_rencana_tiba ?? '-'} · ${humanisasi(x.status)}`), 'Semua bukti sudah diunggah.')),
  armada_pengemudi: (w) => h('div', { class: 'stack-tight' }, perStatus(w.armada_per_status),
    daftar(w.armada, (x) => baris(x.plat_nomor, `${x.jenis ?? '-'} · ${humanisasi(x.status)}`)),
    baris('Pengemudi aktif', arr(w.pengemudi_aktif).map((p) => p.nama).join(', ') || '-')),
  operasional_lintas_sppg: (w) => h('div', { class: 'stack-tight' },
    grid(kpi('Target porsi', an(w.total?.target_porsi)), kpi('Realisasi', an(w.total?.realisasi_porsi), formatPersen(w.total?.capaian_persen)),
      kpi('Distribusi selesai', `${an(w.total?.distribusi_selesai)}/${an(w.total?.distribusi_jadwal)}`), kpi('Keluhan terbuka', an(w.total?.keluhan_terbuka), `Kritis ${an(w.total?.keluhan_kritis)}`), kpi('Stok di bawah minimum', an(w.total?.stok_di_bawah_minimum))),
    daftar(w.per_sppg, (s) => baris(s.nama, `${formatPersen(s.capaian_persen)} · keluhan ${an(s.keluhan_terbuka)}`))),
  pengguna_aktif_per_peran: (w, m) => h('div', { class: 'stack-tight' }, baris('Total aktif', an(w.total_aktif)), grafik(m, w, { judul: 'Pengguna aktif per peran' }),
    daftar(w.items, (x) => baris(x.label ?? humanisasi(x.peran), `${an(x.aktif)} aktif · ${an(x.nonaktif)} nonaktif`))),
  login_gagal: (w, m) => h('div', { class: 'stack-tight' },
    grid(kpi('Masuk gagal hari ini', an(w.hari_ini?.masuk_gagal)), kpi('Token salah', an(w.hari_ini?.token_salah)), kpi('Akun terkunci', an(w.akun_terkunci_saat_ini))),
    baris('Total dalam rentang', an(w.total_rentang)), grafik(m, w.tren, { jenis: 'line', judul: 'Tren login gagal' })),
  antrean_job_webhook: (w) => h('div', { class: 'stack-tight' },
    baris('Antrean menunggu/ditunda', w.antrean ? an(w.antrean.menunggu_atau_ditunda) : 'tidak tersedia'),
    baris('Sedang diproses', w.antrean ? an(w.antrean.sedang_diproses) : 'tidak tersedia'),
    baris('Job gagal (total)', w.job_gagal ? an(w.job_gagal.total) : 'tidak tersedia'),
    baris('Job gagal (rentang)', w.job_gagal ? an(w.job_gagal.dalam_rentang) : 'tidak tersedia'),
    baris('Webhook tersangkut', w.webhook_tersangkut ? `${an(w.webhook_tersangkut.jumlah)}${w.webhook_tersangkut.tertua_menit != null ? ` (tertua ${an(w.webhook_tersangkut.tertua_menit)} menit)` : ''}` : 'tidak tersedia')),
  draf_ai_tertunda: (w) => h('div', { class: 'stack-tight' }, baris('Menunggu konfirmasi', an(w.menunggu_konfirmasi)), baris('Kedaluwarsa belum dibersihkan', an(w.kedaluwarsa_belum_dibersihkan)),
    daftar(w.per_modul, (x) => baris(humanisasi(x.modul), an(x.jumlah)), 'Tidak ada draf per modul.')),
};

function itemStok(x) {
  return baris(x.nama, `${an(x.stok)} / min ${an(x.stok_minimum)} ${x.satuan ?? ''}`.trim());
}

/** Fallback aman untuk kunci tak dikenal: pasangan skalar sederhana, selain itu pesan netral. */
export function renderGenerik(w) {
  const p = pasanganSkalar(w);
  return p.length ? tabel(p.map(([k, v]) => baris(k, formatValue(v)))) : kosong('Data ringkasan belum bisa ditampilkan.');
}

export function renderKartu(kunci, w, mgr) {
  let isi;
  try {
    isi = (R[kunci] ?? renderGenerik)(w, mgr);
  } catch {
    isi = renderGenerik(w);
  }
  const ket = labelPeriode(periodeWidget(w));
  return h('section', { class: 'panel', 'data-widget': kunci, 'aria-label': judulWidget(kunci) },
    h('h2', { class: 'panel-title', style: 'margin-bottom:2px' }, judulWidget(kunci)),
    ket ? h('p', { class: 'muted small', style: 'margin:0 0 8px' }, ket) : null,
    isi);
}

export const KUNCI_DIKENAL = Object.keys(R);

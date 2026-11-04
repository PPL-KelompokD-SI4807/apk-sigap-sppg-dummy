// Logika murni Dashboard R-07 (tanpa DOM) agar bisa diuji: node --test resources/miniapp/tests/
import { errorMessage } from '../../core/errors.js';
import { PESAN_TANPA_SPPG } from '../../core/sppg-aktif.js';

export const SLUG = 'dashboard';
export const RENTANG = [7, 30];
export const RENTANG_BAWAAN = 7;
export const LEGENDA_PENYAMARAN = '"<5" = disamarkan (jumlah kecil disembunyikan demi privasi; bukan selalu kurang dari 5).';

/** Rentang valid hanya 7 atau 30; selain itu kembali ke bawaan. */
export function rentangValid(v) {
  const n = Number(v);
  return RENTANG.includes(n) ? n : RENTANG_BAWAAN;
}

/** Query GET /dashboard: sppg_id hanya dikirim bila bilangan bulat positif (kosong = gabungan). */
export function bangunQuery({ rentang, sppgId } = {}) {
  const q = { rentang: rentangValid(rentang) };
  const id = Number(sppgId);
  if (Number.isInteger(id) && id > 0) q.sppg_id = id;
  return q;
}

// ---- Angka ----
const num = (v) => (typeof v === 'number' && Number.isFinite(v) ? v : null);

/** Persen 0-100 atau null (pembagi 0) -> "76,67%" / "-". */
export function formatPersen(v) {
  const n = num(v);
  if (n == null) return '-';
  return `${String(Math.round(n * 100) / 100).replace('.', ',')}%`;
}

/** Bilangan biasa dengan pemisah ribuan id-ID; null -> "-". */
export function formatAngka(v) {
  const n = num(v);
  if (n == null) return '-';
  const [bulat, desimal] = String(Math.round(n * 100) / 100).split('.');
  const ribuan = bulat.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  return desimal ? `${ribuan},${desimal}` : ribuan;
}

/** Rupiah ringkas untuk sumbu grafik: 1.500.000 -> "1,5jt". */
export function singkatRupiah(v) {
  const n = num(v);
  if (n == null) return '-';
  const a = Math.abs(n);
  const f = (x, s) => `${String(Math.round(x * 10) / 10).replace('.', ',')}${s}`;
  if (a >= 1e9) return f(n / 1e9, 'M');
  if (a >= 1e6) return f(n / 1e6, 'jt');
  if (a >= 1e3) return f(n / 1e3, 'rb');
  return String(n);
}

// ---- Anggaran ----
export const STATUS_ANGGARAN = {
  belum_ada_anggaran: { label: 'Belum ada anggaran', cls: 'badge' },
  aman: { label: 'Aman', cls: 'badge badge-ok' },
  peringatan: { label: 'Peringatan', cls: 'badge badge-warn' },
  habis: { label: 'Anggaran habis', cls: 'badge badge-warn' },
  melampaui: { label: 'Melampaui anggaran', cls: 'badge badge-danger' },
};

export function statusAnggaran(status) {
  return STATUS_ANGGARAN[status] ?? { label: status ? String(status) : '-', cls: 'badge' };
}

/**
 * Penanda anggaran dengan prioritas melampaui > mencapai_100 > mencapai_ambang.
 * -> null atau {kunci, label, cls, warna}. Warna lewat teks + kelas (bukan hanya warna).
 */
export function penandaAnggaran(penanda) {
  const p = penanda && typeof penanda === 'object' ? penanda : {};
  if (p.melampaui === true) return { kunci: 'melampaui', label: 'Melampaui anggaran (>100%)', cls: 'badge badge-danger', warna: 'merah' };
  if (p.mencapai_100 === true) return { kunci: 'mencapai_100', label: 'Mencapai 100% anggaran', cls: 'badge badge-orange', warna: 'oranye' };
  if (p.mencapai_ambang === true) {
    const ambang = num(p.ambang_peringatan_persen) ?? 80;
    return { kunci: 'mencapai_ambang', label: `Mencapai ambang ${ambang}%`, cls: 'badge badge-warn', warna: 'kuning' };
  }
  return null;
}

/** Gaya inline untuk kelas lencana yang belum ada di CSS (badge-orange). */
export const GAYA_ORANYE = 'background:#ffe3cc;color:#8a3b00';

// ---- Penyamaran ----
/** Sel penyamaran: {jumlah:null, penanda:"<5"} -> {teks:"<5", disamarkan:true}. Tidak pernah menghitung ulang. */
export function tampilSel(sel) {
  if (sel == null || typeof sel !== 'object') return { teks: '-', disamarkan: false };
  if (sel.jumlah == null) {
    return sel.penanda ? { teks: String(sel.penanda), disamarkan: true } : { teks: '-', disamarkan: false };
  }
  return { teks: formatAngka(sel.jumlah), disamarkan: false };
}

/** Total/sel tunggal yang null pada widget penyamaran: tampil "disamarkan", bukan "-". */
export const tampilTotalSamar = (v) => (v == null ? { teks: 'disamarkan', disamarkan: true } : { teks: formatAngka(v), disamarkan: false });

// ---- Widget ----
const JUDUL = {
  porsi_hari_ini: 'Porsi hari ini', anggaran: 'Anggaran bulan ini', pengajuan_menunggu: 'Pengajuan menunggu keputusan',
  stok_kritis: 'Stok kritis', insiden_mutu: 'Insiden mutu', keluhan_kritis: 'Keluhan kritis', bukti_nota: 'Bukti nota pengeluaran',
  tren: 'Tren harian', jadwal_produksi_hari_ini: 'Jadwal produksi hari ini', porsi_target_vs_realisasi: 'Porsi target vs realisasi',
  distribusi_per_titik: 'Distribusi per titik layanan', mutu: 'Mutu dan keamanan pangan', keluhan_terbuka: 'Keluhan terbuka',
  pengajuan_menunggu_investor: 'Menunggu keputusan investor', rekap_harian: 'Rekap harian', menu_hari_ini: 'Menu hari ini',
  gizi_vs_standar: 'Gizi vs standar', biaya_per_porsi: 'Biaya per porsi', kebutuhan_khusus: 'Kebutuhan khusus penerima',
  hasil_mutu: 'Hasil pemeriksaan mutu', pencairan_vs_pengeluaran: 'Pencairan vs pengeluaran', serapan_per_kategori: 'Serapan per kategori',
  pengeluaran_menunggu: 'Pengeluaran menunggu', selisih_tren: 'Selisih pencairan dan pengeluaran', stok_per_bahan: 'Stok per bahan',
  stok_di_bawah_minimum: 'Stok di bawah minimum', stok_mendekati_kedaluwarsa: 'Stok mendekati kedaluwarsa', mutasi_hari_ini: 'Mutasi stok hari ini',
  pengiriman_pemasok: 'Pengiriman pemasok', menu_target_hari_ini: 'Menu dan target hari ini', status_log_produksi: 'Status log produksi',
  checklist_mutu_belum_diisi: 'Checklist mutu belum diisi', rute_hari_ini: 'Rute hari ini', status_kirim: 'Status pengiriman',
  bukti_belum_diunggah: 'Bukti belum diunggah', armada_pengemudi: 'Armada dan pengemudi', operasional_lintas_sppg: 'Operasional lintas SPPG',
  pengguna_aktif_per_peran: 'Pengguna aktif per peran', login_gagal: 'Login gagal', antrean_job_webhook: 'Antrean job dan webhook',
  draf_ai_tertunda: 'Draf AI tertunda',
};

export const humanisasi = (k) => {
  const s = String(k ?? '').replace(/_/g, ' ').trim();
  return s ? s.charAt(0).toUpperCase() + s.slice(1) : '-';
};
export const judulWidget = (kunci) => JUDUL[kunci] ?? humanisasi(kunci);

/** Daftar [kunci, widget] yang ditampilkan: nilai null/bukan objek disembunyikan. Urutan dari server dipertahankan. */
export function widgetTampil(widgets) {
  if (!widgets || typeof widgets !== 'object' || Array.isArray(widgets)) return [];
  return Object.entries(widgets).filter(([, w]) => w != null && typeof w === 'object');
}

const BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
const BULAN_PENUH = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

/** "2026-11-02" -> "2 Nov 2026"; format lain dikembalikan apa adanya. */
export function tanggalPendek(s) {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(s ?? ''));
  if (!m) return s == null ? '-' : String(s);
  return `${Number(m[3])} ${BULAN[Number(m[2]) - 1] ?? m[2]} ${m[1]}`;
}

/** Keterangan periode kartu dari widget.periode.jenis. */
export function labelPeriode(periode) {
  if (!periode || typeof periode !== 'object') return '';
  switch (periode.jenis) {
    case 'hari_ini': return `Hari ini (${tanggalPendek(periode.tanggal)})`;
    case 'bulan_berjalan': {
      const m = /^(\d{4})-(\d{2})$/.exec(String(periode.bulan ?? ''));
      return `Bulan berjalan${m ? ` (${BULAN_PENUH[Number(m[2]) - 1] ?? m[2]} ${m[1]})` : ''}`;
    }
    case 'tren': return `Tren ${periode.rentang_hari ?? ''} hari (${tanggalPendek(periode.dari)} s.d. ${tanggalPendek(periode.sampai)})`.replace('  ', ' ');
    case 'saat_ini': return `Saat ini${periode.per ? ` (per ${tanggalPendek(periode.per)})` : ''}`;
    default: return '';
  }
}

/** Periode kartu: widget.periode, atau periode milik tren bila kartu hanya bertren. */
export const periodeWidget = (w) => w?.periode ?? w?.tren?.periode ?? null;

export const bukaDashboardKosong = (data) => widgetTampil(data?.widgets).length === 0;

// ---- Grafik ----
/** Deret valid: labels larik dan tiap series larik. Mengembalikan {labels, series:{nama:[angka]}} atau null. */
export function deretGrafik(w, namaTerpilih) {
  if (!w || !Array.isArray(w.labels) || !w.series || typeof w.series !== 'object') return null;
  const series = {};
  for (const [nama, arr] of Object.entries(w.series)) {
    if (namaTerpilih && !namaTerpilih.includes(nama)) continue;
    if (Array.isArray(arr)) series[nama] = w.labels.map((_, i) => (num(arr[i]) ?? 0));
  }
  return Object.keys(series).length ? { labels: w.labels.map(String), series } : null;
}

/** Label sumbu tanggal: "2026-11-02" -> "02/11"; non-tanggal apa adanya. */
export const labelSumbu = (l) => {
  const m = /^\d{4}-(\d{2})-(\d{2})$/.exec(String(l));
  return m ? `${m[2]}/${m[1]}` : String(l);
};

/** Ringkasan teks bagi pembaca layar: nama deret, total, dan nilai terakhir. */
export function ringkasanDeret(deret, judul = 'Grafik') {
  if (!deret) return `${judul}: tidak ada data.`;
  const bagian = Object.entries(deret.series).map(([nama, arr]) => {
    const total = arr.reduce((a, b) => a + b, 0);
    return `${humanisasi(nama)}: total ${formatAngka(total)}, terakhir ${formatAngka(arr[arr.length - 1])}`;
  });
  return `${judul}, ${deret.labels.length} titik data. ${bagian.join('; ')}.`;
}

// ---- Pasangan skalar (fallback kunci tak dikenal) ----
/** Hanya nilai skalar tingkat atas, tanpa `periode`; objek/larik diabaikan. */
export function pasanganSkalar(w) {
  if (!w || typeof w !== 'object') return [];
  return Object.entries(w)
    .filter(([k, v]) => k !== 'periode' && (v === null || ['string', 'number', 'boolean'].includes(typeof v)))
    .map(([k, v]) => [humanisasi(k), v]);
}

// ---- Bukti nota ----
export const jumlahBuktiBelumAda = (w) => {
  const n = Number(w?.bukti_belum_ada?.jumlah);
  return Number.isFinite(n) && n > 0 ? n : 0;
};

// ---- SPPG ----
export const pilihanSppg = (data) => (Array.isArray(data?.sppg_pilihan) ? data.sppg_pilihan.filter((s) => s && s.id != null) : []);
export const labelMode = (mode) => (mode === 'gabungan' ? 'Gabungan semua SPPG' : 'Satu SPPG');

// ---- Galat ----
const PESAN_403 = {
  perlu_login_penuh: 'Selesaikan login dengan kata sandi terlebih dahulu untuk membuka dashboard.',
  sppg_aktif_ditolak: 'SPPG yang dipilih bukan milik akun Anda. Pilih SPPG lain.',
  tanpa_sppg: PESAN_TANPA_SPPG,
};

export function pesanGalat(e) {
  const s = e?.status;
  if (s === 401) return 'Sesi Anda berakhir. Masuk kembali untuk melanjutkan.';
  if (s === 403) return PESAN_403[e?.data?.alasan] ?? 'Anda tidak punya hak akses ke dashboard ini.';
  if (s === 404) return 'SPPG yang dipilih tidak ditemukan.';
  if (s === 422) return 'Rentang atau SPPG yang dipilih tidak valid. Coba pilih ulang.';
  if (s === 429) return 'Terlalu banyak permintaan. Tunggu sebentar lalu coba lagi.';
  return errorMessage(e, 'Dashboard gagal dimuat.');
}

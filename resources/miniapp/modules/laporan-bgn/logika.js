// Logika murni layar Ekspor Laporan BGN (R07-4): jenis, periode, badan permintaan, galat. Tanpa DOM.
// Kontrak: docs/api/openapi.yaml (/laporan-bgn/.../nomor dan /laporan-bgn/.../pdf). Hak ditegakkan SERVER; di sini hanya opsi UI.
import { bolehAksi } from '../../core/permissions.js';
import { addDays } from '../../core/waktu.js';

export const MAKS_HARI_RENTANG = 16;

/**
 * periode: 'rentang' (tanggal_awal/akhir) | 'tanggal' | 'bulan'.
 * nomor: jalur penerbitan nomor LPA (langkah 1) atau null bila dokumen tak bernomor (30a, 30d-C).
 * investor: false = server menolak Investor (403), opsi disembunyikan.
 */
export const JENIS = [
  { key: '30a', get: '/laporan-bgn/porsi', label: 'Lampiran 30a - Rekapitulasi Perhitungan Porsi', periode: 'rentang', pdf: '/laporan-bgn/porsi/pdf', nomor: null, investor: true },
  { key: '30b', get: '/laporan-bgn/harian', label: 'Lampiran 30b - Laporan Harian Penggunaan Dana', periode: 'tanggal', pdf: '/laporan-bgn/harian/pdf', nomor: '/laporan-bgn/harian/nomor', investor: false },
  { key: '30c', get: '/laporan-bgn/dua-mingguan', label: 'Lampiran 30c - Laporan 2 Mingguan Penggunaan Dana', periode: 'rentang', pdf: '/laporan-bgn/dua-mingguan/pdf', nomor: '/laporan-bgn/dua-mingguan/nomor', investor: true },
  { key: '30d-A', get: '/laporan-bgn/bulanan/ringkasan', label: 'Lampiran 30d-A - Laporan Bulanan (Summary)', periode: 'bulan', pdf: '/laporan-bgn/bulanan/ringkasan/pdf', nomor: '/laporan-bgn/bulanan/nomor', investor: true },
  { key: '30d-B', get: '/laporan-bgn/bulanan/anggaran', label: 'Lampiran 30d-B - Laporan Bulanan (Anggaran)', periode: 'bulan', pdf: '/laporan-bgn/bulanan/anggaran/pdf', nomor: '/laporan-bgn/bulanan/nomor', investor: true },
  { key: '30d-C', get: '/laporan-bgn/bulanan/penerima-manfaat', label: 'Lampiran 30d-C - Laporan Bulanan (Penerima Manfaat)', periode: 'bulan', pdf: '/laporan-bgn/bulanan/penerima-manfaat/pdf', nomor: null, investor: false },
];

export const jenisByKey = (key) => JENIS.find((j) => j.key === key) ?? null;
/**
 * Opsi jenis menurut peta hak server: aksi.lihat_laporan_bgn = semua; hanya aksi.lihat_laporan_bgn_agregat = jenis agregat
 * (tanpa 30b dan 30d-C); tanpa keduanya = kosong. Server tetap memeriksa (403).
 */
export function jenisUntuk(user) {
  if (bolehAksi(user, 'lihat_laporan_bgn')) return JENIS;
  if (bolehAksi(user, 'lihat_laporan_bgn_agregat')) return JENIS.filter((j) => j.investor);
  return [];
}

/** Boleh menerbitkan nomor LPA (aksi.terbitkan_nomor_laporan_bgn). Tanpa hak: langkah nomor dilewati, PDF memuat "(belum diterbitkan)". */
export const bolehTerbitkanNomor = (user) => bolehAksi(user, 'terbitkan_nomor_laporan_bgn');

const idValid = (k) => k && Number.isInteger(k.id) && k.id > 0 && typeof k.nama === 'string';
/** Kandidat penandatangan dari respons GET laporan: data.penandatangan.pihak_kedua.kandidat (akuntan) dan mengetahui.kandidat (kepala). */
export function kandidatDari(res) {
  const p = (res?.data ?? res)?.penandatangan;
  return {
    akuntan: (Array.isArray(p?.pihak_kedua?.kandidat) ? p.pihak_kedua.kandidat : []).filter(idValid),
    kepala: (Array.isArray(p?.mengetahui?.kandidat) ? p.mengetahui.kandidat : []).filter(idValid),
  };
}

const BULAN_RE = /^\d{4}-(0[1-9]|1[0-2])$/;
const TGL_RE = /^\d{4}-\d{2}-\d{2}$/;

/** Selisih hari inklusif antara dua tanggal ISO. */
export const jumlahHari = (awal, akhir) => Math.round((Date.parse(`${akhir}T00:00:00Z`) - Date.parse(`${awal}T00:00:00Z`)) / 86400000) + 1;

function bulanBerikut(ym) {
  const [y, m] = ym.split('-').map(Number);
  return m === 12 ? `${y + 1}-01` : `${y}-${String(m + 1).padStart(2, '0')}`;
}

/** Bawaan periode dari hari ini (WIB): 30c = setengah bulan berjalan; 30a = 7 hari terakhir; bulan = bulan berjalan. */
export function periodeBawaan(jenis, today) {
  const bulan = today.slice(0, 7);
  if (jenis.periode === 'tanggal') return { tanggal: today };
  if (jenis.periode === 'bulan') return { bulan };
  if (jenis.key === '30c') {
    if (Number(today.slice(8, 10)) <= 15) return { tanggal_awal: `${bulan}-01`, tanggal_akhir: `${bulan}-15` };
    return { tanggal_awal: `${bulan}-16`, tanggal_akhir: addDays(`${bulanBerikut(bulan)}-01`, -1) };
  }
  return { tanggal_awal: addDays(today, -6), tanggal_akhir: today };
}

/** -> {kolom: pesan}; kosong bila valid. */
export function validasiPeriode(jenis, v, today) {
  const e = {};
  if (jenis.periode === 'tanggal') {
    if (!TGL_RE.test(v.tanggal ?? '')) e.tanggal = 'Tanggal wajib diisi.';
    else if (v.tanggal > today) e.tanggal = 'Tanggal tidak boleh di masa depan.';
  } else if (jenis.periode === 'bulan') {
    if (!BULAN_RE.test(v.bulan ?? '')) e.bulan = 'Bulan wajib diisi (format TTTT-BB).';
    else if (v.bulan > today.slice(0, 7)) e.bulan = 'Bulan tidak boleh di masa depan.';
  } else {
    if (!TGL_RE.test(v.tanggal_awal ?? '')) e.tanggal_awal = 'Tanggal awal wajib diisi.';
    if (!TGL_RE.test(v.tanggal_akhir ?? '')) e.tanggal_akhir = 'Tanggal akhir wajib diisi.';
    if (!e.tanggal_awal && !e.tanggal_akhir) {
      if (v.tanggal_akhir < v.tanggal_awal) e.tanggal_akhir = 'Tanggal akhir tidak boleh sebelum tanggal awal.';
      else if (jumlahHari(v.tanggal_awal, v.tanggal_akhir) > MAKS_HARI_RENTANG) e.tanggal_akhir = `Rentang maksimal ${MAKS_HARI_RENTANG} hari.`;
    }
  }
  return e;
}

/** Badan permintaan (nomor dan pdf memakai kunci periode yang sama). sppg_id hanya bila admin memilih SPPG; akuntan_id/kepala_sppg_id hanya bila dipilih. */
export function badanPermintaan(jenis, v, sppgId = null, pilihan = {}) {
  const body = jenis.periode === 'tanggal' ? { tanggal: v.tanggal }
    : jenis.periode === 'bulan' ? { bulan: v.bulan }
      : { tanggal_awal: v.tanggal_awal, tanggal_akhir: v.tanggal_akhir };
  if (sppgId != null && sppgId !== '') body.sppg_id = Number(sppgId);
  if (pilihan.akuntan_id) body.akuntan_id = Number(pilihan.akuntan_id);
  if (pilihan.kepala_sppg_id) body.kepala_sppg_id = Number(pilihan.kepala_sppg_id);
  return body;
}

export const PESAN_TELEGRAM = 'Akun Anda belum terhubung ke Telegram. Hubungkan akun lewat bot SiGAP lebih dulu, lalu coba lagi.';
export const PESAN_TIDAK_BERHAK = 'Peran Anda tidak berhak mengekspor laporan ini.';

export function telegramBelumTerhubung(e) {
  if (e?.status !== 422) return false;
  if (e.data?.code === 'telegram_belum_terhubung' || e.data?.alasan === 'telegram_belum_terhubung') return true;
  return Object.keys(e.errors ?? {}).some((k) => /telegram/i.test(k));
}

/** Galat langkah ekspor -> {pesan, fields}. langkah: 'nomor' | 'pdf'. */
export function ringkasGalatEkspor(e, langkah) {
  const s = e?.status;
  if (s === 0) return { pesan: 'Tidak ada koneksi internet. Coba lagi saat tersambung.', fields: {} };
  if (s === 403) return { pesan: PESAN_TIDAK_BERHAK, fields: {} };
  if (s === 429) return { pesan: 'Terlalu banyak permintaan PDF (maksimal 5 per menit). Tunggu sebentar lalu coba lagi.', fields: {} };
  if (telegramBelumTerhubung(e)) return { pesan: PESAN_TELEGRAM, fields: {} };
  if (s === 422) {
    const fields = {};
    for (const [k, v] of Object.entries(e.errors ?? {})) { const m = Array.isArray(v) ? v[0] : v; if (m) fields[k] = String(m); }
    return { pesan: Object.values(fields)[0] || e.message || 'Data tidak valid.', fields };
  }
  if (s >= 500) return { pesan: 'Terjadi gangguan pada server. Coba lagi nanti.', fields: {} };
  return { pesan: e?.message || (langkah === 'nomor' ? 'Nomor laporan gagal diterbitkan.' : 'Permintaan PDF gagal.'), fields: {} };
}

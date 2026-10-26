// Skema Notifikasi Perjalanan Mobil (fitur 2). Kontrak: docs/api/paths/notifikasi-perjalanan.yaml,
// docs/api/components/notifikasi-perjalanan.yaml. Murni (tanpa DOM) agar bisa diuji.
import { buildPayload } from '../../core/schema-helpers.js';
import { jamWIB, tanggalJamWIB, todayWIB } from '../../core/waktu.js';

export const STATUS_PERJALANAN = [
  { value: 'berangkat', label: 'Berangkat' },
  { value: 'dalam_perjalanan', label: 'Dalam perjalanan' },
  { value: 'tiba', label: 'Tiba' },
  { value: 'dibatalkan', label: 'Dibatalkan' },
];
const labelOf = (v) => STATUS_PERJALANAN.find((s) => s.value === v)?.label ?? v ?? '-';
export const statusLabel = labelOf;
const BADGE = { berangkat: 'badge-info', dalam_perjalanan: 'badge-warn', tiba: 'badge-ok', dibatalkan: 'badge-danger' };
export const statusBadge = (v) => BADGE[v] || '';

// berangkat -> dalam_perjalanan | dibatalkan; dalam_perjalanan -> tiba | dibatalkan; tiba dan dibatalkan final.
export const TRANSISI = {
  berangkat: ['dalam_perjalanan', 'dibatalkan'],
  dalam_perjalanan: ['tiba', 'dibatalkan'],
  tiba: [],
  dibatalkan: [],
};
export const transisiValid = (status) => [...(TRANSISI[status] ?? [])];
export const aksiLabel = (to) => ({ dalam_perjalanan: 'Mulai perjalanan', tiba: 'Tandai tiba', dibatalkan: 'Batalkan perjalanan' }[to] ?? labelOf(to));

/** Relawan hanya dari unit distribusi; peran lain mengikuti matriks. */
export function unitPerjalananBoleh(user) {
  if (!user) return false;
  if (user.role !== 'relawan') return true;
  return user.unit === 'distribusi';
}

/** Tombol transisi: perlu hak C (p.C), masih ada transisi, dan relawan hanya perjalanan yang ia picu sendiri. */
export function bolehUbahStatus(user, rec, p) {
  if (!p?.C || !transisiValid(rec?.status).length) return false;
  if (user?.role === 'relawan') return rec?.dipicu_oleh?.id != null && rec.dipicu_oleh.id === user.id;
  return true;
}

export const ALASAN_MAX = 500;
/** Alasan wajib saat dibatalkan (maks 500 karakter). Mengembalikan '' bila valid. */
export function validasiAlasan(to, alasan) {
  const a = String(alasan ?? '').trim();
  if (to === 'dibatalkan' && !a) return 'Alasan pembatalan wajib diisi.';
  if (a.length > ALASAN_MAX) return `Alasan maksimal ${ALASAN_MAX} karakter.`;
  return '';
}
export function statusPayload(to, alasan) {
  const a = String(alasan ?? '').trim();
  return to === 'dibatalkan' ? { status: to, alasan: a } : { status: to };
}

export const PENANDA_PIC = 'PIC belum terhubung, dialihkan ke Kepala SPPG';
/** Penanda bila pesan dialihkan ke Kepala SPPG (data perjalanan atau pratinjau). */
export const picDialihkan = (rec) => !!rec && (rec.dialihkan_ke_kepala === true || rec.penerima === 'kepala_sppg');

export const KIRIM = { menunggu: 'Menunggu dikirim', terkirim: 'Terkirim', gagal: 'Gagal terkirim', tidak_terkirim: 'Tidak terkirim' };
export const kirimLabel = (v) => KIRIM[v] ?? v ?? '-';
export const kirimBadge = (v) => ({ terkirim: 'badge-ok', gagal: 'badge-danger', tidak_terkirim: 'badge-warn', menunggu: 'badge-info' }[v] || '');

export const SUMBER_ESTIMASI = { manual: 'diisi manual', jarak: 'dihitung dari jarak', jadwal: 'dari jam rencana tiba', default: 'perkiraan standar 30 menit' };
export const sumberEstimasiLabel = (v) => SUMBER_ESTIMASI[v] ?? '-';

/** Ringkasan pratinjau/perjalanan untuk tampilan: {estimasi:'08:50 WIB', durasi:'20 menit', sumber, dialihkan}. */
export function ringkasEstimasi(d) {
  if (!d) return null;
  return {
    estimasi: jamWIB(d.estimasi_tiba),
    durasi: d.estimasi_menit != null ? `${d.estimasi_menit} menit` : '-',
    sumber: sumberEstimasiLabel(d.estimasi_sumber),
    dialihkan: picDialihkan(d),
  };
}

/** Jadwal distribusi yang dapat memicu perjalanan: status siap/berangkat dan mutu belum ditahan. */
export const jadwalLayak = (j) => ['siap', 'berangkat'].includes(j?.status) && j?.layak_distribusi !== false;
export const jadwalLabel = (j) => [j.titik_layanan?.nama ?? `Jadwal #${j.id}`, j.jam_berangkat ? String(j.jam_berangkat).slice(0, 5) : null, j.jumlah_porsi != null ? `${Number(j.jumlah_porsi).toLocaleString('id-ID')} porsi` : null].filter(Boolean).join(' · ');

export const ESTIMASI_MIN = 1;
export const ESTIMASI_MAX = 720;

/** Skema form pemicu (waktu berangkat = sekarang, ditetapkan server). */
export function perjalananFields({ mode }) {
  if (mode === 'detail') {
    return [
      { name: 'titik_layanan', label: 'Titik layanan', display: (r) => r.titik_layanan?.nama ?? '-' },
      { name: 'status', label: 'Status', display: (r) => r.status_label || statusLabel(r.status) },
      { name: 'waktu_berangkat', label: 'Berangkat', display: (r) => tanggalJamWIB(r.waktu_berangkat) },
      { name: 'estimasi_tiba', label: 'Estimasi tiba', display: (r) => `${tanggalJamWIB(r.estimasi_tiba)}${r.estimasi_menit != null ? ` (${r.estimasi_menit} menit, ${sumberEstimasiLabel(r.estimasi_sumber)})` : ''}` },
      { name: 'waktu_tiba', label: 'Waktu tiba', display: (r) => tanggalJamWIB(r.waktu_tiba), onlyIfPresent: true },
      { name: 'waktu_dibatalkan', label: 'Dibatalkan', display: (r) => tanggalJamWIB(r.waktu_dibatalkan), onlyIfPresent: true },
      { name: 'alasan_batal', label: 'Alasan batal', onlyIfPresent: true },
      { name: 'pic', label: 'PIC titik layanan', display: (r) => [r.titik_layanan?.pic_nama, r.titik_layanan?.pic_terhubung === false ? '(belum terhubung)' : null].filter(Boolean).join(' ') || '-' },
      { name: 'status_kirim', label: 'Status kirim pesan', display: (r) => kirimLabel(r.status_kirim) },
      { name: 'dipicu_oleh', label: 'Dipicu oleh', display: (r) => r.dipicu_oleh?.name ?? '-' },
      { name: 'pesan', label: 'Pesan', onlyIfPresent: true },
    ];
  }
  return [
    {
      name: 'jadwal_distribusi_id', label: 'Jadwal distribusi (hari ini)', type: 'relasi', required: true,
      relation: { endpoint: '/jadwal/distribusi', query: { tanggal: todayWIB() }, filter: jadwalLayak, format: jadwalLabel, label: 'id', value: 'id' },
      help: 'Hanya jadwal berstatus Siap/Berangkat dengan mutu Layak.',
    },
    {
      name: 'estimasi_menit', label: 'Estimasi durasi (menit, opsional)', type: 'number', min: ESTIMASI_MIN, max: ESTIMASI_MAX, step: 1,
      help: 'Kosongkan agar dihitung otomatis dari jarak titik layanan.',
      validate: (v) => (v != null && !Number.isInteger(v) ? 'Harus bilangan bulat.' : ''),
    },
  ];
}

/** Muatan POST /notifikasi-perjalanan dan /pratinjau. */
export function perjalananPayload(values) {
  return buildPayload(values, { keys: ['jadwal_distribusi_id', 'estimasi_menit'], ints: ['jadwal_distribusi_id', 'estimasi_menit'], nullable: ['estimasi_menit'] });
}

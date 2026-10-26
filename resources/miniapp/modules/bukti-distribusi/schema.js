// Skema Bukti Distribusi (fitur 13, PBI 13). Kontrak: docs/api/paths/bukti-distribusi.yaml,
// docs/api/components/bukti-distribusi.yaml. Murni (tanpa DOM) agar dapat diuji dengan node --test.
import { buildPayload } from '../../core/schema-helpers.js';
import { noNik } from '../../core/validators.js';
import { todayWIB, tanggalJamWIB, jamWIB } from '../../core/waktu.js';

export const TERLAMBAT_BATAS_MENIT = 15; // config server; hanya untuk teks bantuan

export const STATUS_JADWAL = { menunggu: 'Menunggu', siap: 'Siap', berangkat: 'Berangkat', diterima: 'Diterima', dibatalkan: 'Dibatalkan' };
export const statusJadwalLabel = (v) => STATUS_JADWAL[v] ?? v ?? '-';

/** Relawan hanya unit distribusi; peran lain mengikuti matriks. */
export function unitDistribusiBoleh(user) {
  if (!user) return false;
  return user.role !== 'relawan' || user.unit === 'distribusi';
}

/** Jadwal yang boleh dipilih untuk mencatat bukti: aktif; relawan hanya hari ini dan milik sendiri/belum bertugas. */
export function jadwalDapatDipilih(j, user, today = todayWIB()) {
  if (!j || !['menunggu', 'siap', 'berangkat'].includes(j.status)) return false;
  if (user?.role === 'relawan') {
    if (String(j.tanggal_distribusi ?? '').slice(0, 10) !== today) return false;
    if (j.petugas_id != null && j.petugas_id !== user.id) return false;
  }
  return true;
}

export function jadwalOptionLabel(j) {
  const jam = j.jam_rencana_tiba ? ` · tiba ${String(j.jam_rencana_tiba).slice(0, 5)}` : '';
  return `${j.titik_layanan?.nama ?? `Jadwal #${j.id}`}${jam} · ${Number(j.jumlah_porsi ?? 0).toLocaleString('id-ID')} porsi · ${statusJadwalLabel(j.status)}`;
}

/** Peringatan pra-kirim berdasarkan jadwal terpilih. -> {tone:'danger'|'warn', text} | null. Server tetap otoritas (422). */
export function peringatanJadwal(j) {
  if (!j) return null;
  if (j.status === 'dibatalkan') return { tone: 'danger', text: 'Jadwal ini dibatalkan, bukti tidak dapat dicatat.' };
  if (j.status === 'diterima') return { tone: 'danger', text: 'Bukti untuk jadwal ini sudah dicatat (satu bukti per jadwal).' };
  if (j.status === 'menunggu') return { tone: 'danger', text: 'Jadwal belum siap (masih menunggu). Server akan menolak bukti sampai jadwal berstatus Siap/Berangkat.' };
  if (j.layak_distribusi === false) return { tone: 'danger', text: 'Distribusi ditahan: pemeriksaan mutu terbaru belum Layak. Server akan menolak bukti ini.' };
  if (j.status === 'siap') return { tone: 'warn', text: 'Armada belum berstatus Berangkat. Pastikan makanan sudah tiba di titik layanan sebelum mengirim bukti.' };
  return null;
}

export function terlambatLabel(rec) {
  if (!rec) return '-';
  if (rec.terlambat) return rec.menit_keterlambatan != null ? `Terlambat ${rec.menit_keterlambatan} menit` : 'Terlambat';
  return 'Tepat waktu';
}
export const terlambatBadge = (rec) => (rec?.terlambat ? 'badge-danger' : 'badge-ok');

export function selisihLabel(rec) {
  const s = rec?.selisih_porsi;
  if (s == null || s === 0) return 'Sesuai';
  return s > 0 ? `Kurang ${s} porsi` : `Lebih ${Math.abs(s)} porsi`;
}

/** Validasi porsi diterima terhadap porsi dikirim (jadwal). */
export function validasiPorsi(diterima, dikirim) {
  if (diterima == null || Number.isNaN(diterima)) return '';
  if (!Number.isInteger(diterima) || diterima < 0) return 'Porsi diterima harus bilangan bulat 0 atau lebih.';
  if (dikirim != null && diterima > dikirim) return `Porsi diterima tidak boleh melebihi porsi dikirim (${dikirim}).`;
  return '';
}
export function validasiCatatanSelisih(catatan, diterima, dikirim) {
  if (dikirim != null && diterima != null && diterima < dikirim && !String(catatan ?? '').trim()) return 'Catatan selisih wajib diisi bila porsi diterima kurang dari porsi dikirim.';
  return '';
}

/** Relawan hanya mengubah bukti yang ia catat pada hari yang sama; relawan tidak menghapus. */
export function rowPermsBukti(user, rec, p, today = todayWIB()) {
  if (user?.role !== 'relawan') return p;
  const milik = rec?.dicatat_oleh?.id != null && rec.dicatat_oleh.id === user.id;
  const hariIni = rec?.waktu_tiba ? todayWIB(new Date(rec.waktu_tiba)) === today : false;
  return { ...p, U: p.U && milik && hariIni, D: false };
}

/**
 * Skema form. jadwalSel = {cur} menyimpan jadwal terpilih (diisi kolom kustom `jadwal_distribusi_id` di index.js).
 * Kolom kustom (jadwal, porsi dikirim, waktu tiba, lokasi) dipasang oleh pemanggil lewat `custom`.
 */
export function buktiFields({ mode, jadwalSel, custom = {} }) {
  const dikirim = () => jadwalSel?.cur?.jumlah_porsi ?? null;
  return [
    ...(mode === 'create' ? [custom.jadwal] : []),
    { name: 'foto', label: 'Foto serah terima', type: 'photo', photoLabel: 'foto serah terima', required: mode === 'create', help: 'Ambil dengan kamera. Foto dikompres otomatis (maks 1600 px, maks 5 MB).' },
    custom.porsiDikirim,
    { name: 'porsi_diterima', label: 'Porsi diterima', type: 'number', required: true, min: 0, step: 1, validate: (v) => validasiPorsi(v, dikirim()) },
    { name: 'nama_pic', label: 'Nama penerima di titik (PIC)', type: 'text', required: true, maxlength: 100, placeholder: 'Mis. Ibu Ani S. (guru piket)', validate: (v) => noNik(v, 'Nama penerima') },
    { name: 'no_berita_acara', label: 'No. berita acara', type: 'text', maxlength: 50 },
    { name: 'catatan_selisih', label: 'Catatan selisih', type: 'textarea', maxlength: 1000, help: 'Wajib bila porsi diterima kurang dari porsi dikirim.', validate: (v, all) => validasiCatatanSelisih(v, all.porsi_diterima, dikirim()) },
    { name: 'catatan', label: 'Catatan', type: 'textarea', maxlength: 2000, placeholder: 'Opsional' },
    custom.waktuTiba,
    { name: 'berita_acara', label: 'Foto berita acara (opsional)', type: 'photo', photoLabel: 'berita acara' },
    ...(mode === 'create' ? [custom.lokasi] : []),
  ].filter(Boolean);
}

export const buktiDetailFields = [
  { name: 'titik', label: 'Titik layanan', display: (r) => r.jadwal_distribusi?.titik_layanan?.nama ?? '-' },
  { name: 'tanggal', label: 'Tanggal distribusi', display: (r) => r.jadwal_distribusi?.tanggal_distribusi ?? '-', type: 'date' },
  { name: 'porsi_dikirim', label: 'Porsi dikirim', type: 'number' },
  { name: 'porsi_diterima', label: 'Porsi diterima', type: 'number' },
  { name: 'selisih_porsi', label: 'Selisih', display: (r) => selisihLabel(r) },
  { name: 'nama_pic', label: 'Penerima (PIC)' },
  { name: 'no_berita_acara', label: 'No. berita acara' },
  { name: 'waktu_berangkat', label: 'Berangkat', display: (r) => tanggalJamWIB(r.waktu_berangkat) },
  { name: 'waktu_tiba', label: 'Waktu tiba', display: (r) => tanggalJamWIB(r.waktu_tiba) },
  { name: 'acuan_tiba', label: 'Acuan tiba', display: (r) => (r.acuan_tiba ? jamWIB(r.acuan_tiba) : '-') },
  { name: 'terlambat', label: 'Ketepatan waktu', display: (r) => terlambatLabel(r) },
  { name: 'catatan_selisih', label: 'Catatan selisih' },
  { name: 'catatan', label: 'Catatan' },
  { name: 'dicatat_oleh', label: 'Dicatat oleh', display: (r) => r.dicatat_oleh?.name ?? '-' },
];

/** Payload: foto/berita_acara berupa File (multipart otomatis). lokasi = {latitude, longitude} dari kolom kustom. */
export function buktiPayload(values, { mode } = {}) {
  const v = { ...values, latitude: values.lokasi?.latitude ?? null, longitude: values.lokasi?.longitude ?? null };
  const out = buildPayload(v, {
    keys: ['jadwal_distribusi_id', 'porsi_diterima', 'nama_pic', 'no_berita_acara', 'catatan_selisih', 'catatan', 'latitude', 'longitude', 'foto', 'berita_acara'],
    ints: ['jadwal_distribusi_id', 'porsi_diterima'],
    nums: ['latitude', 'longitude'],
    nullable: ['no_berita_acara', 'catatan_selisih', 'catatan'],
  });
  if (mode === 'update') delete out.jadwal_distribusi_id; // tidak dapat diubah (422)
  return out;
}

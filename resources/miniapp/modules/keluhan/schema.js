// Skema Feedback dan Keluhan (fitur 15). Kontrak: docs/api/paths/keluhan.yaml, components/keluhan.yaml.
// Murni (tanpa DOM) agar bisa diuji. Foto keluhan: backend belum menerima foto_path, sehingga tidak ada unggah.
import { buildPayload, sppgField } from '../../core/schema-helpers.js';
import { tanggalJamWIB, addDays, todayWIB } from '../../core/waktu.js';

export const KATEGORI = [
  { value: 'makanan_basi', label: 'Makanan basi' },
  { value: 'gejala_sakit', label: 'Gejala sakit' },
  { value: 'porsi_kurang', label: 'Porsi kurang' },
  { value: 'terlambat', label: 'Keterlambatan' },
  { value: 'rasa', label: 'Rasa' },
  { value: 'kemasan', label: 'Kemasan' },
  { value: 'lainnya', label: 'Lainnya' },
];
export const SUMBER = [
  { value: 'guru', label: 'Guru' },
  { value: 'orang_tua', label: 'Orang tua' },
  { value: 'penerima', label: 'Penerima manfaat' },
  { value: 'relawan', label: 'Relawan' },
  { value: 'lainnya', label: 'Lainnya' },
];
export const STATUS = [
  { value: 'baru', label: 'Baru' },
  { value: 'diproses', label: 'Diproses' },
  { value: 'selesai', label: 'Selesai' },
  { value: 'ditolak', label: 'Ditolak' },
];
export const PRIORITAS = [
  { value: 'kritis', label: 'Kritis' },
  { value: 'tinggi', label: 'Tinggi' },
  { value: 'sedang', label: 'Sedang' },
  { value: 'rendah', label: 'Rendah' },
];
const lbl = (list, v) => list.find((x) => x.value === v)?.label ?? v ?? '-';
export const kategoriLabel = (v) => lbl(KATEGORI, v);
export const statusLabel = (v) => lbl(STATUS, v);
export const prioritasLabel = (v) => lbl(PRIORITAS, v);
export const sumberLabel = (v) => lbl(SUMBER, v);

/** Prioritas otomatis dari kategori (server menegakkan; ini hanya untuk peringatan di form). */
export function prioritasOtomatis(kategori) {
  if (kategori === 'makanan_basi' || kategori === 'gejala_sakit') return 'kritis';
  if (kategori === 'porsi_kurang' || kategori === 'terlambat') return 'sedang';
  return 'rendah';
}
export const isKritis = (kategori) => prioritasOtomatis(kategori) === 'kritis';

const BADGE_PRIORITAS = { kritis: 'badge-danger', tinggi: 'badge-warn', sedang: 'badge-info', rendah: '' };
export const prioritasBadge = (v) => BADGE_PRIORITAS[v] ?? '';
const BADGE_STATUS = { baru: 'badge-info', diproses: 'badge-warn', selesai: 'badge-ok', ditolak: '' };
export const statusBadge = (v) => BADGE_STATUS[v] ?? '';

// baru -> diproses -> selesai | ditolak (selesai/ditolak final).
export const TRANSISI = { baru: ['diproses'], diproses: ['selesai', 'ditolak'], selesai: [], ditolak: [] };
export const transisiValid = (status) => [...(TRANSISI[status] ?? [])];
export const isFinal = (status) => transisiValid(status).length === 0;
/** Pilihan status saat tindak lanjut: status sekarang + transisi valid. */
export const statusOptionsUpdate = (current) => [current, ...transisiValid(current)].filter(Boolean).map((v) => ({ value: v, label: statusLabel(v) }));

export const TL_MAX = 2000;
/** tindak_lanjut wajib saat status selesai/ditolak. '' bila valid. */
export function validasiTindakLanjut(status, tl) {
  const t = String(tl ?? '').trim();
  if ((status === 'selesai' || status === 'ditolak') && !t) return `Catatan tindak lanjut wajib diisi saat status ${statusLabel(status).toLowerCase()}.`;
  if (t.length > TL_MAX) return `Maksimal ${TL_MAX} karakter.`;
  return '';
}

/**
 * Keluhan kritis wajib terkait batch: jadwal produksi, atau titik layanan (server menyimpulkan batch dari
 * jadwal distribusi hari ini), atau tombol "batch tidak diketahui". '' bila valid.
 */
export function validasiBatch(all) {
  if (!isKritis(all?.kategori)) return '';
  if (all.jadwal_produksi_id || all.batch_tidak_diketahui === true || all.titik_layanan_id) return '';
  return 'Keluhan kritis wajib dikaitkan dengan batch: pilih jadwal produksi, pilih titik layanan, atau tekan "Batch tidak diketahui".';
}

/** Hak per baris: kepala/ahli gizi tak dapat mengubah keluhan yang sudah final; Admin bebas. */
export function rowPermsKeluhan(user, rec, p) {
  if (user?.role === 'admin') return p;
  return { ...p, U: p.U && !isFinal(rec?.status), D: false };
}

/** Muatan POST/PUT. Non-admin ubah: hanya status + tindak_lanjut. */
export function keluhanPayload(values, { mode, user }) {
  const admin = user?.role === 'admin';
  if (mode === 'update' && !admin) {
    return buildPayload(values, { keys: ['status', 'tindak_lanjut'], nullable: ['tindak_lanjut'] });
  }
  const keys = mode === 'create'
    ? ['sppg_id', 'sumber', 'kategori', 'titik_layanan_id', 'jadwal_produksi_id', 'deskripsi']
    : ['sumber', 'kategori', 'prioritas', 'titik_layanan_id', 'jadwal_produksi_id', 'deskripsi', 'status', 'tindak_lanjut'];
  const out = buildPayload(values, {
    keys, ints: ['sppg_id', 'titik_layanan_id', 'jadwal_produksi_id'], nullable: ['titik_layanan_id', 'jadwal_produksi_id', 'tindak_lanjut', 'prioritas'],
  });
  if (mode === 'create' && !admin) delete out.sppg_id;
  if (mode === 'create' && isKritis(values.kategori) && values.batch_tidak_diketahui === true && !out.jadwal_produksi_id) out.batch_tidak_diketahui = true;
  return out;
}

const jadwalFormat = (j) => `${String(j.tanggal_produksi ?? '').slice(0, 10)} · ${j.menu?.nama_menu ?? `Jadwal #${j.id}`}`;

/** Skema form. mode: create | update | detail. */
export function keluhanFields({ mode, user, record }) {
  const admin = user?.role === 'admin';
  if (mode === 'detail') {
    return [
      { name: 'kategori', label: 'Kategori', display: (r) => r.kategori_label || kategoriLabel(r.kategori) },
      { name: 'prioritas', label: 'Prioritas', display: (r) => prioritasLabel(r.prioritas) },
      { name: 'status', label: 'Status', display: (r) => r.status_label || statusLabel(r.status) },
      { name: 'sumber', label: 'Sumber', display: (r) => sumberLabel(r.sumber) },
      { name: 'titik_layanan', label: 'Titik layanan', display: (r) => r.titik_layanan?.nama ?? '-' },
      { name: 'jadwal_produksi_id', label: 'Batch (jadwal produksi)', display: (r) => (r.jadwal_produksi_id ? `Jadwal ${String(r.jadwal_produksi_tanggal ?? '').slice(0, 10) || `#${r.jadwal_produksi_id}`}` : 'Tidak diketahui / tidak terkait') },
      { name: 'deskripsi', label: 'Deskripsi' },
      { name: 'tindak_lanjut', label: 'Tindak lanjut' },
      { name: 'dicatat_oleh', label: 'Dicatat oleh', display: (r) => r.dicatat_oleh?.name ?? '-' },
      { name: 'ditangani_oleh', label: 'Ditangani oleh', display: (r) => r.ditangani_oleh?.name ?? '-' },
      { name: 'created_at', label: 'Dicatat pada', display: (r) => tanggalJamWIB(r.created_at) },
      { name: 'dieskalasi_pada', label: 'Dieskalasi pada', display: (r) => tanggalJamWIB(r.dieskalasi_pada), onlyIfPresent: true },
      { name: 'selesai_pada', label: 'Selesai pada', display: (r) => tanggalJamWIB(r.selesai_pada), onlyIfPresent: true },
    ];
  }
  const tindak = [
    { name: 'status', label: 'Status', type: 'select', required: true, options: statusOptionsUpdate(record?.status ?? 'baru'), default: record?.status },
    {
      name: 'tindak_lanjut', label: 'Catatan tindak lanjut', type: 'textarea', maxlength: TL_MAX,
      help: 'Wajib diisi saat status Selesai atau Ditolak.',
      validate: (v, all) => validasiTindakLanjut(all.status, v),
    },
  ];
  if (mode === 'update' && !admin) return tindak;

  const isi = [
    ...(mode === 'create' ? sppgField(user, mode) : []),
    { name: 'sumber', label: 'Sumber', type: 'select', required: true, options: SUMBER, default: user?.role === 'relawan' ? 'relawan' : undefined },
    { name: 'kategori', label: 'Kategori', type: 'select', required: true, options: KATEGORI },
    { name: 'titik_layanan_id', label: 'Titik layanan', type: 'relasi', relation: { endpoint: '/titik-layanan', label: 'nama', value: 'id' } },
    {
      name: 'jadwal_produksi_id', label: 'Jadwal produksi terkait (batch)', type: 'relasi',
      relation: { endpoint: '/jadwal', query: { from: addDays(todayWIB(), -14) }, format: jadwalFormat, label: 'id', value: 'id' },
      help: 'Bila diketahui. Wajib untuk keluhan kritis kecuali titik layanan dipilih atau batch tidak diketahui.',
      validate: mode === 'create' ? (v, all) => validasiBatch(all) : undefined,
    },
    { name: 'deskripsi', label: 'Deskripsi', type: 'textarea', required: true, maxlength: 2000 },
  ];
  if (mode === 'create') return isi;
  // Admin ubah: semua kolom + prioritas + tindak lanjut
  isi.splice(2, 0, { name: 'prioritas', label: 'Prioritas', type: 'select', options: PRIORITAS });
  return [...isi, ...tindak];
}

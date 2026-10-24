// Skema Log Produksi Harian (fitur 10). Kontrak: docs/api/paths/log-produksi.yaml, components/log-produksi.yaml.
// Foto: backend baru menerima foto_path (teks); unggah berkas belum tersedia, jadi tidak ada kolom foto.
import { buildPayload } from '../../core/schema-helpers.js';
import { todayISO } from '../../core/validators.js';

export const jadwalOptionLabel = (j) => `${j.tanggal_produksi} · ${j.menu?.nama_menu ?? 'Menu'} (target ${j.target_porsi})`;

export function logFields({ mode, record } = {}) {
  return [
    ...(mode === 'create' ? [{ name: 'jadwal_produksi_id', label: 'Jadwal produksi', type: 'relasi', required: true, relation: { endpoint: '/jadwal', filter: (j) => j.status !== 'dibatalkan' && String(j.tanggal_produksi).slice(0, 10) <= todayISO(), format: jadwalOptionLabel }, help: 'Hanya jadwal yang tidak dibatalkan dan tanggalnya sudah tiba.' }] : []),
    { name: 'kode_batch', label: 'Kode batch', type: 'text', readonly: true, placeholder: 'Dibuat otomatis (BATCH-TTTTBBHH-NN)', default: record?.kode_batch ?? '' },
    { name: 'realisasi_porsi', label: 'Realisasi porsi', type: 'number', required: true, min: 0, step: 1 },
    { name: 'sisa_makanan_kg', label: 'Sisa makanan (kg)', type: 'number', min: 0, max: 99999, step: 'any', default: 0 },
    { name: 'waktu_mulai', label: 'Jam mulai', type: 'time' },
    { name: 'jam_selesai', label: 'Jam selesai', type: 'time', validate: (v, all) => (v && all.waktu_mulai && String(v).slice(0, 5) <= String(all.waktu_mulai).slice(0, 5) ? 'Jam selesai harus setelah jam mulai.' : '') },
    { name: 'kendala', label: 'Kendala', type: 'textarea', maxlength: 2000, placeholder: 'Mis. gas hampir habis' },
  ];
}

const hhmm = (v) => (v ? String(v).slice(0, 5) : '-');
export const logDetailFields = [
  { name: 'jadwal', label: 'Jadwal', display: (r) => [r.jadwal?.menu?.nama_menu, r.jadwal?.tanggal_produksi].filter(Boolean).join(' · ') || '-' },
  { name: 'kode_batch', label: 'Kode batch' },
  { name: 'target_porsi', label: 'Target porsi', display: (r) => (r.target_porsi == null ? '-' : Number(r.target_porsi).toLocaleString('id-ID')) },
  { name: 'realisasi_porsi', label: 'Realisasi porsi', display: (r) => Number(r.realisasi_porsi ?? 0).toLocaleString('id-ID') },
  { name: 'selisih_porsi', label: 'Selisih', display: (r) => selisihText(r.selisih_porsi) },
  { name: 'persentase_capaian', label: 'Capaian', display: (r) => (r.persentase_capaian == null ? '-' : `${Number(r.persentase_capaian).toLocaleString('id-ID', { maximumFractionDigits: 1 })}%`) },
  { name: 'sisa_makanan_kg', label: 'Sisa makanan', display: (r) => `${Number(r.sisa_makanan_kg ?? 0).toLocaleString('id-ID')} kg` },
  { name: 'waktu_mulai', label: 'Jam mulai', display: (r) => hhmm(r.waktu_mulai) },
  { name: 'jam_selesai', label: 'Jam selesai', display: (r) => hhmm(r.jam_selesai) },
  { name: 'kendala', label: 'Kendala' },
  { name: 'pencatat', label: 'Dicatat oleh' },
];

export const logPayload = (values, { mode } = {}) => {
  const out = buildPayload(values, {
    keys: ['jadwal_produksi_id', 'realisasi_porsi', 'sisa_makanan_kg', 'waktu_mulai', 'jam_selesai', 'kendala'],
    ints: ['jadwal_produksi_id', 'realisasi_porsi'],
    nums: ['sisa_makanan_kg'],
    nullable: ['waktu_mulai', 'jam_selesai', 'kendala'],
  });
  if (mode === 'update') delete out.jadwal_produksi_id; // tidak dapat diubah
  return out;
};

/** "+20" / "-30" / "0" (porsi). */
export const selisihText = (s) => (s == null ? '-' : `${s > 0 ? '+' : ''}${Number(s).toLocaleString('id-ID')} porsi`);

/** Hitung sisi klien (cermin server): {selisih, persen, peringatan}. Target 0/kosong -> null. */
export function hitungCapaian(realisasi, target) {
  const r = Number(realisasi);
  const t = Number(target);
  if (realisasi === '' || realisasi == null || Number.isNaN(r) || target == null || Number.isNaN(t) || t <= 0) return null;
  const selisih = r - t;
  const persen = Math.round((r / t) * 1000) / 10;
  const peringatan = selisih === 0 ? null : selisih < 0 ? `Realisasi ${-selisih} porsi di bawah target.` : `Realisasi ${selisih} porsi di atas target.`;
  return { selisih, persen, peringatan };
}

/** Relawan hanya mengubah log buatannya sendiri; Kepala SPPG bebas (U) dan satu-satunya D. */
export function rowPermsLog(user, rec, p) {
  const milik = user?.role !== 'relawan' || rec?.dicatat_oleh == null || rec.dicatat_oleh === user.id;
  return { ...p, U: p.U && milik };
}

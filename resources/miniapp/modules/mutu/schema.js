// Skema Pemeriksaan Mutu dan Keamanan Pangan (fitur 11). Kontrak: docs/api/paths/mutu.yaml, components/mutu.yaml.
// Foto: backend baru menerima foto_path (teks); unggah berkas belum tersedia, jadi tidak ada kolom foto.
import { buildPayload } from '../../core/schema-helpers.js';
import { toBool, todayISO } from '../../core/validators.js';

export const SUHU_MINIMUM_C = 60; // default config('produksi.mutu.suhu_minimum_c'); server otoritas
export const HASIL = [{ value: 'layak', label: 'Layak' }, { value: 'tidak_layak', label: 'Tidak layak' }];
export const hasilLabel = (v) => HASIL.find((x) => x.value === v)?.label ?? v ?? '-';
export const hasilBadge = (v) => (v === 'layak' ? 'badge-ok' : v === 'tidak_layak' ? 'badge-danger' : '');

const pilih = (ya, tidak) => [{ value: 'true', label: ya }, { value: 'false', label: tidak }];
export const jadwalOptionLabel = (j) => `${j.tanggal_produksi} · ${j.menu?.nama_menu ?? 'Menu'}`;

/**
 * Konsistensi hasil dengan checklist (cermin aturan server). '' bila konsisten.
 * layak hanya bila kebersihan dapur, petugas, organoleptik baik, dan suhu >= minimum (bila suhu diisi).
 */
export function cekKonsistensiMutu(v, suhuMin = SUHU_MINIMUM_C) {
  if (v.hasil !== 'layak') return '';
  const t = (x) => toBool(x) === true;
  const masalah = [];
  if (!t(v.kebersihan_dapur)) masalah.push('kebersihan dapur belum baik');
  if (!t(v.kebersihan_petugas)) masalah.push('kebersihan petugas belum baik');
  if (!t(v.organoleptik_baik)) masalah.push('rasa/aroma/tampilan tidak baik');
  if (v.suhu_makanan_c != null && v.suhu_makanan_c !== '' && Number(v.suhu_makanan_c) < suhuMin) masalah.push(`suhu di bawah ${suhuMin} °C`);
  return masalah.length ? `Tidak dapat dinyatakan Layak: ${masalah.join(', ')}. Pilih Tidak layak dan isi tindakan korektif.` : '';
}

export function mutuFields({ mode, record } = {}) {
  const koreksiWajib = mode === 'update' && !!record?.memengaruhi_distribusi;
  return [
    ...(mode === 'create' ? [{ name: 'jadwal_produksi_id', label: 'Jadwal produksi', type: 'relasi', required: true, relation: { endpoint: '/jadwal', filter: (j) => j.status !== 'dibatalkan' && String(j.tanggal_produksi).slice(0, 10) <= todayISO(), format: jadwalOptionLabel }, help: 'Pemeriksaan terbaru menjadi gerbang keberangkatan distribusi.' }] : []),
    { name: 'suhu_makanan_c', label: 'Suhu makanan (°C)', type: 'number', min: -50, max: 200, step: '0.1', help: `Layak membutuhkan suhu minimal ${SUHU_MINIMUM_C} °C bila diisi.` },
    { name: 'kebersihan_dapur', label: 'Kebersihan dapur', type: 'select', required: true, options: pilih('Bersih', 'Belum bersih') },
    { name: 'kebersihan_petugas', label: 'Kebersihan petugas', type: 'select', required: true, options: pilih('Bersih', 'Belum bersih') },
    { name: 'organoleptik_baik', label: 'Rasa, aroma, dan tampilan', type: 'select', required: true, options: pilih('Baik', 'Tidak baik') },
    { name: 'catatan_organoleptik', label: 'Catatan organoleptik', type: 'textarea', maxlength: 2000, required: true, showIf: (v) => v.organoleptik_baik === 'false', help: 'Wajib bila rasa/aroma/tampilan tidak baik.' },
    { name: 'sampel_disimpan', label: 'Sampel makanan disimpan', type: 'select', required: true, default: 'false', options: pilih('Ya', 'Tidak') },
    { name: 'hasil', label: 'Hasil pemeriksaan', type: 'select', required: true, options: HASIL, validate: (v, all) => cekKonsistensiMutu(all) },
    { name: 'tindakan_korektif', label: 'Tindakan korektif', type: 'textarea', maxlength: 2000, required: true, showIf: (v) => v.hasil === 'tidak_layak', help: 'Wajib. Hasil Tidak layak menahan keberangkatan distribusi untuk jadwal ini.' },
    ...(koreksiWajib ? [{ name: 'catatan_koreksi', label: 'Catatan koreksi', type: 'textarea', maxlength: 2000, required: true, help: 'Pemeriksaan ini sudah memengaruhi distribusi. Alasan koreksi wajib dan dicatat di audit.' }] : []),
  ];
}

const yn = (v, ya, tidak) => (v ? ya : tidak);
export const mutuDetailFields = [
  { name: 'jadwal', label: 'Jadwal produksi', display: (r) => [r.jadwal?.menu?.nama_menu, r.jadwal?.tanggal_produksi].filter(Boolean).join(' · ') || '-' },
  { name: 'hasil', label: 'Hasil', display: (r) => r.hasil_label || hasilLabel(r.hasil) },
  { name: 'suhu_makanan_c', label: 'Suhu makanan', display: (r) => (r.suhu_makanan_c == null ? '-' : `${Number(r.suhu_makanan_c).toLocaleString('id-ID')} °C`) },
  { name: 'kebersihan_dapur', label: 'Kebersihan dapur', display: (r) => yn(r.kebersihan_dapur, 'Bersih', 'Belum bersih') },
  { name: 'kebersihan_petugas', label: 'Kebersihan petugas', display: (r) => yn(r.kebersihan_petugas, 'Bersih', 'Belum bersih') },
  { name: 'organoleptik_baik', label: 'Rasa, aroma, tampilan', display: (r) => yn(r.organoleptik_baik, 'Baik', 'Tidak baik') },
  { name: 'catatan_organoleptik', label: 'Catatan organoleptik' },
  { name: 'sampel_disimpan', label: 'Sampel disimpan', display: (r) => yn(r.sampel_disimpan, 'Ya', 'Tidak') },
  { name: 'tindakan_korektif', label: 'Tindakan korektif' },
  { name: 'pemeriksa', label: 'Pemeriksa' },
  { name: 'waktu_pemeriksaan', label: 'Waktu pemeriksaan', type: 'datetime' },
  { name: 'catatan_koreksi', label: 'Catatan koreksi' },
  { name: 'dikoreksi_pada', label: 'Dikoreksi pada', type: 'datetime' },
];

export const mutuPayload = (values, { mode } = {}) => {
  const out = buildPayload(values, {
    keys: ['jadwal_produksi_id', 'suhu_makanan_c', 'kebersihan_dapur', 'kebersihan_petugas', 'organoleptik_baik', 'catatan_organoleptik', 'sampel_disimpan', 'hasil', 'tindakan_korektif', 'catatan_koreksi'],
    ints: ['jadwal_produksi_id'],
    nums: ['suhu_makanan_c'],
    bools: ['kebersihan_dapur', 'kebersihan_petugas', 'organoleptik_baik', 'sampel_disimpan'],
    nullable: ['suhu_makanan_c', 'catatan_organoleptik', 'tindakan_korektif'],
  });
  if (mode === 'update') delete out.jadwal_produksi_id;
  if (out.hasil === 'layak') out.tindakan_korektif = null;
  return out;
};

/** Hapus disembunyikan bila server pasti menolak (memengaruhi_distribusi). Koreksi tetap lewat Ubah. */
export const rowPermsMutu = (rec, p) => ({ ...p, D: p.D && !rec.memengaruhi_distribusi });

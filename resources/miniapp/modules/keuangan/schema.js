// Skema Keuangan (fitur 14, PBI 14): ringkasan anggaran, pencairan dana, pengeluaran operasional.
// Kontrak: docs/api/paths/keuangan.yaml, docs/api/components/keuangan.yaml. Murni (tanpa DOM).
import { buildPayload } from '../../core/schema-helpers.js';
import { todayWIB } from '../../core/waktu.js';

export const NOMINAL_MAX = 9999999999999;
export const AMBANG_PERINGATAN = 80;
export const AMBANG_HABIS = 100;

export const KATEGORI = [
  { value: 'bahan_baku', label: 'Belanja Bahan' },
  { value: 'insentif_relawan', label: 'Insentif Relawan' },
  { value: 'energi', label: 'Energi (Gas/Listrik/Air)' },
  { value: 'transportasi', label: 'Transportasi (BBM)' },
  { value: 'peralatan', label: 'Peralatan' },
  { value: 'lainnya', label: 'Lainnya' },
];
export const kategoriLabel = (v) => KATEGORI.find((k) => k.value === v)?.label ?? v ?? '-';

// ---- Rupiah: tampil "1.250.000", kirim integer ----
/** 1250000 -> "1.250.000" (tanpa bergantung ICU). */
export function formatRibuan(n) {
  if (n == null || n === '' || Number.isNaN(Number(n))) return '';
  const v = Math.trunc(Number(n));
  return `${v < 0 ? '-' : ''}${String(Math.abs(v)).replace(/\B(?=(\d{3})+(?!\d))/g, '.')}`;
}
export const formatRupiah = (n) => (n == null || n === '' ? '-' : `Rp ${formatRibuan(n)}`);

/**
 * Teks isian -> integer rupiah. '' -> null. Titik/spasi/awalan "Rp" dianggap pemisah ribuan.
 * Koma/desimal atau huruf -> NaN (rupiah bulat saja; ditolak oleh validasiNominal).
 */
export function parseRupiah(text) {
  const s = String(text ?? '').replace(/^\s*rp\.?\s*/i, '').replace(/[\s.]/g, '');
  if (s === '') return null;
  if (!/^\d+$/.test(s)) return NaN;
  const n = Number(s);
  return Number.isSafeInteger(n) ? n : NaN;
}
export function validasiNominal(v, label = 'Nominal') {
  if (v == null) return '';
  if (typeof v !== 'number' || Number.isNaN(v) || !Number.isInteger(v)) return `${label} harus berupa rupiah bulat tanpa desimal.`;
  if (v < 1) return `${label} minimal Rp 1.`;
  if (v > NOMINAL_MAX) return `${label} terlalu besar (maksimal ${formatRupiah(NOMINAL_MAX)}).`;
  return '';
}

// ---- Periode & tanggal ----
export const periodeDari = (tanggal) => String(tanggal ?? '').slice(0, 7);
export const periodeSekarang = (now = new Date()) => todayWIB(now).slice(0, 7);
export const PERIODE_RE = /^\d{4}-(0[1-9]|1[0-2])$/;
const BULAN = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
export function labelPeriode(p) {
  if (!PERIODE_RE.test(String(p ?? ''))) return p ?? '-';
  return `${BULAN[Number(p.slice(5, 7)) - 1]} ${p.slice(0, 4)}`;
}
export const validasiPeriode = (p) => (!p ? '' : PERIODE_RE.test(p) ? '' : 'Format periode harus TTTT-BB (contoh 2026-11).');
export function tidakMasaDepan(v, label = 'Tanggal', today = todayWIB()) {
  if (!v) return '';
  return String(v).slice(0, 10) > today ? `${label} tidak boleh di masa depan.` : '';
}

// ---- Status anggaran (ambang 80/100%) ----
/** Persen serapan; null bila belum ada anggaran. */
export function hitungPersen(anggaran, terpakai) {
  const a = Number(anggaran) || 0;
  return a > 0 ? (Number(terpakai) || 0) / a * 100 : null;
}
export const clampPersen = (v) => Math.max(0, Math.min(100, Number(v) || 0));

/** Status dari server diutamakan; bila tak ada dihitung dari angka (aman <80, peringatan >=80, habis =100, melampaui >100). */
export function statusKunci({ status, anggaran, terpakai, persen } = {}) {
  if (['aman', 'peringatan', 'habis', 'melampaui', 'belum_ada_anggaran'].includes(status)) return status;
  const a = Number(anggaran) || 0;
  const t = Number(terpakai) || 0;
  if (a <= 0) return t > 0 ? 'melampaui' : 'belum_ada_anggaran';
  const p = persen ?? t / a * 100;
  if (p > AMBANG_HABIS) return 'melampaui';
  if (p >= AMBANG_HABIS) return 'habis';
  if (p >= AMBANG_PERINGATAN) return 'peringatan';
  return 'aman';
}
const STATUS_INFO = {
  aman: { tone: 'ok', badge: 'badge-ok', label: 'Aman', teks: 'Aman: serapan di bawah 80%.' },
  peringatan: { tone: 'warn', badge: 'badge-warn', label: 'Peringatan', teks: 'Peringatan: serapan sudah 80% atau lebih.' },
  habis: { tone: 'danger', badge: 'badge-danger', label: 'Anggaran habis', teks: 'Anggaran habis: serapan 100%.' },
  melampaui: { tone: 'danger', badge: 'badge-danger', label: 'Melampaui anggaran', teks: 'Melampaui anggaran: pengeluaran lebih besar dari pencairan.' },
  belum_ada_anggaran: { tone: 'muted', badge: '', label: 'Belum ada pencairan', teks: 'Belum ada pencairan dana pada periode ini.' },
};
/** -> {key, tone, badge, label, teks}; tone dipakai untuk warna, label/teks untuk pembaca yang tidak mengandalkan warna. */
export const statusAnggaran = (r) => ({ key: statusKunci(r), ...STATUS_INFO[statusKunci(r)] });

/** Pengeluaran baru melebihi sisa anggaran periode? sisa boleh negatif. */
export const melebihiSisa = (nominal, sisa) => Number.isFinite(nominal) && Number.isFinite(sisa) && nominal > sisa;
export const teksPersen = (p) => (p == null ? '-' : `${(Math.round(Number(p) * 10) / 10).toString().replace('.', ',')}%`);

// ---- Skema form ----
/** custom = {periode, nominal} kolom kustom dari index (DOM). */
export function pencairanFields({ custom }) {
  return [
    { ...custom.periode, name: 'periode', label: 'Periode', required: true, validate: (v) => validasiPeriode(v) },
    { name: 'sumber_dana', label: 'Sumber dana', type: 'text', required: true, maxlength: 100, placeholder: 'Mis. BGN Tahap I' },
    { ...custom.nominal('nominal', 'Nominal pencairan') },
    { name: 'tanggal_cair', label: 'Tanggal cair', type: 'date', required: true, default: todayWIB(), validate: (v) => tidakMasaDepan(v, 'Tanggal cair') },
    { name: 'no_referensi', label: 'No. referensi', type: 'text', maxlength: 50 },
    { name: 'catatan', label: 'Catatan', type: 'textarea', maxlength: 2000 },
    { name: 'bukti', label: 'Bukti transfer (opsional)', type: 'photo', photoLabel: 'bukti transfer' },
  ];
}
export function pengeluaranFields({ custom, record = null }) {
  return [
    { name: 'tanggal', label: 'Tanggal', type: 'date', required: true, default: todayWIB(), validate: (v) => tidakMasaDepan(v, 'Tanggal') },
    { name: 'kategori', label: 'Kategori', type: 'select', required: true, options: KATEGORI },
    { name: 'uraian', label: 'Uraian', type: 'text', required: true, maxlength: 255, placeholder: 'Mis. Belanja daging ayam 140 kg' },
    { ...custom.nominal('nominal', 'Nominal') },
    { name: 'pemasok_id', label: 'Pemasok (opsional)', type: 'relasi', default: record?.pemasok?.id ?? '', relation: { endpoint: '/pemasok', label: 'nama', value: 'id' } },
    { name: 'pencairan_dana_id', label: 'Sumber pencairan (opsional)', type: 'relasi', relation: { endpoint: '/keuangan/pencairan', value: 'id', format: (p) => `${labelPeriode(p.periode)} · ${p.sumber_dana} · ${formatRupiah(p.nominal)}` } },
    { name: 'nota', label: 'Foto nota (opsional)', type: 'photo', photoLabel: 'nota' },
  ];
}

export const pencairanDetailFields = [
  { name: 'periode', label: 'Periode', display: (r) => labelPeriode(r.periode) },
  { name: 'sumber_dana', label: 'Sumber dana' },
  { name: 'nominal', label: 'Nominal', display: (r) => formatRupiah(r.nominal) },
  { name: 'tanggal_cair', label: 'Tanggal cair', type: 'date' },
  { name: 'no_referensi', label: 'No. referensi' },
  { name: 'catatan', label: 'Catatan' },
  { name: 'dicatat_oleh', label: 'Dicatat oleh', display: (r) => r.dicatat_oleh?.name ?? '-' },
];
export const pengeluaranDetailFields = [
  { name: 'tanggal', label: 'Tanggal', type: 'date' },
  { name: 'periode', label: 'Periode', display: (r) => labelPeriode(r.periode) },
  { name: 'kategori', label: 'Kategori', display: (r) => r.kategori_label || kategoriLabel(r.kategori) },
  { name: 'uraian', label: 'Uraian' },
  { name: 'nominal', label: 'Nominal', display: (r) => formatRupiah(r.nominal) },
  { name: 'status_persetujuan', label: 'Persetujuan investor', display: (r) => r.status_persetujuan_label || ({ diajukan: 'Menunggu persetujuan', disetujui: 'Disetujui', ditolak: 'Ditolak' }[r.status_persetujuan] ?? 'Disetujui') },
  { name: 'pemasok', label: 'Pemasok', display: (r) => r.pemasok?.nama ?? '-' },
  { name: 'melebihi_anggaran', label: 'Terhadap anggaran', display: (r) => (r.status_persetujuan && r.status_persetujuan !== 'disetujui' ? 'Dihitung setelah disetujui' : r.melebihi_anggaran ? 'Melebihi anggaran periode' : 'Dalam anggaran') },
  { name: 'dicatat_oleh', label: 'Dicatat oleh', display: (r) => r.dicatat_oleh?.name ?? '-' },
];

export const pencairanPayload = (v) => buildPayload(v, {
  keys: ['periode', 'sumber_dana', 'nominal', 'tanggal_cair', 'no_referensi', 'catatan', 'bukti'],
  ints: ['nominal'],
  nullable: ['no_referensi', 'catatan'],
});
export const pengeluaranPayload = (v) => buildPayload(v, {
  keys: ['tanggal', 'kategori', 'uraian', 'nominal', 'pemasok_id', 'pencairan_dana_id', 'nota'],
  ints: ['nominal', 'pemasok_id', 'pencairan_dana_id'],
  nullable: ['pemasok_id', 'pencairan_dana_id'],
});

/** Seri donat per kategori dari ringkasan (label, nominal, persen). */
export function seriKategori(r) {
  const items = (r?.per_kategori ?? []).filter((x) => Number(x.nominal) > 0);
  return { items: items.map((x) => ({ key: x.kategori, label: x.label || kategoriLabel(x.kategori), value: Number(x.nominal), persen: x.persen })), total: items.reduce((a, x) => a + Number(x.nominal), 0) };
}

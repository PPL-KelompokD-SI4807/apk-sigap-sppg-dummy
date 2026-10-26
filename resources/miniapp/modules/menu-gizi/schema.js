// Skema Menu dan Gizi (fitur 8). Kontrak: docs/api/paths/menu-gizi.yaml, components/menu-gizi.yaml.
import { buildPayload } from '../../core/schema-helpers.js';

export const KELOMPOK = [
  { value: 'siswa', label: 'Siswa' },
  { value: 'balita', label: 'Balita' },
  { value: 'ibu_hamil', label: 'Ibu hamil' },
  { value: 'ibu_menyusui', label: 'Ibu menyusui' },
];
export const kelompokLabel = (v) => KELOMPOK.find((k) => k.value === v)?.label ?? v ?? '-';

/** kolom menu <-> awalan kolom standar. */
export const GIZI = [
  { kolom: 'energi_kkal', std: 'energi', label: 'Energi', unit: 'kkal' },
  { kolom: 'protein_g', std: 'protein', label: 'Protein', unit: 'g' },
  { kolom: 'lemak_g', std: 'lemak', label: 'Lemak', unit: 'g' },
  { kolom: 'karbohidrat_g', std: 'karbohidrat', label: 'Karbohidrat', unit: 'g' },
  { kolom: 'serat_g', std: 'serat', label: 'Serat', unit: 'g' },
];
export const MAX_BAHAN = 50;

export const menuFields = () => [
  { name: 'nama_menu', label: 'Nama menu', type: 'text', required: true, maxlength: 255 },
  { name: 'kelompok_sasaran', label: 'Kelompok sasaran', type: 'select', required: true, options: KELOMPOK },
  { name: 'tanggal_sajian', label: 'Tanggal sajian', type: 'date' },
  { name: 'siklus', label: 'Siklus menu', type: 'text', maxlength: 50, placeholder: 'Siklus 1' },
  ...GIZI.map((g) => ({ name: g.kolom, label: `${g.label} per porsi (${g.unit})`, type: 'number', min: 0, max: 99999, step: 'any', default: 0 })),
  { name: 'catatan', label: 'Catatan', type: 'textarea', maxlength: 2000 },
];

export const menuDetailFields = [
  { name: 'kelompok_sasaran', label: 'Kelompok sasaran', display: (r) => r.kelompok_sasaran_label || kelompokLabel(r.kelompok_sasaran) },
  { name: 'tanggal_sajian', label: 'Tanggal sajian', type: 'date' },
  { name: 'siklus', label: 'Siklus' },
  { name: 'catatan', label: 'Catatan' },
];

/** Baris komposisi bersih -> payload API. Baris kosong total dibuang. */
export function normalisasiKomposisi(rows) {
  return (rows || [])
    .filter((r) => r && (r.bahan_baku_id || r.gram_per_porsi))
    .map((r) => ({ bahan_baku_id: Math.trunc(Number(r.bahan_baku_id)), gram_per_porsi: Number(r.gram_per_porsi) }));
}

/** '' bila valid. Server otoritas (ganda, SPPG sama, maks 50). */
export function validasiKomposisi(rows) {
  const list = (rows || []).filter((r) => r && (r.bahan_baku_id || r.gram_per_porsi));
  if (list.length > MAX_BAHAN) return `Maksimal ${MAX_BAHAN} bahan.`;
  const seen = new Set();
  for (let i = 0; i < list.length; i++) {
    const r = list[i];
    const n = i + 1;
    if (!r.bahan_baku_id) return `Baris ${n}: pilih bahan.`;
    const g = Number(r.gram_per_porsi);
    if (r.gram_per_porsi === '' || r.gram_per_porsi == null || Number.isNaN(g) || g <= 0) return `Baris ${n}: takaran harus lebih dari 0 gram.`;
    if (g > 100000) return `Baris ${n}: takaran maksimal 100.000 gram.`;
    const id = String(r.bahan_baku_id);
    if (seen.has(id)) return `Baris ${n}: bahan yang sama tidak boleh ganda.`;
    seen.add(id);
  }
  return '';
}

export const mapPayload = (values) => {
  const out = buildPayload(values, {
    keys: ['nama_menu', 'kelompok_sasaran', 'tanggal_sajian', 'siklus', ...GIZI.map((g) => g.kolom), 'catatan'],
    nums: GIZI.map((g) => g.kolom),
    nullable: ['tanggal_sajian', 'siklus', 'catatan'],
  });
  if ('bahan' in values) out.bahan = normalisasiKomposisi(values.bahan);
  return out;
};

const fmt = (n) => Number(n).toLocaleString('id-ID', { maximumFractionDigits: 2 });

/**
 * Perbandingan gizi per porsi dengan satu baris standar (bentuk StandarGizi). Cermin peringatan server,
 * hanya untuk umpan balik langsung; tidak pernah memblokir simpan. standar null -> [].
 */
export function hitungPeringatanGizi(values, standar) {
  if (!standar) return [];
  const out = [];
  for (const g of GIZI) {
    const raw = values?.[g.kolom];
    if (raw === '' || raw == null) continue;
    const nilai = Number(raw);
    const min = standar[`${g.std}_min`];
    const max = standar[`${g.std}_max`];
    if (Number.isNaN(nilai) || min == null || max == null) continue;
    if (nilai < Number(min)) out.push({ kolom: g.kolom, label: g.label, status: 'kurang', nilai, minimum: Number(min), maksimum: Number(max), pesan: `${g.label} ${fmt(nilai)} ${g.unit} di bawah standar minimum ${fmt(min)} ${g.unit}.` });
    else if (nilai > Number(max)) out.push({ kolom: g.kolom, label: g.label, status: 'lebih', nilai, minimum: Number(min), maksimum: Number(max), pesan: `${g.label} ${fmt(nilai)} ${g.unit} di atas standar maksimum ${fmt(max)} ${g.unit}.` });
  }
  return out;
}

// ---- Standar gizi ----
export const standarFields = () => [
  { name: 'kelompok_sasaran', label: 'Kelompok sasaran', type: 'select', required: true, options: KELOMPOK, help: 'Satu standar per kelompok sasaran.' },
  ...GIZI.flatMap((g) => [
    { name: `${g.std}_min`, label: `${g.label} minimum (${g.unit})`, type: 'number', required: true, min: 0, step: 'any' },
    { name: `${g.std}_max`, label: `${g.label} maksimum (${g.unit})`, type: 'number', required: true, min: 0, step: 'any',
      validate: (v, all) => (v != null && all[`${g.std}_min`] != null && Number(v) < Number(all[`${g.std}_min`]) ? `Maksimum harus >= minimum ${g.label.toLowerCase()}.` : '') },
  ]),
];
export const standarDetailFields = GIZI.map((g) => ({ name: `${g.std}_min`, label: g.label, display: (r) => rentang(r, g) }));
export const rentang = (r, g) => (r[`${g.std}_min`] == null ? '-' : `${fmt(r[`${g.std}_min`])} - ${fmt(r[`${g.std}_max`])} ${g.unit}`);

export const standarPayload = (values) => buildPayload(values, {
  keys: ['kelompok_sasaran', ...GIZI.flatMap((g) => [`${g.std}_min`, `${g.std}_max`])],
  nums: GIZI.flatMap((g) => [`${g.std}_min`, `${g.std}_max`]),
});

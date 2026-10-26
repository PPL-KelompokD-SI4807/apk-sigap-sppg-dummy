// Skema Penerima Manfaat (fitur 5). Kontrak: docs/api/paths/penerima-manfaat.yaml. Tanpa NIK (UU PDP).
import { buildPayload, activeField } from '../../core/schema-helpers.js';
import { noNik } from '../../core/validators.js';

export const KELOMPOK = [
  { value: 'siswa', label: 'Siswa' },
  { value: 'balita', label: 'Balita' },
  { value: 'ibu_hamil', label: 'Ibu hamil' },
  { value: 'ibu_menyusui', label: 'Ibu menyusui' },
];
export const kelompokLabel = (v) => KELOMPOK.find((k) => k.value === v)?.label ?? v ?? '-';

export const penerimaFields = () => [
  { name: 'titik_layanan_id', label: 'Titik layanan', type: 'relasi', required: true, relation: { endpoint: '/titik-layanan', label: 'nama', value: 'id', as: 'titik_layanan', query: { is_active: 1 } }, display: (r) => r.titik_layanan_nama ?? r.titik_layanan?.nama ?? '-' },
  { name: 'nama', label: 'Nama (inisial)', type: 'text', required: true, maxlength: 100, help: 'Cukup inisial atau nama minimal. Jangan isi NIK atau nomor identitas.', validate: (v) => noNik(v) },
  { name: 'kelompok_sasaran', label: 'Kelompok sasaran', type: 'select', required: true, options: KELOMPOK },
  { name: 'kelas_keterangan', label: 'Kelas / keterangan', type: 'text', maxlength: 100, placeholder: 'Mis. Kelas 4A' },
  { name: 'catatan_alergi', label: 'Catatan alergi / kebutuhan khusus', type: 'textarea', maxlength: 1000, help: 'Menjadi masukan bagi Ahli Gizi.' },
  activeField(),
];

export const mapPayload = (values) => buildPayload(values, {
  keys: ['titik_layanan_id', 'nama', 'kelompok_sasaran', 'kelas_keterangan', 'catatan_alergi', 'is_active'],
  ints: ['titik_layanan_id'],
  nullable: ['kelas_keterangan', 'catatan_alergi'],
  bools: ['is_active'],
});

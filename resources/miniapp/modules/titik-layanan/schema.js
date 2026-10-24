// Skema Titik Layanan (fitur 4). Kontrak: docs/api/paths/titik-layanan.yaml (TitikLayananInput).
import { buildPayload, activeField, sppgField } from '../../core/schema-helpers.js';
import { phone } from '../../core/validators.js';

export const JENIS = [
  { value: 'sekolah', label: 'Sekolah' },
  { value: 'paud', label: 'PAUD' },
  { value: 'posyandu', label: 'Posyandu' },
  { value: 'lainnya', label: 'Lainnya' },
];
export const jenisLabel = (v) => JENIS.find((j) => j.value === v)?.label ?? v ?? '-';

export const titikFields = ({ mode, user }) => [
  ...sppgField(user, mode),
  { name: 'nama', label: 'Nama titik layanan', type: 'text', required: true, maxlength: 255 },
  { name: 'jenis', label: 'Jenis', type: 'select', required: true, options: JENIS },
  { name: 'alamat', label: 'Alamat', type: 'textarea', required: true, maxlength: 255 },
  { name: 'jarak_km', label: 'Jarak dari SPPG (km)', type: 'number', min: 0, max: 9999.99, step: '0.01' },
  { name: 'jumlah_sasaran', label: 'Jumlah sasaran (orang)', type: 'number', min: 0, max: 100000, step: 1 },
  { name: 'pic_nama', label: 'Nama PIC', type: 'text', required: true, maxlength: 255 },
  { name: 'pic_telepon', label: 'Telepon PIC', type: 'text', maxlength: 30, validate: (v) => phone(v, { label: 'Telepon PIC' }) },
  activeField(),
];

export const mapPayload = (values) => buildPayload(values, {
  keys: ['sppg_id', 'nama', 'jenis', 'alamat', 'jarak_km', 'jumlah_sasaran', 'pic_nama', 'pic_telepon', 'is_active'],
  ints: ['sppg_id', 'jumlah_sasaran'],
  nums: ['jarak_km'],
  nullable: ['pic_telepon'],
  bools: ['is_active'],
});

/** "3.50" -> "3,5 km" (format id-ID). */
export const jarakText = (v) => (v == null || v === '' ? '-' : `${Number(v).toLocaleString('id-ID', { maximumFractionDigits: 2 })} km`);

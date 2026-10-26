// Skema Pemasok (fitur 7). Kontrak: docs/api/paths/pemasok.yaml (PemasokInput).
import { buildPayload, activeField, sppgField } from '../../core/schema-helpers.js';
import { phone } from '../../core/validators.js';

export const JENIS_PEMASOK = [
  { value: 'umkm', label: 'UMKM' },
  { value: 'petani', label: 'Petani' },
  { value: 'peternak', label: 'Peternak' },
  { value: 'nelayan', label: 'Nelayan' },
];
export const jenisPemasokLabel = (v) => JENIS_PEMASOK.find((j) => j.value === v)?.label ?? v ?? '-';

export const pemasokFields = ({ mode, user }) => [
  ...sppgField(user, mode),
  { name: 'nama', label: 'Nama pemasok', type: 'text', required: true, maxlength: 255 },
  { name: 'jenis', label: 'Jenis pemasok', type: 'select', required: true, options: JENIS_PEMASOK },
  { name: 'kontak', label: 'Kontak (telepon)', type: 'text', maxlength: 30, placeholder: '0812-0000-0000', validate: (v) => phone(v, { label: 'Kontak' }) },
  { name: 'alamat', label: 'Alamat', type: 'textarea', maxlength: 255 },
  { name: 'jenis_bahan', label: 'Jenis bahan yang dipasok', type: 'text', maxlength: 255, placeholder: 'Mis. sayuran, telur, ikan' },
  activeField(),
  ...(mode === 'detail' ? [{ name: 'bahan_baku_count', label: 'Bahan baku aktif', type: 'number', onlyIfPresent: true }] : []),
];

export const mapPayload = (values) => buildPayload(values, {
  keys: ['sppg_id', 'nama', 'jenis', 'kontak', 'alamat', 'jenis_bahan', 'is_active'],
  ints: ['sppg_id'],
  nullable: ['kontak', 'alamat', 'jenis_bahan'],
  bools: ['is_active'],
});

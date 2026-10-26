// Skema Data SPPG (fitur 3). Kontrak: docs/api/paths/sppg.yaml + components/sppg.yaml (SppgInput).
import { buildPayload } from '../../core/schema-helpers.js';
import { phone } from '../../core/validators.js';

export const STATUS_OPERASIONAL = [
  { value: 'aktif', label: 'Aktif' },
  { value: 'libur', label: 'Libur' },
  { value: 'nonaktif', label: 'Nonaktif' },
];
export const statusBadge = (s) => ({ aktif: 'badge-ok', libur: 'badge-warn', nonaktif: 'badge-danger' }[s] || '');

/** kode: hanya Admin yang boleh mengisi/ubah (Kepala SPPG: baca saja). */
export function sppgFields({ mode, user }) {
  const isAdmin = user?.role === 'admin';
  return [
    { name: 'kode', label: 'Kode SPPG', type: 'text', required: true, maxlength: 30, readonly: mode !== 'create' && !isAdmin, help: isAdmin ? null : 'Kode hanya dapat diubah Admin.' },
    { name: 'nama', label: 'Nama SPPG', type: 'text', required: true, maxlength: 255 },
    { name: 'alamat', label: 'Alamat', type: 'textarea', required: true, maxlength: 255 },
    { name: 'mitra_yayasan', label: 'Mitra / yayasan pengelola', type: 'text', maxlength: 255 },
    { name: 'telepon', label: 'Telepon', type: 'text', maxlength: 30, validate: (v) => phone(v) },
    { name: 'kapasitas_porsi_harian', label: 'Kapasitas porsi per hari', type: 'number', required: true, min: 1, max: 100000, step: 1, help: 'Menjadi batas target porsi pada jadwal produksi.' },
    { name: 'status_operasional', label: 'Status operasional', type: 'select', required: true, default: 'aktif', options: STATUS_OPERASIONAL },
  ];
}

export const mapPayload = (values) => buildPayload(values, {
  keys: ['kode', 'nama', 'alamat', 'mitra_yayasan', 'telepon', 'kapasitas_porsi_harian', 'status_operasional'],
  ints: ['kapasitas_porsi_harian'],
  nullable: ['mitra_yayasan', 'telepon'],
});

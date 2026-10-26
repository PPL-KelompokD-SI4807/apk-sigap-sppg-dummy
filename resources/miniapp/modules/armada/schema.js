// Skema Armada (fitur 12). Kontrak: docs/api/paths/armada.yaml (ArmadaInput).
import { buildPayload, sppgField } from '../../core/schema-helpers.js';
import { plat, normalizePlat } from '../../core/validators.js';

export const STATUS_ARMADA = [
  { value: 'tersedia', label: 'Tersedia' },
  { value: 'bertugas', label: 'Bertugas' },
  { value: 'servis', label: 'Servis' },
];
export const statusArmadaLabel = (v) => STATUS_ARMADA.find((s) => s.value === v)?.label ?? v ?? '-';
export const statusArmadaBadge = (v) => ({ tersedia: 'badge-ok', bertugas: 'badge-info', servis: 'badge-warn' }[v] || '');

export const armadaFields = ({ mode, user }) => [
  ...sppgField(user, mode),
  { name: 'plat_nomor', label: 'Plat nomor', type: 'text', required: true, maxlength: 20, placeholder: 'B 1234 XY', validate: (v) => plat(v), help: 'Plat dinormalkan (huruf besar) dan harus unik per SPPG.' },
  { name: 'jenis', label: 'Jenis kendaraan', type: 'text', required: true, maxlength: 50, placeholder: 'Mobil box / Pikap tertutup' },
  { name: 'kapasitas_box', label: 'Kapasitas (box)', type: 'number', min: 0, max: 100000, step: 1 },
  { name: 'status', label: 'Status', type: 'select', required: true, default: 'tersedia', options: STATUS_ARMADA },
];

export const mapPayload = (values) => {
  const out = buildPayload(values, { keys: ['sppg_id', 'plat_nomor', 'jenis', 'kapasitas_box', 'status'], ints: ['sppg_id', 'kapasitas_box'] });
  if (out.plat_nomor) out.plat_nomor = normalizePlat(out.plat_nomor);
  return out;
};

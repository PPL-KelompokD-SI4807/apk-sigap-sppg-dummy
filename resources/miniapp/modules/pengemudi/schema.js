// Skema Pengemudi (fitur 12). Kontrak: docs/api/paths/armada.yaml (/pengemudi, PengemudiInput).
// no_sim & sim_berlaku_hingga hanya ada di respons untuk Admin/Kepala SPPG.
import { buildPayload, activeField, sppgField } from '../../core/schema-helpers.js';
import { phone, notBeforeToday, todayISO } from '../../core/validators.js';

export const SIM_STATUS = {
  berlaku: { label: 'SIM berlaku', cls: 'badge-ok' },
  segera_berakhir: { label: 'SIM segera berakhir', cls: 'badge-warn' },
  kedaluwarsa: { label: 'SIM kedaluwarsa', cls: 'badge-danger' },
};
export const simLabel = (v) => SIM_STATUS[v]?.label ?? 'SIM tidak dicatat';
export const simBadge = (v) => SIM_STATUS[v]?.cls ?? '';

/** Aturan lintas kolom: no_sim diisi -> masa berlaku wajib dan tidak boleh sebelum hari ini. */
export const validateSimDate = (v, all = {}, now = new Date()) => {
  if (all.no_sim && !v) return 'Masa berlaku SIM wajib diisi bila nomor SIM diisi.';
  return notBeforeToday(v, 'Masa berlaku SIM', now);
};

export const pengemudiFields = ({ mode, user }) => [
  ...sppgField(user, mode),
  { name: 'nama', label: 'Nama pengemudi', type: 'text', required: true, maxlength: 255 },
  { name: 'no_hp', label: 'No. HP', type: 'text', maxlength: 30, validate: (v) => phone(v, { label: 'No. HP' }) },
  { name: 'no_sim', label: 'Nomor SIM', type: 'text', maxlength: 30, onlyIfPresent: true, inputmode: 'numeric' },
  { name: 'sim_berlaku_hingga', label: 'SIM berlaku hingga', type: 'date', min: mode === 'detail' ? undefined : todayISO(), onlyIfPresent: true, validate: (v, all) => validateSimDate(v, all) },
  ...(mode === 'detail' ? [{ name: 'sim_status', label: 'Status SIM', type: 'text', display: (r) => simLabel(r.sim_status) }] : []),
  activeField(),
];

export const mapPayload = (values) => buildPayload(values, {
  keys: ['sppg_id', 'nama', 'no_hp', 'no_sim', 'sim_berlaku_hingga', 'is_active'],
  ints: ['sppg_id'],
  nullable: ['no_hp', 'no_sim', 'sim_berlaku_hingga'],
  bools: ['is_active'],
});

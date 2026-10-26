// Pembantu skema form + payload (murni, tanpa DOM) dipakai modul grup A.
import { toBool, nullIfEmpty } from './validators.js';

export const BOOL_OPTIONS = [{ value: 'true', label: 'Aktif' }, { value: 'false', label: 'Nonaktif' }];

/** Kolom status aktif (select Aktif/Nonaktif; dikirim sebagai boolean lewat buildPayload). */
export const activeField = (label = 'Status') => ({ name: 'is_active', label, type: 'select', required: true, default: 'true', options: BOOL_OPTIONS });

/** Kolom sppg_id: hanya Admin saat tambah (peran lain memakai SPPG sendiri; tak dapat dipindah saat ubah). */
export function sppgField(user, mode) {
  if (mode !== 'create' || user?.role !== 'admin') return [];
  return [{ name: 'sppg_id', label: 'SPPG', type: 'relasi', required: true, relation: { endpoint: '/sppg', label: 'nama', value: 'id' } }];
}

/**
 * buildPayload(values, {keys, ints, nums, bools, nullable, upper})
 *  keys: kolom yang dikirim (urutan dipertahankan). Kolom yang tak ada di `values` dilewati
 *  (kolom readonly/sembunyi tidak ikut). '' -> null untuk kolom nullable.
 */
export function buildPayload(values, { keys, ints = [], nums = [], bools = [], nullable = [] }) {
  const out = {};
  for (const k of keys) {
    if (!(k in values)) continue;
    let v = values[k];
    if (typeof v === 'string') v = v.trim();
    if (bools.includes(k)) { const b = toBool(v); if (b === null) continue; out[k] = b; continue; }
    if (v === '' || v == null) { if (nullable.includes(k)) out[k] = null; continue; }
    if (ints.includes(k)) out[k] = Math.trunc(Number(v));
    else if (nums.includes(k)) out[k] = Number(v);
    else out[k] = v;
  }
  return out;
}
export { nullIfEmpty };

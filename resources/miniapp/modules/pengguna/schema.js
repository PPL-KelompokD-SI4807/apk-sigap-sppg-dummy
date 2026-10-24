// Skema Pengguna + Undangan (fitur 1). Kontrak: docs/api/paths/pengguna.yaml + components/pengguna.yaml.
import { ROLES } from '../../core/permissions.js';
import { buildPayload, activeField } from '../../core/schema-helpers.js';
import { phone } from '../../core/validators.js';

export const ROLE_OPTIONS = Object.entries(ROLES).map(([value, label]) => ({ value, label }));
export const UNIT_OPTIONS = [{ value: 'dapur', label: 'Dapur' }, { value: 'distribusi', label: 'Distribusi' }];
export const unitLabel = (v) => UNIT_OPTIONS.find((u) => u.value === v)?.label ?? null;

export const STATUS_PENGGUNA = {
  aktif: { label: 'Aktif', cls: 'badge-ok' },
  menunggu_telegram: { label: 'Menunggu Telegram', cls: 'badge-warn' },
  nonaktif: { label: 'Nonaktif', cls: 'badge-danger' },
};
export const statusLabel = (s) => STATUS_PENGGUNA[s]?.label ?? s ?? '-';
export const statusCls = (s) => STATUS_PENGGUNA[s]?.cls ?? '';

export const needsUnit = (role) => role === 'relawan';
export const needsSppg = (role) => !!role && role !== 'admin';

/** Form tambah/ubah pengguna (Admin). Unit hanya untuk relawan; SPPG hanya untuk non-admin. */
export function penggunaFields({ mode }) {
  if (mode === 'detail') {
    return [
      { name: 'name', label: 'Nama', type: 'text' },
      { name: 'role', label: 'Peran', type: 'text', display: (r) => r.role_label ?? ROLES[r.role] ?? r.role },
      { name: 'unit', label: 'Unit', type: 'text', display: (r) => unitLabel(r.unit) ?? '-' },
      { name: 'sppg_id', label: 'SPPG', type: 'text', display: (r) => r.sppg_nama ?? (r.sppg_id ? `#${r.sppg_id}` : 'Semua SPPG') },
      { name: 'phone', label: 'Telepon', type: 'text' },
      { name: 'telegram_username', label: 'Telegram', type: 'text', display: (r) => (r.telegram_terhubung ? (r.telegram_username ? `@${r.telegram_username}` : 'Terhubung') : 'Belum terhubung') },
      { name: 'status', label: 'Status', type: 'text', display: (r) => statusLabel(r.status) },
      { name: 'last_login_at', label: 'Login terakhir', type: 'datetime' },
    ];
  }
  return [
    { name: 'name', label: 'Nama lengkap', type: 'text', required: true, maxlength: 120 },
    { name: 'role', label: 'Peran', type: 'select', required: true, options: ROLE_OPTIONS },
    { name: 'unit', label: 'Unit relawan', type: 'select', required: true, options: UNIT_OPTIONS, showIf: (v) => needsUnit(v.role) },
    { name: 'sppg_id', label: 'SPPG', type: 'relasi', required: true, relation: { endpoint: '/sppg', label: 'nama', value: 'id' }, showIf: (v) => needsSppg(v.role) },
    { name: 'phone', label: 'Telepon', type: 'text', maxlength: 30, validate: (v) => phone(v) },
    ...(mode === 'update' ? [{ ...activeField('Status akun'), help: 'Mengubah peran, unit, SPPG, atau status mencabut sesi login pengguna ini.' }] : []),
  ];
}

export const mapPayload = (values, { mode } = {}) => {
  const out = buildPayload(values, {
    keys: ['name', 'role', 'unit', 'sppg_id', 'phone', 'is_active'],
    ints: ['sppg_id'],
    nullable: ['unit', 'sppg_id', 'phone'],
    bools: ['is_active'],
  });
  // Konsistensi aturan server: admin tanpa sppg, non-relawan tanpa unit.
  if (out.role && !needsUnit(out.role)) out.unit = null;
  if (out.role === 'admin') out.sppg_id = null;
  if (mode === 'create') delete out.is_active;
  return out;
};

// ---- Undangan ----
export const STATUS_UNDANGAN = [
  { value: 'aktif', label: 'Aktif' },
  { value: 'terpakai', label: 'Terpakai' },
  { value: 'kedaluwarsa', label: 'Kedaluwarsa' },
  { value: 'dicabut', label: 'Dicabut' },
];
export const undanganCls = (s) => ({ aktif: 'badge-info', terpakai: 'badge-ok', kedaluwarsa: 'badge-warn', dicabut: 'badge-danger' }[s] || '');
export const undanganLabel = (s) => STATUS_UNDANGAN.find((x) => x.value === s)?.label ?? s ?? '-';

/** Judul ringkas sebuah undangan: nama akun / titik layanan, atau peran+unit bila akun belum dibuat. */
export function undanganTarget(inv) {
  if (inv.tipe === 'pic') return inv.titik_layanan_nama ? `PIC ${inv.titik_layanan_nama}` : `PIC titik layanan #${inv.titik_layanan_id ?? '?'}`;
  if (inv.user_nama) return inv.user_nama;
  const role = ROLES[inv.role] ?? inv.role ?? 'Pengguna baru';
  return [role, unitLabel(inv.unit)].filter(Boolean).join(' · ');
}

/** Form undangan: pilih akun yang sudah ada (belum bertaut) ATAU akun baru dibuat saat tautan dipakai. */
export const undanganFields = () => [
  { name: 'mode', label: 'Jenis undangan', type: 'select', required: true, default: 'akun', options: [
    { value: 'akun', label: 'Untuk akun yang sudah dibuat' },
    { value: 'baru', label: 'Akun baru (dibuat saat tautan dibuka)' },
  ] },
  { name: 'user_id', label: 'Akun', type: 'relasi', required: true, relation: { endpoint: '/pengguna', label: 'name', value: 'id', query: { status: 'menunggu_telegram' } }, help: 'Hanya akun yang belum menautkan Telegram.', showIf: (v) => v.mode !== 'baru' },
  { name: 'role', label: 'Peran', type: 'select', required: true, options: ROLE_OPTIONS, showIf: (v) => v.mode === 'baru' },
  { name: 'unit', label: 'Unit relawan', type: 'select', required: true, options: UNIT_OPTIONS, showIf: (v) => v.mode === 'baru' && needsUnit(v.role) },
  { name: 'sppg_id', label: 'SPPG', type: 'relasi', required: true, relation: { endpoint: '/sppg', label: 'nama', value: 'id' }, showIf: (v) => v.mode === 'baru' && needsSppg(v.role) },
];

/** user_id TIDAK boleh bersama role/unit/sppg_id (UndanganInput). */
export function buildInvitePayload(values) {
  if (values.mode === 'baru') {
    const out = { role: values.role };
    if (needsUnit(values.role) && values.unit) out.unit = values.unit;
    if (needsSppg(values.role) && values.sppg_id != null && values.sppg_id !== '') out.sppg_id = Math.trunc(Number(values.sppg_id));
    return out;
  }
  return { user_id: Math.trunc(Number(values.user_id)) };
}

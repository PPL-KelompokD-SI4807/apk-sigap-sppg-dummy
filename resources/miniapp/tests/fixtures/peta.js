// Fixture TES: peta hak seperti dikirim server pada GET /api/v1/auth/me -> user (docs/api/peta-hak.md).
// Diambil dari snapshot server tests/Fixtures/peta-hak/auth-me.json (milik backend-dev; jangan diedit, dibaca saja).
// Hanya kolom identitas (id, nama, dsb.) yang ditambahkan di sini. Klien TIDAK memakai ini.
// Data fiktif.

import { readFileSync } from 'node:fs';

const SNAPSHOT = JSON.parse(readFileSync(new URL('../../../../tests/Fixtures/peta-hak/auth-me.json', import.meta.url), 'utf8'));
const LABEL = { admin: 'Admin', kepala_sppg: 'Kepala SPPG', ahli_gizi: 'Ahli Gizi', akuntan: 'Akuntan', staf_gudang: 'Staf Gudang', relawan: 'Relawan', investor: 'Investor' };
const TANPA_SPPG = new Set(['admin', 'investor']);

function user(id, nama, snap) {
  const sppg_id = TANPA_SPPG.has(snap.role) ? null : 1;
  const label = LABEL[snap.role];
  return {
    id, name: `Pengguna ${label}`, username: `u_${id}`, role_label: label, sppg_id, sppg_name: sppg_id ? 'SPPG Contoh' : null,
    sppg_aktif_id: sppg_id, multi_sppg: snap.role === 'investor',
    ...structuredClone(snap),
  };
}

export const PROFIL = Object.fromEntries(Object.entries(SNAPSHOT).map(([nama, snap], i) => [nama, user(i + 1, nama, snap)]));

/** Salinan dalam (tes boleh memutasi tanpa memengaruhi tes lain). */
export const salin = (nama) => structuredClone(PROFIL[nama]);
export const NAMA_PROFIL = Object.keys(PROFIL);

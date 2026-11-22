// Tujuan layar Mini App dari draf Agen AI + pencocokan draf dengan layar. Murni (tanpa DOM) agar bisa diuji dengan node --test.

const bersih = (v) => String(v ?? '').replace(/[^a-z0-9_-]/gi, '');

// Kolom pembeda dua sumber daya modul `keuangan` (dipakai hanya bila draf tanpa penanda `_resource`).
const KUNCI_PENCAIRAN = ['sumber_dana', 'periode', 'tanggal_cair', 'no_referensi'];
const KUNCI_PENGELUARAN = ['uraian', 'kategori', 'pemasok_id', 'pencairan_dana_id', 'tanggal', 'versi'];

function basisKeuangan(marker, payload) {
  // Server (WriteDraftTool): draf pengeluaran SELALU bertanda `pengeluaran`; draf pencairan tanpa penanda.
  if (marker === 'pengeluaran') return '/keuangan/pengeluaran';
  const ada = (kunci) => kunci.some((k) => k in payload);
  if (ada(KUNCI_PENCAIRAN)) return '/keuangan/pencairan';
  if (ada(KUNCI_PENGELUARAN)) return '/keuangan/pengeluaran'; // draf lama tanpa penanda
  return '/keuangan/pencairan'; // ubah pencairan parsial (mis. hanya nominal/catatan)
}

/**
 * Tujuan layar Mini App dari draf AI (GET /ai-drafts/{uuid}). Hanya path internal yang dikenal; modul tidak
 * dikenal memakai pola standar /{module}/baru atau /{module}/{id}/ubah.
 *  - Modul `keuangan` dipakai bersama pencairan dan pengeluaran: dibedakan lewat penanda `_resource` (pengeluaran);
 *    tanpa penanda, lewat kolom payload, dan ubah parsial tanpa kolom pembeda = pencairan.
 *  - Penanda sub-modul `payload._resource` (server: DraftToolResolver) memilih layar: undangan -> /pengguna/undangan,
 *    distribusi -> /jadwal/distribusi, pengemudi -> /pengemudi, standar_gizi -> /menu-gizi/standar-gizi,
 *    mutasi_stok -> /bahan-baku/:id/mutasi (konfirmasi hanya lewat POST /bahan-baku/{id}/mutasi).
 *  - Draf HAPUS tidak pernah dipetakan ke form /baru: ke detail record (tempat tombol Hapus) atau, bila tanpa target, daftar.
 *    Draf cabut undangan menuju daftar undangan.
 *  - Draf UBAH tanpa target_id valid menuju daftar (bukan form baru).
 */
export function tujuanDraf(d) {
  const modul = bersih(d?.module);
  const marker = bersih(d?.payload?._resource);
  const idMentah = d?.target_id ?? d?.payload?.id ?? null;
  const id = idMentah != null && Number.isInteger(Number(idMentah)) && Number(idMentah) > 0 ? Number(idMentah) : null;
  const aksi = d?.action;

  let base = `/${modul}`;
  if (modul === 'keuangan') base = basisKeuangan(marker, d?.payload ?? {});
  else if (modul === 'pengguna' && marker === 'undangan') base = '/pengguna/undangan';
  else if (modul === 'jadwal' && marker === 'distribusi') base = '/jadwal/distribusi';
  else if (modul === 'armada' && marker === 'pengemudi') base = '/pengemudi';
  else if (modul === 'menu-gizi' && marker === 'standar_gizi') base = '/menu-gizi/standar-gizi';
  else if (modul === 'bahan-baku' && marker === 'mutasi_stok') {
    // Mutasi hanya berupa aksi ubah pada satu bahan baku; selain itu jangan pernah membuka form edit/tambah bahan.
    return aksi === 'update' && id != null ? `/bahan-baku/${id}/mutasi` : '/bahan-baku';
  }

  if (aksi === 'delete') return base === '/pengguna/undangan' || id == null ? base : `${base}/${id}`;
  if (aksi === 'update') return id == null ? base : `${base}/${id}/ubah`;
  if (aksi == null || aksi === 'create') return `${base}/baru`;
  return base; // aksi tak dikenal: jangan pernah membuka form tambah
}

/** Path rute tanpa query dan tanpa garis miring penutup. */
export const jalurBersih = (path) => String(path ?? '').split('?')[0].replace(/\/+$/, '') || '/';

/**
 * Draf cocok dengan layar? marker null = layar bawaan modul (draf tanpa `_resource`). Target harus bahan/record yang sama.
 * -> boolean. Dipakai layar khusus (mis. mutasi stok) sebelum mengirim `ai_draft_id`; server tetap otoritas (422).
 */
export function draftCocok(draft, { module, marker = null, aksi, targetId = null }) {
  if (!draft || typeof draft !== 'object') return false;
  if (draft.module !== module) return false;
  if ((draft.payload?._resource ?? null) !== marker) return false;
  if (aksi != null && draft.action !== aksi) return false;
  if (targetId != null && Number(draft.target_id ?? draft.payload?.id) !== Number(targetId)) return false;
  return true;
}

/** Path DELETE; bila hapus berasal dari draf AI, sertakan `?ai_draft_id=<uuid>` (tanpa draf = perilaku lama). */
export function pathHapus(path, uuid = null) {
  return uuid ? `${path}?${new URLSearchParams({ ai_draft_id: uuid })}` : path;
}

/** Nilai form dari payload draf: tanpa kunci penanda server (berawalan "_") dan tanpa `id` target. */
export function isianDariPayload(payload, { buang = ['id'] } = {}) {
  return Object.fromEntries(Object.entries(payload || {}).filter(([k]) => !k.startsWith('_') && !buang.includes(k)));
}

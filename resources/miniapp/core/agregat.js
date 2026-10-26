// Helper agregat penerima manfaat (Investor). Hanya hitungan; tidak pernah ada nama/data pribadi.
// Sel yang disamarkan server bernilai null + penanda ("<5"): tampilkan penanda, jangan jumlahkan di klien.
const num = (v) => (Number.isFinite(Number(v)) ? Math.max(0, Math.trunc(Number(v))) : 0);
const PENANDA_DEFAULT = '<5';

/** Nilai tampil satu sel: angka asli, atau penanda bila null. */
export function tampilJumlah(jumlah, penanda) {
  if (jumlah === null || jumlah === undefined) return penanda || PENANDA_DEFAULT;
  return String(num(jumlah));
}

export const LEGENDA_PENYAMARAN = '<5 = jumlah disamarkan demi privasi (bisa berarti kurang dari 5 atau disamarkan karena sel lain).';

/** Respons GET /penerima-manfaat/agregat -> bentuk siap tampil. Persen hanya bila jumlah dan total diketahui. */
export function normalisasiAgregat(res) {
  const d = res?.data ?? res ?? {};
  const total = d.total == null ? null : num(d.total);
  const persen = (n) => (n === null || !total ? null : Math.round((n / total) * 100));
  const baris = (arr, labelKey) => (Array.isArray(arr) ? arr : []).map((x) => {
    const jumlah = x.jumlah == null ? null : num(x.jumlah);
    return { label: String(x[labelKey] ?? '-'), jumlah, tampil: tampilJumlah(jumlah, x.penanda), persen: persen(jumlah) };
  });
  const aktif = d.aktif == null ? null : num(d.aktif);
  const nonaktif = total !== null && aktif !== null ? Math.max(0, total - aktif) : null;
  return {
    sppgId: d.sppg_id ?? null,
    total,
    totalTampil: tampilJumlah(total, d.total_penanda),
    aktif,
    aktifTampil: tampilJumlah(aktif, d.aktif_penanda),
    nonaktif,
    nonaktifTampil: tampilJumlah(nonaktif, d.nonaktif_penanda || d.aktif_penanda),
    disamarkan: d.penyamaran?.diterapkan === true,
    kelompok: baris(d.per_kelompok_sasaran, 'label'),
    titik: baris(d.per_titik_layanan, 'nama'),
  };
}

/** Admin wajib mengirim sppg_id; Investor memakai header X-SPPG-Id. */
export const queryAgregat = (user, sppgId) => (user?.role === 'admin' && sppgId ? { sppg_id: sppgId } : undefined);

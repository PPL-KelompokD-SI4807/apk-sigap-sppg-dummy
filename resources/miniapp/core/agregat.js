// Helper agregat penerima manfaat (Investor). Hanya hitungan; tidak pernah ada nama/data pribadi.
const num = (v) => (Number.isFinite(Number(v)) ? Math.max(0, Math.trunc(Number(v))) : 0);

/** Respons GET /penerima-manfaat/agregat -> bentuk siap tampil, dengan persen terhadap total. */
export function normalisasiAgregat(res) {
  const d = res?.data ?? res ?? {};
  const total = num(d.total);
  const persen = (n) => (total > 0 ? Math.round((n / total) * 100) : 0);
  const baris = (arr, labelKey) => (Array.isArray(arr) ? arr : []).map((x) => {
    const jumlah = num(x.jumlah);
    return { label: String(x[labelKey] ?? '-'), jumlah, persen: persen(jumlah) };
  });
  const aktif = num(d.aktif);
  return {
    sppgId: d.sppg_id ?? null,
    total,
    aktif,
    nonaktif: Math.max(0, total - aktif),
    kelompok: baris(d.per_kelompok_sasaran, 'label'),
    titik: baris(d.per_titik_layanan, 'nama'),
  };
}

/** Admin wajib mengirim sppg_id; Investor memakai header X-SPPG-Id. */
export const queryAgregat = (user, sppgId) => (user?.role === 'admin' && sppgId ? { sppg_id: sppgId } : undefined);

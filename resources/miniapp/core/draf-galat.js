// Galat draf Agen AI (GET /ai-drafts/{uuid}) -> kolom/baris formulir. Murni (tanpa DOM) agar bisa diuji dengan node --test.

/** Draf perlu diperbaiki: flag server `perlu_diperbaiki`; bila tak ada, fallback = `errors` tidak kosong. */
export function perluDiperbaiki(draft) {
  if (!draft || typeof draft !== 'object') return false;
  if (typeof draft.perlu_diperbaiki === 'boolean') return draft.perlu_diperbaiki;
  return Object.keys(draft.errors || {}).length > 0;
}

const pesanPertama = (v) => String((Array.isArray(v) ? v[0] : v) ?? '');

/**
 * Petakan errors server ke struktur form.
 *  kolom: {nama: pesan} untuk kunci tanpa titik yang ada di `namaKolom`
 *  baris: {kolom: {indeks: {sub: pesan}}} untuk kunci "kolom.N.sub" bila `kolom` ada di `namaKolom`
 *  lainnya: [pesan] untuk kunci yang tidak punya kolom di form (tampil di notice)
 */
export function petakanGalatDraf(errors, namaKolom = []) {
  const ada = new Set(namaKolom);
  const out = { kolom: {}, baris: {}, lainnya: [] };
  if (!errors || typeof errors !== 'object') return out;
  for (const [kunci, v] of Object.entries(errors)) {
    const msg = pesanPertama(v);
    if (!msg) continue;
    const bagian = kunci.split('.');
    if (bagian.length === 1 && ada.has(kunci)) { out.kolom[kunci] = msg; continue; }
    if (bagian.length >= 2 && ada.has(bagian[0])) {
      if (/^\d+$/.test(bagian[1])) {
        const sub = bagian.slice(2).join('.') || '_baris';
        ((out.baris[bagian[0]] ||= {})[Number(bagian[1])] ||= {})[sub] = msg;
      } else out.kolom[bagian[0]] = out.kolom[bagian[0]] || msg;
      continue;
    }
    out.lainnya.push(msg);
  }
  return out;
}

/** Pilihan referensi untuk satu kolom -> {indeks: {dicari, jumlah, satuan, kandidat[], lebih_banyak}}. */
export function pilihanPerBaris(pilihan, kolom) {
  const out = {};
  for (const p of Array.isArray(pilihan) ? pilihan : []) {
    if (!p || p.kolom !== kolom || !Number.isInteger(p.indeks)) continue;
    const kandidat = (Array.isArray(p.kandidat) ? p.kandidat : []).filter((k) => k && k.id != null);
    if (!kandidat.length) continue;
    out[p.indeks] = { dicari: p.dicari ?? '', jumlah: p.jumlah ?? '', satuan: p.satuan ?? '', kandidat, lebih_banyak: !!p.lebih_banyak };
  }
  return out;
}

/** Teks kandidat: "Ayam (kg)". */
export const labelKandidat = (k) => (k.satuan ? `${k.nama} (${k.satuan})` : String(k.nama ?? `#${k.id}`));

/** Galat satu baris -> satu teks ("Bahan baku: pesan; ..."), tanpa HTML. */
export function ringkasGalatBaris(sub) {
  return Object.values(sub || {}).filter(Boolean).join(' ');
}

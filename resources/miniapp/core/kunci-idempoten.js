// Kunci idempotensi untuk aksi satu panel/form (web, R04-8 PR-W4): murni, tanpa DOM.
/**
 * SATU kunci dibuat per isian dan dipakai ulang pada pengulangan manual dengan isian yang sama (server hanya menyimpan
 * respons 2xx, jadi kunci sama aman dipakai setelah galat); isian berbeda = permintaan berbeda = kunci baru (server menolak
 * kunci sama dengan isi berbeda). `buat` = newKey dari transport (diinjeksi agar murni).
 */
export function kunciPerIsian(buat) {
  let kunci = null;
  let sidik = null;
  return (body) => {
    const s = JSON.stringify(body);
    if (s !== sidik) { sidik = s; kunci = buat(); }
    return kunci;
  };
}

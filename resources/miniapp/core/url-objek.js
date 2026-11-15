// Pelacak object URL (blob:) agar memori tidak bocor. Dicabut (revoke) oleh pemiliknya saat foto dihapus/diganti, dan SEMUA
// yang masih terlacak dicabut oleh router di awal setiap render rute (pindah rute maupun render ulang rute yang sama).
// Modul murni tanpa DOM: aman diimpor router dan tes node.
const tracked = new Set();

/** Catat URL objek; mengembalikan URL yang sama. */
export function trackUrl(url) {
  if (url) tracked.add(url);
  return url;
}

/** Cabut satu URL terlacak (tidak berbuat apa-apa bila bukan milik pelacak ini). */
export function lepasUrl(url) {
  if (url && tracked.delete(url)) URL.revokeObjectURL(url);
}

/** Cabut semua URL terlacak. Dipanggil router sebagai bagian dari cleanup tiap render rute. */
export function bersihkanUrlTerlacak() {
  tracked.forEach((u) => URL.revokeObjectURL(u));
  tracked.clear();
}

/** Jumlah URL yang masih terlacak (diagnostik/tes). */
export const jumlahUrlTerlacak = () => tracked.size;

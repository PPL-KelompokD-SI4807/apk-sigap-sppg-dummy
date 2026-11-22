// Logika murni layar Sesi Aktif & Browser Tepercaya (R04-9). Tanpa DOM/storage agar dapat diuji.
export const KUOTA_BAWAAN = { miniapp: 5, web: 3, browser_tepercaya: 5 };
export const MASA_BROWSER_HARI_BAWAAN = 30;

export const labelJenis = (jenis) => (jenis === 'web' ? 'Web' : jenis === 'miniapp' ? 'Mini App' : 'Perangkat');

/** Sesi ini paling atas, lalu terakhir dipakai terbaru. Tidak mengubah array masukan. */
export function urutkanSesi(sesi = []) {
  const t = (s) => Date.parse(s.terakhir_dipakai_pada || s.dibuat_pada || '') || 0;
  return [...sesi].sort((a, b) => Number(!!b.sesi_ini) - Number(!!a.sesi_ini) || t(b) - t(a));
}

/** Pemakaian kuota: sesi per jenis dihitung dari daftar, browser tepercaya dari panjang daftarnya. */
export function ringkasKuota(sesi = [], browser = [], meta = {}, metaBrowser = {}) {
  const k = { ...KUOTA_BAWAAN, ...(meta?.kuota || {}) };
  if (Number.isInteger(metaBrowser?.kuota)) k.browser_tepercaya = metaBrowser.kuota;
  return {
    miniapp: { dipakai: sesi.filter((s) => s.jenis === 'miniapp').length, maks: k.miniapp },
    web: { dipakai: sesi.filter((s) => s.jenis === 'web').length, maks: k.web },
    browser_tepercaya: { dipakai: browser.length, maks: k.browser_tepercaya },
  };
}

export function teksKuota(r) {
  return `${r.miniapp.dipakai}/${r.miniapp.maks} Mini App, ${r.web.dipakai}/${r.web.maks} web, ${r.browser_tepercaya.dipakai}/${r.browser_tepercaya.maks} browser tepercaya`;
}

/** Pesan konfirmasi cabut sesi; sesi ini -> peringatan keluar. */
export function konfirmasiCabutSesi(s) {
  return s.sesi_ini
    ? 'Ini adalah sesi yang sedang Anda pakai. Anda akan keluar dan harus masuk lagi. Lanjutkan?'
    : 'Perangkat ini akan keluar dan harus masuk lagi. Lanjutkan?';
}

/** Pesan konfirmasi cabut browser tepercaya (sesi web turunannya ikut berakhir). */
export function konfirmasiCabutBrowser(b) {
  return b.browser_ini
    ? 'Ini browser yang sedang Anda pakai. Sesi ini ikut berakhir dan login berikutnya wajib sandi + kode. Lanjutkan?'
    : 'Browser ini tidak lagi melewati kode, dan sesi web yang berasal darinya ikut berakhir. Lanjutkan?';
}

/** Sisa hari berlaku browser tepercaya (>= 0), pembulatan ke atas. */
export function sisaHari(iso, sekarang = Date.now()) {
  const t = Date.parse(iso);
  return Number.isFinite(t) ? Math.max(0, Math.ceil((t - sekarang) / 86400000)) : 0;
}

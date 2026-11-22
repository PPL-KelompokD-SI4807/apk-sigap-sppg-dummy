// Adapter platform (R04-6): 'telegram' (Mini App, bawaan) atau 'web' (browser biasa di /web/).
// Dipilih SEKALI oleh titik masuk (main.js tidak memanggil apa pun = telegram; web-main.js memanggil setPlatform('web')).
// Modul inti (telegram.js, theme.js, ui.js, router.js) bertanya ke sini lewat getTg()/isNative(): di mode web
// getTg() = null dan isNative() = false, sehingga MainButton -> bilah bawah DOM, BackButton -> tombol appbar,
// showPopup -> dialog DOM, haptic -> no-op, tema -> prefers-color-scheme. Mode telegram tidak berubah perilaku.
export const PLATFORM_TELEGRAM = 'telegram';
export const PLATFORM_WEB = 'web';

let aktif = PLATFORM_TELEGRAM;

export function setPlatform(nama) {
  if (nama !== PLATFORM_TELEGRAM && nama !== PLATFORM_WEB) throw new Error(`Platform tidak dikenal: ${nama}`);
  aktif = nama;
}
export const getPlatform = () => aktif;
export const isWeb = () => aktif === PLATFORM_WEB;

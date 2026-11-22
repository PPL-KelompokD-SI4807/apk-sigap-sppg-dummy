// Akses tunggal ke Telegram.WebApp. "Native" = benar-benar dibuka dari Telegram (bukan shim dev).
// Mode web (core/platform.js): tidak ada Telegram.WebApp, jadi getTg() null dan isNative() false.
import { isWeb } from './platform.js';

export const getTg = () => (isWeb() ? null : window.Telegram?.WebApp ?? null);
export const isNative = () => {
  const tg = getTg();
  return !!(tg && tg.initData && !tg.__shim);
};
export function haptic(kind = 'success') {
  try {
    const hf = getTg()?.HapticFeedback;
    if (!hf) return;
    if (['success', 'error', 'warning'].includes(kind)) hf.notificationOccurred(kind);
    else hf.impactOccurred(kind === 'heavy' ? 'heavy' : 'light');
  } catch { /* abaikan */ }
}

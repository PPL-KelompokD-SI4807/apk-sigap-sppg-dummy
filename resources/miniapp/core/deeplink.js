// Deep link rute generik (?r=/rute) dari tombol web_app bot. Fungsi murni agar mudah diuji.
const POLA_AMAN = /^\/[a-z0-9/_-]{1,80}$/;

/**
 * Mengembalikan rute aman dari query string, atau null.
 * adaRute(path) -> true bila router punya rute yang cocok (daftar rute terdaftar).
 */
export function parseRuteDeepLink(search = '', adaRute = () => false) {
  let r;
  try { r = new URLSearchParams(search).get('r'); } catch { return null; }
  if (typeof r !== 'string' || r.length > 81) return null;
  if (!POLA_AMAN.test(r) || r.includes('//')) return null;
  const path = r.length > 1 && r.endsWith('/') ? r.slice(0, -1) : r;
  return adaRute(path) ? path : null;
}

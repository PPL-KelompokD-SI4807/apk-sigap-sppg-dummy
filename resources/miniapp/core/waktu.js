// Pembantu tanggal/jam zona Asia/Jakarta (WIB). Murni (tanpa DOM) agar dapat diuji dengan node --test.
const TZ = 'Asia/Jakarta';

/** Tanggal hari ini (YYYY-MM-DD) di WIB. */
export function todayWIB(now = new Date()) {
  return new Intl.DateTimeFormat('en-CA', { timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit' }).format(now);
}

/** "08:50 WIB" dari string ISO 8601; '-' bila kosong/tidak valid. */
export function jamWIB(v) {
  if (!v) return '-';
  const d = new Date(v);
  if (isNaN(d)) return '-';
  return `${new Intl.DateTimeFormat('en-GB', { timeZone: TZ, hour: '2-digit', minute: '2-digit', hour12: false }).format(d)} WIB`;
}

/** "2 Nov 2026, 08:50 WIB". */
export function tanggalJamWIB(v) {
  if (!v) return '-';
  const d = new Date(v);
  if (isNaN(d)) return '-';
  const tgl = new Intl.DateTimeFormat('id-ID', { timeZone: TZ, day: 'numeric', month: 'short', year: 'numeric' }).format(d);
  return `${tgl}, ${jamWIB(v)}`;
}

/** "Senin, 2 November 2026" dari YYYY-MM-DD. */
export function hariLengkap(iso) {
  if (!/^\d{4}-\d{2}-\d{2}/.test(String(iso ?? ''))) return '-';
  const d = new Date(`${String(iso).slice(0, 10)}T00:00:00Z`);
  return new Intl.DateTimeFormat('id-ID', { timeZone: 'UTC', weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(d);
}

/** Geser tanggal YYYY-MM-DD sebanyak n hari. */
export function addDays(iso, n) {
  const d = new Date(`${String(iso).slice(0, 10)}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() + n);
  return d.toISOString().slice(0, 10);
}

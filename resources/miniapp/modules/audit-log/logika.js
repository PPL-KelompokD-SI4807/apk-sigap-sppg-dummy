// Logika murni layar Log Audit (Admin, hanya baca). Tanpa DOM agar dapat diuji node --test.
const AKSI = { created: 'Dibuat', updated: 'Diubah', deleted: 'Dihapus', restored: 'Dipulihkan' };

/** Label aksi berbahasa Indonesia; aksi lain ditampilkan apa adanya. */
export const labelAksi = (aksi) => AKSI[aksi] ?? String(aksi ?? '-');

/** Hanya Admin; server tetap sumber kebenaran (403 => "Tidak punya akses"). */
export const bolehLogAudit = (user) => user?.role === 'admin';

/** "dd/mm HH:MM" zona WIB dari ISO 8601; '-' bila tidak valid. */
export function waktuSingkat(iso) {
  const d = new Date(iso);
  if (!iso || isNaN(d)) return '-';
  const p = Object.fromEntries(new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Asia/Jakarta', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false,
  }).formatToParts(d).map((x) => [x.type, x.value]));
  return `${p.day}/${p.month} ${p.hour === '24' ? '00' : p.hour}:${p.minute}`;
}

export const namaPengguna = (item) => item?.pengguna?.nama || 'Sistem';

/** Nilai sel sebagai teks: skalar apa adanya, objek/larik sebagai JSON ringkas, kosong sebagai "-". */
export function teksNilai(v) {
  if (v === undefined || v === null) return '-';
  if (typeof v === 'object') { try { return JSON.stringify(v); } catch { return '[objek]'; } }
  return String(v);
}

const sama = (a, b) => JSON.stringify(a) === JSON.stringify(b);
const objek = (v) => (v && typeof v === 'object' && !Array.isArray(v) ? v : {});

/** Baris perbandingan [{kolom, lama, baru}] untuk kunci yang berubah (gabungan kunci lama dan baru). */
export function diffNilai(lama, baru) {
  const a = objek(lama); const b = objek(baru);
  const kunci = [...new Set([...Object.keys(a), ...Object.keys(b)])];
  return kunci
    .filter((k) => !(k in a && k in b && sama(a[k], b[k])))
    .map((k) => ({ kolom: k, lama: teksNilai(a[k]), baru: teksNilai(b[k]) }));
}

/** Query dari filter; kolom kosong dibuang. */
export function bangunQuery(f = {}, page = 1) {
  const q = { page, per_page: 25 };
  for (const k of ['modul', 'user_id', 'aksi', 'from', 'to']) if (f[k] !== undefined && String(f[k]).trim() !== '') q[k] = String(f[k]).trim();
  return q;
}

/** Pesan galat ramah; 403 => "Tidak punya akses". */
export function pesanGalat(e) {
  if (e?.status === 403) return 'Tidak punya akses';
  if (e?.status === 422) return Object.values(e.errors ?? e.data?.errors ?? {}).flat()[0] || 'Filter tidak valid.';
  return e?.message || 'Gagal memuat log audit.';
}

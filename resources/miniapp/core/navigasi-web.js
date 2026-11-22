// Logika murni navigasi kerangka WEB (tanpa DOM, dapat diuji node --test).
// Butir nav dan rel DIBANGUN dari menuFor(user) + pintasanWeb(user); tidak ada daftar menu yang ditulis tangan di sini,
// jadi peran dengan sedikit modul otomatis melihat sedikit butir. Hak tetap dari peta server (lihat modules/menu.js).

/** Inisial untuk lambang pengguna: dua huruf pertama dari dua kata pertama, huruf besar. Kosong -> "?". */
export function inisial(nama) {
  const kata = String(nama ?? '').trim().split(/\s+/).filter(Boolean);
  if (!kata.length) return '?';
  const huruf = kata.length === 1 ? kata[0].slice(0, 2) : kata[0][0] + kata[1][0];
  return huruf.toUpperCase();
}

const kunciDari = (href) => String(href).replace(/^#\//, '').replace(/\/$/, '');

/**
 * Susun butir navigasi dari data yang sama dengan beranda.
 * pintasan: keluaran pintasanWeb(user) [{href, ikon(emoji), judul}]. menu: keluaran menuFor(user) [{slug, title, icon}].
 * Hasil: [{kunci, href, label, emoji, grup: 'utama'|'modul'}], tanpa href ganda. Beranda selalu pertama.
 */
export function susunButir({ menu = [], pintasan = [] } = {}) {
  const hasil = [{ kunci: '', href: '#/', label: 'Beranda', emoji: '\u{1F3E0}', grup: 'utama' }];
  const ada = new Set(['#/']);
  const tambah = (b) => { if (!ada.has(b.href)) { ada.add(b.href); hasil.push(b); } };
  for (const p of pintasan) tambah({ kunci: kunciDari(p.href), href: p.href, label: p.judul, emoji: p.ikon, grup: 'utama' });
  for (const m of menu) tambah({ kunci: m.slug, href: `#/${m.slug}`, label: m.title, emoji: m.icon, grup: 'modul' });
  return hasil;
}

/** Path rute dari hash ("#/jadwal/distribusi?x=1" -> "/jadwal/distribusi"); hash lain -> "/". */
export function pathDariHash(hash = '') {
  if (!String(hash).startsWith('#/')) return '/';
  return String(hash).slice(1).split('?')[0].replace(/\/+$/, '') || '/';
}

/**
 * Butir yang sedang aktif untuk `path`: cocok persis, atau induk terdekat (mis. "/keuangan/pencairan" -> keuangan,
 * "/jadwal/12" -> jadwal). "/sesi" -> 'sesi'. Tidak ada kecocokan -> null.
 */
export function butirAktif(path, butir) {
  if (path === '/' || path === '') return '';
  if (path === '/sesi') return 'sesi';
  let terbaik = null;
  for (const b of butir) {
    if (!b.kunci) continue;
    const k = `/${b.kunci}`;
    if ((path === k || path.startsWith(`${k}/`)) && (terbaik === null || b.kunci.length > terbaik.length)) terbaik = b.kunci;
  }
  return terbaik;
}

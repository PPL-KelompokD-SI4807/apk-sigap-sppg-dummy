// Ikon SVG sebaris untuk antarmuka WEB (stroke currentColor, kotak 24). Dibangun lewat createElementNS (tanpa innerHTML,
// tanpa atribut style), jadi aman terhadap CSP `style-src 'self'`. Kunci peta = slug modul agar kerangka dan beranda
// dapat memilih ikon dari data menu tanpa daftar tersendiri. Modul tanpa ikon di sini memakai cadangan emoji bawaan modulnya.
const NS = 'http://www.w3.org/2000/svg';

const lingkaran = (x, y, r) => `M${x - r} ${y}a${r} ${r} 0 1 0 ${2 * r} 0a${r} ${r} 0 1 0 ${-2 * r} 0`;
const kotak = (x, y, w, t, r) => `M${x + r} ${y}h${w - 2 * r}a${r} ${r} 0 0 1 ${r} ${r}v${t - 2 * r}a${r} ${r} 0 0 1 ${-r} ${r}h${-(w - 2 * r)}a${r} ${r} 0 0 1 ${-r} ${-r}v${-(t - 2 * r)}a${r} ${r} 0 0 1 ${r} ${-r}Z`;

export const PETA_IKON = {
  beranda: [kotak(3, 3, 7, 8, 1.6), kotak(14, 3, 7, 5, 1.6), kotak(14, 11, 7, 10, 1.6), kotak(3, 14, 7, 7, 1.6)],
  dashboard: ['M4 20V10M10 20V4M16 20v-7M22 20H2'],
  'penerima-manfaat-agregat': ['M12 3v9h9', 'M20.5 15A9 9 0 1 1 9 3.5'],
  sppg: ['M5 13h14a7 7 0 0 1-7 7 7 7 0 0 1-7-7Z', 'M4 20h16', 'M9 9c0-1.5 1-1.5 1-3s-1-1.5-1-3', 'M14 9c0-1.5 1-1.5 1-3s-1-1.5-1-3'],
  'titik-layanan': ['M12 21s-6.5-5.6-6.5-11a6.5 6.5 0 0 1 13 0c0 5.4-6.5 11-6.5 11Z', lingkaran(12, 10, 2.4)],
  'penerima-manfaat': [lingkaran(9, 8, 3.2), 'M3.5 19a5.5 5.5 0 0 1 11 0', 'M16.5 6.2a3 3 0 0 1 0 5.6', 'M18 19a5.4 5.4 0 0 0-2.3-4.4'],
  'bahan-baku': ['M5 13c0-5 4-9 14-9 0 10-4 14-9 14a5 5 0 0 1-5-5Z', 'M5 20c2-5 5-8 9-10'],
  pemasok: ['M4 9 5.5 4h13L20 9', 'M4 9v10a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V9', 'M4 9a2.7 2.7 0 0 0 5.3 0 2.7 2.7 0 0 0 5.4 0A2.7 2.7 0 0 0 20 9', 'M10 20v-5h4v5'],
  'menu-gizi': ['M7 3v7a2 2 0 0 0 2 2v9', 'M5 3v6M9 3v6', 'M17 21V3c-2.2 1.5-3 4-3 7 0 1.7 1 3 3 3'],
  jadwal: [kotak(3, 5, 18, 16, 2.4), 'M3 10h18', 'M8 3v4M16 3v4'],
  'log-produksi': [kotak(5, 4, 14, 17, 2.4), 'M9 4V3h6v1', 'M9 11h6M9 15h4'],
  mutu: ['M12 3 5 6v5.5c0 4.2 2.9 7.6 7 9.5 4.1-1.9 7-5.3 7-9.5V6Z', 'm9.3 12 1.9 1.9 3.6-3.8'],
  armada: ['M3 7h10v9H3z', 'M13 10h4l3 3v3h-7z', lingkaran(7, 18, 1.8), lingkaran(17, 18, 1.8)],
  pengemudi: [lingkaran(12, 12, 9), lingkaran(12, 12, 2.2), 'M12 3v6.8M3.6 14.5l6.4-2M20.4 14.5l-6.4-2'],
  'bukti-distribusi': ['M4 8h3l1.6-2.5h6.8L17 8h3v11H4Z', lingkaran(12, 13.2, 3.3)],
  keuangan: [kotak(3, 6, 18, 12, 2.4), lingkaran(12, 12, 2.4), 'M6.5 12h.01M17.5 12h.01'],
  keluhan: ['M4 5h16v11H9l-5 4Z', 'M12 8.5v3', 'M12 13.6h.01'],
  'rekap-harian': [kotak(4, 3, 16, 18, 2.4), 'M8 16v-3M12 16V8M16 16v-5'],
  'notifikasi-perjalanan': ['M18 8a6 6 0 1 0-12 0c0 7-2 8-2 8h16s-2-1-2-8', 'M13.7 21a2 2 0 0 1-3.4 0'],
  pengguna: [lingkaran(12, 8, 3.6), 'M5 20a7 7 0 0 1 14 0'],
  'persetujuan-menu': [lingkaran(12, 12, 9), 'm8.3 12.3 2.6 2.6 4.9-5.2'],
  'pembelian-bahan': ['M3 4h2.4l2 11h10.2l2-8H6.6', lingkaran(9.5, 19.5, 1.4), lingkaran(17, 19.5, 1.4)],
  'laporan-bgn': ['M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8Z', 'M14 3v5h5', 'M9 13h6M9 17h4'],
  'audit-log': ['M8 6h12M8 12h12M8 18h12', 'M4 6h.01M4 12h.01M4 18h.01'],
  sesi: [kotak(3, 4, 18, 12, 2), 'M8 20h8M12 16v4'],
  keluar: ['M14 20H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h8', 'm16 16 4-4-4-4', 'M20 12H10'],
  matahari: [lingkaran(12, 12, 4), 'M12 2.5v2.2M12 19.3v2.2M2.5 12h2.2M19.3 12h2.2M5.3 5.3l1.6 1.6M17.1 17.1l1.6 1.6M5.3 18.7l1.6-1.6M17.1 6.9l1.6-1.6'],
  bulan: ['M20 14.2A8 8 0 0 1 9.8 4 8 8 0 1 0 20 14.2Z'],
  sistem: [kotak(3, 4, 18, 12, 2), 'M8 20h8M12 16v4'],
  jam: [lingkaran(12, 12, 9), 'M12 7v5l3 2'],
  unduh: ['M12 4v11', 'm7.5 11 4.5 4.5 4.5-4.5', 'M5 20h14'],
  panah: ['M5 12h14', 'm13 6 6 6-6 6'],
  merek: ['M4 12h16a8 8 0 0 1-8 8 8 8 0 0 1-8-8Z', 'M3 20h18', 'M12 4v4'],
  info: [lingkaran(12, 12, 9), 'M12 8h.01M11 12h1v4h1'],
};

/** Elemen <svg> untuk `nama`, atau null bila ikon tidak ada / DOM tidak mendukung SVG (mis. lingkungan uji). */
export function ikon(nama, { ukuran = 18, tebal = 1.8 } = {}) {
  const jalur = PETA_IKON[nama];
  if (!jalur || typeof document === 'undefined' || typeof document.createElementNS !== 'function') return null;
  const svg = document.createElementNS(NS, 'svg');
  const atur = { viewBox: '0 0 24 24', width: ukuran, height: ukuran, fill: 'none', stroke: 'currentColor', 'stroke-width': tebal, 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'aria-hidden': 'true', focusable: 'false' };
  for (const [k, v] of Object.entries(atur)) svg.setAttribute(k, String(v));
  for (const d of jalur) {
    const p = document.createElementNS(NS, 'path');
    p.setAttribute('d', d);
    svg.append(p);
  }
  return svg;
}

/** Ikon SVG bila ada, kalau tidak emoji bawaan modul (cadangan), dibungkus <span aria-hidden>. */
export function ikonAtauEmoji(nama, emoji, opsi) {
  const svg = ikon(nama, opsi);
  if (svg) return svg;
  if (typeof document === 'undefined') return null;
  const s = document.createElement('span');
  s.setAttribute('aria-hidden', 'true');
  s.append(document.createTextNode(emoji || '•'));
  return s;
}

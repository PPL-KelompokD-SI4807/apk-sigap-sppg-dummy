// Pemeriksaan Mutu dan Keamanan Pangan (fitur 11, PBI 11). Mockup: Gambar 14.
import { defineCrudModule } from '../../components/crud-module.js';
import { rowCard } from '../../components/row-card.js';
import { lampiranField, createLampiranPanel } from '../../components/lampiran-field.js';
import { h, formatValue } from '../../core/dom.js';
import { getUser } from '../../core/auth.js';
import { lampiranDisembunyikan } from '../../core/lampiran-uploader.js';
import { mutuFields, mutuDetailFields, mutuPayload, hasilLabel, hasilBadge, rowPermsMutu } from './schema.js';

export default defineCrudModule({
  slug: 'mutu',
  title: 'Mutu Pangan',
  icon: '🧪',
  description: 'Pemeriksaan suhu, kebersihan, dan organoleptik',
  endpoint: '/mutu',
  columns: [{ key: 'jadwal', primary: true, format: (r) => r.jadwal?.menu?.nama_menu ?? `#${r.id}` }],
  fields: ({ mode, record }) => (mode === 'detail' ? mutuDetailFields : [...mutuFields({ mode, record }), lampiranField({
    modul: 'mutu', label: 'Foto pemeriksaan', existing: record?.lampiran || [],
    help: 'Opsional, maks 5 foto (JPEG/PNG/WebP, maks 5 MB). Terunggah langsung; Simpan menunggu semua foto selesai.',
  })]),
  mapPayload: mutuPayload,
  itemTitle: (r) => `Mutu ${r.jadwal?.menu?.nama_menu ?? `#${r.id}`}`,
  searchPlaceholder: 'Cari catatan atau tindakan korektif...',
  filters: { param: 'hasil', label: 'Hasil pemeriksaan', options: [{ value: '', label: 'Semua' }, { value: 'layak', label: 'Layak' }, { value: 'tidak_layak', label: 'Tidak layak' }] },
  prefill: (q) => (q.get('jadwal') ? { jadwal_produksi_id: q.get('jadwal') } : {}),
  renderCard: (r, href) => rowCard({
    href,
    title: r.jadwal?.menu?.nama_menu ?? `Pemeriksaan #${r.id}`,
    sub: [r.jadwal?.tanggal_produksi ? formatValue(r.jadwal.tanggal_produksi, 'date') : null, r.suhu_makanan_c != null ? `${r.suhu_makanan_c} °C` : null, r.pemeriksa].filter(Boolean).join(' · '),
    badge: { text: r.hasil_label || hasilLabel(r.hasil), cls: hasilBadge(r.hasil) },
  }),
  rowPerms: rowPermsMutu,
  detailExtras: (rec, p) => {
    const nodes = [];
    if (rec.hasil === 'tidak_layak') nodes.push(h('div', { class: 'alert-danger' }, 'Hasil TIDAK LAYAK: keberangkatan distribusi untuk jadwal ini ditahan sampai ada pemeriksaan ulang berstatus Layak.'));
    if (rec.memengaruhi_distribusi) nodes.push(h('div', { class: 'notice' }, 'Pemeriksaan ini sudah memengaruhi distribusi, sehingga tidak dapat dihapus. Gunakan Ubah dengan catatan koreksi.'));
    if (p.C && rec.jadwal_produksi_id) nodes.push(h('a', { class: 'btn btn-block', href: `#/mutu/baru?jadwal=${rec.jadwal_produksi_id}` }, 'Periksa ulang jadwal ini'));
    if (lampiranDisembunyikan(getUser(), rec) || (rec.lampiran || []).length || p.U) nodes.push(createLampiranPanel({ disembunyikan: lampiranDisembunyikan(getUser(), rec), modul: 'mutu', recordId: rec.id, kategori: 'foto', lampiran: rec.lampiran || [], canAdd: !!p.U, title: 'Foto pemeriksaan' }));
    return nodes;
  },
});

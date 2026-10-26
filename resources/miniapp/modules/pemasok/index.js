// Data Pemasok (fitur 7, PBI 7). Mockup: Gambar 24.
import { defineCrudModule } from '../../components/crud-module.js';
import { rowCard } from '../../components/row-card.js';
import { hargaPanel } from './harga.js';
import { pemasokFields, mapPayload, JENIS_PEMASOK, jenisPemasokLabel } from './schema.js';

export default defineCrudModule({
  slug: 'pemasok',
  title: 'Pemasok',
  icon: '🚚',
  description: 'Petani, peternak, nelayan, koperasi, dan UMKM pemasok',
  endpoint: '/pemasok',
  columns: [
    { key: 'nama', primary: true },
    { key: 'jenis', badge: true, format: (r) => jenisPemasokLabel(r.jenis) },
    { key: 'jenis_bahan', label: 'Bahan' },
    { key: 'kontak', label: 'Kontak' },
    { key: 'alamat', label: 'Alamat' },
  ],
  fields: pemasokFields,
  mapPayload,
  searchPlaceholder: 'Cari pemasok...',
  filters: { param: 'jenis', label: 'Jenis pemasok', options: [{ value: '', label: 'Semua' }, ...JENIS_PEMASOK] },
  detailExtras: (rec, p) => hargaPanel(rec, p),
  renderCard: (r, href) => rowCard({
    href,
    title: r.nama,
    sub: [jenisPemasokLabel(r.jenis), r.jenis_bahan, r.kontak].filter(Boolean).join(' · '),
    badge: r.is_active === false ? { text: 'Nonaktif', cls: 'badge-danger' } : null,
  }),
});

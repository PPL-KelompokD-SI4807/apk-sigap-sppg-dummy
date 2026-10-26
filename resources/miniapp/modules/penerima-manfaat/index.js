// Data Penerima Manfaat (fitur 5, PBI 5). Mockup: Gambar 22. Tanpa NIK.
import { defineCrudModule } from '../../components/crud-module.js';
import { rowCard } from '../../components/row-card.js';
import { penerimaFields, mapPayload, KELOMPOK, kelompokLabel } from './schema.js';

export default defineCrudModule({
  slug: 'penerima-manfaat',
  title: 'Penerima Manfaat',
  icon: '🧒',
  description: 'Siswa, balita, ibu hamil/menyusui, dan catatan alergi',
  endpoint: '/penerima-manfaat',
  columns: [
    { key: 'nama', primary: true },
    { key: 'kelompok_sasaran', badge: true, format: (r) => kelompokLabel(r.kelompok_sasaran) },
    { key: 'titik_layanan_nama', label: 'Titik layanan' },
    { key: 'kelas_keterangan', label: 'Kelas' },
    { key: 'catatan_alergi', label: 'Alergi' },
  ],
  fields: penerimaFields,
  mapPayload,
  searchPlaceholder: 'Cari nama penerima manfaat...',
  filters: { param: 'kelompok_sasaran', label: 'Kelompok sasaran', options: [{ value: '', label: 'Semua' }, ...KELOMPOK] },
  renderCard: (r, href) => rowCard({
    href,
    title: r.nama,
    sub: [kelompokLabel(r.kelompok_sasaran), r.kelas_keterangan, r.titik_layanan_nama].filter(Boolean).join(' · '),
    badge: r.catatan_alergi ? { text: 'Alergi', cls: 'badge-warn' } : (r.is_active === false ? { text: 'Nonaktif', cls: 'badge-danger' } : null),
  }),
});

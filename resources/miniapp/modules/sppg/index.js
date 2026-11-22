// Data SPPG (fitur 3, PBI 3). Mockup: Gambar 19.
import { defineCrudModule } from '../../components/crud-module.js';
import { sppgFields, mapPayload, statusBadge } from './schema.js';
import { saldoAwalPanel } from './saldo-awal.js';
import { tanpaSppgSendiri } from '../../core/permissions.js';

const label = (v) => ({ aktif: 'Aktif', libur: 'Libur', nonaktif: 'Nonaktif' }[v] ?? v ?? '-');

export default defineCrudModule({
  slug: 'sppg',
  title: 'Data SPPG',
  icon: '🏭',
  description: 'Profil, kapasitas, dan status SPPG',
  endpoint: '/sppg',
  columns: [
    { key: 'nama', primary: true },
    { key: 'status_operasional', badge: true, format: (r) => label(r.status_operasional), badgeClass: (r) => statusBadge(r.status_operasional) },
    { key: 'kode', label: 'Kode' },
    { key: 'kapasitas_porsi_harian', label: 'Kapasitas', format: (r) => `${Number(r.kapasitas_porsi_harian ?? 0).toLocaleString('id-ID')} porsi/hari` },
  ],
  fields: sppgFields,
  mapPayload,
  detailExtras: (rec) => saldoAwalPanel(rec),
  searchPlaceholder: 'Cari nama atau kode SPPG...',
  // Yang punya SPPG sendiri (bukan lintas SPPG) langsung ke profilnya (Gambar 19).
  listRedirect: (user) => (!tanpaSppgSendiri(user) && user?.sppg_id ? `/sppg/${user.sppg_id}` : null),
});

// Armada (fitur 12, PBI 12), layar /armada. Layar pengemudi terpisah di /pengemudi. Mockup: Gambar 23.
import { defineCrudModule } from '../../components/crud-module.js';
import { rowCard } from '../../components/row-card.js';
import { navTabs } from '../../components/nav-tabs.js';
import { armadaFields, mapPayload, STATUS_ARMADA, statusArmadaLabel, statusArmadaBadge } from './schema.js';

export const armadaTabs = (active) => navTabs([
  { key: 'armada', href: '#/armada', label: 'Armada' },
  { key: 'pengemudi', href: '#/pengemudi', label: 'Pengemudi' },
], active, 'Armada dan Pengemudi');

export default defineCrudModule({
  slug: 'armada',
  title: 'Armada',
  icon: '🚐',
  description: 'Kendaraan distribusi dan statusnya',
  endpoint: '/armada',
  columns: [
    { key: 'plat_nomor', primary: true },
    { key: 'status', badge: true, format: (r) => statusArmadaLabel(r.status), badgeClass: (r) => statusArmadaBadge(r.status) },
    { key: 'jenis', label: 'Jenis' },
    { key: 'kapasitas_box', label: 'Kapasitas', format: (r) => `${r.kapasitas_box ?? 0} box` },
  ],
  fields: armadaFields,
  mapPayload,
  searchPlaceholder: 'Cari plat atau jenis kendaraan...',
  filters: { param: 'status', label: 'Status armada', options: [{ value: '', label: 'Semua' }, ...STATUS_ARMADA] },
  listTop: () => armadaTabs('armada'),
  renderCard: (r, href) => rowCard({
    href,
    title: r.plat_nomor,
    sub: [r.jenis, `kapasitas ${r.kapasitas_box ?? 0} box`].filter(Boolean).join(' · '),
    badge: { text: statusArmadaLabel(r.status), cls: statusArmadaBadge(r.status) },
  }),
  // Armada yang sedang bertugas tidak dapat dihapus (422 di server), sembunyikan tombolnya.
  rowPerms: (rec, p) => (rec.status === 'bertugas' ? { ...p, D: false } : p),
});

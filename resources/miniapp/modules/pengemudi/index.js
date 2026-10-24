// Pengemudi (fitur 12, PBI 12), layar /pengemudi (terpisah dari /armada). Hak mengikuti modul "armada".
import { defineCrudModule } from '../../components/crud-module.js';
import { rowCard } from '../../components/row-card.js';
import { pengemudiFields, mapPayload, simLabel, simBadge } from './schema.js';
import { armadaTabs } from '../armada/index.js';

export default defineCrudModule({
  slug: 'pengemudi',
  permissionSlug: 'armada',
  title: 'Pengemudi',
  icon: '🧑‍✈️',
  description: 'Pengemudi dan masa berlaku SIM',
  endpoint: '/pengemudi',
  columns: [
    { key: 'nama', primary: true },
    { key: 'sim_status', badge: true, format: (r) => simLabel(r.sim_status), badgeClass: (r) => simBadge(r.sim_status) },
    { key: 'no_hp', label: 'No. HP' },
  ],
  fields: pengemudiFields,
  mapPayload,
  searchPlaceholder: 'Cari nama pengemudi...',
  filters: { param: 'is_active', label: 'Status pengemudi', options: [{ value: '', label: 'Semua' }, { value: '1', label: 'Aktif' }, { value: '0', label: 'Nonaktif' }] },
  listTop: () => armadaTabs('pengemudi'),
  renderCard: (r, href) => rowCard({
    href,
    title: r.nama,
    sub: [r.no_hp, r.is_active === false ? 'Nonaktif' : null].filter(Boolean).join(' · ') || null,
    badge: r.sim_status ? { text: simLabel(r.sim_status), cls: simBadge(r.sim_status) } : null,
  }),
});

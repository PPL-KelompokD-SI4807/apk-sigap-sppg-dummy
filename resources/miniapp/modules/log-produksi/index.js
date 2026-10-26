// Log Produksi Harian (fitur 10, PBI 10). Mockup: Gambar 13. Hanya relawan unit dapur yang membuat log.
import { defineCrudModule } from '../../components/crud-module.js';
import { rowCard } from '../../components/row-card.js';
import { lampiranField, createLampiranPanel } from '../../components/lampiran-field.js';
import { h, formatValue } from '../../core/dom.js';
import { api } from '../../core/api.js';
import { getUser } from '../../core/auth.js';
import { logFields, logDetailFields, logPayload, hitungCapaian, selisihText, rowPermsLog } from './schema.js';

const unwrap = (r) => (r && r.data !== undefined && !Array.isArray(r) ? r.data : r);

function formTop({ mode, record, form, collect }) {
  const box = h('div', { class: 'stack-tight', 'aria-live': 'polite' });
  let target = record?.target_porsi ?? null;
  let tick = 0;
  async function show() {
    const c = hitungCapaian(collect().realisasi_porsi, target);
    box.replaceChildren(
      target != null ? h('div', { class: 'panel' }, h('strong', {}, `Target porsi: ${Number(target).toLocaleString('id-ID')}`)) : h('p', { class: 'muted small' }, 'Pilih jadwal untuk melihat target porsi.'),
      c ? h('div', { class: c.selisih === 0 ? 'alert-ok' : 'notice' }, `Selisih ${selisihText(c.selisih)} · capaian ${c.persen.toLocaleString('id-ID')}%`, c.peringatan ? ` · ${c.peringatan}` : '') : null);
  }
  async function loadTarget() {
    const my = ++tick;
    const id = collect().jadwal_produksi_id;
    if (!id) { target = null; return show(); }
    try { const j = unwrap(await api.get(`/jadwal/${id}`, null, { silent: true })); if (my === tick) target = j?.target_porsi ?? null; } catch { if (my === tick) target = null; }
    show();
  }
  if (mode === 'create') form.addEventListener('change', (e) => { if (e.target?.name === 'jadwal_produksi_id') loadTarget(); });
  form.addEventListener('input', show);
  if (mode === 'create') loadTarget(); else show();
  return box;
}

export default defineCrudModule({
  slug: 'log-produksi',
  title: 'Log Produksi',
  icon: '👨‍🍳',
  description: 'Realisasi porsi, sisa makanan, dan kendala harian',
  endpoint: '/log-produksi',
  columns: [{ key: 'kode_batch', primary: true }],
  fields: ({ mode, record }) => (mode === 'detail' ? logDetailFields : [...logFields({ mode, record }), lampiranField({
    modul: 'log-produksi', label: 'Foto produksi', existing: record?.lampiran || [],
    help: 'Opsional, maks 5 foto (JPEG/PNG/WebP, maks 5 MB). Terunggah langsung; Simpan menunggu semua foto selesai.',
  })]),
  mapPayload: logPayload,
  itemTitle: (r) => r.kode_batch || `Log #${r.id}`,
  searchPlaceholder: 'Cari kode batch atau kendala...',
  prefill: (q) => (q.get('jadwal') ? { jadwal_produksi_id: q.get('jadwal') } : {}),
  formTop,
  renderCard: (r, href) => rowCard({
    href,
    title: r.jadwal?.menu?.nama_menu ?? r.kode_batch ?? `Log #${r.id}`,
    sub: [r.kode_batch, r.jadwal?.tanggal_produksi ? formatValue(r.jadwal.tanggal_produksi, 'date') : null, r.target_porsi != null ? `target ${r.target_porsi}` : null].filter(Boolean).join(' · '),
    aside: Number(r.realisasi_porsi ?? 0).toLocaleString('id-ID'),
    asideSub: r.persentase_capaian != null ? `${Number(r.persentase_capaian).toLocaleString('id-ID', { maximumFractionDigits: 1 })}%` : 'porsi',
    badge: r.peringatan ? { text: 'Beda target', cls: 'badge-warn' } : null,
  }),
  rowPerms: (rec, p) => rowPermsLog(getUser(), rec, p),
  detailExtras: (rec, p) => {
    const nodes = rec.peringatan ? [h('div', { class: 'notice' }, `⚠ ${rec.peringatan}`)] : [];
    if ((rec.lampiran || []).length || p.U) nodes.push(createLampiranPanel({ modul: 'log-produksi', recordId: rec.id, kategori: 'foto', lampiran: rec.lampiran || [], canAdd: !!p.U, title: 'Foto produksi' }));
    return nodes;
  },
});

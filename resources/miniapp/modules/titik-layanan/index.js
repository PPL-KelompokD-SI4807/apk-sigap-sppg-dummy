// Data Titik Layanan (fitur 4, PBI 4) + undangan PIC. Mockup: Gambar 20.
// Menggantikan modul contoh (modules/_contoh): slug sama, modul asli yang dipakai.
import { defineCrudModule } from '../../components/crud-module.js';
import { rowCard } from '../../components/row-card.js';
import { showInviteLink } from '../../components/invite-dialog.js';
import { h } from '../../core/dom.js';
import { api } from '../../core/api.js';
import { showConfirm, toast } from '../../core/ui.js';
import { errorMessage } from '../../core/errors.js';
import { titikFields, mapPayload, JENIS, jenisLabel, jarakText } from './schema.js';

const unwrap = (r) => (r && r.data !== undefined && !Array.isArray(r) ? r.data : r);

async function undangPic(rec, btn) {
  const ok = await showConfirm({
    title: 'Undang PIC',
    message: rec.pic_terhubung
      ? `PIC "${rec.nama}" sudah terhubung. Buat tautan baru? Tautan aktif sebelumnya akan dicabut.`
      : `Buat tautan undangan Telegram untuk PIC "${rec.nama}"? Tautan aktif sebelumnya akan dicabut.`,
    okText: 'Buat tautan',
  });
  if (!ok) return;
  btn.disabled = true;
  try {
    const inv = unwrap(await api.post(`/titik-layanan/${rec.id}/undangan-pic`, undefined, { silent: true }));
    await showInviteLink(inv, { title: 'Tautan undangan PIC', shareText: `Undangan PIC ${rec.nama} untuk menerima Notifikasi Perjalanan Mobil SiGAP SPPG.` });
  } catch (e) {
    if (e?.status !== 401) toast(errorMessage(e, 'Gagal membuat undangan.'), 'error');
  } finally { btn.disabled = false; }
}

export default defineCrudModule({
  slug: 'titik-layanan',
  title: 'Titik Layanan',
  icon: '📍',
  description: 'Sekolah, PAUD, posyandu, dan PIC penerima notifikasi',
  endpoint: '/titik-layanan',
  columns: [
    { key: 'nama', primary: true },
    { key: 'jenis', badge: true, format: (r) => jenisLabel(r.jenis) },
    { key: 'jarak_km', label: 'Jarak', format: (r) => jarakText(r.jarak_km) },
    { key: 'jumlah_sasaran', label: 'Sasaran', type: 'number' },
    { key: 'pic_nama', label: 'PIC' },
  ],
  fields: titikFields,
  mapPayload,
  searchPlaceholder: 'Cari sekolah, PAUD, posyandu...',
  filters: { param: 'jenis', label: 'Jenis titik layanan', options: [{ value: '', label: 'Semua' }, ...JENIS] },
  renderCard: (r, href) => rowCard({
    href,
    title: r.nama,
    sub: [jenisLabel(r.jenis), jarakText(r.jarak_km), r.pic_nama ? `PIC ${r.pic_nama}${r.pic_terhubung ? ' ✓' : ''}` : null].filter(Boolean).join(' · '),
    aside: Number(r.jumlah_sasaran ?? 0).toLocaleString('id-ID'),
    asideSub: 'sasaran',
    badge: r.is_active === false ? { text: 'Nonaktif', cls: 'badge-danger' } : null,
  }),
  listTop: () => h('p', { class: 'muted small' }, 'PIC yang telah menghubungkan Telegram (✓) menerima Notifikasi Perjalanan Mobil secara otomatis.'),
  detailExtras: (rec, p) => {
    const nodes = [h('div', { class: 'panel' },
      h('h2', { class: 'panel-title' }, 'PIC & Telegram'),
      h('p', {}, rec.pic_terhubung ? 'PIC sudah menghubungkan Telegram dan menerima notifikasi perjalanan mobil.' : 'PIC belum menghubungkan Telegram, notifikasi perjalanan belum dapat dikirim.'))];
    // Hak ubah titik layanan (Admin/Kepala SPPG) = hak menerbitkan undangan PIC.
    if (p.U) {
      const btn = h('button', { class: 'btn btn-primary btn-block', type: 'button' }, rec.pic_terhubung ? 'Buat ulang undangan PIC' : 'Undang PIC lewat Telegram');
      btn.addEventListener('click', () => undangPic(rec, btn));
      nodes.push(btn);
    }
    return nodes;
  },
});

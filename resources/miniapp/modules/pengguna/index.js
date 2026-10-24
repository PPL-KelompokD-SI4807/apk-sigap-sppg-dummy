// Data Pengguna / Login Akun (fitur 1, PBI 1) + undangan Telegram. Mockup: Gambar 18.
// Rute: #/pengguna (daftar), /baru, /:id, /:id/ubah, serta #/pengguna/undangan (+ /baru) untuk Admin.
import { defineCrudModule } from '../../components/crud-module.js';
import { createListView } from '../../components/list-view.js';
import { createFormView } from '../../components/form-view.js';
import { rowCard } from '../../components/row-card.js';
import { showInviteLink } from '../../components/invite-dialog.js';
import { h } from '../../core/dom.js';
import { api } from '../../core/api.js';
import { getUser } from '../../core/auth.js';
import { userPerms } from '../../core/permissions.js';
import { route, navigate } from '../../core/router.js';
import { showConfirm, toast } from '../../core/ui.js';
import { errorMessage } from '../../core/errors.js';
import {
  penggunaFields, mapPayload, unitLabel, statusLabel, statusCls,
  STATUS_UNDANGAN, undanganCls, undanganLabel, undanganTarget, undanganFields, buildInvitePayload,
} from './schema.js';

const unwrap = (r) => (r && r.data !== undefined && !Array.isArray(r) ? r.data : r);
const canInvite = () => { const p = userPerms(getUser(), 'pengguna'); return p.C && p.U; }; // hanya Admin

async function terbitkanUndangan(body, { title, shareText }) {
  const inv = unwrap(await api.post('/pengguna/undangan', body, { silent: true }));
  await showInviteLink(inv, { title, shareText });
}

const base = defineCrudModule({
  slug: 'pengguna',
  title: 'Data Pengguna',
  icon: '👥',
  description: 'Akun, peran, dan undangan Telegram',
  endpoint: '/pengguna',
  columns: [
    { key: 'name', primary: true },
    { key: 'status', badge: true, format: (r) => statusLabel(r.status), badgeClass: (r) => statusCls(r.status) },
    { key: 'role_label', label: 'Peran' },
    { key: 'sppg_nama', label: 'SPPG' },
  ],
  fields: penggunaFields,
  mapPayload,
  createLabel: '+ Tambah',
  searchPlaceholder: 'Cari nama pengguna...',
  filters: { param: 'status', label: 'Status pengguna', options: [
    { value: '', label: 'Semua' }, { value: 'aktif', label: 'Aktif' }, { value: 'menunggu_telegram', label: 'Undangan' }, { value: 'nonaktif', label: 'Nonaktif' },
  ] },
  renderCard: (r, href) => rowCard({
    href,
    avatar: true,
    title: r.name,
    sub: [r.role_label, unitLabel(r.unit)].filter(Boolean).join(' · '),
    badge: { text: statusLabel(r.status), cls: statusCls(r.status) },
  }),
  listTop: (p) => (p.C ? h('div', { class: 'stack' },
    h('a', { class: 'btn btn-block', href: '#/pengguna/undangan' }, 'Kelola tautan undangan'),
    h('p', { class: 'notice' }, 'Tautan undangan Telegram berlaku sekali pakai dan kedaluwarsa dalam 72 jam. Akun Telegram terhubung otomatis saat tautan dibuka. Buat akun, lalu buka detailnya untuk menerbitkan tautan.')) : null),
  // Admin tidak boleh menghapus akunnya sendiri (422 di server): sembunyikan tombol hapus.
  rowPerms: (rec, p) => (rec.id === getUser()?.id ? { ...p, D: false } : p),
  detailExtras: (rec, p) => {
    if (!(canInvite() && rec.status === 'menunggu_telegram')) return null;
    const btn = h('button', { class: 'btn btn-primary btn-block', type: 'button' }, 'Buat tautan undangan Telegram');
    btn.addEventListener('click', async () => {
      btn.disabled = true;
      try {
        await terbitkanUndangan({ user_id: rec.id }, { title: `Undangan untuk ${rec.name}`, shareText: `Halo ${rec.name}, buka tautan ini di Telegram untuk masuk ke SiGAP SPPG.` });
      } catch (e) {
        if (e?.status !== 401) toast(errorMessage(e, 'Gagal membuat undangan.'), 'error');
      } finally { btn.disabled = false; }
    });
    return btn;
  },
});

function registerUndangan() {
  // Didaftarkan SEBELUM rute /pengguna/:id supaya "undangan" tidak dianggap id.
  route('/pengguna/undangan', (ctx) => {
    const lv = createListView({
      endpoint: '/pengguna/undangan',
      columns: [{ key: 'tipe', primary: true }],
      searchable: false,
      itemHref: () => '#/pengguna/undangan',
      createHref: '#/pengguna/undangan/baru',
      createLabel: '+ Undangan',
      emptyText: 'Belum ada undangan.',
      filters: { param: 'status', label: 'Status undangan', options: [{ value: '', label: 'Semua' }, ...STATUS_UNDANGAN] },
      renderCard: (inv) => {
        const exp = inv.expires_at ? new Date(inv.expires_at).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }) : '-';
        const card = h('div', { class: 'row-card' },
          h('span', { class: 'rc-main' },
            h('span', { class: 'rc-title' }, undanganTarget(inv)),
            h('div', { class: 'rc-sub' }, `${inv.tipe === 'pic' ? 'PIC titik layanan' : 'Pengguna'} · berlaku hingga ${exp}`)),
          h('span', { class: `badge ${undanganCls(inv.status)}` }, undanganLabel(inv.status)));
        if (inv.status === 'aktif') {
          card.append(h('button', {
            class: 'btn', type: 'button', 'aria-label': `Cabut undangan ${undanganTarget(inv)}`,
            onclick: async () => {
              if (!(await showConfirm({ title: 'Cabut undangan', message: 'Tautan undangan ini tidak akan berlaku lagi. Lanjutkan?', okText: 'Cabut', destructive: true }))) return;
              try { await api.del(`/pengguna/undangan/${inv.id}`, { silent: true }); toast('Undangan dicabut.', 'success'); lv.reload(); }
              catch (e) { if (e?.status !== 401) toast(errorMessage(e, 'Gagal mencabut undangan.'), 'error'); }
            },
          }, 'Cabut'));
        }
        return card;
      },
    });
    ctx.el.append(h('p', { class: 'muted small' }, 'Token tidak dapat ditampilkan ulang. Bila tautan hilang, buat undangan baru (undangan aktif sebelumnya otomatis dicabut).'), lv.el);
  }, { title: 'Undangan Telegram', guard: canInvite });

  route('/pengguna/undangan/baru', (ctx) => {
    const fv = createFormView({
      fields: undanganFields(),
      submitText: 'Buat tautan undangan',
      onSubmit: async (values) => {
        try {
          await terbitkanUndangan(buildInvitePayload(values), { title: 'Tautan undangan pengguna', shareText: 'Buka tautan ini di Telegram untuk masuk ke SiGAP SPPG.' });
        } catch (e) {
          if (e?.status !== 422 && e?.status !== 401) toast(errorMessage(e, 'Gagal membuat undangan.'), 'error');
          throw e; // 422 -> galat per kolom oleh form-view
        }
        navigate('/pengguna/undangan', { replace: true });
      },
    });
    ctx.el.append(fv.el);
    return fv.destroy;
  }, { title: 'Buat Undangan', guard: canInvite });
}

export default {
  ...base,
  register() { registerUndangan(); base.register(); },
};

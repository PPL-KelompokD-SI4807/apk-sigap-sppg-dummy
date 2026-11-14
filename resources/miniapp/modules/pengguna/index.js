// Data Pengguna / Login Akun (fitur 1, PBI 1) + undangan Telegram. Mockup: Gambar 18.
// Rute: #/pengguna (daftar), /baru, /:id, /:id/ubah, serta #/pengguna/undangan (+ /baru) untuk Admin.
import { defineCrudModule } from '../../components/crud-module.js';
import { createListView } from '../../components/list-view.js';
import { createFormView } from '../../components/form-view.js';
import { rowCard } from '../../components/row-card.js';
import { showInviteLink } from '../../components/invite-dialog.js';
import { renderPermintaanReset } from '../../components/permintaan-reset-view.js';
import { renderSesiPengguna } from '../../components/sesi-view.js';
import { kepemilikanSppgPanel } from '../../components/kepemilikan-sppg.js';
import { h } from '../../core/dom.js';
import { api } from '../../core/transport.js';
import { getUser } from '../../core/auth.js';
import { userPerms, bolehAksi } from '../../core/permissions.js';
import { route, navigate } from '../../core/router.js';
import { showConfirm, toast } from '../../core/ui.js';
import { errorMessage } from '../../core/errors.js';
import { perluDiperbaiki } from '../../core/draf-galat.js';
import {
  penggunaFields, mapPayload, unitLabel, statusLabel, statusCls,
  STATUS_UNDANGAN, undanganCls, undanganLabel, undanganTarget, undanganFields, buildInvitePayload,
  isianUndanganDariDraf, isianPenggunaDariDraf,
} from './schema.js';

const unwrap = (r) => (r && r.data !== undefined && !Array.isArray(r) ? r.data : r);
// Aksi pengguna mengikuti peta hak server (aksi.*), bukan huruf modul/peran.
const bolehUndang = () => bolehAksi(getUser(), 'undang_pengguna');
const bolehResetSesi = () => bolehAksi(getUser(), 'reset_password_pengguna');

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
  listTop: () => (bolehUndang() || bolehResetSesi() ? h('div', { class: 'stack' },
    bolehUndang() ? h('a', { class: 'btn btn-block', href: '#/pengguna/undangan' }, 'Kelola tautan undangan') : null,
    bolehResetSesi() ? h('a', { class: 'btn btn-block', href: '#/pengguna/permintaan-reset' }, 'Permintaan reset password') : null,
    bolehUndang() ? h('p', { class: 'notice' }, 'Tautan undangan Telegram berlaku sekali pakai dan kedaluwarsa dalam 72 jam. Akun Telegram terhubung otomatis saat tautan dibuka. Buat akun, lalu buka detailnya untuk menerbitkan tautan.') : null) : null),
  // Kartu menu: bagi hak "R*" (profil sendiri) judulnya "Profil Saya", bukan daftar "Data Pengguna".
  menuInfo: (user) => (userPerms(user, 'pengguna').selfOnly ? { title: 'Profil Saya', description: 'Akun, peran, dan perangkat Anda' } : {}),
  prefillDraf: isianPenggunaDariDraf,
  // Admin tidak boleh menghapus akunnya sendiri (422 di server): sembunyikan tombol hapus.
  rowPerms: (rec, p) => (rec.id === getUser()?.id ? { ...p, D: false } : p),
  detailExtras: (rec) => {
    const out = [];
    if (bolehResetSesi()) out.push(akunKredensial(rec));
    if (rec.role === 'investor' && bolehAksi(getUser(), 'kelola_kepemilikan_sppg')) out.push(kepemilikanSppgPanel(rec));
    if (!bolehUndang() || rec.status !== 'menunggu_telegram') return out.length ? out : null;
    const btn =h('button', { class: 'btn btn-primary btn-block', type: 'button' }, 'Buat tautan undangan Telegram');
    btn.addEventListener('click', async () => {
      btn.disabled = true;
      try {
        await terbitkanUndangan({ user_id: rec.id }, { title: `Undangan untuk ${rec.name}`, shareText: `Halo ${rec.name}, buka tautan ini di Telegram untuk masuk ke SiGAP SPPG.` });
      } catch (e) {
        if (e?.status !== 401) toast(errorMessage(e, 'Gagal membuat undangan.'), 'error');
      } finally { btn.disabled = false; }
    });
    out.push(btn);
    return out;
  },
});

// Kredensial (R-03): status password, reset password, dan cabut sesi. Admin tidak pernah melihat password.
function akunKredensial(rec) {
  const aksi = (label, cls, { title, message, okText, run, sukses }) => {
    const b = h('button', { class: `btn ${cls} btn-block`, type: 'button' }, label);
    b.addEventListener('click', async () => {
      if (!(await showConfirm({ title, message, okText, destructive: true }))) return;
      b.disabled = true;
      try { const r = await run(); toast(r?.message || sukses, 'success'); }
      catch (e) { if (e?.status !== 401) toast(errorMessage(e, 'Aksi gagal.'), 'error'); }
      finally { b.disabled = false; }
    });
    return b;
  };
  const self = rec.id === getUser()?.id;
  return h('div', { class: 'panel stack' },
    h('h2', { class: 'panel-title' }, 'Kredensial login'),
    h('p', { class: 'muted small' }, `Username: ${rec.username || '-'} · Password: ${rec.password_diatur ? 'sudah diatur' : 'belum diatur'}`),
    self ? null : aksi('Reset password', 'btn-danger', {
      title: 'Reset password', okText: 'Reset', sukses: 'Password direset.',
      message: `Reset password ${rec.name}? Semua sesinya dicabut dan ia wajib mengatur password baru saat membuka Mini App.`,
      run: () => api.post(`/pengguna/${rec.id}/reset-password`, {}, { silent: true }),
    }),
    h('a', { class: 'btn btn-block', href: `#/pengguna/${encodeURIComponent(rec.id)}/sesi` }, 'Lihat sesi & perangkat'),
    aksi('Cabut semua sesi', 'btn-danger', {
      title: 'Cabut sesi', okText: 'Cabut', sukses: 'Sesi dicabut.',
      message: `Keluarkan ${rec.name} dari semua perangkat? Password tidak berubah.`,
      run: () => api.del(`/pengguna/${rec.id}/sesi`, { silent: true }),
    }));
}

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
  }, { title: 'Undangan Telegram', guard: bolehUndang });

  route('/pengguna/permintaan-reset', renderPermintaanReset, { title: 'Permintaan Reset Password', guard: bolehResetSesi });
  route('/pengguna/:id/sesi', renderSesiPengguna, { title: 'Sesi Pengguna', guard: bolehResetSesi });

  // Form undangan terisi dari draf Agen AI: /pengguna/undangan/baru?draft=<uuid> (tombol "Ubah" di chat bot).
  // Endpoint undangan tidak menerima ai_draft_id, jadi setelah terbit draf dibuang lewat DELETE /ai-drafts/{uuid}.
  route('/pengguna/undangan/baru', async (ctx) => {
    const uuid = ctx.query.get('draft');
    let draf = null;
    if (uuid) {
      try {
        draf = unwrap(await api.get(`/ai-drafts/${encodeURIComponent(uuid)}`, null, { silent: true }));
      } catch (e) {
        if (e?.status === 401) throw e;
        toast(e?.status === 404 ? 'Draf AI tidak ditemukan atau sudah kedaluwarsa.' : 'Draf AI gagal dimuat.', 'error');
      }
      if (ctx.isStale()) return;
      if (draf && draf.module !== 'pengguna') { toast('Draf ini untuk modul lain.', 'error'); draf = null; }
    }
    const fv = createFormView({
      fields: undanganFields(),
      values: draf ? isianUndanganDariDraf(draf.payload) : {},
      confidence: draf?.confidence || {},
      submitText: 'Buat tautan undangan',
      onSubmit: async (values) => {
        try {
          await terbitkanUndangan(buildInvitePayload(values), { title: 'Tautan undangan pengguna', shareText: 'Buka tautan ini di Telegram untuk masuk ke SiGAP SPPG.' });
        } catch (e) {
          if (e?.status !== 422 && e?.status !== 401) toast(errorMessage(e, 'Gagal membuat undangan.'), 'error');
          throw e; // 422 -> galat per kolom oleh form-view
        }
        if (draf) api.del(`/ai-drafts/${encodeURIComponent(draf.uuid || uuid)}`, { silent: true }).catch(() => { /* draf kedaluwarsa sendiri */ });
        navigate('/pengguna/undangan', { replace: true });
      },
    });
    if (draf) ctx.el.append(h('div', { class: 'notice' }, 'Diisi otomatis oleh Agen AI. Periksa kolom bertanda "Perlu dicek", lalu buat tautan.'));
    if (draf && perluDiperbaiki(draf)) {
      const lainnya = fv.terapkanDraf({ errors: draf.errors || {} });
      ctx.el.append(h('div', { class: 'alert-danger', role: 'alert' }, 'Draf dari Agen AI perlu diperbaiki. Perbaiki kolom bertanda merah, lalu buat tautan.', lainnya.length ? h('ul', { class: 'small' }, lainnya.map((m) => h('li', {}, m))) : null));
    }
    ctx.el.append(fv.el);
    return fv.destroy;
  }, { title: 'Buat Undangan', guard: bolehUndang });
}

export default {
  ...base,
  register() { registerUndangan(); base.register(); },
};

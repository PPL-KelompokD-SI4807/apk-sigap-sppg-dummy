// Perangkat & sesi aktif (R-03): daftar sesi, cabut satu sesi, keluar dari semua perangkat, keluar dari perangkat ini.
// Label perangkat berasal dari klien, jadi hanya dirender sebagai teks (h() memakai text node, bukan innerHTML).
import { h, clear, formatValue } from '../core/dom.js';
import {
  daftarSesi, keluarSemua, logout, bersihkanSesi, hapusSesi, daftarSesiPengguna, cabutSesiPengguna, cabutSemuaSesiPengguna,
} from '../core/auth.js';
import { showConfirm, toast } from '../core/ui.js';
import { errorMessage } from '../core/errors.js';
import { pesanGalatAkun } from '../core/kredensial.js';

const galat = (e, fallback) => pesanGalatAkun(e) || errorMessage(e, fallback);

/** Kartu satu sesi. onCabut dipanggil saat tombol Hapus ditekan. */
function kartuSesi(s, onCabut, labelTombol = 'Hapus') {
  const btn = h('button', { class: 'btn btn-danger', type: 'button', 'aria-label': `${labelTombol} sesi ${s.perangkat || 'perangkat tidak dikenal'}` }, labelTombol);
  btn.addEventListener('click', () => onCabut(s, btn));
  return h('div', { class: 'row-card' },
    h('span', { class: 'rc-main' },
      h('span', { class: 'rc-title' }, s.perangkat || 'Perangkat tidak dikenal'),
      h('div', { class: 'rc-sub' }, `Terakhir dipakai ${formatValue(s.terakhir_dipakai_pada, 'datetime')}`),
      h('div', { class: 'rc-sub' }, `Masuk ${formatValue(s.diverifikasi_pada || s.dibuat_pada, 'datetime')}`)),
    s.sesi_ini ? h('span', { class: 'badge badge-ok' }, 'Perangkat ini') : null,
    btn);
}

function isiDaftar(list, sesi, onCabut, labelTombol) {
  clear(list);
  if (!sesi.length) list.append(h('p', { class: 'muted pad center' }, 'Tidak ada sesi aktif.'));
  for (const s of sesi) list.append(kartuSesi(s, onCabut, labelTombol));
}

export async function renderSesi(ctx, { onKeluar }) {
  const list = h('div', { class: 'cards' });
  const aksi = h('div', { class: 'stack' });
  ctx.el.append(
    h('p', { class: 'muted small' }, 'Perangkat yang saat ini masuk ke akun Anda. Bila ada perangkat yang tidak dikenal, hapus sesinya atau keluar dari semua perangkat lalu masuk kembali.'),
    list, aksi);

  const muat = async () => {
    const sesi = await daftarSesi();
    if (ctx.isStale()) return;
    isiDaftar(list, sesi, cabut, 'Hapus');
  };

  async function cabut(s, btn) {
    const ini = s.sesi_ini;
    if (!(await showConfirm({
      title: 'Hapus sesi', okText: 'Hapus', destructive: true,
      message: ini ? 'Ini adalah perangkat yang sedang Anda pakai. Anda akan keluar dan harus masuk lagi. Lanjutkan?' : 'Perangkat ini akan keluar dan harus masuk lagi dengan kode dari bot. Lanjutkan?',
    }))) return;
    btn.disabled = true;
    try {
      await hapusSesi(s.id);
      if (ini) { bersihkanSesi(); onKeluar(); return; }
      toast('Sesi dihapus.', 'success');
      await muat();
    } catch (e) {
      btn.disabled = false;
      if (e?.status === 404) { toast('Sesi sudah tidak ada.', 'info'); return muat().catch(() => {}); }
      if (e?.status !== 401) toast(galat(e, 'Gagal menghapus sesi.'), 'error');
    }
  }

  const keluarIni = h('button', { class: 'btn btn-block', type: 'button' }, 'Keluar dari perangkat ini');
  keluarIni.addEventListener('click', async () => {
    if (!(await showConfirm({ title: 'Keluar', message: 'Keluar dari perangkat ini?', okText: 'Keluar' }))) return;
    keluarIni.disabled = true;
    await logout();
    onKeluar();
  });
  const keluarSemuaBtn = h('button', { class: 'btn btn-danger btn-block', type: 'button' }, 'Keluar dari semua perangkat');
  keluarSemuaBtn.addEventListener('click', async () => {
    if (!(await showConfirm({ title: 'Keluar dari semua perangkat', message: 'Semua perangkat, termasuk perangkat ini, akan keluar dan harus masuk lagi. Lanjutkan?', okText: 'Keluar semua', destructive: true }))) return;
    keluarSemuaBtn.disabled = true;
    try {
      const res = await keluarSemua();
      bersihkanSesi();
      toast(`${res?.jumlah_sesi ?? 0} sesi dikeluarkan.`, 'success');
      onKeluar();
    } catch (e) {
      keluarSemuaBtn.disabled = false;
      if (e?.status !== 401) toast(galat(e, 'Gagal keluar dari semua perangkat.'), 'error');
    }
  });
  aksi.append(h('a', { class: 'btn btn-block', href: '#/password' }, 'Ubah password'), keluarIni, keluarSemuaBtn);

  await muat();
}

/** Admin: sesi satu pengguna dalam scope. Rute #/pengguna/:id/sesi. */
export async function renderSesiPengguna(ctx) {
  const userId = ctx.params.id;
  const list = h('div', { class: 'cards' });
  const aksi = h('div', { class: 'stack' });
  ctx.el.append(
    h('p', { class: 'muted small' }, 'Sesi login pengguna ini. Mencabut sesi tidak mengubah password; pengguna harus masuk lagi dengan kode dari bot.'),
    list, aksi);

  const semua = h('button', { class: 'btn btn-danger btn-block', type: 'button' }, 'Cabut semua sesi pengguna');
  const muat = async () => {
    const sesi = await daftarSesiPengguna(userId);
    if (ctx.isStale()) return;
    isiDaftar(list, sesi, cabut, 'Cabut');
    semua.disabled = !sesi.length;
  };
  async function cabut(s, btn) {
    if (!(await showConfirm({ title: 'Cabut sesi', message: 'Cabut sesi perangkat ini? Pengguna harus masuk lagi di perangkat itu.', okText: 'Cabut', destructive: true }))) return;
    btn.disabled = true;
    try { await cabutSesiPengguna(userId, s.id); toast('Sesi dicabut.', 'success'); await muat(); }
    catch (e) {
      btn.disabled = false;
      if (e?.status === 404) { toast('Sesi sudah tidak ada.', 'info'); return muat().catch(() => {}); }
      if (e?.status !== 401) toast(galat(e, 'Gagal mencabut sesi.'), 'error');
    }
  }
  semua.addEventListener('click', async () => {
    if (!(await showConfirm({ title: 'Cabut semua sesi', message: 'Keluarkan pengguna ini dari semua perangkat?', okText: 'Cabut semua', destructive: true }))) return;
    semua.disabled = true;
    try { const r = await cabutSemuaSesiPengguna(userId); toast(r?.message || 'Semua sesi dicabut.', 'success'); await muat(); }
    catch (e) { semua.disabled = false; if (e?.status !== 401) toast(galat(e, 'Gagal mencabut sesi.'), 'error'); }
  });
  aksi.append(semua);
  await muat();
}

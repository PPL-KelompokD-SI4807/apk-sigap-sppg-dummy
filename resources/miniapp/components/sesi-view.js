// Perangkat & sesi aktif (R-03): daftar sesi, keluar dari semua perangkat, keluar dari perangkat ini.
import { h, clear, formatValue } from '../core/dom.js';
import { daftarSesi, keluarSemua, logout, bersihkanSesi } from '../core/auth.js';
import { showConfirm, toast } from '../core/ui.js';
import { errorMessage } from '../core/errors.js';

export async function renderSesi(ctx, { onKeluar }) {
  const list = h('div', { class: 'cards' });
  const aksi = h('div', { class: 'stack' });
  ctx.el.append(
    h('p', { class: 'muted small' }, 'Perangkat yang saat ini masuk ke akun Anda. Bila ada perangkat yang tidak dikenal, keluar dari semua perangkat lalu masuk kembali.'),
    list, aksi);

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
      if (e?.status !== 401) toast(errorMessage(e, 'Gagal keluar dari semua perangkat.'), 'error');
    }
  });
  aksi.append(keluarIni, keluarSemuaBtn);

  const sesi = await daftarSesi();
  if (ctx.isStale()) return;
  clear(list);
  if (!sesi.length) list.append(h('p', { class: 'muted pad center' }, 'Tidak ada sesi aktif.'));
  for (const s of sesi) {
    list.append(h('div', { class: 'row-card' },
      h('span', { class: 'rc-main' },
        h('span', { class: 'rc-title' }, s.perangkat || 'Perangkat tidak dikenal'),
        h('div', { class: 'rc-sub' }, `Terakhir dipakai ${formatValue(s.terakhir_dipakai_pada, 'datetime')}`),
        h('div', { class: 'rc-sub' }, `Masuk ${formatValue(s.diverifikasi_pada || s.dibuat_pada, 'datetime')}`)),
      s.sesi_ini ? h('span', { class: 'badge badge-ok' }, 'Perangkat ini') : null));
  }
}
